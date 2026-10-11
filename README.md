# UCG Platform

Plateforme de gestion esports multi-tenant d'Ultra Cyber Game.

## Structure

Le ticket `UCG-IAM-003` fournit désormais les rôles configurables et leurs
affectations par organisation. Voir [le contrat API](docs/conventions-api.md)
et [l’état de livraison](docs/etat-projet.md).

- `back/` : API Laravel 13 sous PHP 8.5.
- `front/` : application React 19, TypeScript et Vite 8.
- `docs/` : cahier des charges et backlog technique.

Le dépôt Git est unique à la racine. `back/` et `front/` ne sont pas des dépôts indépendants.

## Prérequis

- PHP 8.3 ou supérieur, PHP 8.5 recommandé, avec `pdo_pgsql`, `pdo_sqlite` et `sqlite3` ;
- Composer 2 ;
- PostgreSQL 17 ;
- Redis 7 ou supérieur pour Horizon en recette/production ;
- Node.js 22.12 ou supérieur, Node.js 24 LTS recommandé ;
- npm 10 ou supérieur.

Horizon nécessite les extensions Unix `pcntl` et `posix`. Il doit être exécuté
sur Linux, WSL ou dans l’environnement de production, pas directement dans PHP
pour Windows. Le développement Windows reste fonctionnel avec la queue
`database` et le cache `database`.

## Démarrer l'API

```powershell
cd back
Copy-Item .env.example .env
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
php artisan key:generate
php artisan serve
```

La base locale attendue est `ucg_platform`. Renseigner `DB_USERNAME` et `DB_PASSWORD` dans `back/.env`, puis exécuter :

```powershell
php artisan migrate --seed
```

Le seed local crée uniquement en environnement `local` un compte de développement :
`admin@ucg.local` / `password`. Il doit être remplacé par un vrai parcours d’invitation
avant toute mise en production.

Il initialise également UCG comme organisation pilote, rattachée à ce compte.
Relancer le seed ne remplace ni un mot de passe existant ni les paramètres du pilote.
Les valeurs `UTC`, `fr` et `FR` sont des valeurs de développement, pas une
configuration de production validée.

L’API est disponible sur `http://localhost:8000/api/v1`. Le frontend utilise
l’authentification SPA Sanctum avec cookies de session et protection CSRF.

Le parcours `UCG-IAM-001` fournit aussi la récupération du mot de passe, la
vérification signée de l’adresse e-mail et la révocation des autres sessions.
Les demandes de récupération répondent toujours de la même manière afin de ne
pas révéler l’existence d’un compte. Les anciennes sessions sont invalidées par
le contrôle du hash de mot de passe intégré à Sanctum. Les connexions, échecs,
réinitialisations, vérifications et révocations sont inscrits dans le journal
global append-only `identity_security_events`.

`UCG-IAM-002` impose le MFA aux propriétaires, aux administrateurs dont l’adhésion
est effective et aux comptes de la liste `horizon.authorized_emails`. Après le
mot de passe, `auth/me` expose `mfa.required`, `mfa.enabled` et `mfa.verified`.
L’espace d’organisation, les mutations métier et l’autorisation des canaux
privés restent bloqués jusqu’à l’activation puis au challenge. Horizon exige
également une preuve MFA courante, y compris en local. Aucun rôle plateforme
supplémentaire n’est créé par IAM-003 ; leur séparation reste IAM-005.

L’activation depuis le panneau de sécurité demande le mot de passe courant et
une adresse e-mail vérifiée. Ajouter manuellement la clé à une application TOTP
(SHA-1, six chiffres, période de 30 secondes), puis confirmer un premier code
avant dix minutes. Le secret est chiffré avec Laravel ; les dix codes de
récupération aléatoires de 128 bits sont hachés et affichés une seule fois, sans
stockage navigateur. Un code TOTP accepté ne peut pas être réutilisé, même sur
un autre appareil ; attendre le suivant dans l’application.

Un code de récupération donne accès après le mot de passe, est consommé sous
verrou de ligne et inscrit dans le journal de sécurité. Il révoque les autres
sessions : suppression des lignes avec le driver database et changement de
version contrôlé sur les requêtes suivantes avec les autres drivers. Le compte
peut ensuite remplacer l’authentificateur en confirmant son mot de passe puis le
premier code du nouvel appareil ; l’ancien facteur reste actif pendant cette
préparation. Renouveler les codes révoque les anciens codes et les autres
sessions. Un compte privilégié ne peut pas désactiver le MFA.

Un reset de mot de passe conserve le MFA et révoque les anciennes preuves ;
il ne récupère pas le deuxième facteur. En cas de perte de l’authentificateur
et de tous les codes, aucun contournement par e-mail ou endpoint de désactivation
n’est fourni. Les jobs d’un acteur privilégié revérifient son enrôlement avant
exécution, sans transporter une preuve de session dans leur payload.

La migration additive IAM-002 ajoute cinq colonnes nullable à `users`, sans
enrôler automatiquement les comptes existants. Appliquer les migrations IAM-001
et IAM-002 avant de démarrer cette version, avec `php artisan migrate` et une
sauvegarde habituelle. Ne pas lancer `migrate:fresh` sur `ucg_platform`. Un rollback
IAM-002 supprime les facteurs enregistrés ; privilégier une correction en avant
sur une base utilisée. Conserver les clés de chiffrement lors d’une rotation.
La politique utilise des requêtes d’existence indexées, sans parcourir toutes les
organisations du compte ni mettre en cache durablement ses privilèges.

