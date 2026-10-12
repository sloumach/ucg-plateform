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

## Périmètre sportif — décision du 11 octobre 2026

Le module `Competition` reste prévu pour les scrims, matchs, parties, scores et
le suivi interne des participations aux compétitions externes. Ses dépendances
vers `Tenancy`, `Teams` et `Scheduling`, ainsi que celles de `Performance` et
`Reporting`, restent pertinentes pour ces usages.

L’organisation de tournois est retirée du périmètre : aucun portail participant,
inscription publique, bracket, moteur de progression ou arbitrage de tournoi
n’est prévu, même en P1/P2. Le modèle conceptuel conservé distingue la compétition
externe et le dossier de participation interne ; il ne décrit pas un tenant
organisateur accueillant des inscrits sur la plateforme.

Les accès externes nécessaires aux sponsors et candidats, les instantanés
d’adversaires et les permissions par événement restent des mécanismes distincts.
Les événements hors compétition restent dans le planning. Cette décision
documentaire ne modifie ni le code, ni les migrations, ni les données existantes.

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

IAM-002 complète ce parcours avec `MfaController`, trois Form Requests,
`MfaResource`, `MfaService`, `MfaSession` et le port `MfaRepository`.
`Totp` porte le calcul RFC 6238, vérifié avec ses vecteurs publiés.
Le repository verrouille le compte pour consommer un TOTP ou un code de
récupération, persister la version et auditer dans la même transaction.
Le secret est chiffré, les codes hachés et les attributs exclus de la
sérialisation ordinaire de `User`.

Identity ne dépend toujours pas de Tenancy : il déclare le port public
`PrivilegedAccountDirectory`, implémenté et lié par Tenancy pour les propriétaires
et administrateurs effectifs. `AccountDirectory.hasConfirmedMfa` permet aux
services d’administration et aux jobs Tenancy de vérifier l’enrôlement sans
importer le modèle utilisateur. La seule capacité plateforme actuelle concernée
est la liste opérateur Horizon ; IAM-003 raccorde les rôles configurables privilégiés.

Les routes métier suivent `auth:sanctum` → `identity.session` → `mfa` → `tenant`
→ confirmation éventuelle → binding/Policy. La preuve de session est liée au
compte, à la version MFA et à une empreinte du hash de mot de passe. Un reset,
une récupération ou un changement de facteur invalide les anciennes preuves.
Le challenge revérifie cette liaison sous verrou pour empêcher une révocation
concurrente d’être contournée. Les anciennes sessions sans marqueur sont refusées
dès qu’une version MFA existe. React masque l’espace tenant pendant le challenge
et recharge l’identité après un blocage MFA ou `401`.


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
La restauration contrôlée des jobs est livrée dans `TEN-006` : le transport
inclut l’acteur, mais pas ses rôles ; le worker résout à nouveau ses accès.

### Propriété des tables et contraintes SQL — UCG-TEN-004

Le registre fermé `App\Architecture\Database\TableOwnership::GLOBAL_TABLES`
recense les seules exceptions au rattachement tenant. Une table inconnue est
tenant-owned par défaut, jamais globale par convention de nommage.

| Tables exemptées | Motif et limites |
| --- | --- |
| `users`, `password_reset_tokens`, `personal_access_tokens` | Identité et authentification du compte global ; aucun droit métier implicite |
| `identity_security_events` | Journal append-only des événements de sécurité du compte global ; accès opérateur uniquement |
| `organizations` | Racine du tenant : `id` identifie l’organisation ; ce registre ne donne aucun droit de lecture globale |
| `sessions` | Transport de session du compte ; la sélection ne constitue pas une autorisation |
| `cache`, `cache_locks` | Transport partagé ; clés tenant et verrous atomiques via les ports TEN-005 |
| `jobs`, `job_batches`, `failed_jobs` | Transport et suivi techniques ; contexte contrôlé dans TEN-006, accès aux incidents réservé aux opérateurs |
| `migrations` | Historique technique du schéma |

