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
| `Teams` | `Identity`, `Tenancy`, `People` |
| `Academy` | `Tenancy`, `People`, `Teams`, `Scheduling` |
| `Scheduling` | `Tenancy`, `People`, `Teams` |
| `Competition` | `Tenancy`, `People`, `Teams`, `Scheduling` |
| `Performance` | `Tenancy`, `People`, `Teams`, `Competition` |
| `Contracts` | `Tenancy`, `People` |
| `Partnerships` | `Tenancy`, `People`, `Teams`, `Contracts` |
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
TEAM-001 implémente désormais ce contrat via GameProfileCatalogueBridge dans
Teams et le lie dans son provider : dépendance Teams → API publique People,
sans cycle People → Teams. L’adaptateur de configuration reste un composant
historique ; la composition applicative utilise le catalogue persistant.

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

## Import de création People — HUM-005

PersonImport porte le staging tenant d’un CSV ; PersonImportRow conserve un résultat
par première ligne physique du record. Source stockée via TenantArtifacts après
chiffrement, nom serveur fixe et budget existant. En-têtes et values/errors de lignes
utilisent des casts encrypted:array, champs cachés hors projection autorisée. Pas
de compte lié, rôle, invitation, profil de jeu ou mise à jour d’une personne existante.

Form Request stricte et policy exigent sports.read/write + administrative.read/write
pour toutes les opérations. Service d’import orchestre dépôt, preview, confirmation
et lecture ; parser isolé pour UTF-8/BOM, quotes, champs multilignes et lignes
physiques ; règles PersonInput réutilisées. Source ≤1 Mio/1000 lignes/32 colonnes,
cellule ≤32768 octets, quotas tenant supplémentaires. Préparation synchrone bornée,
création asynchrone. Aucun modèle/CSV ni donnée privée dans le payload des jobs.

Application dépend du port PersonImportQueue ; LaravelPersonImportQueue en
Infrastructure construit ProcessPersonImport, qui hérite du port TenantJob.
Le middleware TEN-006 rétablit un contexte frais ; le service réautorise les quatre
droits sous verrou organisation/import. Lots de 25, 3 tentatives deadlock, writes
PersonService/audit/statut de ligne atomiques. Source/mapping confirmés immuables,
lignes créées ignorées au rejeu. Échec définitif : failed et code fermé ; état queued
durable si dispatch échoue, reprise HTTP explicitement confirmée. La transaction
ne couvre pas toute l’importation : les lots déjà validés sont conservés.

HMAC tenant/séparateur/octets du fichier, unique par tenant, évite un second registre
au redépôt identique. Déduplication des candidats par pseudonyme : fichier via
trim/mb_strtolower, personnes existantes via LOWER(TRIM) natif PG/SQLite. Index
`(organization_id, LOWER(TRIM(pseudonym)))` et recherche DISTINCT bornée par blocs
de 100 clés. Le verrou organisation sérialise cette vérification avec les créations
PersonService et les autres imports ; aucune unicité globale de pseudonyme ajoutée
aux fiches manuelles. Pas de normalisation des accents ou d’identité devinée par
nom/e-mail chiffré. Doublon apparu entre preview et exécution : skipped explicite.

Migration additive : FK composites tenant/artifact/import/personne, index tenant,
identité/source immuables, mapping confirmé et lignes créées protégés. Histoire
confirmée non supprimable, rollback refusé si registres non vides. Anciennes
migrations déployées inchangées ; tests de rollback adaptent l’ordre des dépendances.
Les deux tables et leur ciphertext rejoignent le catalogue fermé de portabilité.
Sources conservées dans le stockage privé et comptées dans le quota ; aucune durée
de purge métier arbitraire ajoutée.

Listes et lignes par pages de 10, page 1..10000 ; comptages regroupés et projections
explicites sous les quatre permissions. Rapport CSV hors traitement : ligne, statut,
codes fermés et UUID de personne, jamais la valeur source ou une formule tableur.
Audit du registre limité à id/état/version ; audit des fiches via PersonService.
TEN-006 fournit les logs structurés des jobs. Profiler coût par ligne et contention
avant augmentation des limites ; aucun benchmark de millions de lignes.

## TEAM-001 — Catalogue commun et activation tenant

Teams consomme les ports publics Identity PlatformCatalogueGuard et Tenancy
TenantAuthorization/TenantBusinessGuard/TenantAuditJournal. L’accès à Identity
ajoute une arête déclarée sans cycle. People ne dépend pas de Teams : son port
GameProfileCatalogue est fourni par le provider Teams.

GameCatalogueEntry porte une référence typée avec scope global ou game_id.
Les jeux contiennent seulement les associations de clés plateformes/fournisseurs
et régions ; modes, rôles, cartes et releases sont des enfants du jeu, avec FK
et garde SQL exigeant un parent game. Ces métadonnées ne contiennent pas de
personne, roster, statistique ou configuration privée d’organisation.

TableOwnership déclare précisément game_catalogue_entries, game_catalogue_state
et game_catalogue_events comme globaux. OrganizationGame est tenant-owned avec
organization_id, game_id global, enabled, restrictions et version ; identité,
FK racine, unique organization/game et index tenant explicites. La portabilité
exporte cette table uniquement pour le tenant, jamais les trois tables globales.

Les écritures communes prennent le verrou du compte acteur, de l’administration
plateforme et de la révision ; elles revérifient superadmin actif, vérification
et MFA puis persistent référence/révision/audit atomiquement. Les écritures locales
prennent le verrou d’organisation puis celui du catalogue et du réglage ; sports.read
et games.manage organisation sont revérifiés. Les profils People prennent déjà
leur garde tenant puis le verrou catalogue avant de vérifier la disponibilité.
Cet ordre évite de coupler une administration globale à des verrous tenant.

Les clés et rattachements sont immuables ; active=false retire une référence
des nouveaux usages sans supprimer les historiques. Le modèle et SQL interdisent
la suppression physique, et l’audit global est append-only. Le journal local
conserve les UUID, disponibilité et versions, sans dump des choix privés dans
un journal global. Tout défaut d’audit annule la modification correspondante.

La migration valide la configuration existante avant DDL, importe ses métadonnées
et ses overrides tenant explicites une seule fois. Les nouveaux jeux ont
default_enabled=false. Le flag initial immutable préserve la compatibilité des
quatre jeux ; chaque organisation peut ensuite les désactiver. Les edits SQL
globaux avancent aussi la révision. Down refuse toute modification postérieure
au baseline, tout audit et tout réglage tenant ; correction forward ensuite.

Catalogue fermé par catégories, clés extensibles : 100 jeux, 32 références par
catégorie globale, 200 par catégorie propre à chaque jeu. Les références retirées
comptent dans ces limites. Pages 1..100000, per_page 1..100 ; détail d’un jeu au
maximum 800 enfants. Le cache global de 300 s est versionné et contient uniquement
les métadonnées ; il est ignoré en transaction. Les réglages tenant restent relus,
sans clé de cache partagée de résultat métier. Nombre de requêtes de profils
constant, aucune requête individuelle par jeu dans la liste locale.

## TEAM-002 — Équipes privées et cycles conservés

Teams possède Team et la table tenant teams. La FK organization_id vise la
racine tenant ; game_id vise intentionnellement un jeu du catalogue global
Teams, avec garde SQL du type game. Aucune FK métier vers un autre tenant.
Le catalogue fermé de portabilité inclut les colonnes physiques de teams,
exportées seulement pour l’organisation demandée.

TeamController délègue à TeamService via Form Requests, Policy et TeamResource.
TeamRepository/EloquentTeamRepository isolent la persistance. Les lectures exigent
sports.read, les écritures sports.read + teams.manage à portée organisation ;
TenantBusinessGuard verrouille l’organisation puis les droits sont revérifiés.
EffectiveGameCatalogue verrouille la révision commune et vérifie le jeu actif
et le réglage local. Cet ordre reste compatible avec TEAM-001 ; pas de cache
tenant des autorisations ou de la disponibilité. Version UUID optimiste et audit
tenant minimal (UUID, statut, version, noms des champs modifiés) dans la même
transaction. Un échec d’audit annule aussi l’écriture de l’équipe.

Jeu, slug, saison descriptive et organisation constituent l’identité immuable
du cycle. Le slug reste réservé après archivage ; un nouveau cycle demande une
nouvelle équipe et un nouveau slug. Les catégories first/academy/reserve/event/
custom sont fermées, custom exige une catégorie descriptive. Nom, identité
facultative et statut draft/active sont modifiables avant archivage. archived
est terminal, sans suppression, réactivation ni modification. L’archivage seul
ne requiert plus un jeu disponible, pour préserver les historiques après retrait.
Gardes modèle et SQL PG/SQLite ; down refuse toute ligne, archives incluses.

Les champs destinés à une identité publique restent privés dans ce lot :
publication_status=private, aucun endpoint public. Aucun roster, appartenance,
scope IAM équipe ou droit implicite du propriétaire/support/superadmin. TEAM-003
portera les appartenances datées avant de brancher leurs effets d’autorisation.

Listes tenant filtrées, pagination 1..100, tri stable avec UUID et relation jeu
eager-loaded ; nombre de requêtes constant vérifié par test. Limite technique
1000 équipes par organisation, archives comprises, indépendante des offres.
Index tenant/nom/id et tenant/jeu/statut/id. Profiler recherches et contention du
verrou avant d’augmenter cette limite ou d’ajouter une infrastructure de recherche.
L’écran charge ses données seulement à l’ouverture et annule les lectures au
changement de tenant ; mutations confirmées via les mécanismes existants.

## TEAM-003 — Appartenances datées et grants liés

Teams possède team_memberships, team_movements et team_scope_grants. Toutes les
tables portent organization_id et des FK composites tenant vers les parents.
Le lien d’une période vers people est intentionnel ; l’accès applicatif à People
passe par son port SportingPeopleDirectory, fourni par SportingPeopleService et
PersonRepository. Il expose seulement contrôle d’affectabilité, pseudonymes en
bloc et corrélation interne compte/personne ; aucune identité administrative.
La dépendance Teams → People reste sans cycle.

TeamMembershipController, Form Request et Policy délèguent à TeamMembershipService,
TeamMembershipRepository et TeamMembershipJournal. Lecture sports.read organisation,
mutations sports.read + teams.manage organisation, MFA et confirmation tenant.
Verrou organisation, droits revérifiés, personne verrouillée pour nouvelle
affectation et catalogue global verrouillé avant nouvelle utilisation d’un jeu.
Version UUID optimiste, périodes/mouvements/audit dans la même transaction.
Motifs fermés et auteur dans le mouvement ; audit minimal sans pseudonyme ou
identité administrative. Un défaut d’audit annule aussi la destination d’un transfert.

Intervalles UTC semi-ouverts [début, fin), fin strictement postérieure au début.
Pour une même personne/équipe : même fonction sans chevauchement ; player et
substitute constituent un groupe incompatible ; captain, coach et analyst restent
distincts. Aucune exclusivité entre différentes équipes n’est déduite. Identité
et début immuables, fin seulement raccourcie. Une clôture future garde status=active
jusqu’à son échéance ; l’effectivité dépend des dates, sans job obligatoire.
Une période déjà finie/closed/cancelled ne se réécrit pas. Annulation avant début,
horodatage serveur ; jamais de suppression. SQL vérifie dates, chevauchements,
identité et états terminaux ; PostgreSQL prend aussi le verrou racine tenant.

Transfert/promotion immédiat ou futur : nouvelle période dans une destination du
même jeu, autres fonctions sources qui dépassent cette date raccourcies ; celles
qui commencent à partir de cette date annulées. Promotion uniquement academy →
first. Un mouvement par fonction source référence la période destination, son
motif et son auteur. Aucune copie implicite des autres fonctions ou des grants.
Les périodes déjà finies restent conservées. Archivage d’équipe refusé tant qu’une
appartenance en cours ou future subsiste ; clôtures autorisées après retrait du jeu.

TeamDepartureParticipant implémente le port public PersonDepartureParticipant et
rejoint le tag people.departure-participants. Sous les verrous HUM-004 existants,
il termine les périodes en cours et annule les futures avec audit atomique. Il
n’exige pas de grant teams.manage, ni de réautorisation après révocation de l’acteur.
Rejeu du départ sans deuxième mouvement ; erreur du participant/audit = rollback
du départ, des accès et de toutes les périodes concernées.

TeamAuthorizationScopes implémente le port Tenancy DatedAuthorizationScopeDirectory,
enregistré sous team par le provider Teams. Tenancy ne dépend pas de classes Teams.
Ressource du tenant non archivée requise ; les permissions organisation explicites
continuent à couvrir ses équipes. Pour un grant strictement équipe, l’attribution
doit tenir dans une période d’appartenance d’une personne liée au compte. Une
liaison team_scope_grants append-only fixe cette période dans la transaction IAM.
L’autorisation relit compte, adhésion organisation, rôle, grant, liaison et période
effective à chaque appel. Un retour, même aux mêmes dates après annulation, ne
réactive pas l’ancien grant. Un changement de fonction ne donne aucun droit.
Le détail d’équipe accepte sports.read équipe ; les listes d’organisation,
identités People, rosters et mutations conservent leurs permissions organisation.

Listes d’appartenances et mouvements par pages de 10 (API ≤100), filtres de fonction,
personne, état et date ; pseudonymes projetés en bloc, noms d’équipes des mouvements
eager-loaded. Limites techniques : 1000 périodes par personne/organisation,
historiques inclus, 1000 liaisons IAM par période. Index tenant/équipe/début,
tenant/personne/équipe/fonction/début et source/destination des mouvements.
Portabilité inclut précisément les trois tables pour le tenant ciblé. Migration
additive sans backfill ; down refuse toute période, mouvement ou liaison.
Profiler contention et recherches avant d’augmenter ces budgets ; aucun benchmark
de millions de lignes. Les lineups/snapshots sont décrits dans TEAM-004 ci-dessous.

## Compositions datées et instantanés — UCG-TEAM-004

Teams possède team_lineups et team_lineup_entries. Une composition garde une
équipe, un jeu et un usage match/registration stables ; son libellé identifie le
contexte préparé, sa date est normalisée en UTC. Les modules Matchs/Compétitions
n’ont pas encore leurs fiches métier : aucune FK fictive ni UUID arbitraire de
match/inscription n’est accepté. Leur intégration utilisera le port public
HistoricalLineups, dont snapshot exige sports.read organisation et un état figé.
Ce port renvoie uniquement la projection sportive conservée, sans importer de
modèle interne Teams ni relire les identités People courantes.

TeamLineupService orchestre validation partagée avec Form Request, autorisation,
verrou TenantBusinessGuard puis équipe/composition/appartenances, Repository et
audit dans une transaction. Lecture sports.read organisation ; gestion aussi
teams.manage organisation, MFA et confirmation tenant. Un grant équipe ne donne
pas de lecture de toute la liste d’organisation. Le journal n’enregistre ni
libellé ni pseudonyme : références UUID, date, action fermée, état et version.

L’appartenance choisie doit être du même tenant/de la même équipe, de fonction
player/substitute et couvrir la date [début, fin). Une place starter/substitute
par personne, ordre explicite et au moins un starter ; la place retenue peut
différer de la fonction dans l’effectif. Captain seul, coach et analyst ne sont
pas des appartenances de joueur. Une appartenance closed reste utilisable à une
date passée couverte ; une annulation ne l’est jamais. L’éligibilité de compétition,
les minima/maxima sportifs, rangs, contrats et remplacements pendant le match
restent aux tickets TEAM-005/MATCH-002.

draft → finalized/cancelled ; états terminaux conservés, sans suppression.
replace remplace atomiquement les participants du seul brouillon, avec nouvelle
version ; sa date et son libellé sont modifiables. finalize recontrôle les versions
et la couverture temporelle, capture les pseudonymes et noms d’équipe/jeu connus
à cet instant, puis l’auteur et la date serveur. Il ne reconstitue pas un ancien
pseudonyme inconnu. Ensuite, un renommage, retrait du jeu, transfert ou départ
ne change ni cette projection ni sa liste de participants. Une correction exige
une nouvelle composition. Annuler un brouillon reste possible après archivage
ou retrait du jeu ; préparer/modifier/figer exige une équipe et un jeu disponibles.

SQL SQLite/PostgreSQL protège l’identité de la composition, ses états terminaux,
les participants figés et leur cohérence avec les appartenances du tenant ;
PostgreSQL verrouille aussi la racine organisation. FK composites sans cascade,
uniques tenant/composition/personne et position. Index de consultation
tenant/équipe/date/id et de référence d’appartenance. 50 participants maximum,
1000 compositions par équipe, annulations/historique inclus ; ces plafonds sont
techniques, sans promesse de benchmark. Liste API ≤100 compositions, UI 10,
participants bornés et eager-loaded ; pseudonymes chargés en une requête lors
d’une préparation/confirmation. L’export enregistre explicitement les deux tables.
Migration additive sans backfill ; down refuse toute composition ou participant.

## Éligibilité sportive et preuves conservées — UCG-TEAM-005

Teams possède team_eligibility_rulesets, team_lineup_rule_bindings et
team_lineup_eligibility_reports. Le contexte de compétition est nommé : aucune
dépendance Teams vers des modèles Competition, aucun competition_id artificiel.
Les futurs modules utilisent HistoricalLineups : references du règlement, de sa
version de contenu et du rapport final, avec eligibility_status. Une composition
sans règlement reste unassessed, jamais implicitement admissible.

TeamEligibilityRulesService : création immuable, archivage terminal conservé,
catalogue disponible et limites explicites. LineupEligibilityService : liaison
unique d’un brouillon, pré-évaluations append-only et preuve finale.
TeamLineupService recontrôle effectif/versions, puis exige une preuve eligible pour
figer une composition liée. Même transaction et verrou racine organisation pour
liaison, rapports, capture finale et audit ; toute panne annule l’ensemble.
Une version de contenu identifie le règlement même après archivage ; pour changer
ses critères, créer un nouveau record puis une nouvelle composition.

EligibilityEvaluator produit des motifs fermés passed/failed/unknown. Refus connu
domine unknown. Jeu, nombres de titulaires/remplaçants, échéance, versions et
couverture d’effectif contrôlés ; région/rang déclarés et âge facultatifs.
Il ne consulte ni contrats ni permissions IAM pour inférer l’admissibilité.
Une fonction sportive, un contrat et un résultat d’éligibilité restent distincts.