### Rôles et permissions contextuels — UCG-IAM-003

Le panneau « Rôles et affectations » permet au propriétaire de créer, modifier
et désactiver des rôles à permissions explicites. Les gestionnaires détenant
`roles.assign` peuvent affecter ces rôles dans les limites de leurs propres
permissions, périmètres et périodes. Un membre ordinaire consulte uniquement ses
affectations. Les mutations demandent confirmation du tenant, révision de session
et, pour une modification, version courante de la ressource.

Les rôles complètent les adhésions `member`/`administrator`. Suspendre une adhésion
retire tous les accès ; la révoquer ou quitter l’organisation révoque ses
affectations dans la même transaction. Une réadmission ne les restaure pas.
Un rôle désactivé ou une affectation suspendue, future ou expirée ne donne aucun
droit. Les droits sportifs, administratifs et financiers ne sont jamais implicites
pour un propriétaire ou administrateur ; l’auto-attribution et l’extension de ses
propres droits sont refusées. Les permissions privilégiées alimentent le MFA global.

**Périmètre disponible aujourd’hui : organisation entière.** Les types équipe,
jeu, cohorte et événement sont préparés et refusés jusqu’à validation de leurs
ressources réelles par leurs modules, via `AuthorizationScopeDirectory`.
IAM-004 traitera la visibilité par champ ; IAM-005 les rôles plateforme/support.

La migration additive IAM-003 ajoute deux tables, des contraintes composites
et les projections du journal d’accès immuable. Elle ne convertit aucune adhésion.
Les exports complets incluent rôles et affectations. Le retour arrière refuse de
supprimer un historique IAM-003 déjà audité : préférer une correction en avant.
Les colonnes générées peuvent réécrire un grand journal PostgreSQL ; prévoir une
fenêtre adaptée. L’attente de verrou est bornée à cinq secondes.

Appliquer IAM-001, IAM-002 et IAM-003 avec sauvegarde et `php artisan migrate`
avant usage de cette version. Les trois migrations restent en attente sur
`ucg_platform` ; seule une base temporaire a servi aux tests PostgreSQL.
Un push GitHub ne met pas à jour la structure de la base locale. Les migrations
ajoutent les tables/champs attendus par le nouveau code ; la sauvegarde préalable
est une copie de la base permettant de revenir à son état avant intervention.
Les listes sont paginées (1 à 100 éléments), les rôles limités à 100 par tenant,
les relations chargées en bloc et les droits revérifiés en base à chaque opération.
À grande échelle, profiler les index ; tout futur cache d’autorisation devra
respecter versions, expirations et révocations immédiates.


Exemple de demande de récupération :

```bash
curl -X POST http://localhost:8000/api/v1/auth/forgot-password \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"member@example.com"}'
```

En production, utiliser HTTPS, définir `SESSION_SECURE_COOKIE=true`, conserver
`SESSION_HTTP_ONLY=true` et configurer précisément `FRONTEND_URL`,
`SANCTUM_STATEFUL_DOMAINS` et `SESSION_DOMAIN`. Le nettoyage des jetons expirés
est planifié toutes les quinze minutes avec `auth:clear-resets`.

Dans un second terminal Windows, démarrer le worker de remplacement local :

```powershell
cd back
composer queue:work:local
```

Ce worker utilise la base de données et traite, dans l’ordre, les queues
`critical`, `default`, `notifications`, `broadcasts` et `reports`.

## Démarrer le frontend

```powershell
cd front
npm install
npm run dev
```

Le frontend est disponible sur `http://localhost:5173`.

## Redis, Horizon et temps réel