Les huit tables tenant actuellement implémentées sont `organization_settings`,
`organization_lifecycle_events`, `organization_memberships`,
`organization_invitations`, `organization_access_events`, `tenant_storage_usage`,
`tenant_artifacts` et `tenant_portability_operations`. Toutes imposent
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
d’intégration n’est pas livré par l’évaluation ni par TEN-006 : ce dernier fournit
les contextes workers, pas les rôles/politiques RLS. RLS ne remplace ni les FK
composites ni les autorisations applicatives.

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
Les jobs/schedulers utilisent le contrat TEN-006 ci-dessous ; les téléchargements
métier devront appliquer les Policies du module.

### Exécutions différées — UCG-TEN-006

Le contrat public `TenantJob` impose un middleware final, implémenté derrière
`TenantJobMiddleware`. Il transporte les IDs tenant/acteur/corrélation et résout
un `TenantContext` frais à chaque tentative. Les rôles du dispatch ne sont pas
une autorisation persistante. Le binding worker ne dépend ni de la sélection
HTTP ni du contexte Laravel de journalisation. `TenantExecutionScope` est scoped,
jamais singleton, et restaure son état dans un `finally`, y compris en cas
d’exception ou de dispatch synchrone imbriqué. Les refus permanents sont marqués
échoués ; les erreurs transitoires laissent la politique queue gérer les retries.
Les jobs métier appliquent toujours leurs Policies/Services avant les effets.

`TenantScheduledTask` représente un périmètre **système enregistré côté serveur**,
pas un acteur propriétaire implicite. Son `TenantExecutionContext` n’accorde pas
les droits d’un utilisateur. Les handlers futurs doivent filtrer les ressources
et définir leurs autorisations système/idempotence ; aucun endpoint public ne
peut choisir une classe ou lancer un handler. Le registre est vide par défaut.
Le fan-out global lit uniquement des IDs actifs par pages de 200 avec curseur ;
il distribue un job indépendant par tenant et une continuation. Le statut est
revérifié à l’exécution, sans snapshot global ni transaction longue sur tous les tenants.
Un verrou par page/run et des checkpoints de distribution de 24 h limitent
les redistributions lors d’une reprise ; ils ne remplacent pas un outbox durable
ni les contraintes d’idempotence des futurs handlers métier.

Les gardes scheduler multi-nœuds utilisent un cache partagé sans failover ;
les verrous d’exécution et marqueurs de succès distinguent tenant/tâche/run.
Les marqueurs réduisent les doublons, sans remplacer une idempotence métier
durable : ce socle ne promet pas exactly-once. Corrélation, logs structurés,
failed jobs et Horizon permettent la supervision et la reprise ciblée.
Le raccordement, les limites, les anciens payloads et l’exploitation sont décrits
dans README. Aucune migration ni réécriture d’historique n’est nécessaire.

### Cycle de vie et portabilité — UCG-TEN-007

Les Services opérateur `OrganizationPortabilityService`, `OrganizationPurgeService`
et `OrganizationRecoveryService` utilisent les ports `PortabilityRepository`,
`PortabilityArchive` et `PortabilityQueue`, implémentés par l’infrastructure
SQL/stockage privé/queue. Les commandes valident les entrées ; aucun endpoint
HTTP, rôle superadmin fictif ou accès implicite du propriétaire n’est ajouté.
L’accès serveur/queue doit être contrôlé ; l’acteur vérifié est déclaré pour audit.

`tenant_portability_operations` conserve identité tenant/acteur/motif/corrélation,
type, état, taille, SHA-256 et détails techniques. Les identités sont immuables
et la suppression SQL interdite ; seuls les états/détails techniques évoluent.
L’export a un inventaire fermé des tables et colonnes, filtres tenant explicites,
snapshot PostgreSQL et pagination. Un ajout de schéma non enregistré bloque
l’export au lieu de le rendre incomplet ou d’y exposer un champ implicitement.
Le NDJSON versionné inclut les lignes et fichiers enregistrés TEN-005, pas les
comptes/jetons globaux ni les dossiers arbitraires. Archives privées, budgets
réservés distincts du ledger métier, intégrité vérifiée et expiration bornée.