Teams accède à People par EligibilityPeopleDirectory (Application/Contracts).
Projection bornée à 50 personnes : profil actif du jeu/plateforme, au plus deux
matches par personne via eager loading limité ; plusieurs = ambigu. Deux requêtes
constantes vérifiées pour une ou plusieurs personnes, sans N+1. Un profil non daté,
futur ou trop ancien n’est pas exploitable. Aucune certification externe implicite.

People conserve birth_date facultative chiffrée et cachée, validée précocement,
lecture/écriture administrative. Pour contrôler l’âge, le port exige
administrative.read et retourne seulement passed/failed/unknown. Sans ce droit,
la colonne n’est même pas sélectionnée ; jamais date ou âge numérique transmis
à Teams. Déchiffrement invalide signalé au gestionnaire d’erreurs et résultat
unknown. Les rapports et l’audit ne copient aucune donnée administrative.

SQL SQLite/PostgreSQL protège critères/version immuables, liaison tenant/jeu/version
et brouillon, append-only des rapports, preuve finale et échéance à l’horloge DB.
Critères JSONB PostgreSQL comparables ; JSON texte SQLite. Échéance exclusive,
annulation permise ; snapshots antérieurs non modifiés. FK composites sans cascade
et index tenant/jeu/date, tenant/composition/date. Export inscrit explicitement
les trois tables et la colonne People chiffrée. Down refuse tout règlement,
lien, rapport ou naissance renseignée avant DDL. Pas de backfill.

Pages <=100 (UI 10), 1000 règlements/organisation et 100 rapports/composition,
dont une place réservée au contrôle final. Ces budgets ne sont pas un benchmark.
Profiler cardinalités et contention du verrou organisation avant de les relever ;
pas de traitement asynchrone pour le contrôle final qui doit rester atomique.

## Références d’adversaires — UCG-TEAM-006

Teams possède `external_opponents` et `external_opponent_snapshots`.
Une référence décrit une organisation externe, une équipe ou un pseudonyme de
joueur à partir de faits publics déclarés : aucun compte, adhésion, permission,
People ou objet d’un tenant voisin n’est créé ou interrogé. Le libellé
organization_name est indépendant des organisations réelles de la plateforme.

Le Controller utilise Form Requests, Gate et Resources fermées.
ExternalOpponentService orchestre création, édition partielle et archivage ;
ExternalOpponentSnapshotService capture les faits actuels et expose le contrat
public ExternalOpponents. ExternalOpponentRepository/EloquentExternalOpponentRepository
portent les requêtes filtrées par UUID tenant. Le port public retourne une projection
fermée ; le futur module appelant reste responsable de l’autorisation et des
relations de son objet métier. Pas d’identifiant fictif de compétition ou de match.

Toutes les mutations prennent le verrou organisation puis la référence, revérifient
les droits et écrivent leur audit dans la même transaction. Contrôle optimiste
par version UUID pour référence/capture, aucune permission mise en cache.
sports.read organisation pour lire, aussi teams.manage pour écrire ; la politique
ne transforme pas un rôle propriétaire ou un périmètre équipe en droit global.

Migration additive, sans backfill ni modification des migrations déployées.
FK organisation directe et FK composite tenant/source pour les instantanés,
sans cascade. Identité et archives protégées en SQL SQLite/PostgreSQL ;
instantané append-only avec comparaison des faits et version à l’insertion.
PostgreSQL verrouille aussi l’organisation dans le trigger de capture.
Les noms de jeu copiés ne sont pas résolus à la lecture historique.
Down refuse les données avant toute DDL ; correction en avant sur une base utilisée.
Les deux tables et chaque colonne sont inscrites explicitement dans l’export.

Relations catalogue eager-loaded ; pagination ≤100 (UI 10), index tenant/nom,
tenant/type/statut et tenant/jeu/statut ; historique tenant/source/date.
1000 références par tenant et 1000 instantanés par référence, archives incluses.
Nombre de requêtes constant vérifié sur plusieurs tailles de page.
Aucun benchmark de forte charge ; mesurer recherche LOWER, profondeur de pagination
et contention du verrou organisation avant d’augmenter ces budgets.
Pas de cache de droits ni de job nécessaire pour la petite capture atomique.
URLs jamais téléchargées ou suivies automatiquement.

Le panneau React est remonté sur tenant/révision/permissions par TenantWorkspace.
Lectures annulables, réponses tardives ignorées, confirmations d’organisation
avant mutation, déclaration de faits publics, version courante et champs modifiés
seulement. Source et date d’observation restent visibles ; données échappées.
Archive et instantané sont conservés sans publication automatique.

## Contrats administratifs — UCG-CTR-001

Contracts possède contracts, contract_parties, contract_obligations et
contract_transitions. L’organisation est la partie hôte implicite ; les autres
parties sont des personnes de ce tenant ou des libellés externes déclarés.
Le responsable désigne une fiche People, sans création de compte ni de permission.

ContractController utilise Form Requests, Gate et Resources fermées.
ContractService orchestre les mutations ; ContractLifecycle porte le graphe et
les règles de dates ; ContractRepository/EloquentContractRepository portent
la persistance filtrée par tenant. Contrats accède à People uniquement via
AdministrativePeopleDirectory : projection bornée id/libellé administratif,
sélection des noms sans coordonnées ni données sportives, y compris les fiches
conservées après départ. Le nom du responsable est copié côté serveur et chiffré.

Chaque mutation prend le verrou organisation puis le dossier, revérifie les
permissions et écrit l’audit dans la transaction. Échec d’audit = rollback.
Versions UUID optimistes du dossier et des obligations ; historique de statuts
append-only. Audit limité aux UUID, état et version, sans textes privés.
Aucun cache de droits. Pas de job pour ces petites écritures atomiques.

Lecture administrative.read ; écriture administrative.read/write ;
cibles approved/signed/active/ended/terminated : aussi administrative.validate.
Aucun droit implicite propriétaire, technique ou sportif. Préparateur et
approbateur distincts ; modifier les termes en revue change le préparateur,
retourner en revue annule l’approbation. Après approbation, termes et parties
figés ; réaffectation du responsable et régularisation des obligations possibles.
Une obligation déjà régularisée ne peut être supprimée par remplacement de liste.

Signed est une déclaration de réception externe avec date, référence chiffrée
et date serveur ; aucun document ou hash fictif. In_force exige active et une
période comprenant la date UTC, fin incluse. Après échéance, in_force devient
false même si le statut manuel reste active. Sporting_eligibility reste
not_assessed. Pas de dépendance vers Teams pour inférer l’admissibilité.
Les documents immuables relèvent de CTR-002, leur stockage privé de CTR-003,
les montants de CTR-004 et les rappels de CTR-005.

Migration additive sans backfill, anciennes migrations déployées inchangées.
FK composites tenant/dossier et tenant/People sans cascade, index de pagination
et contraintes SQLite/PostgreSQL sur identité, transitions, approbation,
signature, termes, parties, obligations et archive. Historique append-only.
Down refuse les données avant DDL ; export fermé enrichi des quatre tables et
de leurs colonnes, en conservant le chiffrement.

Pages ≤100/UI 10, 1000 dossiers/tenant, 10 parties, 50 obligations et
200 transitions/dossier. Liste avec agrégats, détail avec relations chargées
en amont ; nombre de requêtes constant vérifié à plusieurs tailles de page.
Ces budgets ne constituent pas un benchmark ; profiler pagination et contention
du verrou organisation avant un déploiement à forte cardinalité.

## Documents immuables et avenants — UCG-CTR-002

Contracts possède contract_documents, contract_document_versions et
contract_document_signatures. Un original par contrat, avenants distincts avec
FK composite vers cet original du même contrat. En-têtes documentaires immuables ;
versions numérotées append-only ; une liaison de copie signée par document.
Une liaison conserve version UUID, numéro, empreinte, date, auteur et date serveur.
Le document lié ne peut plus recevoir de version ; l’évolution passe par un avenant.

ContractDocumentController reste mince : ContractDocumentRequest/Gate/Resources
fermées ; ContractDocumentService orchestre les mutations et
ContractDocumentRepository/EloquentContractDocumentRepository filtrent tenant,
contrat et document. Policies Contracts existantes réutilisées : lecture
administrative.read, écriture aussi administrative.write, signed_copy et
identification aussi administrative.validate. Pas de droit implicite propriétaire.

Toutes les mutations prennent le verrou organisation puis le contrat,
revérifient droits/statut/version et changent la version UUID du contrat.
Préparation du document original uniquement draft/in_review ; actualise prepared_by
pour maintenir l’auto-approbation interdite. Copie signée et liaison uniquement
signed/active/ended/terminated ; date originale égale à signed_on du contrat.
Avenant exige l’original lié à sa copie signée ; sa date d’effet est déclarative.
Aucun changement automatique de termes, statut, éligibilité ou autorisation IAM.

TenantArtifacts (TEN-005) conserve chaque nouveau PDF chiffré sous un UUID neuf.
Nom de stockage constant et générique, nom original chiffré dans la version,
empreinte SHA-256 calculée sur les octets clairs reçus. MIME/extension PDF, en-tête
PDF, 5 Mo maximum et nom sans chemin/contrôle vérifiés précocement. Ce contrôle
de forme n’est pas une analyse de sécurité. Fichiers en attente, jamais exécutés,
interprétés ou servis ; aucune URL ou route de téléchargement avant CTR-003.

Stockage et réservation durable avant transaction documentaire, avec recontrôle
après I/O. Toute panne de metadata/audit nettoie seulement la nouvelle réservation ;
nettoyage incertain rapporté, réservation conservée pour réconciliation.
Fichiers des versions antérieures jamais remplacés ou supprimés par ce service.
Le quota de stockage inclut les octets chiffrés, plus volumineux que le PDF original.
Audit minimal atomique : UUID contrat/document/version et état, sans contenu/hash.

Migration additive sans backfill, migrations déployées inchangées. FK tenant
composites contrat/document/original/version/artefact sans cascade, indexes pour
pages et numéros, unicité de l’original et de la liaison signée.
Gardes SQLite/PostgreSQL : append-only, contexte du contrat, origine signée,
numéros consécutifs ≤100, hash hexadécimal, artefact prêt, lien exact numéro/hash.
Triggers PostgreSQL verrouillent aussi l’organisation avant insertion.
Down refuse les données avant DDL. Export fermé inclut les trois tables et
les noms/titres chiffrés ; les artefacts existants restent couverts par TEN-007.

Un original +100 avenants/contrat, 100 versions/document, pages API ≤100/UI 10.
Listes avec withCount et signature chargée en amont, versions sur route paginée
séparée, nombre de requêtes constant vérifié sur 1/101 documents.
Aucun benchmark de forte charge : mesurer quotas chiffrés, latence du stockage,
pagination et contention du verrou avant de relever les budgets.
CTR-003 ajoutera analyse de contenu et accès aux octets ; CTR-004 portera les
approbations métier/financières des avenants. Aucune certification électronique.


## Analyse et délivrance documentaires — UCG-CTR-003

ContractDocumentScanService orchestre la demande, ContractDocumentScanProcessor
le traitement tenant et ContractDocumentDownloadService la délivrance.
Les contrôleurs restent limités à validation, Gate, Resources/DTO et réponses.
ContractDocumentScanRepository encapsule la persistance ; ContractDocumentScanner
est une frontière injectée, adaptée à clamd TCP par ClamAvDocumentScanner.
ContractDocumentScanQueue utilise le Bus Laravel et ScanContractDocument,
TenantJob afterCommit, queue reports, restauration du contexte TEN-006.

Le dépôt conserve d’abord définitivement sa version chiffrée, puis demande le scan.
Un échec de dispatch/audit de scan ne supprime jamais une version déjà engagée :
elle reste en quarantaine. Demandes actives idempotentes ; lease de 300 s,
réessai explicite avec expected_scan_id, 1000 tentatives maximum/version.
Verrou organisation puis contrat puis scan ; aucun verrou métier pendant l’I/O
de stockage ou les échanges ClamAV. Droits revérifiés avant traitement et
finalisation ; un scan remplacé pendant l’I/O ne peut publier son verdict.

Pipeline : lecture TEN-005, déchiffrement mémoire, taille/hash, enveloppe PDF,
VERSION avec date de signatures ≤7 jours, INSTREAM par morceaux de 65536 octets.
Seul stream: OK est clean ; FOUND est bloqué, toute panne/réponse ambiguë/limite
est un échec fermé. Réponse ≤512 octets, délais monotones bornés, sockets fermés
en finally. Aucune donnée de fichier ou signature de virus dans les logs/audits.
Le démon reste une dépendance opérationnelle de confiance sur réseau privé ;
ses réglages ScanPDF/alertes/limites doivent être vérifiés par l’opérateur.

Historique contract_document_scans : tentative, auteur, hash de version,
politique, état, motif fermé, moteur, dates et éventuelle invalidation.
Identité/résultat final immuables, suppression interdite ; clean peut seulement
être invalidé une fois en cas de corruption. Audit minimal atomique UUID/état/motif.
Callback de queue terminale limité à l’échec de la tentative du même acteur,
sans pouvoir accorder l’accès. Export fermé TEN-007 enrichi du suivi des analyses.

Accès : verdict clean courant <24 h, dernière tentative, intégrité exacte.
Lien signé relatif cinq minutes lié à compte/tenant/contrat/document/version/scan ;
session et MFA obligatoires. Recontrôle des permissions et du verdict après I/O,
expiration stricte à la frontière. Si intégrité compromise, le verdict clean
conservé est invalidé et aucune donnée claire servie. HTTP attachment, private
no-store, nosniff, CSP sandbox ; pas de cache de décision IAM ni d’URL de stockage.

Analyse/versions/historique paginés (API ≤100, UI 10), latestScan chargé en une
requête corrélée indexée tenant/version/attempt ; nombre de requêtes documentaires
constant sur 1/11 versions et une seule requête pour leurs scans, vérifiés avec
les caches array/database. Le comptage exclut l’initialisation du limiteur HTTP.
Dépôt/relance 10 par minute,
liens 60, téléchargements 20, par tenant et compte. Mesurer mémoire PHP et clamd,
latence, volume reports, stockage chiffré et contention avant d’augmenter budgets
ou concurrence des workers ; aucun benchmark à millions d’utilisateurs réalisé.
Déploiement/configuration et test réel opt-in décrits dans README, section CTR-003.

## Gouvernance financière et approbations — UCG-CTR-004

ContractFinancialService orchestre les blocs financiers ;
ContractAmendmentApprovalService gère les décisions des avenants.
ContractGovernance centralise les contrôles partagés par contrats, documents,
analyses et téléchargements. ContractGovernanceRepository et son adaptateur
Eloquent isolent la persistance. FormRequest, Gate, Resource, revalidation
en service et TenantBusinessGuard protègent chaque opération.

contract_financial_terms : bloc encrypted:array caché à la sérialisation générale,
portée unique tenant/scope_id (contrat ou avenant), version et préparateur.
expected_version nullable protège aussi la première création. Toute écriture
renouvelle la version du dossier. Original éditable en draft/in_review ; avenant
non signé et parent non archivé. Aucun effacement qui retirerait une restriction.

contract_amendment_approvals : décisions immuables numérotées, liées à la dernière
préparation PDF et à la version financière examinée. Approbateur distinct du
créateur documentaire, du préparateur PDF et financier. financial.validate est
requis pour les avenants de tout dossier financier, même sans bloc propre.
contract_approved_copies : liaison immuable copie signée/décision, enregistrée dans
la transaction de dépôt. Après modification, la décision reste dans l’historique
mais ne permet plus la signature ; une ancienne copie ne peut satisfaire la
nouvelle décision. Les gardes et clés composites interdisent les mélanges de
tenants, dossiers et versions.

signature_separation_required est une option de préparation par dossier, fausse
pour l’existant, figée après approbation. Elle impose un déclarant différent des
préparateurs et de l’approbateur. signature_recorded_by reste nul pour les anciennes
déclarations : aucun acteur passé reconstitué. Les signatures électroniques
restent du périmètre CTR-007.

Les réponses ordinaires n’exposent que des métadonnées de restriction et décision.
Un bloc financier rend tous les documents du dossier soumis à financial.read ;
leur préparation exige financial.write. Les téléchargements recontrôlent les
droits après I/O, y compris les liens émis avant cette restriction. Audit limité
aux UUID/action/acteur/version, sans montants ni texte sensible. L’export opérateur
fermé conserve les colonnes chiffrées, sans endpoint de lecture navigateur.

Pagination conservée ; withExists pour les marqueurs financiers, chargement en lots
des dernières préparations/décisions et métadonnées financières, sans déchiffrer
les blocs dans les listes. Index tenant/contrat, tenant/portée et tenant/document/
numéro. Les verrous sérialisent les écritures d’un dossier avec son organisation.
Profiler contention et volumes avant d’élargir les limites ; aucun benchmark à
millions d’utilisateurs revendiqué.

Migration additive sans changement des anciennes migrations. SQLite utilise
ALTER natif sur contracts pour préserver les triggers inter-tables ; les nouvelles
références sont composites. PostgreSQL conserve les contraintes natives et un
lock_timeout local de cinq secondes. Les tests de rollback respectent l’ordre
de dépendance des migrations.


## Rappels de contrats — CTR-005

ContractReminderService orchestre la configuration, l'historique, la boîte
personnelle et la résolution humaine. ContractReminderSchedule calcule les dates
calendaires dans le fuseau de l'organisation et le seuil atteint le plus urgent.
ContractReminderRepository persiste plans et livraisons ; toutes les recherches
restent explicitement tenant. La migration ajoute uniquement deux tables avec
références composites, index et gardes d'identité SQLite/PostgreSQL, et refuse le
rollback destructeur d'un historique utilisé. Les anciens contrats ne sont pas
modifiés ni activés. Les colonnes sont enregistrées dans l'export opérateur fermé.

ProcessContractReminders implémente le port public TenantScheduledTask. Le
scheduler et RunTenantScheduledTask TEN-006 fournissent le contexte système,
l'isolation, les verrous partagés, le dispatch durable et le checkpoint par run.
Aucun propriétaire n'est impersonné. La progression par réglage est durable,
la clé métier unique déduplique chaque contrat/date/type/seuil/responsable/compte.
Une interruption partielle reprend les étapes non terminées. Les marqueurs de
configuration proviennent du serveur ; ils ne sondent pas les workers.

