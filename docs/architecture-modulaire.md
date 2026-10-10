# Architecture modulaire UCG

## Objectif

Le backend reste un monolithe Laravel déployé comme une seule application, mais son code est
divisé en modules métier autonomes. Chaque module possède sa logique, ses interfaces de
persistance, ses adaptateurs Laravel et ses points d’entrée HTTP. Cette séparation prépare une
évolution indépendante des domaines sans ajouter la complexité opérationnelle de microservices.

## Carte des modules

La carte exécutable se trouve dans `App\Architecture\Modules\ModuleDependencyMap`. Une
dépendance signifie que le module de gauche peut consommer l’API publique du module de droite.

| Module | Dépendances autorisées |
| --- | --- |
| `Identity` | aucune |
| `Tenancy` | `Identity` |
| `People` | `Identity`, `Tenancy` |
| `Teams` | `Tenancy`, `People` |
| `Academy` | `Tenancy`, `People`, `Teams` |
| `Scheduling` | `Tenancy`, `People`, `Teams` |
| `Competition` | `Tenancy`, `Teams`, `Scheduling` |
| `Performance` | `Tenancy`, `People`, `Teams`, `Competition` |
| `Contracts` | `Tenancy`, `People` |
| `Partnerships` | `Tenancy` |
| `Finance` | `Tenancy`, `Contracts`, `Partnerships` |
| `Media` | `Tenancy`, `People`, `Teams` |
| `Reporting` | tous les modules métier nécessaires à ses projections de lecture |

`Reporting` est un consommateur terminal : aucun autre module ne doit dépendre de lui.

## Structure d’un module

```text
app/Modules/<Module>/
├── Application/
│   ├── Contracts/       API synchrone consommable par les autres modules
│   ├── Data/            DTO immuables
│   └── Services/        orchestration des cas d’usage
├── Domain/
│   ├── Contracts/       ports de persistance du module
│   ├── Events/          événements publics immuables
│   ├── Exceptions/      erreurs métier ciblées
│   └── Models/          modèles et règles appartenant au domaine
├── Infrastructure/
│   └── Persistence/     implémentations des repositories
├── Presentation/
│   ├── Http/            Controllers, Form Requests et Resources
│   ├── Policies/        autorisations du module
│   └── Routes/          routes chargées par le provider du module
├── Providers/           composition Laravel du module
└── Module.php           frontière physique déclarée
```

Les dossiers ne sont créés que lorsqu’ils portent une responsabilité réelle. `Module.php`
matérialise la frontière même avant l’arrivée des premières fonctionnalités.

## Règles de dépendance

1. Un module ne peut importer que les modules déclarés dans `ModuleDependencyMap`.
2. Une communication inter-module passe uniquement par `Application\Contracts` pour un appel
   synchrone ou par `Domain\Events` pour une réaction découplée.
3. Il est interdit d’importer directement le modèle, le repository, le controller ou
   l’infrastructure d’un autre module.
4. À l’intérieur d’un module, les dépendances pointent vers le cœur :
   `Presentation → Application → Domain` et `Infrastructure → Application/Domain`.
5. Le provider du module est la racine de composition autorisée à relier les couches, charger
   les routes, policies et bindings.
6. Les migrations restent centralisées dans `database/migrations` conformément à la convention
   Laravel. Chaque table conserve toutefois un module propriétaire. Une relation inter-module
   stocke l’identifiant nécessaire, mais ne crée pas un accès implicite au modèle Eloquent voisin.
7. Les écritures cohérentes sont orchestrées dans un Service et protégées par une transaction.
   Les événements externes sont publiés après validation de la transaction.
8. Les controllers restent minces : validation par Form Request, autorisation par Policy,
   orchestration par Service et sérialisation par Resource.

Les tests de `tests/Architecture/ModularArchitectureTest.php` bloquent les modules manquants,
les cycles, les dépendances non déclarées, le contournement des APIs publiques et les
dépendances de couche dirigées vers l’extérieur.

## Exemple vertical Identity

Le parcours de connexion sert de référence exécutable :

```text
Presentation/Routes/api.php
  → LoginRequest
  → AuthController
  → AuthenticationService + LoginData
  → UserRepositoryInterface
  → EloquentUserRepository
  → User
```