`BuildOrganizationExport` est un job opérateur avec UUID tenant/opération : il
recharge l’acteur et le statut technique en base, y compris pour un tenant
inactif. Son contexte système scoped ne fabrique pas de droits utilisateur ;
le refus des tenants inactifs dans les jobs métier TEN-006 reste inchangé.
La queue est durable et après commit ; les pannes restent visibles/reprenables
par UUID. Les mutations externes ne bénéficient pas d’un retry transactionnel
automatique. Voir README pour limites, scheduler optionnel et procédures.

La purge est un plan en lecture seule par défaut. Une exécution archivée exige
confirmation exacte, export intègre généré après archivage et politique complète
revue par UUID/date/catégories/référence de sauvegardes. Elle ne supprime que
paramètres/artefacts/exports autorisés, par lots reprenables avec empreinte de
politique. Racine, historiques, sujets d’audit et comptes restent protégés.
Ce socle n’est pas un effacement physique complet : durées et effacement des
historiques/sauvegardes/réplicas restent CAD-004/OPS-007.

La récupération opérateur de données conservées accepte seulement `archived`
vers `suspended`, sans purge engagée, avec paramètres/export présents et
admission du propriétaire vérifiée sous verrous compte puis organisation.
Elle est auditée et ne remplace ni données ni adhésions ; aucune queue n’est
reprise automatiquement. La transition ordinaire depuis `archived` reste
interdite. La restauration sélective après perte est documentée dans README,
mais son import/outillage et sa répétition opérationnelle restent OPS-005.

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

## Autorisation contextuelle — UCG-IAM-003

Tenancy possède `OrganizationRole`, `OrganizationRoleAssignment` et le catalogue
fermé `Permission`. `RoleManagementService` orchestre DTO validés, autorisation
fraîche, verrous, périodes, versions et audit ; `RoleRepository` isole Eloquent.
Les contrôleurs utilisent Form Requests, Policy/Gate et Resources. Les clés
étrangères composites lient tenant/rôle et tenant/adhésion/compte ; des triggers
bloquent le changement d’identité. Le journal d’accès projette les nouveaux sujets
par colonnes générées et conserve sa protection append-only.

Le contrat public `TenantAuthorization` expose `allows`, `authorize` et le
snapshot organisation `permissions`. Il recharge organisation, adhésion,
affectation effective, rôle actif et MFA depuis la base. Un ancien contexte ne
peut imposer ses indicateurs propriétaire/rôle à cette décision. Le Gate
`tenant.permission` vérifie aussi que le compte authentifié correspond au contexte.
La gouvernance historique reste sans droits métier implicites.

Les autres modules doivent injecter ce contrat et demander la permission exacte
avec type/UUID de ressource, sans importer les modèles internes de Tenancy.
`TenantJobRunner::user` accepte permission, type et ID optionnels et les revérifie
à l’exécution d’un job utilisateur :

```php
$runner->user($transport, fn () => $reportService->build(), 'sports.read', 'team', $teamId);
```

Cet exemple reste refusé tant que Teams ne fournit pas son annuaire. Les tâches
planifiées système gardent leur contrat distinct. `PrivilegedAccountDirectory`
inclut les droits configurables privilégiés effectifs dans la politique MFA globale.

`RegisteredAuthorizationScopes` valide l’organisation par défaut. Chaque module
propriétaire doit implémenter `AuthorizationScopeDirectory`, prouver l’existence
et l’appartenance de ses ressources au tenant et enregistrer sa classe dans
`tenancy.authorization_scope_resolvers` sous `team`, `game`, `cohort` ou `event`.
La configuration ne provient jamais d’un payload utilisateur. Sans adaptateur
ou pour un UUID inconnu, le droit est refusé. Les tests utilisent un annuaire
factice avec deux équipes valides pour prouver les limites de délégation.

Les droits nécessitent une adhésion actuellement effective, sauf propriétaire.
Suspension, expiration et rôle inactif priment. Départ/révocation d’adhésion
révoquent les anciennes affectations sous verrou organisation, dans la même
transaction avec audit ; une réadmission ne les restaure pas. Les mutations
sérialisées et versions UUID empêchent les mises à jour depuis un état périmé.