Le mode local par défaut ne requiert pas Redis :

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
ARTIFACTS_DISK=local
```

Sur Linux, WSL, recette ou production, Redis et Reverb peuvent être activés :

```dotenv
REDIS_CLIENT=predis
QUEUE_CONNECTION=redis
CACHE_STORE=failover
BROADCAST_CONNECTION=reverb
HORIZON_METRICS_ENABLED=true
HORIZON_AUTHORIZED_EMAILS=admin@example.com
```

Utiliser des identifiants Reverb secrets et propres à chaque environnement,
puis démarrer les processus supervisés :

```bash
php artisan horizon
php artisan reverb:start
php artisan schedule:work
```

Le frontend configure Echo avec `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`,
`VITE_REVERB_PORT` et `VITE_REVERB_SCHEME`. Les abonnements privés passent par
`/api/broadcasting/auth`, protégé par Sanctum. Les origines autorisées du serveur
Reverb sont définies dans `REVERB_ALLOWED_ORIGINS` ; aucune origine générique
`*` n’est activée.

Horizon sépare les traitements urgents, généraux, notifications, broadcasts et
rapports. Le `retry_after` est supérieur au timeout du worker le plus long afin
d’éviter le traitement simultané accidentel d’un même job.

## Stockage des artefacts

`ARTIFACTS_DISK=local` conserve les fichiers privés dans le stockage Laravel
local. En production, utiliser `ARTIFACTS_DISK=s3` et renseigner les variables
`AWS_*`. Les modules métier passent par le contrat public `TenantArtifacts`
de Tenancy. `ArtifactStorage` reste le transport bas niveau d’infrastructure,
sans compteur de quota ; il ne doit pas être appelé directement par un module métier.

Les identifiants de tenant sont propagés explicitement dans les jobs concernés
et les artefacts sont préfixés par `tenants/<tenant_id>/`. Redis transporte les
jobs et accélère le cache, mais ne constitue jamais la seule copie durable d’un
contrat, paiement, résultat ou fichier critique.

### Ressources tenant — UCG-TEN-005

Les ports publics `TenantArtifacts`, `TenantCache` et `TenantResourceLimits`
prennent un contexte explicite. Fichiers et caches revérifient les accès courants
et le statut de l’organisation, sans mettre les droits en cache. Le module
appelant reste responsable de la Form Request, de la Policy de son objet et
du contrôle du contenu. Aucun endpoint fichier/import/export ni URL publique
ou temporaire n’est créé par ce lot.

Les nouveaux fichiers sont privés et immuables :
`tenants/<uuid organisation>/artifacts/<uuid artefact>`. Le nom est un libellé,
pas un chemin ; réutiliser un nom n’écrase rien. Lecture/suppression par UUID
filtrent l’organisation. Les fichiers anciens ne sont ni déplacés ni
comptabilisés rétroactivement : avant adoption sur un stockage existant,
préparer un inventaire/rattachement contrôlé. S3 reçoit la visibilité privée,
avec délais réseau par requête de 5 s (connexion) et 30 s (transfert), distincts
des retries SDK. Activer aussi S3 Block Public Access côté bucket.

Les clés utilisent l’UUID canonique, le type `cache`/`lock`/`rate` et un hash
de l’identifiant fonctionnel. `TENANCY_CACHE_STORE=database` fonctionne en local ;
à plusieurs nœuds utiliser Redis partagé (`redis`, `REDIS_CLIENT=predis`) ou
une base partagée. `array` est réservé aux tests ; `file`, `failover` et les
backends sans verrou atomique sont refusés. Pas de `flush()` ni `forceRelease()`.
Le bail d’un verrou est de 1 à 300 s et son callback doit finir avant expiration.
Acquérir le verrou cache avant une transaction métier. L’acquisition database
utilise `ON CONFLICT DO NOTHING` : une contention normale n’invalide pas la
transaction PostgreSQL. Redis expire ses verrous ; prévoir un entretien borné
des verrous database expirés après crash, sans purger les verrous actifs.

Valeurs techniques initiales, à ajuster après mesure, sans plan commercial :

| Configuration | Défaut |
| --- | --- |
| `TENANCY_REQUESTS_PER_MINUTE` | 600 requêtes/minute par organisation, acteurs et routes tenant confondus |
| `TENANCY_ARTIFACT_BYTES` | 25 MiB par fichier |
| `TENANCY_STORAGE_BYTES` | 1 GiB cumulé par organisation |
| `TENANCY_IMPORT_BYTES` | 10 MiB par import |
| `TENANCY_IMPORT_ROWS` | 10 000 lignes par import |
| `TENANCY_EXPORT_ROWS` | 50 000 lignes par export |

Les opérateurs peuvent surcharger des bornes entières positives par UUID dans
`config/tenancy.php:limit_overrides`, jamais via un payload client. Une mauvaise
configuration refuse l’opération. Les routes globales de compte/sélection
restent hors du budget tenant ; les invitations gardent aussi leur borne
de 10/minute par acteur et organisation avec une clé canonique.
`429 RATE_LIMIT_EXCEEDED` inclut `Retry-After` ; une borne de volume renvoie
`429 TENANT_LIMIT_EXCEEDED`, avec l’enveloppe JSON et le feedback ARC-006 existants.

`tenant_storage_usage` conserve le compteur durable, `tenant_artifacts` les
réservations/disques/états publiés. La réservation est commitée avant l’I/O :
en production, `store()` refuse une transaction englobante. Cette garde est
testée ; seules les transactions de rollback du runner PHPUnit sont exemptées.
La mutation est sérialisée par organisation, sans verrou global. Le quota n’est
libéré qu’après suppression confirmée. Un crash/nettoyage incertain conserve
une réservation `ready=false`, comptée et non lisible. Après avoir vérifié
que le traitement est terminé, un opérateur autorisé peut la supprimer via
le Service ; ne jamais remettre le compteur à zéro ni purger sur le seul âge.
Un rollback supprime les métadonnées/compteurs mais pas les objets : il est
destructif sur un stockage utilisé ; préférer un correctif forward.

Le verrou organisation reste tenu pendant l’I/O pour empêcher une suppression
concurrente de réservation. Mesurer contention et latence S3 avant la forte
charge ; aucun résultat « millions d’utilisateurs » n’est revendiqué.
Un disque local doit être partagé entre nœuds ou remplacé par S3.

Exemple dans un Service autorisé, avant sa transaction de métadonnées :

```php
// TenantArtifacts $artifacts et TenantResourceLimits $limits sont injectés.
// $context vient du résolveur serveur ; le contenu est validé et l’objet autorisé.
$limits->assertImport($context, $measuredBytes, $measuredRows);
$file = $artifacts->store($context, 'rapport.json', $validatedContents);
$contents = $artifacts->read($context, $file->id);
```

Les futurs imports doivent appeler les gardes d’octets/lignes pendant la
lecture ; les exports utiliser `assertExport()` avant matérialisation. Ces
gardes sont livrées/testées, mais les parcours métier n’existent pas encore.
Contexte/retry/scheduler des jobs sont décrits dans la section TEN-006 ci-dessous.

### Jobs et scheduler tenant — UCG-TEN-006

Les jobs métier utilisateur héritent du contrat public
`App\Modules\Tenancy\Application\Contracts\TenantJob`. Leur transport
`$context->toJobContext()` contient seulement UUID tenant, UUID de corrélation
et identifiant de l’acteur, jamais les rôles ni la sélection HTTP courante.
La méthode `middleware()` est finale et restaure un contexte revérifié à chaque
tentative, y compris après délai et `queue:retry`. Injecter `TenantContext` dans
`handle()`, jamais dans le constructeur ; appliquer ensuite la Policy de l’action
et les gardes des Services métier. Utiliser `dispatch()`/`dispatchSync()`, pas
`dispatchNow()` ni un appel direct à `handle()` qui contournerait ce middleware.

Un tenant absent/inactif, un accès révoqué ou un refus d’autorisation termine le
job dans `failed_jobs`, sans nouvelles tentatives automatiques inutiles. Les
pannes transitoires suivent les retries et backoffs. Le contexte worker est
nettoyé dans un `finally` ; une exécution imbriquée restaure son contexte extérieur.
Le contexte Laravel de journalisation, même hydraté depuis la queue, ne donne
aucune autorisation. Les invitations e-mail enregistrent aussi l’expéditeur et
revérifient son droit d’administration juste avant le transport.

Les tâches système implémentent le port public `TenantScheduledTask` et sont
enregistrées **côté serveur** dans `tenancy.scheduled_tasks` :

```php
// Exemple de raccordement d’un futur handler métier, pas une tâche déjà livrée.
'scheduled_tasks' => [
    'my-task' => MyTenantTask::class, // implements TenantScheduledTask
],
```

Le registre est fermé et borné à 64 noms sûrs ; aucune classe ne vient d’un
payload client. Il est vide par défaut : aucun traitement métier fictif n’est
lancé. Chaque handler enregistré est distribué chaque minute ; les fréquences
métier spécifiques doivent être définies lors de son raccordement. Le contexte
système `TenantExecutionContext` contient le tenant actif, sa timezone/langue
actuelles et la corrélation, **sans usurper le propriétaire**. Il ne donne pas de
`TenantContext` utilisateur. Le handler applique son périmètre système explicite,
filtre ses ressources par UUID et revérifie les conditions métier avant ses
écritures ; ne pas fabriquer un acteur propriétaire pour réutiliser une API utilisateur.

```powershell
cd back
php artisan tenancy:dispatch-scheduled my-task --no-interaction
php artisan schedule:work --no-interaction
```

La commande exige un nom enregistré et une queue durable database/Redis
(`TENANCY_SCHEDULE_CONNECTION=database` en local, `redis` avec Horizon).
En production, superviser le scheduler (ou appeler `schedule:run` chaque minute)
et les workers documentés plus haut. Scheduler multi-nœuds : cache partagé sans
failover, `onOneServer()` et `withoutOverlapping(2)` pour la distribution. Les
pages de 200 tenants utilisent un curseur UUID, pas un offset ni une collection
globale ; une continuation en file couvre les pages suivantes. Une erreur de
mise en file est signalée et retentée sans empêcher les autres distributions.
Des checkpoints de distribution (24 h) évitent de réenfiler les tenants et
continuations déjà distribués lors d’une reprise de page. Un verrou partagé
par tâche/run/curseur sérialise cette page ; il n’accorde aucun accès métier.

Chaque exécution a un verrou **par tenant et nom de tâche**, bail 90 s pour un
job de 60 s. Une contention relâche le job 5 s ; une autre organisation conserve
son propre verrou. Les jobs système disposent de 10 tentatives, les jobs
utilisateur et pages de 3, avec backoffs 5/30/120 s. Conserver les timeouts
workers sous `retry_after` (330 s pour database/Redis). Les limites d’arrêt dur
et Horizon nécessitent l’environnement Linux/PCNTL de production ; les tests
Windows valident l’exécution et les retries, pas un arrêt par signal.

Après succès, un marqueur cache tenant/task/run réduit les doublons pendant
24 h. Ce n’est **pas une garantie exactly-once** : crash après effet métier,
crash entre mise en file et checkpoint, cache perdu ou retry plus tardif exigent une idempotence durable du handler,
avec le même `runId` et des contraintes/transactions métier. Les mutations et
effets externes doivent être bornés sous le bail ; découper les travaux longs.
Éviter toute purge globale de cache/verrous/queues pour résoudre un incident.
La pagination considère les tenants actifs au passage de chaque page ; une
activation derrière le curseur sera prise au cycle suivant, pas dans un snapshot global.

Superviser `tenant.work.started/handled/failed/rejected`,
`tenant.schedule.page_dispatched/dispatch_failed/busy/completed/duplicate_skipped`,
les corrélations, les temps d’attente Horizon et `queue:failed`. `handled` indique
le retour du handler, pas nécessairement un succès métier (un job peut être
relâché). Les refus enregistrent des codes stables ; nos événements structurés
de panne enregistrent seulement le type d’exception, pas le contenu métier.
Cela ne masque pas les rapports d’exception du framework. Un callback `failed()` ne
dispose pas d’un contexte autorisé : utiliser les IDs sérialisés pour le suivi,
et revérifier explicitement toute mutation. Horizon et les incidents sont réservés
aux opérateurs ; les payloads de `failed_jobs` peuvent contenir des données privées.

Avant déploiement, examiner/drainer les anciennes invitations en file : un ancien
payload sans acteur est refusé, jamais attribué implicitement au propriétaire.
Ne pas vider les files ; traiter les anciens envois explicitement. Après correction
d’un incident, reprendre un UUID précis avec `php artisan queue:retry <uuid>` :
la reprise vérifie les droits/statuts actuels et conserve les IDs d’origine.
Redis réel (cache/verrous et queue/retry) est couvert par les tests de la CI dédiée ; le test
de queue est ignoré explicitement si Redis n’est pas disponible localement.
La CI ajoute un Redis dédié. Le test réel est activé par
`TENANCY_TEST_REDIS=true` uniquement sur un Redis de test, avec des clés uniques
nettoyées individuellement, jamais `FLUSHDB`. Aucun accès réel à AWS n’a été testé.

### Cycle de vie et portabilité — UCG-TEN-007

Les opérations sensibles restent **réservées aux opérateurs disposant d’un accès
autorisé au serveur**. Un `--actor` vérifié est une attribution d’audit, pas un
rôle plateforme ni une autorisation HTTP. Aucun endpoint ou écran de purge n’est
exposé. Suspension, réactivation ordinaire et archivage utilisent toujours
`organizations:transition` et sa matrice historique.

La nouvelle table `tenant_portability_operations` rattache chaque export, purge
ou récupération à un tenant, un acteur, un motif et une corrélation. L’identité
de l’opération et sa conservation sont protégées en SQL ; son état technique
reste modifiable. Les références à des comptes globaux ne sont pas transférées.

Exemples opérateur (remplacer les placeholders par des identifiants approuvés) :

```powershell
php artisan organizations:portability UUID_ORGANISATION export --actor=42 --reason="Export approuvé"
php artisan organizations:portability UUID_ORGANISATION inspect --operation=UUID_EXPORT --actor=42 --reason="Contrôle de l’export"
php artisan organizations:portability UUID_ORGANISATION read --operation=UUID_EXPORT --actor=42 --reason="Consultation approuvée"
php artisan organizations:portability UUID_ORGANISATION purge --actor=42 --reason="Inventaire sans suppression"
```

`export` retourne un UUID `pending` puis un job sur `reports` produit une archive
privée. Le worker existant database/Redis doit être actif. L’export opérateur
accepte un tenant inactif sans réactiver ses jobs métier TEN-006. Le catalogue
fermé couvre la racine et les huit tables tenant présentes : chaque nouvelle
table/colonne doit être enregistrée explicitement, sinon l’export est refusé.
Les fichiers couverts sont les artefacts enregistrés par TEN-005, lus sur leur
disque d’origine ; ce n’est pas un dump récursif des dossiers privés. Tout futur
stockage métier doit intégrer ce contrat avant de revendiquer un export complet.
Les comptes globaux, sessions, jetons, mots de passe et queues sont exclus.

Format `ucg-tenant-ndjson` version 1 : manifeste tenant/opération/corrélation,
lignes `row` avec table et données, blocs `file_chunk` base64 de 1 MiB avec offset,
`file_end` avec taille/SHA-256, puis bilan `end`. Les colonnes SQL calculées du
journal d’accès ne sont pas des entrées de restauration. Une ligne/fichier
manquant, incomplet ou hors limite empêche l’état `ready` ; jamais de troncature
silencieuse. La lecture vérifie la taille et le SHA-256 de toute l’archive avant
de restituer son contenu. Attention : `read` affiche des données privées ; ne
pas utiliser un terminal partagé ou des logs publics.

Les limites techniques sont distinctes des durées légales : 50 000 lignes via
TEN-005, 64 MiB d’archive finale, 128 MiB de réservations d’exports par tenant et
24 h depuis la demande. Le base64 compte dans les octets. Les exports ont leur
budget séparé du ledger des artefacts ; `pending` et `failed` restent réservés
jusqu’au nettoyage. Ces valeurs se configurent côté serveur dans `tenancy`.
Pour un tenant dépassant ces bornes, prévoir un export partitionné validé, ne
pas augmenter arbitrairement les limites ni déclarer un export partiel complet.
La génération utilise une transaction snapshot PostgreSQL, des lectures par
pages et un tampon temporaire, pas une collection globale en mémoire.

Superviser `tenant.export.ready/read/expired/enqueue_failed/expiry_failed` et
`tenant.purge.batch`. Après panne de mise en file, l’UUID reste dans l’opération
`pending` et dans le log `enqueue_failed`. Pour redistribuer le même export :

```powershell
php artisan organizations:portability UUID_ORGANISATION export --operation=UUID_EXPORT --actor=42 --reason="Reprise après correction"
php artisan organizations:expire-exports
```

Le nettoyage traite au plus 100 exports échus par lot ; il supprime uniquement
leur chemin exact et libère leur réservation, jamais les artefacts métier.
Une archive échue n’est plus lisible même avant le nettoyage. Un échec de stockage
pour un tenant n’empêche pas le traitement des autres : les tentatives de
suppression échouées passent derrière les candidats plus anciens sans modifier
leur date d’expiration. Le scheduler de nettoyage
est **désactivé par défaut** ; `tenancy.portability.expire_exports_scheduled=true`
nécessite un cache partagé sans failover et enregistre un passage toutes les
dix minutes avec `onOneServer`/`withoutOverlapping`.

#### Purge ciblée et conservation

Sans `--execute`, `purge` est un inventaire en lecture seule. La configuration
`tenancy.portability.purge_policies` reste vide : **toute purge métier est refusée
par défaut**. Une politique revue par UUID doit indiquer `reference`, une date
ISO 8601 avec offset `not_before`, `backups_procedure` et exactement les six
catégories `settings`, `artifacts`, `exports`, `access_history`,
`lifecycle_history`, `organization_identity`. Chacune vaut `delete` ou `retain` ;
les trois dernières doivent rester `retain` dans ce socle. Une date future,
invalide ou une catégorie absente/inconnue bloque toute suppression.
`backups_procedure` est une référence déclarée par l’opérateur, **pas une preuve
que les sauvegardes et réplicas ont été effacés**.

L’exécution exige un tenant archivé, `--confirm=UUID_ORGANISATION`,
`--source-export=UUID_EXPORT` et `--execute`. L’archive doit être complète,
intègre, non expirée et générée après le dernier archivage. Les seules catégories
supprimables sont les paramètres, artefacts et exports autorisés par la
politique. Les fichiers sont supprimés avant de libérer leur comptabilité.
Le lot est borné à 100 artefacts et 100 exports, avec une échéance souple de
45 s. S’il reste du travail (`pending`), reprendre avec `--operation=UUID_PURGE`,
le même export source et la même politique ; son empreinte interdit une reprise
avec des règles modifiées. Aucun nouvel export ou seconde purge n’est admis
après engagement d’une purge. Les archives peuvent faire partie des catégories
supprimées : vérifier la procédure approuvée de sauvegarde avant l’exécution.

Ce mécanisme n’est **pas une suppression physique complète de l’organisation** :
racine, comptes globaux, adhésions, invitations, historiques protégés et suivi
technique restent conservés. Aucune protection d’audit/FK n’est désactivée.
La matrice de durées/catégories à valider dans CAD-004, l’effacement conforme des
historiques et la disparition dans les sauvegardes/réplicas restent OPS-007.
Le code ne fournit pas de preuve d’effacement RGPD à lui seul.

Les verrous sont propres au tenant (90 s pour export/expiration, 180 s pour un
lot de purge). L’échéance de 45 s est souple : une dernière opération I/O peut
la dépasser. En production, régler les délais DB/I/O et exécuter les workers
sous Linux/PCNTL (`timeout=60`, `retry_after` supérieur au timeout) ; l’arrêt dur
n’est pas validé sous Windows. Ne jamais laisser un appel dépasser le bail ni
résoudre un incident par une suppression globale des fichiers/queues/verrous.

#### Récupération logique

Pour une organisation archivée dont les données ont été **conservées**, la
commande suivante revient à `suspended`, jamais directement à `active` :

```powershell
php artisan organizations:portability UUID_ORGANISATION recover --confirm=UUID_ORGANISATION --source-export=UUID_EXPORT --actor=42 --reason="Récupération approuvée"
```

Elle vérifie l’archive, les paramètres présents, le propriétaire vérifié et
l’exclusivité optionnelle TEN-003, puis écrit l’audit et le statut dans la même
transaction. Toute purge déjà engagée interdit ce raccourci. Les fichiers et
adhésions ne sont pas remplacés ; aucun job métier n’est repris automatiquement.
Après contrôle métier, la réactivation ordinaire est une décision séparée.

Une récupération **après perte/suppression** relève du scénario OPS-005 :

1. Restaurer une sauvegarde dans un environnement isolé, jamais par PITR global
   sur la base courante pour récupérer un seul tenant.
2. Vérifier version du schéma/format, UUID tenant, exhaustivité des catégories,
   tailles/SHA-256 des fichiers et références aux comptes globaux ; produire un
   inventaire comparé et conserver la preuve de la source approuvée.
3. Préparer une réconciliation par tenant (identités immuables, propriétaire,
   exclusivité, audits, FK composites, ledger et fichiers privés), sans déplacer
   un objet vers un autre tenant ni désactiver les contraintes.
4. Effectuer une répétition à blanc, faire approuver les écarts, puis exécuter
   une réimportation sélective auditée et revérifier les autres tenants avant
   toute remise en service ou reprise ciblée de jobs.

TEN-007 documente cette procédure ; il **n’implémente pas un import automatique
d’archives ni une restauration de sauvegardes**. Leur outillage et exercice
opérationnel restent OPS-004/OPS-005. Aucun export, nettoyage, changement de
statut ou purge n’a été exécuté sur les données actuelles pendant ce ticket.

## Organisations — UCG-TEN-001

Les organisations ont un UUID et un slug immuables, un propriétaire global,
un statut, un fuseau IANA, une langue et un pays ISO alpha-2. Les paramètres de
base retenus sont le premier jour de la semaine (1 = lundi, 7 = dimanche) et
le format de date (`d/m/Y` ou `Y-m-d`). Seul le français est actuellement
sélectionnable, car c’est le seul catalogue installé.

L’API expose uniquement la liste paginée des organisations possédées, leur
consultation et le remplacement de leurs paramètres par leur propriétaire.
Elle ne donne aucun droit plateforme au propriétaire.

La création et les transitions restent des commandes opérateur réservées aux
personnes ayant un accès autorisé au serveur. `--owner` et `--actor` désignent
des comptes existants avec une adresse e-mail vérifiée. L’acteur est déclaré
par l’opérateur ; cette déclaration ne remplace pas l’authentification de
l’accès au serveur.

```powershell
cd back
php artisan organizations:provision --name="Ultra Cyber Game" --slug=ucg --owner=42 --actor=42 --timezone=UTC --language=fr --country=FR --reason="Initialisation approuvée du pilote"
php artisan organizations:transition UUID_ORGANISATION suspended --actor=42 --reason="Régularisation nécessaire"
```

Remplacer les identifiants et les paramètres par les valeurs approuvées.
La création échoue sans modification si le slug existe déjà.

Les transitions retenues pour ce ticket sont :

| Statut courant | Statuts suivants autorisés |
| --- | --- |
| `active` | `suspended`, `closing` |
| `suspended` | `active`, `closing` |
| `closing` | `archived` |
| `archived` | aucun |

Cette matrice est une décision conservatrice d’implémentation : les documents
initiaux ne précisaient pas chaque transition. Une organisation non active ne
peut pas modifier ses paramètres ordinaires. La réactivation après archivage,
les régularisations métier, le transfert renforcé de propriété et la purge
ne sont pas implémentés dans TEN-001. TEN-007 ajoute les procédures opérateur
distinctes de récupération de données conservées et de purge ciblée ci-dessus ;
la matrice des transitions ordinaires reste inchangée.

Chaque création/changement de statut exige un motif et écrit un audit dans la
même transaction : organisation, acteur, ancien/nouveau statut, date et UUID
de corrélation. Le journal est protégé contre les mises à jour et suppressions
par Eloquent et par des triggers PostgreSQL/SQLite. Ces protections ne sont pas
une protection contre un administrateur de base ayant le droit de changer le schéma.
Les suppressions physiques d’organisations et de comptes référencés sont refusées.
Les futures procédures de transfert/conservation devront faire évoluer
explicitement ces protections, sans contournement par une mise à jour ordinaire.

Les adhésions/invitations sont ajoutées par `TEN-003`, décrit ci-dessous.
L’isolation SQL des tables existantes et le contrat des futures tables sont
livrés par `TEN-004`, décrit ci-dessous. L’audit transverse reste `IAM-006`.

La CI teste ce module sur une base PostgreSQL 17 dédiée en plus de la suite SQLite.

## Contexte tenant — UCG-TEN-002

Les routes métier utilisent le middleware `tenant`, après Sanctum et avant les
bindings de ressources. Le contexte immuable est disponible uniquement pendant
la requête : UUID d’organisation, acteur authentifié, slug, fuseau, langue et
UUID de corrélation. Aucune organisation n’est sélectionnée automatiquement.

- `GET /api/v1/tenants/{uuid}/context` : résolution explicite par la route.
- `GET /api/v1/tenant/context` : résolution par le domaine approuvé uniquement.
- `PUT /api/v1/organizations/{uuid}` conserve son URL et son payload, mais
  passe désormais par `tenant:organization` et un Service recevant le contexte.
- Les lectures de gestion propriétaire, l’authentification et le statut système
  restent globales ; elles ne supposent pas de tenant actif.

`back/config/tenancy.php` contient `approved_domains`, vide par défaut :
associer un nom d’hôte exact en minuscules à l’UUID d’une organisation existante
uniquement après validation opérateur de son contrôle et de son routage.
Pas de wildcard, de sous-domaines implicites, ni d’approbation depuis une requête.
Le reverse proxy doit rejeter les hôtes inattendus ; ne faire confiance aux
en-têtes transférés que depuis des proxies explicitement maîtrisés.
Un domaine approuvé ne donne aucun droit sur son organisation.

L’accès est autorisé au propriétaire ou à une adhésion active dans sa période.
Une source absente produit `422 TENANT_CONTEXT_REQUIRED`,
une organisation inconnue/non autorisée ou un conflit route/domaine produit le
même `404 RESOURCE_NOT_FOUND`, et un tenant non actif produit
`409 ORGANIZATION_INACTIVE`. Query string, payload, cookies et en-têtes tenant
ne peuvent pas choisir ou remplacer le contexte.

Le contexte n’est pas un singleton et est nettoyé après la réponse, y compris
après un refus. Les Services prennent le contexte explicitement ; les
Repositories reçoivent l’UUID issu de ce contexte, jamais un filtre tenant client.
`toJobContext()` transporte les identifiants tenant/requête dans le socle de
files existant, sans autoriser une opération différée. La restauration et la
revérification des droits/statuts des jobs sont assurées par les contrats de `TEN-006`.
Les contraintes PostgreSQL sont renforcées par `TEN-004` ; l’isolation complète
des fichiers, caches et verrous utilise les ports de `TEN-005`.

## Adhésions et changement d’organisation — UCG-TEN-003

Après connexion, l’espace « Mes organisations » permet de sélectionner un tenant,
consulter/répondre aux invitations et quitter explicitement une adhésion.
Le propriétaire et les administrateurs peuvent inviter des membres et gérer
leurs rôles, statuts et périodes. Seul le propriétaire délègue ou retire le rôle
`administrator` ; `owner` reste un accès de gouvernance implicite et non assignable.
Les rôles métier configurables sont fournis par IAM-003 ; leurs périmètres de
ressources seront raccordés par les modules propriétaires.

Une adhésion possède un UUID immuable, un couple organisation/compte unique,
des rôles, un statut `active`/`suspended`/`revoked` et une période
`[starts_at, ends_at[`. Une date de fin absente signifie durée indéfinie.
Les dates sont normalisées en UTC avant persistance ; la session PostgreSQL
est fixée à UTC, indépendamment du fuseau du serveur. Le fuseau de
l’organisation concerne l’affichage, pas le stockage des instants.
Une adhésion future, expirée, suspendue ou révoquée ne donne aucun accès.
Un départ révoque l’adhésion sans supprimer le compte ou les audits.
Une réadhésion réutilise son identité et conserve les périodes précédentes
dans le journal append-only, écrit dans la même transaction.

Les invitations ciblent une adresse e-mail normalisée. Leur acceptation exige
un compte authentifié dont l’adresse est vérifiée et identique à la cible :
pas de compte dupliqué ni de lien accordant un accès sans authentification.
La création/vérification d’un nouveau compte reste le parcours Identity
(`IAM-001`), pas une inscription publique ajoutée à ce ticket.
L’invitation expire après 7 jours par défaut ; une expiration calculée suffit
à refuser l’acceptation, sans dépendre d’un scheduler. Son historique est
conservé ; une invitation expirée peut être remplacée par une nouvelle.

La notification e-mail est mise en file `notifications` après commit.
Configurer `MAIL_*`, `FRONTEND_URL` et exécuter le worker documenté ci-dessus.
Le transport local `log` ne livre pas de message à une boîte externe.
Une erreur de préparation avant commit annule invitation et audit.
Une erreur de mise en file après commit ne peut pas annuler l’invitation déjà
enregistrée : superviser et reprendre explicitement l’envoi. L’invitation reste
consultable dans la boîte interne du destinataire vérifié.
Un échec de livraison d’un job en file relève des retries et des failed jobs ;
une notification de création ne constitue pas une preuve de livraison externe.

La sélection serveur en session ne contient que tenant, acteur, révision et
confirmation. Elle ne remplace jamais le contexte résolu par route/domaine :
les accès et le statut sont revérifiés à chaque requête.
Le frontend efface immédiatement les anciennes données/commandes, annule
les lectures obsolètes et recharge les permissions. Il ne stocke pas de données
tenant dans localStorage. Un retour de focus recharge aussi les accès.
Chaque action propose une confirmation explicite de sa cible ; une action
tenant confirme ensuite la révision active avant mutation.
Les requêtes sensibles et les changements de sélection utilisent les verrous
de session Laravel : conserver un cache à verrous atomiques et, en déploiement
horizontal, un stockage de session/cache partagé.

Le mode multi-organisations reste activé par défaut :

```dotenv
TENANCY_ALLOW_MULTIPLE_ORGANIZATIONS=true
```

Passer explicitement à `false` active une exclusivité globale au déploiement,
isolée dans `MembershipAdmissionService`. Elle vérifie les chevauchements
d’adhésions actives ou suspendues à l’acceptation, la réactivation/modification
et la création d’une organisation possédée. Le verrou du compte sérialise
les admissions à des organisations différentes. La propriété d’une autre
organisation non archivée compte comme une appartenance indéfinie.
Ne pas activer ce mode sur des données existantes sans examiner et résoudre
explicitement les conflits ; aucune révocation automatique n’est effectuée.
Les restrictions contextuelles utilisent le contrat `TenantAuthorization` d’IAM-003.
Le détail des endpoints et exemples est dans `docs/conventions-api.md`.

## Isolation PostgreSQL — UCG-TEN-004

Les tables tenant existantes ont un `organization_id` obligatoire. Le journal
ne peut plus référencer l’adhésion ou l’invitation d’une autre organisation,
un sujet absent ou du mauvais type ; ses objets audités ne sont pas supprimables
physiquement. Les paramètres ne peuvent pas être déplacés vers un autre tenant.
Les unicités des comptes membres et des invitations restent locales au tenant.

La liste fermée des exceptions globales est dans
`back/app/Architecture/Database/TableOwnership.php`, avec leurs justifications
dans `docs/architecture-modulaire.md`. Une nouvelle table est tenant-owned par
défaut ; le contrôle opérateur et les tests détectent les écarts de schéma.
Cette liste ne donne aucun accès global aux données.

Après sauvegarde et vérification de l’environnement cible :

```powershell
cd back
php artisan migrate --no-interaction
php artisan tenancy:check-schema --no-interaction
```

La migration vérifie l’historique et s’arrête sans correction silencieuse en cas
d’incohérence. Elle ajoute des colonnes calculées et des FK sans changer les
payloads des audits. L’attente de verrou PostgreSQL est bornée à cinq secondes,
mais la matérialisation sur une grande table exige une mesure et une fenêtre
de maintenance ; prévoir un lot progressif pour des millions d’audits.
Ne pas employer `migrate:fresh` ou un rollback sur la base principale.

Le contrôle de schéma est en lecture seule et retourne un code non nul en cas
d’écart ; il vérifie contraintes, index et relations, pas les droits HTTP ni
les payloads de caches/jobs. Aucun endpoint ou écran supplémentaire n’est ajouté.
RLS a été évaluée et reste désactivée : son intégration exige des rôles SQL,
transactions/contextes, parcours globaux et workers maîtrisés. Les contrôles
applicatifs de TEN-002/TEN-003 restent indispensables, notamment pour les lectures.

## Vérifications

Installer une fois les dépendances et le navigateur Playwright :

```powershell
cd back
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix

cd ..\front
npm install
npx playwright install chromium
```

Exécuter la chaîne de qualité backend :

```powershell
cd back
composer validate --strict
composer format:check
composer analyse
composer test:ci
```

`composer test:ci` conserve le rapport PHPUnit dans `back/reports/phpunit.xml`.

Exécuter la chaîne frontend et le parcours navigateur :

```powershell
cd front
npm run lint
npm run test:ci
npm run build
npm run test:e2e
```

Les rapports Vitest et Playwright sont générés dans `front/reports/`. Le test
Playwright démarre Laravel et Vite automatiquement sur les ports `8000` et `5173`.

Les workflows GitHub Actions `.github/workflows/backend-quality.yml` et
`.github/workflows/frontend-quality.yml` exécutent ces contrôles à chaque push et
pull request. Tout échec de formatage, d’analyse statique, de test ou de build
bloque le pipeline ; les rapports sont conservés comme artefacts pendant 14 jours.

## Documentation

- `docs/cahier-des-charges-ucg-multitenant.md`
- `docs/backlog-technique-ucg-multitenant.md`
- `docs/architecture-modulaire.md`
- `docs/conventions-api.md`