`IdentityServiceProvider` lie le contrat au repository Eloquent, enregistre la Policy, le rate
limiter et les routes. Aucun de ces détails n’est exposé aux futurs modules.

## Ajouter une fonctionnalité

### Contexte métier — UCG-TEN-002

Les modules autorisés à dépendre de Tenancy consomment
`Tenancy\Application\Contracts\TenantContext`, un DTO public `readonly`.
Le middleware `tenant` le résout depuis un UUID de route ou un hôte exact
approuvé, vérifie le propriétaire ou une adhésion active datée et le statut actif, puis l’attache à la
requête. Il s’exécute après l’authentification et avant les bindings de ressources.
Les adhésions, rôles et permissions minimales sont pris en compte depuis `TEN-003`.

Injecter le contexte dans la méthode d’action, pas dans le constructeur du
controller : Laravel peut instancier celui-ci avant l’exécution du middleware.
Passer ensuite le contexte explicitement aux Services. Ceux-ci transmettent
son UUID aux ports de persistance du Domain, sans créer de dépendance
`Domain → Application`. Toute recherche de ressource combine son identifiant
avec cet UUID ; `assertOrganization()` permet de refuser un identifiant
d’organisation différent, même si l’acteur possède les deux organisations.
Cette garde ne remplace ni les Policies ni les contraintes de base.

Le binding du contexte relit la requête courante et vérifie son acteur et sa
corrélation. Il ne mémorise pas le contexte dans le conteneur ; le middleware
nettoie ses attributs et le contexte de journalisation tenant dans un `finally`.
Ne jamais utiliser le contexte Laravel de journalisation comme preuve
d’autorisation. Les callbacks après réponse, workers et commandes ne disposent
pas automatiquement d’un contexte HTTP. Capturer les identifiants explicitement
avant la fin de la requête ; `toJobContext()` fournit le transport existant.
La restauration contrôlée des jobs sera réalisée dans `TEN-006`.

### Propriété des tables et contraintes SQL — UCG-TEN-004

Le registre fermé `App\Architecture\Database\TableOwnership::GLOBAL_TABLES`
recense les seules exceptions au rattachement tenant. Une table inconnue est
tenant-owned par défaut, jamais globale par convention de nommage.

| Tables exemptées | Motif et limites |
| --- | --- |
| `users`, `password_reset_tokens`, `personal_access_tokens` | Identité et authentification du compte global ; aucun droit métier implicite |
| `organizations` | Racine du tenant : `id` identifie l’organisation ; ce registre ne donne aucun droit de lecture globale |
| `sessions` | Transport de session du compte ; la sélection ne constitue pas une autorisation |
| `cache`, `cache_locks` | Transport partagé ; clés tenant et verrous atomiques via les ports TEN-005 |
| `jobs`, `job_batches`, `failed_jobs` | Transport et suivi techniques ; contexte contrôlé dans TEN-006, accès aux incidents réservé aux opérateurs |
| `migrations` | Historique technique du schéma |

Les sept tables tenant actuellement implémentées sont `organization_settings`,
`organization_lifecycle_events`, `organization_memberships`,
`organization_invitations`, `organization_access_events`, `tenant_storage_usage`
et `tenant_artifacts`. Toutes imposent
`organization_id NOT NULL`, une FK vers `organizations.id` et un index de lecture
commençant par l’organisation. Le tenant est immuable après création, y compris
pour les paramètres. Les comptes auteur/destinataire restent des références
globales : une FK SQL ne remplace pas leur autorisation ou une adhésion datée.

Les adhésions sont uniques par `(organization_id, user_id)` ; les invitations
`pending` par `(organization_id, email)`. Un même compte ou e-mail peut donc
exister dans plusieurs tenants conformément à TEN-003. L’exclusivité optionnelle
reste une politique d’admission explicite, pas une unicité globale accidentelle.