Listes paginées, chargement des rôles en bloc, requêtes `exists`, index composés
et plafond de 100 rôles par tenant bornent le travail. Aucun cache durable de
permissions n’est introduit ; profiler avant optimisation distribuée et respecter
versions/expirations/révocations. La portabilité inclut les deux nouvelles tables ;
les projections générées d’audit sont reconnues mais ne sont pas des entrées de
restauration. La purge existante conserve les historiques d’accès et leurs sujets.

La migration additive borne l’attente de verrou PostgreSQL à cinq secondes ;
ses colonnes générées peuvent réécrire un grand journal. Le rollback fonctionne
sans audit de rôles et refuse de supprimer un historique IAM-003 déjà audité.
Préférer une correction en avant après usage. IAM-004 fournit le socle de
projection ci-dessous ; IAM-005 sépare la plateforme et le support, décrit plus bas.

## Visibilité des sorties — UCG-IAM-004

Les modules consomment exclusivement les ports publics `TenantDataVisibility`,
`TenantDataRecord` et `FieldVisibility` sous `Tenancy/Application/Contracts`.
`TenantDataProjectionService` s’appuie sur `TenantAuthorization` et le répertoire
de périmètres ; il ne lit aucune table métier. Chaque module prépare un DTO de
données candidates après sélection tenant-scoped et autorisation de l’action.
Le schéma de champs appartient au code serveur, jamais à une requête cliente.

La projection refuse les tenants étrangers, vérifie le périmètre réel et l’accès
actuel, puis conserve seulement les champs déclarés et autorisés. Les structures
imbriquées sont projetées récursivement ; aucun modèle, objet ou tableau associatif
ne peut traverser une feuille sans schéma enfant. Un administrateur n’obtient pas
implicitement `sports.read`, `administrative.read` ou `financial.read`. Les deux
dernières permissions exigent un MFA confirmé.

Un appel est borné à 200 records. Les décisions identiques sont mutualisées dans
cet appel, par permission et périmètre ; aucune décision n’est conservée dans le
service, un cache partagé ou un payload de queue. Chaque nouveau chunk ou livraison
relit les autorisations. Le test de requêtes compare une projection et 100 records.
Les enfants appartiennent au même périmètre que leur record ; les collections de
ressources de périmètres différents doivent préparer plusieurs records distincts.

`RoleProjectionService` constitue le raccordement existant : DTO explicite avant
`RoleResource`, contrôles `roles.assign`, pagination HTTP préservée. Les autres
Resources actuelles conservent leurs allow-lists et Policies. L’autorisation du
mail d’invitation utilise désormais `invitations.manage` relu par le worker ;
les délégations sont acceptées et les délégations révoquées ne livrent pas le mail.

Les modules métier et leurs canaux de recherche/export/notification n’existent
pas encore. Chacun devra raccorder ce port avant livraison, filtrer ses prédicats
de recherche/tri et sélectionner seulement les records autorisés. Une projection
ne remplace pas l’autorisation de l’action ni le filtrage des requêtes. Les backups
opérateur TEN-007 restent complets, réservés à un accès autorisé au serveur avec
acteur vérifié, catalogue fermé et contrôles avant génération et lecture. IAM-005
refuse les acteurs techniques actifs ; un schéma nouveau
non intégré bloque la sauvegarde. Aucun endpoint métier, migration ou dépendance
n’est ajouté par IAM-004.

## Séparation plateforme/support — UCG-IAM-005

Identity possède les tables globales `platform_roles` et `platform_role_events`,
explicitement classées dans `TableOwnership`. Le port public
`Identity/Application/Contracts/PlatformAccountDirectory` fournit les décisions
actuelles et la version du rôle ; Tenancy ne lit pas les modèles internes Identity.
`PlatformRoleService` utilise un repository, valide les comptes vérifiés/MFA
et sérialise les changements sur l’événement d’initialisation immuable.
La contrainte unique de bootstrap empêche deux initialisations concurrentes ;
le dernier superadmin actif est protégé. L’audit avant/après est transactionnel.

Tenancy possède `organization_support_accesses` et `organization_support_events`.
Form Requests, Policy, controllers minces, DTO/Resources fermées, service et
repository séparent validation, orchestration et persistance. Les routes support
utilisent auth/session/MFA et un quota de 30 requêtes par minute et opérateur.
Elles ne construisent aucun `TenantContext` et ne modifient pas `TenantResolver`.