People expose LinkedPersonAccountDirectory, simple lien personne/compte ;
Tenancy expose TenantNotificationRecipientDirectory, qui re-résout l'adhésion,
le compte vérifié, la MFA et chaque permission actuelle via Identity. Contracts
n'importe aucun modèle Identity ni Tenancy/People. L'e-mail et la boîte ne donnent
aucun accès implicite ; une réaffectation et un retrait financial.read prennent
effet lors de la lecture et du traitement des e-mails encore en attente.

ContractReminderMailer isole le transport. LaravelContractReminderMailer
utilise le mailer SMTP configuré et un message générique, sans données du dossier.
Son identifiant technique stable pourra être fourni à un adaptateur Brevo API
ultérieur ; le header SMTP ne crée aucune garantie d'idempotence fournisseur.
La prise en charge sending est commitée avant l'appel réseau, puis sent après
acceptation ; un échec ambigu ou un sending abandonné devient uncertain, jamais
réessayé automatiquement. Une autorisation humaine de retry accepte un risque
de doublon et est auditée avec l'UUID du rappel et l'acteur. Une panne avant prise
en charge laisse pending ; un changement du dossier rend l'envoi obsolete.
Le statut sent décrit l'acceptation du transport ou sa confirmation humaine,
pas la livraison finale au destinataire.

Lots bornés : 25 plans/passage, délai de quinze minutes entre vérifications d'un
plan, trois e-mails/passage, timeout SMTP de 1 à 5 secondes par opération réseau,
timeout du job TEN-006 conservé. Le débit est volontairement limité ; suivre les
backlogs et profiler avant d'augmenter les lots, de sharder la file ou d'introduire
un fournisseur avec idempotence native. Historique/boîte paginés, requêtes sans
chargement des contenus contractuels ou documentaires sensibles. Audit minimal
sur la configuration et les décisions ; le ledger conserve run, état et tentatives.


## Audit des accès sensibles — CTR-006

Tenancy expose TenantSensitiveAccessJournal, port d'écriture de métadonnées pour
des appels serveur déjà autorisés. EloquentSensitiveAccessJournal persiste
organization_sensitive_access_events. SensitiveAccessData accepte uniquement
acteur, action fermée, UUID de cible/requête, catégorie de motif et IP facultative.
SensitiveAccessAction détermine le type de cible, sans texte provenant d'un contrat.
Le journal n'est pas un mécanisme d'autorisation ; ses appelants doivent résoudre
le tenant et vérifier l'accès avant de l'invoquer.

AuditContractAccess est placé après la résolution du tenant sur les routes
Contracts. Une table fermée de noms de routes sélectionne les lectures et
l'émission de liens, puis les paramètres serveur fournissent la cible. Aucune
URL, query string, empreinte, pièce ou réponse n'est persistée. Les Form Requests
existantes conservent la validation et les policies ; l'en-tête facultatif
X-Access-Purpose fait l'objet d'une validation de métadonnées séparée.
Après une réponse 2xx, l'événement doit être enregistré avant de retourner cette
réponse au pipeline. Un échec rend 503 avec Retry-After, sans fuite du corps
JSON/PDF déjà préparé. Ce mécanisme ne convient pas à une réponse streamée
différée sans adaptation ; les téléchargements CTR-003 actuels sont bornés et
préparés avant leur retour. Les jobs internes ne sont pas attribués à un faux
acteur humain de consultation.

OrganizationPortabilityService TEN-007 écrit les événements de demande et relance
dans leur transaction, puis l'événement de génération avec le passage à ready.
Une reprise d'une opération déjà ready ne crée pas une seconde génération.
Après lecture du stockage, le service revérifie l'opérateur et l'opération avant
de journaliser l'ouverture ; toute erreur ferme le flux. Les archives restent
privées et accessibles uniquement par l'outillage opérateur existant. Leur motif
libre n'est pas recopié dans le journal de consultation.

La source tenancy.sensitive rejoint le journal tenant IAM-006. TenantAuditService
conserve audit.view, vérification du compte/MFA et exclusion des profils
techniques plateforme. AuditTimelineQuery conserve ses bornes et curseurs chiffrés
liés à l'auditeur, au tenant et aux filtres. Aucun modèle Contracts n'est importé
dans Tenancy et aucun modèle privé Tenancy n'est importé dans Contracts.
Le catalogue d'export opérateur référence les colonnes du nouveau journal fermé.

Migration additive, aucune ancienne migration modifiée, aucune reprise
rétrospective des accès. FK tenant/acteur, liste d'actions et catégories fermée,
cohérence SQL action/type de cible, gardes append-only, rollback interdit si usage.
Les tests de round-trip d'Organization retirent et réinstallent cette dépendance.

Index pour les lectures réelles tenant/date, tenant/acteur/date et tenant/action/date.
L'insertion synchrone constitue le coût par lecture ; elle est nécessaire à
l'absence de divulgation sans trace. La source ajoute une requête bornée à la
timeline générique (cinq sources tenant). Pas de jointure par événement ni de
déchiffrement du contenu métier. Conservation illimitée sans purge IAM-006 ;
mesurer volume/latence avant partitionnement, archivage ou modification de cette politique.

## Prospection commerciale — UCG-SPON-001

Partnerships possède `sponsors`, `sponsor_contacts`, `sponsorship_opportunities`
et `sponsorship_interactions`. Il dépend uniquement de Tenancy, sans import de
People, Identity, Teams ou Contracts. Les contacts ne sont ni comptes ni fiches RH.
Les deux controllers délèguent aux Form Requests, Policy, Services, contrat
`SponsorRepository` et Resources explicites. Le provider lie le repository et
charge les routes API v1. Les mutations relisent les droits après verrou
organisation ; version optimiste et verrou dossier empêchent les écrasements.
L’audit transactionnel ne conserve aucun texte commercial ; sa panne annule
la mutation.

Les FK composites bornent chaque enfant au tenant et au sponsor, y compris
les liens contact/opportunité des échanges. Les gardes SQL SQLite/PostgreSQL
bloquent les changements d’identité, suppressions et modifications d’archives ;
les échanges sont append-only. Le catalogue fermé de portabilité inclut les
quatre tables. Le rollback refuse de supprimer un historique utilisé.
Les listes sont paginées séparément, sans collections imbriquées non bornées.
Profiler la recherche insensible à la casse et la contention par tenant avant
ajout d’index d’expression ou changement de sérialisation. React annule les
lectures obsolètes et invalide le contexte après refus/conflit. Les permissions
partenariats sont explicites et privilégiées, sans droit RH ou financier induit.
Accords, montants, livrables et portails restent les tickets SPON suivants.

## Accords et expositions — UCG-SPON-002

Partnerships ajoute sponsorship_agreements, sponsorship_agreement_terms,
sponsorship_exposures et sponsorship_agreement_events. Ses dépendances autorisées
incluent maintenant People, Teams et Contracts en plus de Tenancy, par leurs
seuls contrats publics ; le graphe reste acyclique. People/Teams proposent des
références sportives minimales, Tenancy des noms de compte membres, Contracts des
références de contrats sponsor et une vérification booléenne de version/activité.
Les données RH ne transitent pas vers Partnerships. Aucun modèle/table d’un autre
module n’est lu directement par une couche de Partnerships.

Controllers/Form Requests/Policies délèguent aux Services accords, périmètres,
finances, références et projection ; persistence par le repository dédié.
TenantDataVisibility projette les champs de l’accord et réserve le rattachement
contractuel au droit administratif. Les finances sont dans une table chiffrée,
servies par un endpoint distinct et autorisé. L’interface documentaire réutilise
les composants et routes Contrats ; elle ne contourne ni ses Policies, ni ses
restrictions financières, ni ClamAV. Aucune duplication du stockage de documents.

Verrou organisation puis dossier, révision optimiste, droits et références relus
à l’écriture. Une modification annule l’approbation entière ; validation par un
acteur distinct des deux préparateurs. Les exclusivités par catégorie sont
réservées sur tout le tenant pendant les périodes inclusives approuvées.
L’autorisation d’exposition est recalculée sur un lot borné de références,
sans N+1, avec dates tenant et contrôles de responsable, approbateur et contrat.
Elle ne vaut ni publication publique ni preuve de réception client.

Les FK composites et gardes SQLite/PostgreSQL protègent rattachement, identités,
dates, structure des approbations, modifications d’un accord approuvé et archives.
Les journaux sont append-only. L’audit de mutation participe à sa transaction ;
la consultation financière ajoute une trace synchrone avant remise de réponse,
avec échec fermé 503 sans contenu. Aucun montant, description ou autorisation
libre n’est copié au journal. Portabilité et round-trip schéma incluent les quatre
tables, rollback refusé en présence de données. Pas de migration/backfill de
l’environnement métier par l’agent. Les futurs modules événement/contenu devront
fournir un port de référence réel avant activation de leurs périmètres.

Les noms des responsables et pseudonymes sont chargés en bloc, par page de 100
maximum ; le fragment React des accords est chargé à l’ouverture de l’onglet.
Mesurer coût des droits courants, écritures de journal financier et contention
par tenant avant toute optimisation ou revendication de volume.

## Campagnes et livrables — UCG-SPON-003

Le module Partnerships ajoute sponsorship_campaigns, sponsorship_deliverables
et sponsorship_campaign_events. Le graphe de dépendances ne change pas : les
références membres et sujets utilisent les ports publics de SPON-002. Les
campagnes dépendent d’un accord ; chaque livrable conserve accord/campagne/
périmètre immuables et des FK composites. Aucune table RH ou financière n’est
chargée pour le suivi opérationnel.

Controllers et Form Requests dédiés réutilisent SponsorshipAgreementPolicy
(viewAny/manage/validate), Services campagnes/livrables, projection à liste
fermée et SponsorshipCampaignRepository. Tenant confirmé, version d’accord,
version de campagne et version du livrable sont relues sous verrou organisation.
Les versions de campagne changent à chaque mutation enfant ; une version obsolète
est refusée sans rejeu automatique. Les noms et périmètres sont projetés par lots.

Les statuts promis/livré/validé restent distincts. La livraison est déclarative,
avec date et référence manuelle chiffrée. La validation indépendante contrôle
l’approbation et les droits actuels ; aucune publication n’est effectuée. Les
versions accord/périmètre de la validation sont conservées, et leur correspondance
courante est calculée à la lecture. Rouvrir préserve la preuve antérieure dans
une capture chiffrée de l’historique métier, puis retire la preuve/validation
courantes. Le journal transversal ne contient que UUID et action ; le journal
métier HTTP ne projette pas les captures privées.

Gardes SQL SQLite/PostgreSQL : identités immuables, liens cohérents,
structure livraison/validation et indépendance, absence de réécriture silencieuse
de la preuve pendant validation, annulations/archives figées et journal en ajout
seul. La période d’un accord ne peut exclure une campagne active. Accord et
partenaire ne peuvent être archivés avec des campagnes actives ; une campagne
ne peut être archivée avec des livrables promis/livrés. Aucune migration déployée
n’est modifiée. Catalogue de portabilité étendu aux colonnes physiques des trois
tables, rollback refusé en présence de données. Tous les round-trips qui retirent
SPON-002 retirent désormais SPON-003 auparavant et le recréent après.

Le tableau tenant est une projection privée des campagnes actives : agrégats
fixes par statut, retards promis paginés, noms de responsables et contexte
sponsor/accord/campagne, sans preuve ni montant. Il ne prétend pas mesurer une
audience, ni certifier une publication. Un changement de tenant/droits invalide
les lectures et les formulaires ; les mutations actualisent le tableau par un
callback explicite. Les campagnes restent dans le fragment React des accords ;
le tableau de suivi possède son propre fragment chargé pour les lecteurs habilités.

## Catalogue de médias — UCG-MEDIA-001

Media possède la table tenant `media_items`. MediaController reste un point
d’entrée mince : MediaRequest fermée, MediaPolicy, MediaService, port
MediaRepository et adaptateur Eloquent, puis Resources à liste fermée. Les liens
passent par la règle de domaine MediaLink. MediaTeamsDirectory expose depuis
Teams uniquement des choix/noms actifs paginés et un contrôle de référence sous
verrou ; aucun modèle Teams n’est importé par Media. Les méthodes internes de
références d’équipes sont renommées referencePage/referenceLabels et partagées
avec Partnerships sans partager leurs contrôles d’accès.

Les mutations verrouillent l’organisation avant les ressources et recontrôlent
les droits sous verrou. Version UUID optimiste ; audit minimal media_id/action
dans la même transaction, sans titre, URL ou observation. Le schéma protège la
FK composite tenant/équipe, les états de diffusion selon le type, la cohérence
du partage et l’identité ; archives immuables, suppression physique interdite.
Migration additive indépendante, export opérateur explicitement étendu.

Droits media.read/write/publish explicites, MFA, aucun accès de gouvernance ou
plateforme implicite. Lecture simple : seulement les médias non archivés dont
le partage est effectif. Édition : accès aux références privées/planifiées et
archives. Un scrim exige une date de partage, future lors de l’autorisation.
Les contrôles temporels sont rejoués à la lecture ; aucun job n’envoie une
autorisation périmée. Il n’existe pas de route publique ni de lecteur intégré.

Liens officiels HTTPS contrôlés sans requête réseau, ouverture volontaire et sans
référent. La disponibilité reste une observation manuelle distincte d’un score ;
un renommage ne réaffirme pas une ancienne observation. L’horodatage tenant sert
à l’affichage, les saisies locales sont converties en ISO avec fuseau.

Seul le rattachement équipe est opérationnel : Competition et Scheduling restent
des frontières vides pour matchs/événements. Aucun UUID libre ni nouvelle
dépendance Media → Competition/Scheduling n’est introduit. Ces liaisons exigeront
des contrats publics tenant et des contrôles scrim issus du référentiel réel.

## Communications internes — socle UCG-COM-001

Media possède `announcements`, `announcement_comments`, `communication_events`,
`communication_preferences` et `communication_receipts`. Flux MVC existant :
CommunicationRequest fermée, CommunicationPolicy par action, CommunicationService,
port CommunicationRepository et persistance Eloquent, puis Resources explicites.
Titres et corps chiffrés via les casts Laravel ; aucun de ces textes dans l’audit,
les événements ou les accusés de lecture.

Les contrats publics CommunicationTeamsDirectory (Teams) et
CommunicationRolesDirectory (Tenancy) exposent des choix minimaux paginés,
le contrôle d’une cible active et les identifiants effectifs du compte courant.
Les requêtes restent dans les repositories de leurs modules propriétaires.
Teams obtient la personne liée via SportingPeopleDirectory de People. Media
n’importe aucun modèle d’un autre module et le graphe de dépendances reste inchangé.
Les ensembles effectifs sont bornés à 1 000 et une limite dépassée échoue explicitement.

CommunicationAudience est une valeur éphémère par requête ; la visibilité est
appliquée en SQL avant pagination, déchiffrement et comptage. Cible organisation,
équipe, jeu ou rôle ; équipe/jeu exigent un effectif effectif et un jeu activé.
Seules les attributions de rôles actives au niveau de l’organisation constituent
une audience de rôle. Membership/MFA et permission read restent nécessaires.
Le mode gestion exige read + write et ne permet jamais de commenter hors audience.

Les mutations verrouillent l’organisation avant l’annonce, recontrôlent les droits,
puis comparent la version UUID. L’audit est atomique avec contenu/événement.
Brouillon éditable ; publication confirmée, contenu/cible/expiration immuables ;
ouverture des commentaires mutable ; retrait définitif. Append de commentaire
sous verrou du parent sans changement de version du parent. Préférences
personnelles versionnées, création initiale avec version null. Lecture idempotente
d’un événement visible, unicité tenant/utilisateur/événement.

La boîte interne matérialise les événements, sans liste persistée de destinataires
ni job de diffusion. Au GET de remise, le service vérifie les droits actuels puis
le repository filtre audience, état, expiration, préférences et participation
avant les métadonnées. Les titres sont chargés par lot depuis les annonces
visibles ; aucun extrait périmé ne survit à une révocation. L’auteur d’un événement
ne reçoit pas sa propre notification. Les réponses visent l’auteur de l’annonce
ou les comptes ayant déjà commenté avant la réponse, seulement sur opt-in.
Aucun envoi externe et aucune configuration de worker/scheduler requise pour ce
socle. Un futur connecteur devra refaire ces contrôles au moment de son envoi.

Les FKs composites protègent les liens tenant/équipe/rôle/annonce/commentaire/
événement. Les triggers SQLite/PostgreSQL figent l’identité et le contenu publié,
interdisent les suppressions d’annonces et les modifications/suppressions
d’historique. Index d’audience/fil, unicités tenant des notifications et lectures.
Les cinq tables et toutes les colonnes physiques sont ajoutées au catalogue de
portabilité ; rollback refusé si une table contient des données. Les round-trips
Organization, Teams et Catalogue retirent COM-001 avant leurs parents.

Le panneau React est chargé à la demande selon communication.read, avec une clé
tenant/révision/permissions. Lectures abortables ; confirmation et révision via
le hook de mutation existant, erreurs 409 sans rejeu, texte échappé, pagination
des annonces/références/réponses/notifications et préférences propres au tenant.
Les liens vers tâches, matchs, séances et événements restent un reliquat explicite
de COM-001 : aucun modèle ou identifiant libre simulé dans ces frontières vides.

## Activités du calendrier — UCG-CAL-001

Scheduling possède calendar_activities et calendar_participants. Provider dédié
enregistré, six routes privées API v1, CalendarRequest fermée, CalendarPolicy,
CalendarService, CalendarRepository/EloquentCalendarRepository et Resources à
liste explicite. CalendarAudience est une valeur éphémère (acteur, personne
courante, équipes effectives). CalendarPage transporte pagination et métadonnées
de fuseau/période, sans modèle d’un autre module.

CalendarPeopleDirectory expose depuis People uniquement références id/pseudonyme,
validation groupée des participants sous transaction, labels groupés et fuseau
de la personne actuellement liée au compte. Personnes parties exclues, y compris
en présence d’une trace de départ. CalendarTeamsDirectory vérifie les équipes
actives, fournit choix/labels paginés et effectifs actuels. Le repository interne
CommunicationTeamsRepository devient TeamAudienceRepository, partagé avec COM-001 ;
ses règles effectif/jeu restent identiques. Contrats publics communication inchangés.