Le journal d’accès conserve `action`, `subject_id`, les snapshots et la corrélation.
Deux colonnes calculées par SQL, `membership_id` et `invitation_id`, dérivent
le sujet de la liste fermée des actions TEN-003. Exactement une référence est
requise ; les actions inconnues sont refusées. Les FK composites
`(organization_id, membership_id/invitation_id)` ciblent les clés uniques
`(organization_id, id)` des objets. Elles interdisent les sujets étrangers,
inexistants ou du mauvais type, ainsi que leur suppression physique après audit.
Les colonnes calculées ne sont pas assignables et restent masquées par le modèle.
Les index partiels des références couvrent les vérifications de FK ; la clé
unique des invitations remplace l’ancien index de pagination identique.

`TenantSchemaInspector` et `php artisan tenancy:check-schema` vérifient en lecture
seule le schéma courant partagé : rattachement non nul, FK racine, index tenant,
unicités métier et appariement de l’organisation dans les relations tenant.
Seule la PK technique `id` est exemptée des unicités composites. Les relations
hors du schéma partagé sont refusées. Le contrôle ne lit aucune donnée métier,
ne répare rien et ne prouve pas à lui seul l’autorisation des requêtes, les
triggers d’immutabilité ou la bonne sémantique de chaque clé fonctionnelle.
Les tests d’intégration exécutent les tentatives de contournement en SQL direct
sur SQLite et PostgreSQL 17 ; la CI couvre aussi ce contrôle de schéma.

Pour chaque future table métier : attribuer le tenant côté Service depuis le
contexte autorisé ; déclarer le rattachement non nul et immuable, les unicités
locales et les index adaptés aux lectures ; ajouter `(organization_id, id)`
lorsqu’une autre table doit la référencer ; utiliser une FK composite avec le
même `organization_id` local pour toute association tenant. Tester les accès
positifs et négatifs en HTTP **et** les écritures SQL inter-tenant. Une nouvelle
exception globale exige une décision documentée et une modification du registre.
Les futurs modules ne sont pas créés artificiellement pour ce ticket.

#### Migration et exploitation

TEN-004 ajoute une migration sans modifier celles déjà déployées. Elle valide
l’historique avant toute modification : sujet inexistant, mauvais tenant/type
ou action inconnue interrompent la migration sans réparation ou suppression.
Sur PostgreSQL, la transaction verrouille les quatre tables concernées contre
les écritures concurrentes ; l’attente d’acquisition est limitée à cinq secondes.
Les colonnes calculées sont stockées sur PostgreSQL, virtuelles sur SQLite.
SQLite reconstruit la table pour ajouter les FK ; les triggers append-only sont
alors explicitement restaurés, à l’aller comme au retour.

Sur une base volumineuse, la matérialisation des colonnes peut réécrire la table
et prolonger les verrous : mesurer sur une copie représentative, disposer d’une
sauvegarde vérifiée et prévoir une fenêtre de maintenance. La limite de cinq
secondes concerne l’attente de verrou, pas la durée totale de la migration.
Pour plusieurs millions d’audits, préparer un déploiement progressif dédié avant
d’exécuter cette migration telle quelle ; aucun benchmark de cette taille n’est
revendiqué. Un historique incohérent nécessite une régularisation approuvée,
jamais une désactivation automatique des protections d’audit.

Le rollback TEN-004 conserve les payloads mais retire ces protections d’isolation.
Il est testé uniquement sur des bases dédiées ; préférer une correction en avant
en production. Ne jamais utiliser `migrate:fresh` ou un rollback pour mettre à
jour la base principale.

#### Décision RLS