Chaque accès est immuable sauf sa révocation définitive ; durée maximale
30 minutes, actions fermées, identité tenant/opérateur/version de rôle figée.
Le service verrouille l’organisation et l’accès, vérifie les droits courants,
puis audite le diagnostic avant diffusion. Un refus de période/action/version
sur son propre accès est commité avant le 403. Les audits SQL refusent update/delete
et les FK composites refusent les références de support entre tenants.

`TenantPermissionService` refuse les permissions métier aux comptes techniques
actifs, même si une affectation tenant existe. `OrganizationPortabilityService`
refuse aussi ces acteurs, au lancement, au traitement et à la lecture ; le rôle
support ne constitue pas une autorisation de sauvegarde complète. Horizon exige
superadmin actif, e-mail vérifié/autorisé et MFA de session courant.

Le catalogue de sauvegarde conserve l’historique support de son seul tenant ;
les rôles et audits plateforme globaux sont exclus. Aucune restauration n’est
ajoutée. Les index tenant/opérateur/expiration et opérateur/date/UUID couvrent
les vérifications et la liste paginée ; aucun cache durable de droits.

Migration additive PostgreSQL/SQLite, attente de verrou PostgreSQL bornée à 5 s,
pas de backfill ni compte créé. Le rollback vide est testé ; après usage il
refuse de supprimer l’historique. Le journal transverse et la conservation sont
livrés par IAM-006 ci-dessous ; l’interface de support React reste à implémenter.

## Journal transverse et conservation — UCG-IAM-006

Les ports publics `PlatformAuditJournal` (Identity) et `TenantAuditJournal` (Tenancy)
isolent la persistance. DTOs et fusion chronologique bornée résident dans
`Support/Audit` ; seuls les repositories propriétaires définissent leurs tables,
colonnes et projections. Les services orchestrent autorisation courante, transaction
et trace de consultation ; Form Requests, Policies et Resource encadrent les routes.
Aucun modèle interne d’un autre module n’est utilisé.

Identity agrège sécurité, rôles plateforme et `identity_audit_events` global.
Tenancy agrège cycle de vie, accès, support et `organization_audit_events` filtrés
par le tenant résolu. Aucun accès tenant implicite pour le superadmin ; les comptes
techniques actifs sont refusés. `audit.view` est privilégiée et limitée à
l’organisation. Une lecture n’est diffusée qu’après écriture de sa trace.
Le middleware de refus est placé après validation de session et avant MFA/résolution
tenant ; il trace les 403/404 sans contenu de requête ni tenant demandé.

Les colonnes sélectionnées excluent motifs libres et secrets des sources.
`AuditState` applique des clés/valeurs fermées : projection des anciens états,
rejet strict pour les nouveaux événements. Ceux-ci utilisent action/cible/motif
typés, request ID, IP et avant/après utile. Les nouveaux états JSON sont des objets
de 4 Ko maximum en SQL. Gardes Eloquent/SQL append-only et FK restrictives empêchent
les modifications/suppressions ordinaires. Aucun backfill des cinq sources existantes.

Pagination par curseur date/source/UUID : au plus `per_page+1` lignes par source,
trois globales ou quatre tenant, puis fusion. Maximum 404 lignes projetées pour
une page de 100, sans COUNT, OFFSET, profils ni requête par événement. Paramètres
liés et aucun identifiant SQL fourni par le client. Le curseur chiffré lie
compte/tenant/filtres. Un test compare les requêtes avec 1 et 101 événements,
avec caches mémoire et base : quota initialisé avant mesure et temps figé.

Migration additive, deux tables et index chronologiques manquants. Attente de verrou
PostgreSQL limitée à cinq secondes, sans borner la durée de création des index.
Préparer une fenêtre ou un déploiement progressif dédié pour les gros historiques ;
aucun benchmark de millions de lignes. Rollback vide testé, suppression d’un historique
non vide refusée. Portabilité : seul le nouveau journal de son tenant est inclus,
Identity reste exclu.