Le graphe Scheduling → Tenancy/People/Teams reste inchangé. Les modes privé,
équipe et organisation désignent une audience interne, jamais une route anonyme.
Privé : créateur ou personne explicitement inscrite ; équipe : aussi effectif
effectif actuel ; organisation : lecteurs habilités. Toute voie exige read,
adhésion active et MFA. Mode gestion explicite read + write : lecture de tout
le calendrier tenant. Les opérateurs techniques n’obtiennent pas cet accès,
même avec des attributions métier. Partage exige publish et confirmation ;
les écritures privées, retrait de partage et archivage exigent write.

CalendarTime vérifie les instants ISO avec offset par aller-retour dans le fuseau
IANA d’origine : une heure inexistante ou un décalage incohérent échoue ; deux
offsets valides d’une heure répétée désignent deux instants distincts. UTC stocké,
casts immuables. Les fenêtres sont des dates locales [début, fin[, 1–93 jours,
converties en minuits locaux puis UTC, donc 23/25 heures selon le changement
d’heure. Le frontend résout les heures via Intl, vérifie les candidats par
aller-retour, affiche les décalages et exige un choix pour une heure répétée.
Aucune dépendance de date ajoutée ; le serveur reste autoritaire.

Mutations : verrou organisation puis activité, droits revérifiés, version UUID,
participants validés par lot, enregistrement et audit atomiques. Titres,
descriptions, lieux et URL chiffrés ; audit seulement calendar_activity_id/action.
Conservation par archive définitive, sans DELETE métier. Les triggers protègent
identité, période et consentement de partage ; participants archivés immuables.
FKs composites tenant/équipe/activité/personne, unicité participant par activité.
Deux tables et leurs colonnes physiques inscrites dans la portabilité. Down
refusé en présence de données. Round-trips Person/Team/Catalogue/Organization adaptés.

La requête applique audience, période de chevauchement, filtres et archive avant
comptage/pagination. Relation participants eager-loaded et labels groupés, bornes
50 participants/activité et 100 activités/page. Pas de cache durable de droits.
Les index couvrent organisation, période et équipe ; les optimisations futures
doivent être guidées par les plans et volumes représentatifs.

Panneau React chargé à la demande avec clé tenant/révision/permissions,
lectures abortables, confirmations et révisions via le hook existant. Rendu
texte et liens HTTPS protégés, sans accès réseau de vérification. L’agenda
paginé montre fuseau de consultation et fuseau d’origine. Les écritures en erreur
409 ne sont pas rejouées. Pas de convocation ou notification de report, ni lien
polymorphe vers un objet encore absent. Les futurs raccordements COM/MEDIA
nécessiteront un contrat public et une décision explicite sur les dépendances.

## Disponibilités personnelles et collectives — UCG-CAL-002

Scheduling ajoute AvailabilityRequest/Policy, AvailabilityService,
AvailabilityRepository et son adaptateur Eloquent, AvailabilityPage et deux
Resources explicites. Six routes privées sous calendar ; mêmes middlewares
session/MFA/audit/tenant, confirmation et blocage de session pour les mutations.
Le graphe modulaire reste inchangé.

Le contrat public People AvailabilityPeopleDirectory fournit l’identité actuelle
liée au compte, sa préférence de fuseau, les choix paginés et les labels groupés.
Son service réutilise le repository interne de références déjà employé par
CalendarPeopleService ; aucune lecture de modèle People depuis Scheduling.
Les personnes former ou ayant une trace de départ sont exclues des choix et
des écritures ordinaires. Un gestionnaire peut annuler un ancien créneau après
départ et lire son historique ; son label courant est alors nullable.

Trois droits métier organisationnels distincts : availability.self pour l’identité
personnelle actuelle ; availability.read pour les états collectifs ; read+manage
pour les autres personnes, commentaires et historiques. Tous exigent MFA et
excluent les opérateurs techniques. La lecture collective ne donne ni édition
ni lecture des commentaires/historiques. Les autorisations et l’identité sont
réévaluées, sans cache durable ; filtre personnel avant comptage et pagination.

CalendarTime et la résolution frontend existante sont réutilisés. Les deux
formulaires partagent CalendarTimeChoice ; l’extraction préserve le comportement
CAL-001. Instants UTC, fuseau d’origine et casts immuables. Commentaires chiffrés,
sorties texte échappées, aucun champ médical. Les créneaux sont des déclarations
ponctuelles, pas une synchronisation des états/périodes RH.

Écriture : verrou organisation via TenantBusinessGuard, relecture des droits et
de l’identité actuelle, verrou du créneau si existant, contrôle UUID/version,
validation de la personne, recherche des chevauchements actifs [début,fin[,
puis état courant + instantané numéroté + audit atomiques. Le verrou organisation
sérialise les créations concurrentes qui passent par le service, y compris quand
aucun créneau n’existe encore. Une écriture SQL externe doit respecter ce
protocole ; les triggers protègent identité, période/version et rétention, sans
se substituer à l’autorisation et au protocole applicatif de chevauchement.

availabilities porte l’état actuel ; availability_revisions est append-only,
avec copie du texte chiffré conservé, acteur et instant. Annulation terminale,
aucune suppression métier, aucune notification. FKs composites tenant/personne
et tenant/parent/personne, numéro unique par créneau, deux index de fenêtre
personnelle/collective. Down refuse les données ; portabilité et round-trips
Person/Organization adaptés. Les requêtes et références sont paginées, les
labels chargés par lot ; le nombre de requêtes reste constant avec la taille
de la page. Profiler avant toute optimisation des index ou des verrous.

Le panneau React est chargé à la demande et remonté sur changement
tenant/révision/permissions. Lectures annulables, confirmation humaine, erreurs
localisées/toasts, aucun rejeu sur 409. L’historique est lui-même paginé. Les
capacités renvoyées par le serveur guident les commandes de chaque ligne, sans
remplacer les contrôles serveur. CAL-003 traite les conflits avec les activités
et ressources ; aucun raccordement implicite COM/MEDIA ou rappel ajouté ici.

## Contrôle transactionnel des réservations — UCG-CAL-003

Scheduling ajoute CalendarConflictService/Review et CalendarConflictReport,
CalendarConflictRepository/Eloquent et CalendarResourceService/Repository,
avec Request/Controller/Resources explicites. CalendarService réutilise le même
préparateur de réservation pour l’aperçu et la sauvegarde, incluant les références
effectives lors des PATCH partiels. Aucun changement du graphe modulaire.

Trois tables additives : calendar_resources, calendar_reservations,
calendar_conflict_overrides. FKs composites tenant/activité/ressource, référence
de ressource unique par tenant, réservations uniques par activité/ressource.
Nom, motif d’exception et instantané sont chiffrés ; exceptions append-only,
réservations archivées conservées, ressources archivées terminales. Down refuse
les données. Portabilité physique fermée et round-trips dépendants adaptés.

L’intersection [début,fin[ utilise UTC et les participants explicitement inscrits,
l’équipe associée ou des ressources communes. La réservation d’équipe est une
réservation de cette identité, sans expansion implicite du roster. Les personnes
mobilisées doivent donc être explicitement inscrites. Disponibilités CAL-002 :
lecture locale au même module uniquement avec availability.read, statuts
unavailable/uncertain, aucun commentaire sélectionné. Sans droit, métadonnée
availability_checked=false et avertissement UI ; pas de présomption d’available.

Les writers disposent déjà d’un mode gestion de toutes les activités privées :
l’aperçu exige calendar.read/write. Il projette seulement titre, période,
références communes et causes, sans description/lien/lieu, ni personne étrangère
à la réservation candidate. L’historique des exceptions exige aussi
availability.read pour préserver les droits sur les anciens instantanés.

Aperçu sous verrou organisation et relecture des droits/références/versions ;
jeton Laravel chiffré et authentifié, cinq minutes, empreinte des identités,
horaires, références et résultats. La sauvegarde prend le même verrou, relit
l’activité et recalcule les conflits. Le contrôle peut être omis par un client
API seulement s’il n’existe aucun conflit courant ; une exception exige le
contrôle exact et courant, calendar.conflicts.override, motif et confirmation.
L’exception est enregistrée à chaque écriture restant conflictuelle, jamais
héritée comme une autorisation permanente.

Le verrou organisation sérialise aussi les créations sans ligne préalable et
les modifications de disponibilité/ressource, qui suivent le même protocole.
Une modification entre aperçu et sauvegarde produit 409 ; aucun rejeu implicite.
Le journal, l’activité, les participants/ressources et l’audit minimal sont
atomiques. Les écritures SQL externes doivent respecter ce protocole ; les
triggers protègent rétention/identités mais n’autorisent pas une exception.

Deux requêtes bornées de conflits, relations eager-loaded ; 100 résultats utiles
maximum, dépassement bloquant même pour un gestionnaire. Aucun cache durable des
droits ou des résultats. Référentiel et historiques paginés, sélection de
20 ressources, pagination existante des 50 participants. Index de référence et
de réservation ajoutés ; profiler intersection et contention avant de modifier
le verrou ou les index.

React sépare le contrôle annulable de la mutation confirmée ; toute modification
du formulaire annule l’aperçu. Un motif ne modifie pas le périmètre contrôlé.
Les erreurs 422 gardent la saisie et les champs concernés ; les 409 nécessitent
une nouvelle décision. Les textes des activités, ressources et motifs restent
échappés. Les processus de tests PostgreSQL utilisent des bases temporaires et
une barrière commune, sans écriture dans la base principale.

## Convocations versionnées et remise asynchrone — UCG-CAL-004

Scheduling possède calendar_invitations et calendar_invitation_deliveries.
CalendarInvitationService orchestre validation partagée/Form Request,
CalendarPolicy, contexte tenant, Repository et audit. Les contrôleurs restent
HTTP ; les Resources exposent une liste fermée de champs. Les permissions
calendar.invitations.manage et calendar.invitations.respond sont inscrites
au catalogue IAM et à la projection d’audit. Aucun rôle réel n’est attribué.

CalendarPeopleDirectory ajoute la résolution groupée des comptes des personnes
actuelles, bornée à 50 et réservée à une transaction autorisée. People conserve
sa règle de départ formel ; Scheduling ne lit pas ses modèles internes.
Labels groupés et relations activity.participants eager-loaded, comptage des
remises via withCount. Autorisations courantes non mises en cache.

Une convocation appartient à une personne et à une version d’activité, avec
échéance et seuils figés. Compte destinataire enregistré à l’émission, puis
comparé au lien actuel avant remise et réponse : une réaffectation ne transfère
pas silencieusement une convocation. Une activité modifiée rend ses anciennes
convocations obsolètes. Le suivi conserve références, réponse courante et audit ;
le titre exposé reste celui de l’activité actuelle, sans snapshot de son contenu.

ProcessCalendarInvitations implémente TenantScheduledTask et est enregistré
explicitement, sous flag serveur désactivé par défaut, dans le registre TEN-006.
Le dispatcher et RunTenantScheduledTask transportent UUID tenant et corrélation,
sans usurper le propriétaire. Pour chaque convocation, verrou organisation
via TenantBusinessGuard, puis activité et convocation ; contexte du responsable
utilisé uniquement avec autorisations relues. Le refus attendu d’un responsable
révoqué devient issuer_unavailable. Le lien personne/compte, les accès vérifiés
et le MFA du destinataire sont contrôlés avant toute remise.

Le scheduler est déclenché chaque minute avec les gardes distribuées TEN-006.
25 convocations pending, courantes et non expirées sont sélectionnées par tenant,
avec checked_at pour rotation équitable. La queue default est durable en
exploitation. Les requêtes HTTP ne remettent aucune notification ; le worker
insère la notification interne persistante et son état dans la même transaction.
Unicités (tenant, activité, version, personne) et (tenant, convocation, type, seuil)
assurent la déduplication après crash/retry indépendamment du cache du runner.
Une première remise tardive remplace les anciens seuils ; ensuite un seul seuil
le plus urgent est rattrapé. Réponse ou échéance ferme les rappels.

Les FKs composites protègent tenant/activité/personne/convocation.
Identité/paramètres des convocations et historique des remises sont protégés
en SQLite et PostgreSQL. Les deux tables/colonnes sont enregistrées dans la
portabilité ; rollback refusé si données présentes. Les round-trips des schémas
parents retirent les enfants CAL-004 avant le calendrier sans modifier les
anciennes migrations déployées. Les tests de concurrence créent leurs propres
bases PostgreSQL éphémères, avec deux processus et une barrière, puis les détruisent.

À forte charge, profiler les requêtes tenant, les anciennes versions retenues,
la contention du verrou organisation et le retard de sweep avant de relever
les bornes ou de répartir davantage les jobs. Aucun appel réseau n’est effectué
sous ce verrou. Les logs tenant.schedule.* et failed_jobs exposent les incidents.
Le flag d’activation indique une configuration, pas une vérification de santé
opérationnelle. Canal interne seulement ; ICS CAL-005 et notifications de report
CAL-006 ne sont pas implémentés dans cette étape.
## Flux personnel ICS — UCG-CAL-005

`CalendarFeedService` orchestre validation, garde transactionnelle tenant,
répertoire public d’éligibilité du compte, autorisation fraîche, repository et
audit minimal. `CalendarFeedRepository` porte un seul lien par organisation/compte.
Identité UUID/tenant/compte/création immuable, hash du secret caché, expiration,
fenêtre et révocation explicites. Création/rotation/révocation utilisent la version
optimiste et le même ordre de verrous compte → organisation → lien ; une première
création est sérialisée par le verrou organisation et l’unicité tenant/compte.
Rotation et révocation changent la version ; révocation efface l’empreinte.
Aucun secret brut n’entre dans la base, l’audit ou un job.

L’API de gestion reste sous Sanctum, session, MFA, contexte tenant et confirmation
pour les mutations. FormRequest fermé, policy `export`, permission dédiée
`calendar.export` en plus de `calendar.read`, services revalidant aussi leurs
entrées et droits hors HTTP. Le lien de téléchargement est une capacité personnelle
sans session : `CalendarSubscriptionRequest`, résolution hash/tenant/UUID,
policy compatible invités et second contrôle transactionnel avant génération.
Une résolution antérieure à une révocation ne peut pas rendre un corps ensuite.
Le principal éventuel d’une session ne remplace jamais l’acteur du lien.

Les seuls appels transverses passent par les contrats publics People, Teams et
Tenancy. L’audience SQL personnelle existante s’applique à nouveau à chaque lecture :
créateur, diffusion organisation, équipe actuelle ou participant encore lié à une
personne admissible. Pas de mode management. Une requête SQL ordonnée, projection
fermée et `LIMIT 501`, sans chargement de relations, déchiffre uniquement les titres.
Le quota technique d’export s’ajoute aux plafonds 500 événements/512 Kio.

`CalendarIcs` produit RFC 5545 : CRLF, lignes ≤ 75 octets repliées sans couper
UTF-8, échappement TEXT et suppression des contrôles, UID dérivé du lien et de
l’activité, stable à travers les rotations, DTSTAMP de la modification (horaire
initial si ancienne ligne sans updated_at), DTSTART/DTEND UTC, CLASS PRIVATE.
Pas d’alarme, description, lieu, URL, identité, absence ou donnée contractuelle.
Le jeton est généré par random_bytes(32), stocké SHA-256 et vérifié hash_equals.
URL construite avec APP_URL de confiance et route nommée, HTTPS hors local/tests.

Middleware dédié : retrait du jeton de l’URL interne, limitation des requêtes par
empreinte et IP, erreurs fermées, Referrer-Policy no-referrer, nosniff, noindex et
Cache-Control private/no-store sur succès/erreurs. Aucun ETag/304/cache de corps :
les droits et la révocation sont réévalués. Les logs d’entrée de l’infrastructure
restent à configurer pour masquer token. Révoquer n’efface pas les copies tierces
ou une réponse déjà autorisée en cours d’envoi.

Migration additive SQLite/PostgreSQL, FK restrictives, bornes et cohérence
hash/révocation imposées en SQL, rollback refusant une table peuplée. Le round-trip
OrganizationSchemaTest retire/remonte aussi calendar_feeds ; l’inventaire de
portabilité enregistre explicitement les colonnes, dont l’empreinte seulement.
Après restauration, révoquer les liens restaurés avant de réouvrir le service.
Panneau React chargé à l’ouverture, secret en état mémoire éphémère, confirmations,
états expiré/révoqué et conflits sans rejeu automatique.
## Historique des changements et outbox interne — UCG-CAL-006

CalendarChangeService prépare le changement avant mutation puis persiste le
snapshot après sauvegarde, dans la transaction de CalendarService. Le verrou
tenant puis activité, la version optimiste, les contrôles CAL-003, la nouvelle
version, l’historique, les destinataires et l’audit partagent le même commit.
Un échec d’audit ou de conflit ne laisse ni changement ni notification à traiter.

calendar_changes porte UUID tenant/activité, versions avant/après, acteur,
nature rescheduled/cancelled, titre et motif chiffrés, deux périodes UTC avec
leurs fuseaux et date d’émission. calendar_change_recipients fige personne,
compte résolu ou null et date ; les seuls états évolutifs sont contrôle,
blocage et remise. Unicités par version d’activité, personne et compte ;
FKs composites tenant/parent, historique append-only et identité de livraison
immuable dans SQLite/PostgreSQL. Une remise acquise ne peut être remplacée.
cancelled_at distingue l’annulation des anciennes archives et doit coïncider
avec archived_at. Le rollback refuse un historique ou une annulation conservés.

Les destinataires sont l’union bornée des participants explicites avant/après,
sans expansion d’équipe. Résolution des comptes par le port People, tranches
de 50, insertion groupée ; aucun lien futur ne remplace l’identité d’émission.
Les nouvelles tables et cancelled_at sont inventoriés pour la portabilité.
Les round-trips des parents retirent les enfants avant calendar_activities.

ProcessCalendarChanges implémente TenantScheduledTask ; activation conditionnelle
calendar-changes via CALENDAR_CHANGES_ENABLED=false par défaut. TEN-006 transporte
le contexte tenant et la queue default. Chaque passage parcourt 25 messages non
remis, priorité aux non contrôlés puis aux contrôles anciens. Chaque message
prend les verrous tenant → activité → destinataire et recontrôle le responsable,
le destinataire, les droits, la personne et l’audience actuelle. checked_at,
blocked_reason ou delivered_at/run_id sont transactionnels. Une reprise retrouve
une remise acquise sans la dupliquer ; une erreur transitoire annule le contrôle.
Un report antérieur peut être remis plus tard, avec indication historique.

EloquentCalendarVisibility partage le prédicat d’audience entre calendrier,
ICS et messages. La boîte personnelle inclut les activités annulées uniquement
pour leur historique accessible, filtre par compte/personne/audience avant la
pagination et charge les snapshots/versions en lots. Les listes actives et ICS
continuent d’exclure les archives. Le gestionnaire dispose d’une route séparée
pour l’historique tenant, sous calendar.read/write. Les Resources n’exposent
ni email ni identité des cibles ; les compteurs sont réservés à la gestion.
La SPA charge les panneaux à ouverture, échappe les textes, exige motif et
confirmation, et réutilise le contrôle CAL-003 pour les reports.

Ces notifications internes ne passent ni par le courrier ni par un connecteur
externe. Les limites et index bornent le travail par passage ; aucune cadence à
grande échelle n’est garantie sans mesure. CAL-007/CAL-008 restent hors de ce lot.

## Matchs et machine d’état — UCG-MATCH-001

Competition possède désormais matches, match_participants et match_status_events.
CompetitionServiceProvider enregistre repository, policy et routes tenant.
Les appels transverses passent uniquement par MatchReferenceDirectory (Teams)
et MatchSchedule (Scheduling), plus les contrats Tenancy ; aucune dépendance
directe Competition → People/Identity ni import de leurs modèles n’est ajouté.
La policy utilise Authenticatable et l’identité du TenantContext serveur.

MatchService orchestre FormRequest/validation partagée fermée, autorisation,
garde transactionnelle tenant, verrou du match, version optimiste, sélection
des références, écriture et audit minimal. Les transitions inscrivent leur
événement dans le même commit. Les échecs d’audit et de validation annulent
aussi les participants et l’historique. Deux processus concurrents ne peuvent
modifier la même version : l’un gagne, l’autre reçoit MATCH_VERSION_STALE.

MatchWorkflow définit les huit états et transitions permises. Le parcours
opérationnel s’arrête à awaiting_validation ; validated/disputed/forfeited
restent réservés au workflow de résultats et preuves MATCH-003/004. Aucun accès
HTTP ne peut les imposer, même avec sports.validate. Les champs/participants
sont modifiables uniquement en proposed. La réouverture confirmed → proposed
est explicite et motivée ; cancelled/forfeited sont terminaux. L’historique
reprend version, auteur interne, date et raison chiffrée, sans exposer l’auteur.

BO1/BO3/BO5 : deux participants ; lobby/free_for_all : 2–100. Le discriminant
person/team/opponent_snapshot sélectionne une seule FK composite tenant.
Une équipe appartient au même jeu ; un instantané externe doit correspondre
au jeu ou ne pas le préciser. Aucun lien vers le roster d’un autre tenant.
La sélection utilise des noms/version conservés, indépendants des renommages.
Responsable = personne interne active, sans attribution automatique d’accès.
Le jeu et l’identité du match sont immuables ; titre et libellés sont chiffrés.

Teams expose un nouveau port MatchReferenceDirectory. Ses sélections paginées
sont portées par MatchReferenceRepository ; les personnes passent par le port
public SportingPeopleDirectory étendu, sans inversion du graphe modulaire.
Les instantanés sont résolus par groupes bornés de chaque nature, pas par une
lecture par participant. Les insertions sont groupées après chiffrement par
les modèles. Les lectures chargent les participants en une relation anticipée.
MatchSchedule réutilise CalendarTime pour normaliser UTC, durée et fuseau ;
il ne crée pas de réservation et n’exécute pas de contrôle de chevauchement.

Audience SQL avant pagination : créateur, responsable/personne liée, participant
individuel, équipe actuelle seulement sous visibilité team, ou diffusion
organisation. Le mode gestion explicite exige sports.write. Même audience
pour consultation et historique. sports.publish et consentement sont nécessaires
à l’élargissement du partage. Les Resources n’exposent pas identité du créateur,
email, contacts adverses, autres rosters, score ou note stratégique.

Migration additive SQLite/PostgreSQL : FK composites, unicités de slot/source,
périodes bornées, forme des participants, nombre à confirmation, transitions
et gel SQL du noyau/participants, historique append-only et refus de suppression.
Down protecteur sur toutes les tables peuplées. Inventaire de portabilité
exhaustif et round-trips des parents People/Teams/Jeux/Adversaires/Organisations
actualisés avant suppression des parents. Les anciennes migrations restent
inchangées. La dépendance calendrier reste une API de normalisation uniquement.

Panneau React chargé à ouverture, sélections paginées, noms échappés, confirmation
tenant et motif des transitions, partage explicite, états figés et historique.
Les limites bornent chaque réponse ; mesurer requêtes, taille et contention
avant de modifier la cadence ou les plafonds. Aucun cache de contenu privé ou
notification externe n’est introduit. La liaison métier au calendrier, les lineups,
parties, résultats et rattachements COM/MEDIA restent des lots explicites.

## Compositions du match — UCG-MATCH-002

Competition consomme le port public Teams/Application/Contracts/MatchLineups.
Ce port fournit les choix finalisés paginés, un snapshot sportif et une évaluation
à la date du match. L’adaptateur Teams réutilise HistoricalLineups, les repositories
Teams, EligibilityEvaluator et les ports publics People. Competition n’importe
aucun modèle Teams/People/Identity. Les noms retenus restent ceux de la composition
finalisée ; la vérification courante du roster ne remplace jamais ces libellés.

MatchLineupService orchestre les mutations sous la garde tenant puis le verrou
du dossier Match, avant de lire/verrouiller les références Teams/People. La version
optimiste du dossier est commune aux éditions, transitions, affectations et
remplacements. Une seule révision et son audit sont écrits dans la transaction ;
un refus d’éligibilité ou une panne d’audit annule aussi le changement de version.
Les révisions courantes des participants sont lues ensemble via le repository.
Les périodes du roster et faits des personnes sont groupés par composition,
bornée à 50 personnes ; aucune requête par joueur. Les choix et l’historique sont
paginés, 500 révisions par match au plus. Profiler contention tenant, volumes et
évaluations avant d’augmenter ces limites ou d’ajouter un cache de données privées.

La sélection d’une composition finalisée est permise en proposed/confirmed ;
en in_progress, seuls les échanges de rôles starter/substitute du pool retenu
sont possibles. Chaque équipe interne doit avoir une sélection au démarrage,
aucune personne ne peut jouer pour deux camps. Aucun roster adverse n’est lu.
La présence au roster et l’admissibilité courante des personnes restent requises,
même sans règlement. Avec règlement, toute preuve incomplète/inéligible ou règle
archivée bloque la commande. La preuve de finalisation avant la date de verrouillage
reste celle de Teams ; le pool reste fermé lors d’un remplacement après cette date.
Sans règlement : unassessed explicite, jamais assimilé à eligible.

match_lineup_revisions retient snapshot et évaluation chiffrés, motif chiffré,
numéro, phase, version du dossier, auteur et date serveur. FK composites tenant
vers dossier/participant ; SQL vérifie équipe, correspondance du parent et de la
version, séquence, limites et phase. UPDATE/DELETE interdits. Les participants
ayant un historique ne sont plus remplaçables ; le down refuse une table peuplée.
Les migrations antérieures sont inchangées, leurs round-trips de test déposent
ce nouvel enfant avant ses parents. L’inventaire de portabilité inclut les colonnes
physiques chiffrées. Les règles d’éligibilité restent contrôlées dans les services.

L’audience du dossier filtre aussi son historique de composition. Contrairement
au journal de statuts, celui-ci expose la référence interne du compte auteur pour
tracer chaque opération, sans nom/email/coordonnées du compte. Les contrôleurs et
Form Requests réutilisent MatchPolicy ; sports.write ne vaut pas teams.manage.
Une nouvelle composition dans un dossier partagé exige sports.publish et un
consentement, sans nouvelle attribution d’accès aux joueurs. Le filtre personnel
refuse explicitement les participants sans person_id quand le lecteur n’a pas de
fiche Personne, pour empêcher la confusion NULL = audience d’équipe privée.

React charge choix et historique à l’action, masque les mutations des versions
historiques et des états terminaux, confirme tenant et action avant POST et
rafraîchit la version du dossier après succès. Le client ne rejoue pas un 409.
Les parties, scores, preuves de résultat et notifications restent hors de ce lot.


## Parties et scores — UCG-MATCH-003

Competition reste propriétaire du calcul. MatchScoreService orchestre validation
fermée réutilisable hors HTTP, permissions actuelles, garde tenant, verrou Match,
version optimiste commune, ajout et audit atomiques. MatchScoreRepository isole
la persistance ; BestOfScore et PointsScore implémentent ScoreStrategy pour les
victoires de série et l’addition des points multi-participants. Aucun import privé
de People, Teams, Identity ou Scheduling. Les compositions courantes sont lues en
une requête via le repository interne Competition ; seule leur référence immuable
est conservée à l’instant de saisie, sans reconstituer une chronologie sportive.

match_score_rules et match_game_instances sont additives, tenant-scopées, liées
par FK composites au dossier et entre elles. Les règles sont fixées une fois,
après démarrage ; les parties sont des tentatives numérotées append-only. SQL
vérifie phase, version du parent, format/limite des règles, séquence, capacité,
autorisation des nuls et nombre de parties comptées. Le calcul chiffré et
l’appartenance exacte de tous les participants restent des règles de service.
UPDATE/DELETE interdits ; down refuse toute table peuplée avant de supprimer quoi
que ce soit. Portabilité opérateur : inventaire fermé des deux tables et de leurs
colonnes physiques ; anciens fichiers de migration inchangés, tests de round-trip
des parents actualisés. Noms distincts des FK vers Match et règles pour PostgreSQL.

Résultats et libellés, carte/mode/version du jeu et motifs sont chiffrés. L’API
expose les références sportives et compte auteur interne aux lecteurs du dossier,
sans contacts ni données administratives. Aucun résultat validé, faux justificatif,
notification, événement de diffusion ni donnée publique n’est produit. Les
mutations partagées demandent sports.publish et un consentement explicite.

Les réponses sont paginées et le recalcul synchrone est borné à 100 tentatives
par match et 100 participants. Le test de lecture constate trois requêtes sur
les parties (faits, comptage, page), sans requête par partie ou joueur. Le GET
verrouille le seul dossier jusqu’à matérialisation de la page/résumé ; les écritures
prennent la garde tenant avant le dossier comme les autres modules. Profiler
contention, coûts de chiffrement et taille des réponses avant toute augmentation
de limites. Les agrégats asynchrones et caches versionnés relèveront de MATCH-006.

React charge la feuille à l’ouverture, utilise sa version fraîche, confirme
règles/résultats et tenant avant POST, échappe tout texte et ferme/rafraîchit le
dossier après succès. Les pages historiques, lecteurs et états terminaux sont en
lecture seule ; un conflit invalide le contexte sans rejeu automatique.


## Résultats et approbation indépendante — UCG-MATCH-004

Competition porte MatchResultWorkflow, règle de domaine pure des transitions de
soumission/approbation/contestation. MatchResultService orchestre garde tenant,
permissions actuelles, verrou Match et version optimiste commune. Le repository
MatchResultRepository isole un journal append-only unique match_result_events.
Le service consomme MatchScoreService/Repository à l’intérieur de Competition,
sans nouvelle dépendance de module. Le snapshot provient des scores immuables,
jamais d’un total/vainqueur fourni pour un résultat joué.

Un événement de soumission conserve la référence textuelle de preuve, snapshot,
contributeurs, motif, auteur et date serveur. Une décision référence cette exacte
soumission par FK composite tenant/match ; la séquence et la version sont uniques.
Les contributeurs incluent auteur de la proposition, des règles et de toutes les
tentatives ; ces IDs chiffrés restent hors des Resources. Un approbateur doit être
indépendant de tous. La preuve matérielle reste dans un espace privé géré par
l’organisation ; seul son identifiant déclaré est conservé ici, sans téléchargement,
vérification technique, stockage de fichier ou prétention d’intégrité externe.

La soumission passe le dossier en awaiting_validation ; approbation en validated
ou forfeited, contestation en disputed, puis nouvelle soumission nécessaire.
Un forfait/abandon de rencontre peut être déclaré avant démarrage, sans parties
fictives ; les scores partiels sont conservés et distingués du résultat attribué.
MATCH-006 devra traiter toute correction des parties ; aucun contournement par une
réouverture opérationnelle ou un changement de version ne libère le gel ici.

La migration additive crée le journal, ses FK/index et gardes SQL, puis remplace
les définitions des gardes Match/statuts par une copie historique conservant tous
les invariants précédents et ajoutant uniquement les transitions justifiées par
un événement de résultat. Les anciennes migrations sont inchangées. L’attente de
verrou PostgreSQL est bornée à cinq secondes. Aucun backfill ni changement de
valeur métier existante. Down refuse un journal peuplé, restaure les gardes
précédentes à vide puis retire table/gardes ajoutées. Les round-trips parents
appliquent ce down avant toute suppression d’une table référencée.

L’événement autorise une seule transition de base_version vers match_version,
inséré avant la modification du parent ; la transaction comprend statut/historique
et audit. SQL refuse réécritures, auto-approbation du soumissionnaire, mauvaise
phase/séquence/soumission/version et modification du dossier ou ajout de score
hors de cet événement. L’indépendance des contributeurs chiffrés, autorisation et
complétude des scores restent contrôlées dans les services.

GET matérialise dossier, décision courante et page sous verrou Match cohérent ;
limites 200 événements, 100 participants/tentatives, pagination 1–100. Aucun chargement
d’auteur par ligne ni cache partagé de droits/contenus privés. Profiler contention,
taille des snapshots et coût du chiffrement avant augmentation des limites.
Le validateur read/validate reste dans l’audience ; management exige toujours write.
Les mutations partagées exigent publish et consentement. React utilise les actions
serveur, la version fraîche et les confirmations, conserve un 422 et ne rejoue pas
un 409. Les ressources anciennes et preuves référencées restent consultables selon
l’audience courante, indépendamment de l’approbation actuelle.


### MATCH-005 — Carnet de préparation et débrief

Competition ajoute MatchNotebookRequest/Input, Controller, Service et le contrat
MatchNotebookRepository lié à EloquentMatchNotebookRepository. MatchPolicy garde
les contrôles de base sports.read/write ; le service impose les droits notes ou
stratégies par espace, à nouveau sous verrou lors des mutations. Le mode gestion
ne dispense pas des droits sensibles. Le filtrage des espaces précède les totaux,
la pagination et les lectures d’historique ; aucune donnée privée ajoutée aux
ressources Match, score, résultat ou canaux publics.

match_notebook_revisions stocke un journal chiffré et append-only avec racine
entry_id, nombre séquentiel et identifiant de révision utilisé comme version.
FK composites vers Match et racine, unicité tenant/match/entrée/numéro ; gardes
SQL SQLite/PostgreSQL empêchant mutation, suppression, changement de type/espace
et séquence/limites invalides. Index composite tenant/match/entrée/numéro pour
la sélection des dernières versions via anti-existence. Audit minimal et révision
partagent une transaction, avec verrou tenant puis match ; aucun changement du
statut/version sportif ni interaction avec les gardes de résultat scellé.

Pas de cache pour les contenus sensibles ; réponses private,no-store et texte
React échappé. Les VOD sont des liens HTTPS sans credentials ; aucune connexion
serveur au lien et ouverture humaine noreferrer/no-referrer. Actions simples à
échéance UTC, aucun rappel ou envoi automatique. Bornes 500 contenus × 100 versions
par match, notes 10000 caractères, pages de 100 maximum ; profiler requêtes,
chiffrement et contention du verrou tenant avant d’augmenter ces limites. Une
évolution vers des verrous plus fins doit conserver la révocation atomique des
droits et du cycle de vie du tenant.

Migration additive, sans backfill ni modification des triggers des anciens lots.
Down refuse un journal peuplé ; portabilité explicite conserve les champs
chiffrés. Aucun droit nouveau n’est automatiquement délégué à un rôle existant.


### MATCH-006 — Corrections de scores et agrégats versionnés

MatchCorrectionRequest/Input et Controller délèguent à MatchCorrectionService.
Les Repositories de corrections et scores encapsulent la persistance tenant.
MatchCorrectedScores projette les valeurs corrigées sur des copies des modèles,
sans save des parties sources. Les stratégies de domaine BestOfScore/PointsScore
recalculent les faits ; une série incohérente ou devenue incomplète est refusée.
Le passage remake/compté reste fixe pour respecter les contraintes de parties
existantes. La file passe par le contrat Application MatchRecalculationQueue et
l’adaptateur Infrastructure LaravelMatchRecalculationQueue ; RecalculateMatchScore
hérite de TenantJob, transporte seulement contexte/identifiants, après commit.

match_score_corrections conserve tenant/match, version avant/après du dossier,
révision précédente, numéro, clé d’idempotence/empreinte, auteur et instantanés
chiffrés. match_score_recalculations conserve un agrégat chiffré unique par
correction, auteur d’exécution et date. Historique append-only, FK composites,
séquence et état courant gardés en SQL ; blocage SQL supplémentaire de toute
mutation du match/règles/parties/résultat pendant pending. Aucun ancien trigger
modifié. Portabilité exhaustive explicite et down protecteur ; round-trips parents
suppriment d’abord la nouvelle migration à vide.

Transaction : verrou tenant, droits, match ; idempotence/version/validation ;
invalidation cache ; dispute si résultat soumis/approuvé, ou nouvelle version du
match sans résultat ; correction + audit ; mise en file après commit. Un résultat
déjà disputed garde sa version sportive ; score_revision joue le second verrou
optimiste pour corrections et soumissions. Une répétition identique ne crée pas
d’effet secondaire. Une tâche sous droits courants recalcule, invalide le cache,
ajoute la complétion et l’audit dans une transaction. Doublons sérialisés par le
match et l’unicité SQL, reprise après panne d’envoi/worker/audit/cache.

MatchScoreService matérialise total et page sous le même verrou, overlay des
corrections sur la page et les faits. Si pending, série null et écritures refusées.
Cache seulement des séries après correction, TTL 60 s, namespace TenantCache et
clé match/version/correction ; accès autorisé avant cache. Un changement de version
rend les anciennes clés inaccessibles ; invalidation explicite avant demande et
application. Aucune note/stratégie/proof ni donnée hors audience dans ce cache.
MatchResultService exige la révision fraîche après correction et ajoute tous les
correcteurs aux contributeurs ne pouvant approuver. Les anciennes preuves et
snapshots restent consultables selon leurs droits, sans approbation automatique.

100 corrections et 100 parties/participants par match, requêtes et historiques
bornés, relation de complétion eager-loaded sans requête par ligne. Profiler la
contention tenant et la taille des snapshots avant d’augmenter ces bornes. Aucun
classement de tournoi externe ou cache public introduit ; la publication via les
canaux sera branchée en MATCH-007 après validation et contrôle d’audience.


## 18 octobre 2026 — UCG-MATCH-007 : scores autorisés en temps réel

MATCH-006 (`22f46fc`) : push, CI et migration validés par l’utilisateur avant
MATCH-007 ; migration principale constatée Ran batch 42 en lecture seule.
Départ propre et synchronisé sur staging/origin/staging. MATCH-007 ne crée
aucune migration et ne modifie aucune dépendance ni variable .env.

- Vue « Scores en direct » séparée des formulaires : état provisoire/validé,
  totaux effectifs, vainqueur et issue approuvés (y compris forfait/abandon),
  horodatage de lecture. Aucun total exposé pendant un recalcul en attente.
- Canal privé `tenants.{organization_uuid}.matches.{match_uuid}`, événement
  `match.score.changed` après commit uniquement. Rollback : aucun événement.
  Changements du match, règles/parties, soumission/approbation/contestation et
  demande/fin de correction invalident la vue. Une reprise de correction déjà
  appliquée ou une requête idempotente ne produit pas de nouvel effet.
- Charge utile limitée à `event_id`, `organization_id`, `match_id`, `changed_at`.
  Aucun score, preuve, motif, acteur, composition, stratégie ou contenu du carnet
  dans le WebSocket. Chaque affichage relit une projection HTTP autorisée.
- Signature sur route tenant protégée : session courante, MFA, organisation active,
  confirmation/révision de contexte, sports.read et audience actuelle. Gestionnaires
  sports.write : même accès de gestion que les API sportives existantes. Le canal
  global /api/broadcasting/auth ne peut signer ces abonnements de match.
- Transport sur file `broadcasts`, 3 tentatives, timeout 30 s, délais 1/5/15 s.
  Si la mise en file échoue après commit : journal structuré sans secrets et action
  sportive conservée comme réussie. Pas d’outbox durable : rattrapage par relecture.
- Client : signaux regroupés 250 ms, 200 identifiants de déduplication maximum,
  ancien événement => relecture de l’état actuel, retour en ligne/réabonnement,
  secours toutes les 30 s lorsque la page est visible. Désabonnement et annulation
  des lectures à la fermeture/changement de contexte ; retrait des totaux sur erreur
  d’accès et rechargement du contexte. Échec transitoire : secours maintenu.
- Fraîcheur : délai `changed_at` → réponse relue affiché, incluant transaction,
  queue, transport et relecture ; dépend de la synchronisation des horloges client
  et serveur. Aucune promesse de latence de télémétrie du jeu.
- Diffusion authentifiée interne uniquement. Aucun canal public ni accès adverse :
  visibilité métier limitée à private/team/organization ; publication publique
  distincte de la vitrine P1. Les preuves et stratégies restent dans leurs API privées.
- Aucun worker principal lancé, notification externe envoyée, attribution réelle,
  donnée métier réelle créée, migration/seed principal ou push par l’agent.

Recette de déploiement : configurer Reverb côté serveur et VITE_REVERB_* côté
front puis reconstruire ; utiliser une file persistante et superviser broadcasts
(et default pour les recalculs). Contrôler d’abord les travaux en attente.
Les lectures fonctionnent avec le secours si Reverb n’est pas configuré.
À grande échelle : Reverb et workers séparés, cache/queue Redis partagés ; le
secours ne s’exécute que sur le match ouvert et la page visible. Les API conservent
leurs contrôles d’audience/tenant et les bornes existantes (100 parties/participants).
Après validation du push/CI et recette : prochain P0 COMP-001, suivi des compétitions
externes ; veto/sélection des cartes MATCH-008 reste P1.

Temps réel désactivé (`BROADCAST_CONNECTION=null`) : aucune tâche BroadcastEvent
n’est ajoutée à la file ; les scores et leur relecture restent disponibles.

Vérifications MATCH-007 : SQLite complet final 2149 tests, 2121 réussis,
28 exclusions PostgreSQL/Redis prévues, 10958 assertions ; PostgreSQL sélection CI
complète 1937 tests, 1935 réussis, 2 exclusions Redis prévues, 10291 assertions,
puis 20 cas temps réel finaux réussis, 166 assertions, après le garde-fou null/MFA.
Bases PostgreSQL temporaires supprimées dans finally. Frontend complet : 338 tests,
49 fichiers ; 7 scénarios navigateur sportifs réussis, dont protocole WebSocket
Pusher simulé pour le direct, passage pending/corrigé, doublons, tenant étranger,
révocation et désabonnement. Captures 320/375 px inspectées, sans débordement ni
interprétation HTML. PHPStan niveau 8, Pint/format:check, composer validate strict,
tsc/build, typage strict des nouveaux tests et oxlint réussis.
Serveurs temporaires arrêtés. La recette Reverb réelle + worker broadcasts reste
à effectuer en environnement isolé avant activation supervisée ; aucun worker ou
service temps réel principal n’a été démarré. Le WebSocket navigateur est simulé,
la signature réelle et la mise en file sont vérifiées par les tests backend.


## 18 octobre 2026 — UCG-COMP-001 : compétitions externes privées

Le module Competition possède `external_competitions`,
`external_competition_revisions` et `external_competition_documents`.
Controller + FormRequest + Policy délèguent aux services de dossiers/documents,
puis au contrat ExternalCompetitionRepository et à son implémentation Eloquent.
Le port public Teams ExternalCompetitionGames expose seulement les jeux actifs
et leurs libellés ; aucune lecture de tables Teams depuis Competition.

Le dossier fixe référence et identité du jeu. Chaque édition ajoute une révision
chiffrée avant la mise à jour protégée du parent, dans la même transaction que
l’audit transverse limité aux identifiants. Contacts, noms/organisateur, liens,
instantanés, motifs et textes documentaires utilisent les casts encrypted.
Région/saison restent filtrables. Aucun contenu privé dans des événements publics.
Les trois tables figurent dans l’inventaire de portabilité, avec colonnes explicites
et export brut préservant le chiffrement. Les clés composites imposent le tenant.

Les services verrouillent l’organisation puis le dossier et revérifient les droits.
Version du dossier et version documentaire sont indépendantes : une nouvelle
version texte exige les deux valeurs attendues mais ne modifie pas la version du
parent. Un règlement logique par dossier, notes complémentaires, séquence stricte,
identité/type documentaire immuables. Gardes SQL PostgreSQL/SQLite contre les
réécritures/suppressions ; le down refuse de perdre un historique non vide.
Archivage du parent ferme toute écriture. Les anciennes versions restent lisibles.

Les droits dédiés sports.competitions.read/write sont privilégiés (MFA),
la lecture exige aussi sports.read ; aucun grant implicite ni besoin sports.write.
Le téléchargement reste une route authentifiée vérifiant tenant et droits actuels,
renvoyant du texte en pièce jointe, private/no-store, nosniff et CSP sandbox.
URL externe de citation seulement, HTTPS sans credentials ; aucun fetch serveur.

Les requêtes paginent dossiers/jeux jusqu’à 25 éléments et historiques/documents
jusqu’à 10, sans relations chargées en boucle. Index tenant/statut/date/id et
parent/document/numéro ; dernier document choisi par anti-jointure indexée.
Bornes : 10 000 dossiers/tenant, 1000 révisions/dossier, 100 documents/dossier,
100 versions/document, 20 000 caractères/version et dix contacts. À plus grande
échelle, profiler les filtres région/saison et la pagination avant tout nouvel
index ou curseur ; aucun cache partagé de données privées.

Le panneau React est chargé à la demande selon les droits courants, démonté au
changement de contexte. Confirmation existante réutilisée, 422 conserve les champs,
409 recharge le contexte sans rejeu ; liens et textes échappés, téléchargement Blob
annulé au démontage. Les fichiers binaires et l’inscription restent COMP-002.
Nouvelle migration principale en attente ; aucun worker requis pour COMP-001.
MATCH-007 poussé/CI confirmé (`2c5d622`), recette Reverb réelle toujours à réaliser.

Vérifications COMP-001 : SQLite complet 2191 tests, 2160 réussis et 31 exclusions
attendues (concurrence PostgreSQL, Redis/scanner réel opt-in) ; 11 179 assertions.
Frontend complet 349 tests, lint/tsc/build verts ; 8 parcours Chrome à API simulée,
captures 320/375 px et absence de débordement. PHPStan niveau 8, Pint et
composer validate strict réussis. PostgreSQL : sélection CI de
1981 cas exécutée (1976 réussis, 2 exclusions Redis, 3 erreurs de démontage de
schéma), puis correctif de l’ordre de démontage/recréation validé avec 67 tests
réussis et 331 assertions, incluant les 42 cas COMP-001 et les trois courses réelles.
La suite SQLite complète ci-dessus est exécutée après ce correctif. ClamAV simulé
revalidé (35 cas avec les schémas), puis inclus dans SQLite complet réussi.
Bases PostgreSQL temporaires supprimées et serveurs navigateur arrêtés.
Le contrôle de clés JSON à jokers a été reproduit en échec puis corrigé/testé.
Aucun test du service Reverb réel dans cette tâche.


## 19 octobre 2026 — UCG-COMP-002 : suivi interne et rappels de participation

CompetitionRegistrationController/FormRequest/Policy délèguent à
CompetitionRegistrationService et CompetitionRegistrationRepository. Les écritures
PATCH sont des commandes versionnées : competition_version + expected_version
(null à l’initialisation), état complet borné et motif. Organisation puis parent
verrouillés, droits revérifiés, comparaison de la dernière révision et append dans
la même transaction que l’audit transverse sans contenu privé. Aucun parent
COMP-001 modifié : sa version évolue seulement via son propre service.

`competition_registration_revisions` conserve payload/reason chiffrés, numéro
séquentiel (1–1000), auteur/date et next_due_at pour le balayage. Uniquement
checked_at peut évoluer techniquement, sans altérer le contenu historique.
`competition_registration_reminders` est append-only, clé unique tenant/parent/
occurrence et FK composite vers la révision d’origine. Les deux tables figurent
dans l’inventaire de portabilité avec colonnes explicites et chiffrement conservé.
Le down refuse toute perte d’historique. Les tests de démontage des dépendances
retirent ces tables avant COMP-001 et les recréent ensuite ; anciennes migrations
inchangées, aucune reprise de données existantes.

Le port People CompetitionRegistrationPeople résout en lot les labels sportifs et
les comptes du tenant, sans ouvrir de données personnelles complètes. La dépendance
Competition → People est déclarée pour ce port public ; pas de lecture de tables
People depuis Competition. Nouvelles affectations revérifiées actives, libellés
historiques conservés. Références documentaires cherchées en une requête limitée
au parent/tenant ; identifiants UUID normalisés en minuscules.

Agrégat borné à 20 joueurs et 50 éléments. Les exigences âge/résidence/rang restent
textuelles ; les états externes sont renseignés, sans moteur d’éligibilité ni
transaction financière. Les pièces sont une checklist et un lien vers une version
texte privée COMP-001, pas un stockage de fichiers binaires. Roster approuvé modifié
= réévaluation explicite. Parent archivé = gel de toute écriture du suivi.

ProcessCompetitionRegistrationReminders réutilise TenantScheduledTask/TEN-006.
Balayage de 25 dernières révisions avec échéance à sept jours ou moins, ordre tournant
checked_at/id et budget 45 s vérifié entre dossiers (worker TEN-006 60 s). Parent verrouillé puis dernière
révision/statut relus ; résolution des comptes en lot et contrôle des droits/MFA
actuels de chaque destinataire distinct. Journal interne et checked_at transactionnels.
Clé de déduplication : parent/élément/échéance/personne/compte/seuil, indépendante
d’une édition de notes ou de la complétion du job. Le passage tardif émet seulement
le seuil urgent ; une nouvelle échéance ou attribution crée une nouvelle occurrence.
Les rappels déjà émis restent un historique personnel daté, pas une liste d’actions
courantes. Un échec est journalisé avec IDs/classe d’exception puis relancé via la
politique TEN-006. Aucun service de mail appelé pour envoyer un message.

Les droits privilégiés sports.registrations.read/write s’ajoutent à sports.read et
sports.competitions.read. Aucune attribution automatique, besoin sports.write ou
impersonation du propriétaire. UI React paginée pour références/historiques/rappels,
état agrégé borné, textes échappés, sources HTTPS, annulation des lectures au
changement de contexte ; erreurs 422 conservées et 409 sans nouvelle tentative.
Pas de cache partagé de contenu privé. À l’échelle, profiler le balayage et le
volume d’instantanés avant de modifier les index ou le découpage de cet agrégat.

Flag COMPETITION_REGISTRATION_REMINDERS_ENABLED désactivé par défaut ; aucune
activation de scheduler/worker ni migration principale effectuée dans ce lot.
Vérifications COMP-002 : SQLite complet 2229 tests, 2195 réussis, 34 exclusions
attendues (concurrence PostgreSQL, Redis et scanner réel opt-in), 11 386 assertions.
Sélection CI PostgreSQL complète : 2019 tests, 2017 réussis, deux exclusions Redis,
10 791 assertions ; revalidation finale ciblée : 72 réussis, 398 assertions,
comprenant les trois courses réelles (édition, archivage, rappels dédupliqués).
Frontend complet final : 356 tests/52 fichiers ; deux parcours Chrome à API simulée
COMP-001/COMP-002 réussis, captures 320/375 px sans débordement. Erreurs 422,
focus après retrait de ligne, conflit sans rejeu et secondes UTC vérifiés.
PHPStan niveau 8, Pint, Composer strict, lint, tsc et build verts. Bases PostgreSQL
temporaires supprimées et serveurs de recette arrêtés. Aucun changement de dépendance.
Les pièces restent une checklist avec références texte privées : pas d’upload binaire.

## 19 octobre 2026 — UCG-COMP-003 : matchs, classement et dotations

Le dossier externe propose « Matchs et classement » et, avec financial.read,
« Dotations de compétition ». Le classement est celui communiqué par
l’organisateur, consigné avec sa source et sa date de constat. Les matchs liés
restent soumis à leur audience actuelle, dans les vues courantes et anciennes :
un lecteur non habilité voit une référence opaque « Match à accès restreint ».
L’ouverture d’un résultat recontrôle l’accès et ne présente le score comme
approuvé que si sa validation est encore valable et aucun recalcul n’est en attente.
Les preuves du résultat ne sont pas recopiées dans ce panneau.

Deux flux de révisions indépendants conservent les changements motivés : sport et
awards. Les droits sportifs sont sports.read + sports.competitions.read, puis
sports.competitions.write aux écritures et au choix des matchs. Les dotations
ajoutent financial.read puis financial.write aux écritures ; aucun octroi
implicite au propriétaire/administrateur. Les montants ne figurent pas dans les
réponses sportives. MFA, CSRF, contexte tenant confirmé et vérification actuelle
des droits restent obligatoires pour les écritures.

La dotation annoncée et le montant reçu cumulé sont distincts. Chaque état indique
sa devise explicite (EUR/USD/GBP/CAD/CHF/JPY/AUD), sa source et sa date ; une réception
connue, même nulle, exige une date de réception. Les montants sont des entiers
positifs ou nuls en unités mineures, au plus 1 000 000 000 000. Le formulaire convertit
les chaînes décimales par arithmétique entière (deux décimales, aucune pour JPY).
Changer de devise efface les montants à ressaisir ; aucune conversion implicite,
paiement, écriture comptable ou publication n’est déclenché.

Migration additive `2026_10_19_005858_create_competition_outcome_tracking.php` :
competition_outcome_revisions et competition_outcome_matches, clés étrangères
composites tenant, chiffrement du contenu/motif, séquences bornées à 1000 par
flux, 20 matchs par révision. Historique immuable, suppression SQL interdite,
retour arrière refusé dès qu’il existe des données. Les liens exigent le même
jeu et le match conserve ce jeu après rattachement. L’inventaire de portabilité
inclut les deux tables avec leurs colonnes physiques et le contenu chiffré.

L’écriture verrouille organisation puis dossier ; elle vérifie les versions du
dossier, de la dernière révision et des nouveaux matchs. Historique, liens et
audit sont atomiques. Les choix et historiques sont paginés (10 par page au
maximum), les liens inclus dans un état sont bornés à 20. Leur projection utilise
une lecture groupée, et les scores sont consultés à la demande. L’archivage fige
les deux flux sans supprimer leur historique.

COMP-002 `67ddc29` était poussé et synchronisé à la reprise ; CI et migration
validées par l’utilisateur, migration constatée Ran batch 44. Cette livraison
reste locale : push/CI, sauvegarde et migration principale à valider par
l’utilisateur. Aucun migrate/seed principal, droit réel, donnée réelle ou worker
activé par l’agent. Prochain P0 proposé : STAT-001 après validation et recette.
La recette réelle Reverb/broadcasts et l’activation des rappels COMP-002 restent
séparées.

Vérifications COMP-003 : 34 nouveaux cas backend (31 exécutables sur SQLite,
3 courses entre processus PostgreSQL) et 16 nouveaux cas frontend. Les suites
ci-dessous couvrent tout le projet, pas seulement cette fonctionnalité.
SQLite complet : 2261 cas, 2224 réussis, 37 exclusions attendues, puis 143 cas
ciblés finaux réussis, incluant les forfaits/abandons et tous les retours arrière
concernés. Sélection CI PostgreSQL intégrale rejouée sur deux bases indépendantes :
2053 cas, 2051 réussis, 2 exclusions Redis attendues, 11032 assertions ; les 146
contrôles ciblés finaux étaient également verts. Toutes les bases jetables supprimées.
Frontend : 372 tests/53 fichiers réussis, puis les 16 cas du nouveau panneau rejoués
sur la version finale. Trois parcours Chrome distincts COMP-001/002/003 réussis ;
COMP-003 rejoué après finition, captures 320/375 px sans débordement, lecture
financière absente pour le profil sportif et texte malveillant échappé.
PHPStan niveau 8, Pint, Composer strict/format:check, oxlint, TypeScript (y compris
nouveaux tests) et build Vite verts.

Les premières passes ont révélé l’ordre à adapter dans d’anciens tests de retour
arrière (deux cas SQLite, trois PostgreSQL). Les dépendances sont maintenant
retirées/recréées dans l’ordre, sans modifier les anciennes migrations ni utiliser
CASCADE. Un écart de comptage global dans AuditJournalTest (46 contre 45, cache
array) n’a pas été reproduit dans les contrôles ciblés ni dans la seconde suite
SQLite complète ; aucun seuil ni code d’audit n’a été modifié.

## 19 octobre 2026 — UCG-STAT-001 : dictionnaire global des métriques

Le module Performance fournit le dictionnaire commun, séparé des futures valeurs
privées des organisations. Chaque métrique possède un code stable unique par jeu,
un nom, une définition, une unité, un niveau de mesure (manche/partie/match/séance/
période), une méthode d’agrégation, une formule documentaire versionnée et ses
conditions de disponibilité. Les références facultatives de mode, rôle et patch
proviennent du catalogue de jeux, avec leurs libellés conservés dans chaque révision.
Mesure objective/subjective et disponibilité manuelle/source à confirmer/indisponible
sont explicites. Un ratio impose un comportement pour le dénominateur nul ; aucune
moyenne de ratios ni exécution de code utilisateur n’est déclenchée.

Le catalogue est lisible par les comptes vérifiés via l’API de session. L’écriture
exige le superadmin plateforme et la MFA, y compris un recontrôle dans la transaction.
Les droits de propriétaire/administrateur tenant ne suffisent pas. Le formulaire
exige une confirmation globale distincte ; aucune donnée privée d’organisation ne
doit être saisie dans ces définitions communes. Les valeurs, observations, imports,
connecteurs et calculs effectifs restent dans les tickets STAT suivants.

Trois tables globales déclarées dans l’inventaire fermé : metric_definitions,
metric_revisions et metric_catalogue_state. Identité jeu/code immuable, historique
append-only avec acteur, motif, request_id et date ; compteur par métrique et
compteur global contrôlés par SQL sur PostgreSQL et SQLite. Une révision publie
atomiquement la version du catalogue ; aucune entrée partielle si le journal échoue.
Les écritures vérifient la version du dictionnaire, la version du catalogue de jeux
et, pour une correction, celle de la définition. Retrait/réactivation produisent
des révisions ; les anciennes formules restent accessibles par leur UUID exact.

Le dictionnaire est limité à 10 000 métriques et 1000 révisions par métrique.
Lectures et historiques paginés (10 maximum par page), références également
paginées ; projection d’une page en trois requêtes, sans N+1. La résolution de la
dernière version compare les numéros, sans MAX sur des UUID PostgreSQL. Les trois
tables sont exclues des exports privés tenant : aucune valeur privée n’y est stockée.
Le verrou global convient à la curation de définitions et ne servira pas aux
écritures massives d’observations privées de STAT-002.

Migration additive `2026_10_19_094551_create_metric_dictionary.php`, sans métrique
préremplie ni modification des anciennes migrations. Down refusé si un historique
existe. Les tests d’aller-retour du catalogue parent retirent/recréent ces nouvelles
dépendances dans l’ordre. La dépendance Performance → Identity est déclarée pour
les seuls contrats publics d’accès ; le catalogue de jeux est consommé via le
nouveau port public Teams MetricGameDirectory.

COMP-003 `045887d` était poussé et synchronisé à la reprise, migration constatée
Ran batch 45 ; push/CI/sauvegarde/migration validés par l’utilisateur. STAT-001 reste
local, nouvelle migration principale en attente. Aucun migrate/seed principal,
droit réel, métrique réelle, valeur privée, fournisseur ou worker activé. Suite
proposée après push/CI, sauvegarde/migration et recette : STAT-002. Les recettes
réelles Reverb/broadcasts et rappels COMP-002 restent distinctes.

Validations locales STAT-001 : 28 cas backend dédiés au dictionnaire et deux cas
PostgreSQL de concurrence entre deux superadmins ; sept tests frontend et un
parcours Chrome (création/correction/historique/lecteur, 320 et 375 px) rejoué après
les derniers ajustements. Les grands totaux suivants couvrent tout le projet,
pas seulement ce ticket : suite SQLite complète, 2252 réussis et 39 ignorés sur
2291 cas ; sélection CI PostgreSQL complète en deux bases isolées, 2079 réussis
et deux ignorés Redis sur 2081 cas ; frontend complet, 379 réussis dans 54 fichiers.
Ces suites complètes précèdent les deux derniers cas sur le niveau de mesure ;
la vérification finale ciblée inclut ces ajouts et les schémas parents : 53/53 sur
SQLite et 55/55 sur PostgreSQL, concurrence comprise. Toutes les bases PostgreSQL
de test ont été supprimées. PHPStan sans erreur, Pint, validation Composer,
TypeScript, lint et build frontend réussis. Les captures mobiles ont été relues.
La CI distante de STAT-001 reste à exécuter après le push utilisateur.

## UCG-STAT-002 — observations statistiques privées (19 octobre 2026)

Livraison locale sur `staging` : saisie manuelle d’une observation pour un joueur,
avec version exacte de métrique, période, date de constat, source et taille
explicite de l’échantillon. Sources distinctes : officiel, scrim, entraînement et
évaluation humaine ; cette dernière exige une métrique déclarée subjective et
peut concerner une partie ou un match visible, officiel ou scrim. Les
sources officielles restent des relevés manuels, sans certification automatique.
Contexte jeu/mode/rôle/patch vérifié par les ports publics Teams ; région, saison,
carte et adversaire sont des libellés documentaires privés. Une métrique au niveau
partie/manche exige une partie existante du même match ; le niveau match exige
un match, la manche son numéro et la séance une référence documentaire.

Deux tables tenant : `statistical_observations` (provenance et contexte immuables)
et `observation_revisions` (valeur, disponibilité, note, état, motif, acteur,
request_id, date et version précédente). Valeur décimale transportée en chaîne,
sans flottant : 12 chiffres entiers et six décimales maximum ; zéro reste une
valeur disponible. Une absence exige valeur nulle et motif explicite. Les champs
privés textuels et valeurs sont chiffrés au repos. Les corrections, invalidations
et réactivations ajoutent une version ; aucune suppression ou réécriture SQL de
l’historique. La source importée d’origine est conservée même après correction ;
aucun importeur n’est activé dans STAT-002. Pour corriger un joueur, une période
ou la source elle-même : invalider l’observation puis en saisir une nouvelle.

Droits organisation explicites, sous MFA : `sports.read` +
`sports.statistics.read` pour lire ; en plus `sports.write` +
`sports.statistics.write` pour saisir ; `sports.statistics.correct` pour corriger.
Ni propriétaire, ni administrateur, ni rôle technique plateforme ne reçoit ces
droits implicitement. Pas de grant réel ni de nouveau rôle prédéfini par ce lot.
Les droits d’équipe seuls ne donnent pas accès au registre d’organisation.
Une observation liée à un match suit son audience ordinaire actuelle, y compris
pour les listes, historiques et versions exactes ; aucun accès de gestion des
matchs ajouté implicitement. Les références hors tenant ou hors audience renvoient
404. Le pseudonyme est résolu à la lecture, sans recopier l’identité administrative.

Écritures sous confirmation du tenant actif et contrôle de révision de session.
Réautorisation dans la transaction après le verrou métier tenant ; contrôle de
version optimiste et motif obligatoire pour corriger. Un conflit exige un
rechargement, sans rejeu automatique. Identité, première révision et audit minimal
sont atomiques : aucun contenu privé n’est copié dans le journal transverse.
Les séquences et références composites sont protégées SQL sur les deux moteurs.
Le rattachement aux parties ajoute l’index unique composite `so_part_reference`
sur `match_game_instances`, sans modification d’une ancienne migration.

Listes, choix et historique paginés, dix éléments maximum par page (historique
par défaut cinq), 1000 versions maximum par observation. Audience match appliquée
par sous-requête via le port public Competition `ObservationMatchDirectory`, sans
charger tous les identifiants en mémoire. Chargement anticipé de la dernière
révision et résolution groupée des pseudonymes : nombre de requêtes stable quand
une page passe d’une à dix observations. Index tenant/joueur/période et
tenant/métrique/période. Les deux tables et leurs colonnes sont déclarées dans le
catalogue fermé des exports privés ; les valeurs y restent chiffrées. Les verrous
globaux du dictionnaire sont conservés pour la saisie manuelle contrôlée ; le
pipeline d’import de STAT-003 devra traiter les lots avec des limites explicites,
pas exécuter une transaction longue contenant toutes les lignes.

Migration additive `2026_10_19_113618_create_statistical_observations.php`, sans
reprise ni seed principal ; down refuse tout historique existant. À appliquer par
l’utilisateur après push/CI et sauvegarde, puis recette lecteur/saisisseur/correcteur,
révocation, changement d’organisation et audience match. STAT-001 `cbeba13` était
synchronisé au démarrage et sa migration constatée Ran batch 46 ; push/CI/migration
validés par l’utilisateur. Aucun push, migrate principal, donnée ou permission
réelle, connecteur, worker ou fournisseur activé par l’agent. Prochain P0 proposé
après validation : STAT-003, import statistique privé avec prévisualisation et
confirmation. Les calculs effectifs et agrégats restent STAT-004.

Validations STAT-002 : 34 cas backend du registre, plus un cas PostgreSQL avec deux
correcteurs dans des processus distincts (une seule correction acceptée, l’autre
reçoit OBSERVATION_STALE). Vérification finale dédiée : 34/34 SQLite et 35/35
PostgreSQL, y compris les évaluations humaines liées à une partie et les séances,
la validation des choix et leurs liens de pagination. Six tests frontend dédiés ;
suite frontend complète : 385 réussis dans 55 fichiers. Un parcours Chrome réel,
avec API simulée, a été rejoué : création zéro, correction en indisponibilité,
ancienne valeur conservée, accès lecteur et absence de débordement à 320/375 px.
Les captures ont été relues. Typage de l’application et des tests, lint, build,
PHPStan (zéro erreur), Pint et validation Composer réussis ; dépendances inchangées.

Les nombres suivants couvrent tout le projet, pas seulement les nouveaux cas :
suite SQLite complète, 2328 cas dont 2288 réussis et 40 ignorés (tests réservés
aux autres environnements). Cette exécution précède les derniers ajustements de
filtrage tenant, pagination et évaluation subjective, tous revérifiés dans les
34 cas dédiés ci-dessus. Les tests de démontage/recréation des schémas parents
ont été adaptés aux nouvelles dépendances ; cinq erreurs d’ordre détectées dans
la première sélection générale PostgreSQL ont motivé leur remise en ordre, sans
modification des anciennes migrations.

Sélection CI PostgreSQL validée en lots : 2082 cas hors classes de concurrence,
dont 2080 réussis et deux ignorés Redis ; 36 cas de concurrence réussis issus de
la sélection complète, sans erreur parmi ces cas. La course STAT-002 a également
été rejouée avec le code final dans les 35 contrôles dédiés. Au total, la sélection
couvre 2118 exécutions, dont 2116 réussies et deux ignorées. Les cinq erreurs de
schéma de la première passe sont corrigées et passent dans le lot général repris.
Les schémas concernés et le registre ont aussi passé 54 contrôles SQLite ciblés.
Toutes les bases PostgreSQL temporaires ont été supprimées. CI distante, push,
sauvegarde, migration et recette avec habilitations réelles restent à réaliser
par l’utilisateur ; aucun de ces résultats locaux ne vaut validation de la CI distante.

## UCG-STAT-003A — dépôt CSV et prévisualisation (19 octobre 2026)

STAT-002 `aa6e52e` est poussé, CI et migration validées par l’utilisateur ;
migration constatée Ran batch 47 à la reprise. Ce lot ajoute uniquement le dépôt
privé et l’aperçu de CSV : UTF-8, virgule ou point-virgule, 1 Mio et 100 lignes
maximum, avec quotas tenant également appliqués. Joueurs et métriques utilisent
des identifiants et versions explicites ; erreurs et doublons du fichier sont
affichés par ligne source. Aucun rapprochement approximatif ni création de joueur.
Les valeurs sont des décimales exactes, et indisponible reste distinct de zéro.

Fichier et aperçu chiffrés, réservés au déposant dans son organisation ; l’audience
actuelle des matchs reste appliquée. Nouvelle permission explicite
`sports.statistics.import` en plus de `sports.read`, `sports.write`,
`sports.statistics.read` et `sports.statistics.write`, sous MFA et tenant confirmé.
Aucun droit réel n’est attribué par l’agent. Listes et lignes paginées par dix,
rendu React échappé, fichier conservé sur 422, aucun rejeu sur 409.

Migration additive `2026_10_19_143154_create_statistical_import_previews.php` :
FK artefact du même tenant, compteurs bornés, historique immuable, down refusé
si non vide. Appliquer après push → CI → sauvegarde, puis recette utilisateur.
Aucun push ni migrate/seed principal exécuté par l’agent. Aucun worker nécessaire.

**Aucune observation créée et aucun bouton de traitement dans STAT-003A.**
STAT-003B reste à livrer pour confirmation, revalidation, idempotence des
observations incluant l’organisation, traitement et reprise. Le statut « valide
à la préparation » décrit un aperçu daté, pas un import effectué.

Le service StatisticalImportService orchestre la préparation, le repository
StatisticalImportRepository isole la persistance, et contrôleur/FormRequest/policy/
Resource suivent les conventions du module. Le parseur strict de People est extrait
dans Support/Files/CsvParser, avec façade conservant son contrat et ses limites.
ObservationService partage la validation de valeurs et de références entre saisie
et méthode draft ; cette méthode ne persiste rien. Les transactions manuelles
gardent leur provenance, verrous et audit. L’importeur n’appelle aucun mécanisme de création d’observations.

TenantArtifacts conserve le fichier chiffré sous namespace privé avec réservation
durable et quotas. La table statistical_imports contient un snapshot chiffré borné,
des compteurs et un index org/acteur/id. L’empreinte HMAC inclut tenant, acteur,
séparateur et octets exacts ; une relance identique réutilise cet aperçu uniquement.
Après une course de dépôt ou un rollback, compensation du seul nouvel artefact
inutilisé ; les échecs de nettoyage sont signalés. La portabilité opérateur garde
le ciphertext et n’expose jamais ces données dans le dictionnaire global.

Validation synchrone limitée à 100 lignes/1 Mio sous verrou tenant, puis catalogue
jeux et dictionnaire ; aucun appel réseau ni job. Les résumés ne déchiffrent pas
les snapshots et ne font aucune requête par aperçu ; les pseudonymes du détail
sont résolus par lot. Le détail déchiffre au maximum un snapshot borné, et recontrôle
les matchs résolus avant exposition. Les volumes massifs relèveront des lots de B,
sans relever ce plafond arbitrairement. Une nouvelle vérification des références,
des droits et des versions sera indispensable avant toute acquisition future.

Validation STAT-003A : 21 nouveaux cas backend d’import, six nouveaux tests
frontend et un parcours Chrome réel avec API simulée (dépôt multipart confirmé,
références, zéro, erreurs, absence de traitement, lecture sans permission refusée,
aucun débordement à 320/375 px ; captures relues). Vérification backend ciblée
finale : 92/92 SQLite avec les architectures ; sélection PostgreSQL dédiée :
137/137 dans une base isolée supprimée, incluant les schémas parents, les imports
People, les observations et la concurrence de correction existante. Le workflow
CI inclut le nouveau test d’import dans la sélection PostgreSQL.

Les nombres suivants couvrent les suites du projet : SQLite complète finale,
2349 cas dont 2309 réussis et 40 ignorés ; frontend complet, 391 réussis dans
56 fichiers. Les 12 cas frontend des observations/imports ont été rejoués après
les derniers ajustements de libellés. Un ancien test du protocole antivirus simulé
avait produit une erreur de décodage lors de la première passe SQLite ; ses dix
cas ont réussi à la relance, puis la suite complète a réussi sans changement
de ce code. PHPStan zéro erreur, Pint, typage application/tests/navigateur, lint,
build et validation Composer réussis. Dépendances inchangées ; CI distante
à exécuter après push utilisateur.

## Correctif CI STAT-003A — ordre de démontage PostgreSQL

La CI distante a révélé deux tests de réversibilité oubliés dans la sélection
locale : PersonSchemaTest et TenantStorageSchemaTest. Reproduction locale :
16 réussis et deux erreurs de dépendance sur artifacts_tenant_id_unique.
Les tests démontaient l’import People (qui possède cet index) avant la table
statistical_imports, dont la FK composite en dépend.

Correction limitée aux tests : démonter statistical_imports avant l’import
People, puis la recréer après celui-ci. Vérifier également que la table et
l’isolation tenant sont restaurées. Aucun CASCADE, ancienne migration modifiée,
nouvelle migration, changement applicatif ou donnée réelle touchée.
Les quatre tests qui démontent cet index ont tous été revus.

Contrôles ciblés après correction : 54/54 PostgreSQL en base jetable supprimée,
54/54 SQLite ; Pint et PHPStan sans erreur.

Sélection PostgreSQL complète du workflow CI rejouée localement après correction :
2139 cas, 2137 réussis, deux ignorés, aucun échec et 11794 assertions.
La base PostgreSQL jetable a été supprimée. La CI distante reste à relancer après
push du correctif ; aucune nouvelle migration nécessaire pour ce correctif.

### Calculs statistiques — UCG-STAT-004

Le module Performance ajoute un calculateur décimal à opérations fermées, sans
évaluation de formule textuelle. Le catalogue fige les versions des composants
additifs des ratios. StatisticalCalculationService valide un échantillon fixe et
privé ; le Repository charge les observations courantes en bloc et stocke racine,
liens de dépendance et résultats immuables chiffrés. Les routes utilisent les
permissions existantes des observations avec MFA et confirmation tenant.

CalculateStatistics travaille sur reports, hors verrou pour le calcul puis dans
une transaction courte pour publier après contrôle de génération. Les corrections
d’observation invalident les dépendances dans leur transaction ; après commit,
RecalculateObservationStatistics pagine 25 dépendances et réenfile chaque calcul
sous l’identité du demandeur initial. Les droits et audiences actuels sont relus.
Une panne conserve un état stale/queued/failed récupérable explicitement, sans
exposer un résultat obsolète comme courant. La liste et l’historique sont paginés ;
sélections limitées à 1 000 sources, 100 calculs par acteur/tenant, 1 000 révisions.

Migration additive 2026_10_19_183908 après les migrations STAT-003A/B. Les tests de
réversibilité des schémas parents démontent d’abord les calculs, puis les recréent
après leurs dépendances ; aucun CASCADE ni modification des migrations historiques.
Les tables sont inscrites dans l’inventaire fermé de portabilité du tenant.
Le réglage STATISTICAL_CALCULATIONS_ENABLED est indépendant et false par défaut.
Le frontend propose sélection, confirmation, suivi, détail, historique et recalcul ;
les profils/comparaisons de STAT-005 restent distincts.

### Profils et comparaisons — UCG-STAT-005

Le module Performance expose deux GET statistical-profiles et
statistical-profiles/people. StatisticalProfileService orchestre validation,
lecture des pseudonymes par le contrat public People, versions du dictionnaire,
audience actuelle des matchs via le contrat Competition, puis projection.
StatisticalProfileRepository borne les candidats indexés et charge leurs dernières
révisions en bloc ; les filtres sur contexte/libellé chiffrés suivent le contrôle
de quota. Aucun modèle d’un autre module importé directement.

StatisticalProfileProjector partitionne les contextes, réutilise le moteur décimal
STAT-004 et produit groupes, chronologie et comparaisons paginées. Les empreintes
de couverture exigent le même ensemble d’intervalles ; périodes superposées et
origines répétées restent indisponibles. La lecture ne persiste ni calcul ni job.
Les profils sont partagés aux lecteurs statistiques du tenant et filtrés par
audience ; les calculs enregistrés restent privés au demandeur initial.

Pas de nouvelle table/migration, cache privé, index ajouté sans mesure ou
modification de la portabilité. 1 000 candidats par joueur, deux joueurs et
366 jours inclusifs maximum ; refus avant déchiffrement si dépassement. Le nombre
de requêtes est constant quand le nombre d’observations croît dans cette borne.
Les snapshots historiques du dictionnaire restent sélectionnables ; une version
n’est jamais remplacée implicitement par la courante. Le frontend invalide son
résultat sur changement de filtre/tenant/compte et annule les lectures obsolètes.

### Tableaux de bord par rôle — UCG-REP-001

Reporting possède le catalogue de vues/cartes, DashboardService, FormRequest,
Gate/Policy, Resource et deux routes GET versionnées. Il ne possède aucune table,
repository de stockage, job ou cache. Les données restent dans leurs modules.
Les contrats publics `{People,Scheduling,Competition,Contracts,Partnerships,Performance}Dashboard`
sont implémentés et liés par chaque fournisseur du module source ; Reporting
orchestre ces contrats, sans importer leurs modèles ni leurs repositories.
`DashboardFeed` et `DashboardWindow` sont des DTO neutres dans Support/Data.

Les adaptateurs réutilisent les services métier et leurs autorisations/audiences.
Trois nouvelles lectures paginées sont portées par les repositories propriétaires :
matchs visibles chevauchant la fenêtre, contrats signés/actifs à échéance et
dernière échéance persistée d’inscription active. Les sources chargent leurs
relations en bloc ou projettent des colonnes ; aucun accès relationnel par ligne
supplémentaire. L’accès en écriture ne transforme pas une vue de coach en lecture
de tous les matchs privés. Les calculs sont ceux du demandeur, sans création.

Permissions de vue explicites et limitées à l’organisation, puis permissions
métier cumulatives ; aucun nom de rôle ne déclenche d’élévation. Politique liée
à l’acteur du contexte, MFA et middleware tenant communs. Une carte indisponible
ou en erreur ne donne aucun zéro de substitution. Projections minimales, logs
sans charge privée et réponses no-store. Pas de mutation du schéma, des données
réelles, des workers ou de l’inventaire de portabilité. Les COUNT paginés restent
en direct : les agrégats coûteux et leur fraîcheur relèvent de REP-002.

### Indicateurs différés — UCG-REP-002

Reporting possède désormais DashboardIndicatorService/Processor, son contrat de
queue, un TenantJob sur `reports`, et un repository de matérialisation SQL.
Les endpoints de lecture/demande réutilisent la Policy REP-001 avec FormRequest,
Resource et confirmation du contexte pour le POST. Les permissions métier sont
réévaluées via DashboardService et les contrats publics des modules sources.
Les six agrégats organisationnels ne chargent que des COUNT/GROUP BY dans leurs
repositories propriétaires, sans déchiffrement de noms ou chargement de collections.
Les vues à audience individuelle restent sur leurs services de lecture REP-001.

Migration additive `2026_10_20_184653` : dashboard_source_versions (clé tenant/carte),
dashboard_indicators (sélection privée, génération de demande, état et clé isolée),
dashboard_indicator_revisions (moteur, version de source, résultat chiffré,
évaluation/expiration et request_id). FKs composites tenant, unicités tenant/acteur,
index de consultation et gardes d’identité/historique immuable. L’inventaire de
portabilité couvre les trois tables et leurs colonnes explicites. La migration
refuse un rollback destructeur si des sélections existent ; aucun backfill massif.

Les déclencheurs SQL des tables people, person_imports, contracts,
sponsorship_campaigns, sponsorship_deliverables, external_competitions et
competition_registration_revisions incrémentent les versions des seules cartes
concernées dans le tenant source, transactionnellement. Ils couvrent bulk SQL
et rollback, sans dépendance inversée des modules vers Reporting. Invalidation
conservatrice par carte, jamais lancement massif de jobs depuis les écritures.

Le calcul agrégé se fait hors verrou ; la publication prend le verrou métier du
tenant puis celui de la sélection et de sa version de source. Tout changement
entre lecture et publication empêche la publication. Numéro de révision et
génération uniques protègent les rejeux/concurrences. Les droits et le réglage
d’activation sont revérifiés au worker et à la publication. Les demandes et les
publications écrivent un audit de métadonnées fermé, sans valeurs de comptage.

Les GET ne recalculent pas, ne mettent pas en file et ne recompteront pas les
sources ; ils lisent le cache SQL et sa génération. Aucun Cache::flexible ni
fallback synchrone. Frontend : agrégat/fraîcheur au premier plan, détails actuels
sur action, confirmation du POST, erreurs/reprise, historique paginé, dates et
validité maximale. L’expiration côté client tient compte de checked_at renvoyé
par le serveur et masque la valeur même sans nouvelle requête.

Le calcul est désactivé par défaut ; activation réelle réservée à l’opérateur
après migration et vérification de la queue. Les COUNT/GROUP BY restent sur les
workers avec les filtres/index propriétaires existants. Avant hausse de volume,
mesurer les plans SQL et la contention des versions par tenant/carte ; envisager
une granularité de versions affinée ou un vrai outbox si le recalcul doit devenir
automatique. Aucun agrégat de progression sportive ni budget sans source définie.


### Recherche tenant REP-003

Reporting ne lit pas directement les modèles des autres modules : contrats publics
PeopleSearch, TeamSearch, CompetitionSearch et ContractDocumentSearch, services
propriétaires réautorisant la lecture, repositories existants pour les requêtes.
SearchPage est un DTO de support commun pour le parcours borné des candidats
(100 examinés + une sentinelle, au plus 20 correspondances), sans persistance ni
dépendance inverse vers Reporting. Les prédicats tenant, audience des matchs et
classification financière des contrats sont appliqués en SQL avant déchiffrement.
Les titres documentaires courants sont consultables, le contenu documentaire ne
fait pas partie de cette recherche. Le curseur chiffré est lié à l’organisation,
acteur, catégorie, préfixe et taille ; il ne remplace pas la réautorisation.

Aucun index central contenant des données chiffrées déchiffrées, aucune migration,
aucune invalidation/cache différé à exploiter. Les lectures suivent les sources
courantes, avec les limites d’un parcours sans snapshot. À grand volume, profiler
les plans des candidats avant de retenir une stratégie d’indexation préservant
classification/tenants/droits. La borne applicative ne garantit pas un coût constant
du plan SQL ni d’un parcours exhaustif d’un gros tenant.

### Séances et présences ACA-002

Academy dépend désormais du contrat public `AcademySchedule` de Scheduling, avec
DTO minimal `AcademyActivity`. Scheduling conserve ses modèles et requêtes dans
son propre repository ; aucune dépendance inverse vers Academy. Le port ne retourne
que les activités Académie partagées du tenant, autorisées par `calendar.read`,
avec identifiant, version, titre, période et fuseau. Choix paginés sur 93 jours au
plus ; résolution groupée d'au plus 100 identifiants pour une page de séances.

Academy possède `AcademySessionService`, `AcademyAttendanceService`, les gardes
communes `AcademySessionAccess`, le journal `AcademySessionJournal`, un repository,
FormRequest, Policy, Resources et contrôleur minces. Chaque commande revalide
permissions et contexte, verrouille compte/organisation puis les ressources du
cycle, vérifie les versions et écrit métier, révision chiffrée et audit minimal
dans la même transaction. Un conflit concurrent produit un refus explicite.
La présence a sa permission distincte ; aucune autorisation issue d'une affectation.

La migration additive ACA-002 crée trois tables isolées par organisation et cycle,
avec FK composites et directes tenant, index et unicités. Gardes SQL SQLite/PG :
identités immuables, annulation définitive, période/source/responsable figés après
présence, révisions append-only. Le rollback refuse les données existantes.
Les quatre tests de démontage/rejeu des parents retirent ACA-002 avant Academy,
People ou Calendar et le restaurent après eux ; aucun DROP CASCADE.
L'inventaire fermé de portabilité inclut les trois tables et leurs colonnes.

Les titres, exercices, comptes rendus et snapshots sont chiffrés au repos. Les
lectures résolvent calendrier et pseudonymes en bloc par page et masquent les sources
indisponibles. Pas de cache durable de droits ni de génération asynchrone à activer.
Le verrou organisation sérialise les écritures : profiler sa contention et les
plans des index avec la volumétrie cible avant d'envisager une granularité plus fine.
Le frontend charge le panneau sur ouverture, pagine les listes, confirme les
mutations et traite les versions du calendrier périmées avant la saisie.

### Objectifs et feedback ACA-003

Academy possède AcademyObjective, AcademyFeedback et AcademyCoachingRevision,
un repository de persistance, AcademyCoachingService, FormRequest, Policy et
Resources distincts. Le contrat public existant AcademyPeopleDirectory s’étend
avec viewer(), autorisé par academy.self.read, qui résout seulement la personne
active liée à l’acteur dans le tenant. People garde les requêtes et ses modèles ;
aucune nouvelle dépendance intermodule ni accès à ses données administratives.

Les mutations prennent le verrou métier compte/organisation puis les ressources du
cycle. Responsable vérifié avant transaction et revérifié sous verrou ; validations
et droits réévalués, version UUID obligatoire en correction. Écriture métier,
révision chiffrée et audit minimal sont atomiques. Les conflits de version sont
explicites. Une clôture limitée au statut et au motif n’exige pas une affectation
encore active et n’autorise ni changement de destinataire ni modification de contenu.

La visibilité est imposée par les requêtes du repository, avant déchiffrement et
pagination. Un objectif est individuel (inscription destinataire immuable) ou
collectif (étudiants courants du cycle). Les consultations personnelles résolvent
au plus 100 inscriptions candidates plus sentinelle, bornées en UTC puis évaluées
dans le fuseau du cycle ; dépassement refusé. Le filtre corrèle organisation,
cycle et inscription avec EXISTS. Les pages staff eager-loadent destinataire et
responsable et résolvent leurs pseudonymes par lots de 100.

Le feedback partagé et les notes ont des routes et permissions distinctes ; la
classification est immuable, les notes demandent une justification chiffrée.
Les historiques filtrent les révisions internes en SQL sans en exposer le nombre.
La projection personnelle est en lecture seule, limitée aux retours partagés
actifs ; elle ne possède pas de route d’historique ou de notes et ne charge pas
les identifiants d’inscription dans sa réponse. Aucun cache durable d’autorisations.

Migration additive : trois tables avec FK directes tenant et composites cycle,
index des pages, gardes SQL d’identité, de classification, d’états terminaux et
d’historique append-only. Rollback protégé et inventaire de portabilité étendu.
Rejeux Academy, Calendar, People, Tenancy, GameCatalogue (deux chemins) et Team
adaptés ensemble avant validation PostgreSQL complète. La CI inclut les trois
classes ACA-003 et les deux nouvelles courses à processus indépendants.

Frontend : panneau staff dans le cycle et panneau personnel indépendant, chargés
sur ouverture, sélecteurs paginés avec sélection conservée, confirmations explicites
de publication, erreurs/reprises et historique borné. Aucun traitement planifié,
notification ou métrique automatique. Les écritures restent sérialisées par tenant :
mesurer contention et plans SQL à la volumétrie cible avant de modifier ce verrou.
ACA-004 conserve ressources pédagogiques, évaluations et progression sportive.

### UCG-OPS-001 — Configuration des environnements

Le diagnostic console `ops:check-environment` délègue à `EnvironmentReadiness`,
qui dépend uniquement du contrat de configuration Laravel. Il lit la configuration
effective, y compris si elle est cachée, sans accès DB, Redis ou stockage. Les
messages sont fixes et ne reflètent aucune valeur, même dans le rapport JSON.
Les déclarations `operations.*` n'attestent pas une provenance ni une isolation.
Le socle documenté cible PostgreSQL, Redis et un volume privé local par profil ;
un stockage objet ou des URL de connexion demanderont une adaptation explicite.

Les gabarits staging/production restent inertes, sans clés ni domaines résolvables.
Horizon possède un profil staging borné, sans lancement de processus. La validation
Vite s'applique lors du chargement de sa configuration, avec liste fermée des noms
de variables publiques et contrôles HTTPS/Reverb pour les profils distants explicites.
Les procédures de provisioning et rotation sont dans le README. OPS-001 reste ouvert
jusqu'à la vérification des ressources réelles ; aucun déploiement n'est effectué.

### UCG-QA-003 — Premier parcours avec API réelle

Une suite Playwright distincte utilise un lanceur Node qui crée une base SQLite
et un stockage temporaires, fournit un environnement fermé puis démarre PHP et
Vite directement sur des ports locaux disponibles. Les entrées PHP résident dans
`tests/Fixtures`, vérifient profil/base/nonce/cache avant tout bootstrap et utilisent
un `.env` vide et un chemin de stockage explicite. Aucune route de fixture ni
exception d'authentification n'est ajoutée au runtime public. Les sessions sont
persistantes, le cache et les verrous utilisent la base temporaire ; MFA et CSRF
restent actifs dans le serveur HTTP. Le dossier est supprimé après arrêt des processus.

Le premier parcours détecte aussi la désérialisation interdite des modèles du
catalogue global dans le cache persistant. `EffectiveGameCatalogue` conserve
désormais des attributs simples sous `game-catalogue:v2:{revision}` et réhydrate
les modèles sans requête supplémentaire. La révision, la durée de cinq minutes,
la lecture directe en transaction et les restrictions d'organisation sont conservées.
Le refus de désérialiser des classes reste actif ; les caches v1 expirent normalement.

Le deuxième parcours réel couvre personne, équipes et roster : transfert avec
changement de rôle puis clôture, historique et persistance, données étrangères et
droits en lecture seule. Chaque scénario dispose d'un compte MFA et de deux
organisations propres ; un cinquième tenant reste inaccessible aux deux comptes.
Le générateur TOTP et la restriction des origines sont partagés comme utilitaires
de test. Aucun service, endpoint, permission réelle ou schéma métier modifié.

Le troisième parcours réel couvre le calendrier et les disponibilités : compte
calendar.read/write et availability.read dans une organisation dédiée, lecture
seule dans une autre, sans calendar.publish ni droit d'exception de conflit.
L'indisponibilité fictive bloque l'écriture ; son commentaire reste exclu du
rapport. Le scénario déplace le créneau, ajoute un participant puis annule
l'activité, vérifie les versions, l'historique et l'affichage UTC/Asia-Tokyo.
Le lanceur conserve ses mêmes garde-fous pour les sept tenants et trois comptes
de scénario ; aucun worker ni notification externe activé.

Le quatrième parcours ajoute deux tenants propres aux matchs et un approbateur
indépendant. Le match privé appartient au compte créateur et reste accessible à
l'approbateur en tant que responsable explicitement lié à une personne. Les deux
équipes et leurs compositions finalisées sont des prérequis de fixture ; création
du match, affectations, transitions, scores, soumissions et décisions passent par
les formulaires et les endpoints publics réels.

La file du lanceur utilise désormais le driver database et la même SQLite
temporaire. Une entrée `tests/Fixtures/browser-worker.php` réutilise le bootstrap
gardé, refuse une connexion de file externe et exécute `queue:work database`
uniquement sur `default`, pendant dix minutes au maximum. Le lanceur attend son
marqueur de disponibilité, conserve son processus enfant et l'arrête avant le
nettoyage, aussi en cas d'échec. Aucun processus du poste n'est réutilisé. Le test
attend la correction appliquée via l'API, puis vérifie l'inversion du vainqueur,
la contestation du résultat et la nouvelle validation indépendante. Les scores
sources restent scellés ; les valeurs corrigées et les décisions sont conservées.
Le broadcast reste journalisé ; ce parcours ne valide pas Reverb ni les workers
de recette ou de production.

Le cinquième parcours réel ajoute les contrats : deux tenants propres, un
préparateur, un approbateur sans financial.write et un gestionnaire administratif
sans aucun droit financier. Chaque compte passe par une connexion et un MFA
réels dans un contexte navigateur distinct. La fixture fournit uniquement les
personnes et les dossiers étrangers ; le contrat testé, sa partie, son obligation,
ses conditions financières et ses décisions sont créés via les endpoints publics.

Le test vérifie le gel après approbation, les versions du contrat et des conditions,
la confidentialité des réponses ordinaires et les refus d'accès direct aux finances.
Le cycle continue jusqu'à la signature déclarée, l'activation, la résiliation et
l'archivage après accomplissement de l'obligation. Les conditions restent lisibles
par un compte habilité après rechargement et archivage, sans formulaire d'écriture.
Les onze tenants et huit comptes de scénario/intervenants restent dans la même
SQLite et le stockage temporaires. Aucun changement de schéma ou de code métier ;
documents, analyse antivirus, avenants et rappels ne sont pas exercés par ce parcours.