RLS est évaluée comme défense supplémentaire, **non activée par TEN-004**.
Les contraintes SQL protègent les associations, pas une lecture SQL oubliant
son filtre tenant ; les Repositories contextualisés et Policies restent requis.
PostgreSQL distingue `USING` (visibilité) et `WITH CHECK` (écritures) ; les
propriétaires des tables, superusers et rôles `BYPASSRLS` peuvent contourner les
politiques. Les contraintes référentielles ne constituent pas une politique
d’autorisation. Voir la [documentation PostgreSQL 17 RLS](https://www.postgresql.org/docs/17/ddl-rowsecurity.html).

Avant activation : séparer les rôles SQL de migration et d’application, rendre
le rôle applicatif non propriétaire/non-superuser/sans BYPASSRLS et définir les
politiques nécessaires ; utiliser un tenant validé avec `SET LOCAL` dans une
transaction, jamais un état de connexion persistant issu du client ; refuser
un contexte absent ; tester pools/connexions réutilisées, workers, retries,
scheduler, sauvegardes et administration exceptionnelle auditée. Les parcours
multi-organisations et la boîte d’invitations globale nécessitent une stratégie
explicite, pas une politique tenant unique appliquée aveuglément. Ce travail
d’intégration n’est pas prétendu livré par l’évaluation et dépend notamment de
TEN-006. RLS ne remplace ni les FK composites ni les autorisations applicatives.

### Flux d’ajout

La sélection `TEN-003` est un marqueur de session avec révision, pas un DTO
persisté ni une preuve d’autorisation. Les mutations tenant exigent cette
révision et une confirmation après changement de sélection. Les routes
globales d’invitation/départ conservent leur autorisation propre et un contrôle
de révision lorsqu’une sélection existe. Les verrous de session empêchent
qu’un changement de sélection s’intercale pendant une requête sensible.
Ne pas injecter `TenantContext` dans un constructeur de middleware : Laravel
peut réinstancier ce middleware à la terminaison, une fois le contexte nettoyé.

Les admissions et réactivations verrouillent d’abord le compte vérifié via le
contrat public Identity, puis l’organisation, puis l’adhésion/invitation.
Les audits d’accès sont append-only et transactionnels ; l’e-mail est différé
après commit. Aucun modèle Identity n’est importé directement dans Tenancy.
Les tables d’accès et leurs migrations restent centrales, détenues par Tenancy.

### Ressources techniques — UCG-TEN-005

Les modules utilisent les contrats publics `TenantArtifacts`, `TenantCache`
et `TenantResourceLimits`, sans accéder directement à un bucket ou à des clés
de cache globales. Les adaptateurs restent internes à Tenancy/Support.
Le contexte est explicite, jamais stocké dans un singleton ; fichiers/caches
revérifient l’accès et le statut à chaque opération. Le module propriétaire
doit appliquer ses Policies d’objet et de champ avant de lire du cache ou du
stockage. Une clé de cache de données filtrées doit distinguer l’acteur/les
droits concernés ; ne pas réutiliser le résultat privilégié d’un autre acteur.

Le stockage immuable est préfixé par tenant. La réservation durable en base
est conservatrice face aux pannes et ne dépend pas de Redis. Les deux nouvelles
tables imposent une organisation existante/immuable et des volumes non négatifs.
Les mutations de compteur sont sérialisées par organisation ; il n’y a pas de
verrou global. Cache/verrous/débit utilisent des namespaces distincts, UUID
canonique et identifiant fonctionnel hashé, sur un backend partagé sans failover.
La contention database utilise un insert conflict-safe et conserve le
contrôle d’ownership Laravel pour la libération. La suppression d’un fichier
doit être confirmée avant de libérer le quota. Les modalités de panne,
inventaire des fichiers anciens, transaction englobante, leases, limites
initiales et contraintes de montée en charge sont détaillées dans README.

Les bornes sont techniques et configurables côté opérateur par UUID, sans
facturation. Les imports/exports futurs doivent appeler les gardes pendant
leur traitement : ce lot ne crée pas de parcours métier artificiel.
Les jobs/schedulers, leurs retries et leur autorisation restaurée restent
TEN-006 ; les téléchargements métier devront appliquer les Policies du module.

La politique d’adhésion de `TEN-003` doit être isolée de la résolution du contexte :
multi-organisations par défaut, restriction d’exclusivité activable explicitement.
Vérifier les périodes côté serveur et sérialiser les admissions d’un même compte
pour éviter deux acceptations concurrentes. La suspension n’est pas un départ.
La restriction ne fusionne ni identités ni historiques et ne remplace pas les Policies.

1. Confirmer le module propriétaire et ses dépendances avant de coder.
2. Ajouter ou modifier le contrat public seulement si un autre module doit réellement appeler
   le cas d’usage.
3. Construire le flux vertical complet avec Request, Policy, Controller, Service, Repository et
   Resource lorsque ces couches sont pertinentes.
4. Ajouter migrations réversibles, contraintes, index et tests d’isolation nécessaires.
5. Exécuter les tests d’architecture, PHPUnit, Pint et les vérifications frontend.
6. Refuser toute nouvelle dépendance circulaire ; extraire un contrat ou publier un événement.