Décision utilisateur : conservation sans limite, aucune purge ni tâche de suppression.
`AuditRetentionPolicy` refuse une configuration contradictoire ; `audit:retention`
est en lecture seule. Surveiller stockage/index/backups et profiler avant optimisation.
Une rétention bornée demandera un lot opérationnel distinct et des protections
explicites. Aucun cache durable des décisions d’accès. Les futurs modules métier
doivent écrire via le port de leur périmètre, dans la transaction de l’action, avec
des états fermés. Aucun module métier fictif ni écran de journal créé dans ce lot ;
l’interface de rôles propose `audit.view` avec son badge MFA.

## Référentiel humain — UCG-HUM-001

`People` possède `people`, `Person`, ses statuts, repository, DTO, service,
projection, Form Requests, Policy et routes. Le provider compose les bindings.
Le port public `PeopleDirectory.find(TenantContext, UUID)` renvoie un `PersonRecord`
filtré selon les permissions actuelles ; aucune relation Eloquent vers `Identity`
ou `Tenancy`, aucun modèle interne voisin importé. Ne pas réutiliser ce snapshot
comme preuve d’accès dans un job/cache.

Le port Tenancy `TenantBusinessGuard.lock(context, accountId?)` exige la transaction
du module appelant. Il verrouille le compte vérifié éventuel via Identity, puis
l’organisation, et revérifie activité/accès et admissibilité du compte lié.
Le compte doit déjà être propriétaire ou membre effectif du tenant. Le service
People revérifie ensuite les permissions par catégorie et verrouille la fiche
avec UUID + organisation ; ordre compte → organisation → fiche. Il ne crée aucun
accès à partir d’un lien personne/compte.

Unicité `(organization_id, user_id)` pour les comptes non nuls, identité composite
`(organization_id, id)` pour les futures références tenant, FK restrictives vers
organisation/compte, index tenant/statut/UUID. Plusieurs fiches sans compte et
pseudonymes identiques sont possibles ; aucune déduplication globale.
Identifiant et organisation protégés en SQL/Eloquent, suppression physique refusée.
Les statuts descriptifs ne révoquent pas les adhésions : le départ est HUM-004.

Profil général : `sports.read/write`. Identité, coordonnées, lien compte et
biographie interne : `administrative.read/write`. Chaque catégorie présente dans
une mutation exige son droit d’écriture, y compris une valeur null. Une lecture
exige au moins un droit de lecture métier ; la Resource reçoit uniquement la
projection serveur via IAM-004. Les champs omis par PATCH ne sont pas écrasés.
Les champs administratifs sont chiffrés/masqués ; aucune identité globale copiée.

IAM-006 est consommé via `TenantAuditJournal` dans la transaction, avec
`domain.changed` sur l’organisation et `person_id` dans l’état fermé. Le journal
conserve statut/lien/version/noms des champs soumis sans leurs valeurs privées.
Le contrat d’audit et son schéma restent compatibles avec les historiques.
Portabilité opérateur : catalogue fermé complété par les colonnes physiques de
`people`, ciphertext conservé. Garder les clés avec les sauvegardes.

Migration additive sans backfill ; attente de verrou PostgreSQL bornée à 5 s.
Rollback vide possible, rollback avec personnes refusé ; correction en avant
sur une base utilisée. Aucune liste massive, publication, photo/import ou UI
créée dans ce lot. HUM-002/HUM-003/HUM-004 portent les parcours suivants.

## Profils de jeu et comptes externes — UCG-HUM-002

`People` possède `person_game_profiles` et `person_external_accounts`, leurs
modèles/factories, deux repositories et services, DTO de mutation, validation
partagée, Policy, Form Requests et Resources. Les listes par parent sont paginées
et stables par UUID ; `withCount` fournit le total des comptes, archives incluses,
et `projectMany` contrôle la page sans N+1. Aucune relation vers un modèle voisin.

Le port public `GameProfileCatalogue` décrit les références disponibles par tenant
et vérifie jeu/plateforme/région/fournisseur. L’adaptateur de configuration initial
est borné et fermé ; mauvaises formes ou activations nulles refusées. Ajout/retrait
ou désactivation ne modifie aucune ligne historique. Un retrait autorise encore
lecture et archivage seul, sans réactivation/édition utilisant la référence absente.
Les clés sont stables et ne doivent jamais être réutilisées pour un autre objet.
Teams pourra implémenter ce contrat dans TEAM-001 avec ses modèles et le lier dans
son provider : dépendance Teams → API publique People, sans cycle People → Teams.
Le catalogue complet global/activation tenant, modes/cartes/versions et son UI
restent TEAM-001 ; aucun module ou table globale fictif n’est introduit ici.

Profil : identité tenant/personne/jeu/plateforme/région immuable et unique pour
la personne. Compte : identité tenant/profil/contexte/fournisseur immuable ;
un identifiant externe nullable peut être complété une fois, puis devient immuable.
Son unicité comprend le tenant et le contexte complet, avec casse conservée.
Un alias seul n’entraîne aucun rapprochement. L’archivage conserve les uniques.
Les FK composites refusent les références inter-tenant et les copies de contexte
incohérentes. Les triggers/événements Eloquent refusent mutations d’identité et
suppression physique. Une procédure future de réaffectation/conservation devra
être distincte, autorisée et auditée.

Les services verrouillent organisation puis personne/profil puis compte,
revérifient les droits actuels, versions, catalogue et limites dans la transaction.
`sports.read` et `sports.write` sont distincts ; les données sont uniquement
sportives, sans identité administrative copiée. MFA/confirmation HTTP conservés.
Rang/rôle de jeu et liens externes n’accordent aucun droit ou éligibilité.

`GameProfileFreshness` valide le seuil avant mutation et calcule la récence à
partir de `observed_at`, jamais `updated_at`. Données manuelles et propriété
non vérifiée explicites ; dates futures refusées, UTC à la persistance.
Les modifications de données observées sans date explicite invalident l’ancienne
observation, tout en conservant les autres champs omis du PATCH.

Le port IAM-006 audite dans la transaction `domain.changed` sur l’organisation,
avec UUID personne/profil/compte, statut, présence d’identifiant, version, date
et noms des champs soumis ; aucune valeur de rang, alias ou identifiant externe.
Une panne d’audit annule l’écriture. Portabilité opérateur : les colonnes des
deux tables sont ajoutées au catalogue fermé, toujours filtrées par tenant.

Migration additive sans backfill, verrou PostgreSQL borné à 5 s ; rollback vide
testé et refusé après utilisation. Pas de purge, OAuth, connecteur, vérification
éditeur, UI ou import. Les zones initiales sont applicatives, pas des shards
officiels. Profiler les index/verrous avant optimisation ; pas de benchmark de charge.

### Liste et interface People — UCG-HUM-003

`PersonController::index` autorise via la Policy existante, reçoit un
`ListPeopleRequest` et transmet un `PersonListQuery` au Service. Ce dernier
revérifie les bornes, les clés fermées et les permissions requises pour chaque
filtre/tri/vue, y compris sur un résultat vide. `EloquentPersonRepository` exige
l’organisation et limite les colonnes lues selon les vues et droits effectifs.
`PersonProjectionService::summaries` emploie projectMany IAM-004, avec un schéma
fermé, une page de 100 au maximum et aucun déchiffrement administratif non autorisé
en liste. La Resource garde son contrat de détail et sait rendre les résumés.

`Person::gameProfiles` reste interne au module People ; les FK HUM-002 garantissent
le contexte. withCount charge les nombres sans hydrater tous les profils/comptes.
Le filtre jeu/plateforme utilise un EXISTS tenant sur un même profil actif.
La recherche est un préfixe littéral, bindé avec échappement SQL des wildcards ;
tri fermé et UUID comme départage. Les index tenant/id et tenant/statut/id existants
sont conservés ; aucun changement du schéma déployé. Profilage des plans nécessaire
avant index fonctionnel, moteur externe ou pagination cursor à forte profondeur.
LOWER suit le comportement natif du moteur ; pas de normalisation des accents.

React réutilise les composants de formulaires, tables, pagination, alertes et
notifications. PeoplePanel est monté pour l’organisation/révision/permissions
courantes ; les lectures annulables et gardes de génération empêchent une réponse
obsolète de réafficher le tenant précédent. GameProfilesPanel charge des pages
distinctes à l’ouverture d’une fiche. Le hook de mutation confirme l’organisation,
transmet CSRF/révision/version et évite un envoi après démontage pendant confirmation.
Les formulaires calculent les seuls champs modifiés, préservant les champs privés
omis et évitant de réaffirmer une ancienne observation lors d’un changement de rang.

Les références retirées restent consultables et archivables. Aucune suppression
physique ni révocation d’accès via statut descriptif ; HUM-004 reste responsable
du départ effectif. Catalogue administrable complet reste TEAM-001. Aucun OAuth,
publication publique ou nouveau cache de fiches privées ajouté.

## Cycle de vie People — HUM-004

People ajoute trois agrégats tenant/personne : PersonPeriod (mission
organisationnelle/indisponibilité), PersonDepartureItem (équipement/obligation),
PersonDeparture (registre de départ). Form Requests → DTO → services → repository
→ projections IAM-004/Resource. Controllers minces, policies explicites ; aucun
contenu privé de checklist dans les audits. Libellés/notes chiffrés via casts.

Périodes et checklist prennent le verrou organisation puis personne, autorisent
de nouveau sous verrou, contrôlent version/capacité et écrivent l’audit dans la
transaction. Le chevauchement des intervalles semi-ouverts de même type/rôle est
contrôlé sous verrou personne ; les dates adjacentes sont permises. Limites 1000
périodes et 200 éléments par personne, pagination 100/page et page 10000 maximum,
projections en bloc. La clôture parcourt les périodes actives par lazyById(100).
Pas de cache supplémentaire des fiches privées ni de benchmark de forte charge ;
profilage des plans et de la contention avant optimisation.

Le port public Tenancy `TenantDepartureAccess` orchestre une transaction avec
3 tentatives deadlock : verrou compte global (y compris non vérifié), organisation,
personne ; autorisation sous verrou et contrôle de délégation ; callback People ;
révocation invitations/adhésion/tous rôles du compte dans cette organisation.
Le compte global et les autres tenants sont préservés. Le propriétaire est protégé.
La réponse d’action est préautorisée avant révocation pour permettre l’auto-départ.
Audit ou participant défaillant annule toute la clôture et la révocation.

People publie `Application/Contracts/PersonDepartureParticipant` et le tag
`people.departure-participants`. Teams/Contracts pourront fournir des participants
database-only, retry-safe et synchrones dans cette transaction ; aucun appel réseau,
fichier ou job externe dans le callback. Teams peut dépendre du port People ; People
ne dépend jamais d’un service interne Teams/Contracts. Aucun participant concret
ajouté tant que ces modules n’existent pas ; aucune affectation de roster implicite.

Migration additive PG/SQLite : FK composites `(organization_id, person_id)`, index
tenant/personne/type/statut, gardes SQL d’immuabilité/date/état ; DELETE interdit.
Une seule clôture/personne, registre immuable. Trigger people interdit de modifier
status/user_id après départ ; les services bloquent nouvelles périodes/profils/
comptes externes, avec archivage API possible. Biographie et checklist restent
modifiables selon droits. Réadmission métier future ; les opérations génériques
Tenancy ne créent pas automatiquement le registre People. Le statut descriptif
seul et l’indisponibilité ne modifient pas les accès.

Migration sans backfill, lock_timeout PG 5s et rollback refusé si tables non vides.
Portabilité : les 3 tables rejoignent le dump tenant, champs privés chiffrés.
Les éléments ouverts exigent reconnaissance avant départ mais ne retardent pas
la révocation ; le registre mémorise leur nombre à la clôture. Aucun calcul de
dette, paiement ou décharge contractuelle automatique.

PersonLifecyclePanel compose périodes, checklist, départ et profils de jeu dans
les fiches existantes. Révision/version, confirmation tenant et confirmation
métier, réponses tardives ignorées, rechargement des droits après départ.
La fiche scellée interdit dans l’UI la modification statut/compte et des profils,
mais garde la régularisation administrative. L’autorisation serveur reste décisive.
