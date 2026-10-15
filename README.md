# UCG Platform

Plateforme de gestion esports multi-tenant d'Ultra Cyber Game.

## Structure

Les tickets `UCG-IAM-003/004` fournissent les rôles configurables, leurs
affectations et la projection des champs selon les permissions serveur.
Voir [le contrat API](docs/conventions-api.md)
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
supplémentaire n’est créé par IAM-003 ; IAM-005 ajoute ci-dessous des rôles explicites, sans attribution automatique.

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
IAM-004 fournit la projection par champ décrite ci-dessous ; IAM-005 sépare
les rôles plateforme/support et les permissions tenant.

La migration additive IAM-003 ajoute deux tables, des contraintes composites
et les projections du journal d’accès immuable. Elle ne convertit aucune adhésion.
Les exports complets incluent rôles et affectations. Le retour arrière refuse de
supprimer un historique IAM-003 déjà audité : préférer une correction en avant.
Les colonnes générées peuvent réécrire un grand journal PostgreSQL ; prévoir une
fenêtre adaptée. L’attente de verrou est bornée à cinq secondes.

Pour une nouvelle installation, appliquer IAM-001, IAM-002 et IAM-003 avec
sauvegarde et `php artisan migrate` avant usage. Sur le poste actuel, ces trois
migrations ont été appliquées par l’utilisateur ; `migrate:status` confirme
`Ran`, batch 7. IAM-004 ne crée aucune migration. Ses tests PostgreSQL utilisent
une base temporaire indépendante, supprimée après validation.
Un push GitHub ne met pas à jour la structure de la base locale. Les migrations
ajoutent les tables/champs attendus par le nouveau code ; la sauvegarde préalable
est une copie de la base permettant de revenir à son état avant intervention.
Les listes sont paginées (1 à 100 éléments), les rôles limités à 100 par tenant,
les relations chargées en bloc et les droits revérifiés en base à chaque opération.
À grande échelle, profiler les index ; tout futur cache d’autorisation devra
respecter versions, expirations et révocations immédiates.


### Visibilité par champ — UCG-IAM-004

`TenantDataVisibility` filtre une liste de champs définie dans le code serveur.
Un champ non déclaré ou sans permission est omis, y compris dans les objets
et collections imbriqués. Les champs sportifs, administratifs et financiers
utilisent respectivement `sports.read`, `administrative.read` et `financial.read`.
Propriétaire et administrateur ne reçoivent pas automatiquement ces permissions
métier ; le MFA reste exigé pour les champs privilégiés.

L’API des rôles utilise cette projection avant sa Resource, avec pagination et
contrat JSON conservés. Les e-mails d’invitation revérifient `invitations.manage`
au moment du traitement, y compris pour un expéditeur délégué et après révocation.
Les autres Resources existantes conservent leurs listes explicites de champs.

Les modules sportifs/administratifs/financiers, leurs recherches et leurs exports
métier ne sont pas encore implémentés. Leur livraison devra raccorder le même
contrat avant toute sortie, après filtrage des enregistrements autorisés. Une
projection déjà calculée ne doit pas être réutilisée comme preuve dans un job ou
un cache partagé. Les sauvegardes complètes de maintenance restent un parcours
opérateur serveur distinct, avec acteur vérifié et catalogue de tables/colonnes
fermé. IAM-005 en interdit l’usage aux comptes techniques actifs, y compris lors
de la génération en queue et de la lecture.

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
livrés par `TEN-004`, décrit ci-dessous. L’audit transverse est décrit dans la section IAM-006 ci-dessous.

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

### Administration plateforme et support — UCG-IAM-005

Les propriétaires et administrateurs gardent leur gouvernance tenant. Les rôles
globaux `superadmin` et `support` sont attribués explicitement depuis un accès
autorisé au serveur ; aucune adresse e-mail, adhésion ou installation ne les crée.
Un rôle technique actif interdit les permissions métier sportives, administratives
et financières, même si une affectation tenant existe. Les comptes techniques
ne peuvent pas utiliser les sauvegardes complètes de maintenance.

Après sauvegarde de la base cible, depuis `back/`, appliquer `php artisan migrate`.
La migration `2026_10_11_183828_create_platform_roles_and_support_accesses` ajoute
quatre tables sans modifier les comptes ni les tenants existants. Sur ce poste,
elle reste `Pending` ; la base principale n’a pas été migrée par Codex.

Le premier superadmin doit déjà avoir un e-mail vérifié et un MFA confirmé.
L’initialisation est possible une seule fois ; ensuite un superadmin actuel
doit être déclaré comme acteur. Ces commandes exigent un accès serveur autorisé :
`--actor` attribue l’audit et ne remplace pas l’authentification de l’opérateur
au serveur. Aucun endpoint HTTP d’attribution de rôle plateforme n’est exposé.

```powershell
php artisan identity:platform-role ID_SUPERADMIN --bootstrap --reason="Initialisation plateforme approuvée"
php artisan identity:platform-role ID_SUPPORT --role=support --actor=ID_SUPERADMIN --reason="Affectation support approuvée"
php artisan identity:platform-role ID_SUPPORT --role=support --actor=ID_SUPERADMIN --revoke --reason="Fin de mission support approuvée"
```

Remplacer les identifiants par des comptes approuvés. Le dernier superadmin actif
ne peut pas être révoqué/rétrogradé. Chaque changement renouvelle la version du
rôle et écrit l’audit avant/après dans la même transaction. Réattribuer un rôle
ne réactive pas un ancien accès support. Horizon exige désormais ce rôle
`superadmin` actif, la liste `horizon.authorized_emails` et une preuve MFA courante.

L’API `/api/v1/platform` exige une session authentifiée avec MFA courant.
L’ouverture précise un tenant, un motif de 10 à 500 caractères, une durée de
1 à 30 minutes, une ou deux actions fermées et `confirm=true`. Cinq accès actifs
maximum par tenant/opérateur ; aucune prolongation. Les seuls diagnostics sont
`organization.inspect` (statut/fuseau/langue) et `access.inspect` (compteurs des
adhésions/rôles/affectations). Aucun profil privé ni donnée métier n’est renvoyé.

Début inclus, expiration exclue : le contrôle se fait à chaque requête et ne
dépend d’aucun scheduler. Ouverture, lecture, refus d’un diagnostic sur son propre
accès et révocation sont audités ; les journaux sont immuables en SQL et Eloquent.
Un accès est lié à son tenant, son opérateur et la version du rôle plateforme.
Il ne crée aucun contexte tenant métier. Les listes sont paginées et indexées ;
les autorisations restent relues en base, sans cache durable.

Les commandes et l’API sont disponibles ; aucun écran support React n’est ajouté.
Voir les routes et l’exemple de client dans `docs/conventions-api.md`.
Le rollback fonctionne avant utilisation et refuse de supprimer des accès/audits
existants : après usage, privilégier une migration corrective en avant.

### Journal d’audit — UCG-IAM-006

Deux consultations authentifiées, avec e-mail vérifié et preuve MFA de session :

- `GET /api/v1/platform/audit-events` : superadmin actif, événements Identity
  globaux uniquement.
- `GET /api/v1/tenants/{tenant}/audit-events` : propriétaire/admin ou permission
  privilégiée `audit.view` effective pour l’organisation entière. Les comptes
  techniques actifs y sont refusés même s’ils sont propriétaires.

Les journaux existants sont réunis à la lecture sans copie ni backfill. Les pages
sont bornées à 100 et utilisent un curseur lié au compte, au tenant et aux filtres.
Les lectures réussies sont tracées avant diffusion. Les refus authentifiés 403/404
des routes tenant et du journal global sont tracés sans corps, URL, paramètres ni
identifiant de tenant demandé. Voir filtres et exemple dans
[les conventions API](docs/conventions-api.md#journal-daudit--ucg-iam-006).

**Décision utilisateur : conservation sans limite pour le moment.**
`config/audit.php` fixe `mode=unlimited` et `purge_enabled=false`.
Aucune purge ni tâche de suppression n’est livrée. Contrôle en lecture seule :

~~~powershell
cd back
php artisan audit:retention
~~~

La migration additive `2026_10_11_203439_create_transverse_audit_events` ajoute
deux tables append-only et les index de consultation. Sur la base locale, IAM-005
est appliquée (batch 8) et IAM-006 également (batch 9), après migration utilisateur.
Pour une base non à jour, appliquer `php artisan migrate` depuis `back/`
**après sauvegarde**. Un push ne lance pas cette migration.
Les tests utilisent SQLite et une base PostgreSQL temporaire distincte.

Cette API omet motifs libres et profils privés des anciens journaux, sans réécrire
les historiques d’origine. Les nouveaux événements acceptent uniquement des
métadonnées typées et des états fermés, sans contenu de contrat ni payload métier.
Le rollback refuse de supprimer les événements déjà enregistrés : après usage,
prévoir une correction en avant. Pour de gros historiques, préparer une fenêtre
adaptée pour les index, profiler les requêtes et surveiller stockage et sauvegardes.
Aucun benchmark de millions de lignes ni écran React de journal dans ce lot.
La permission apparaît dans la gestion des rôles ; les futurs modules devront
raccorder les ports d’audit.

### Personnes tenant — UCG-HUM-001

Le module `People` fournit une fiche indépendante du compte global : pseudonyme,
langue, fuseau, statut et biographies publique/interne. Une fiche sans compte est
possible ; un compte vérifié déjà effectif dans l’organisation peut être lié à une
seule fiche de ce tenant. Le lien ne crée ni adhésion, ni rôle, ni permission.
Les pseudonymes ne sont pas uniques et ne déclenchent aucun rapprochement automatique.

Les routes `POST tenants/{tenant}/people`, `GET tenants/{tenant}/people/{person}` et
`PATCH tenants/{tenant}/people/{person}` passent par Sanctum/session/MFA, le contexte
tenant, une Form Request et une Policy. Les mutations demandent confirmation de la
sélection ; PATCH exige la `version` UUID actuelle. Les champs omis sont conservés.
`sports.read/write` couvre le profil général ; `administrative.read/write` couvre
le compte lié, l’identité, les coordonnées et la biographie interne. Aucun droit
métier implicite pour propriétaire/administrateur ; les droits de lecture restent
distincts des droits d’écriture. Une réponse omet les champs non autorisés.

Identité, coordonnées et biographie interne sont chiffrées avec Laravel et masquées
dans la sérialisation ordinaire du modèle. Conserver les clés de chiffrement avec
les sauvegardes et lors d’une rotation. Aucun champ de naissance, pièce d’identité
ou coordonnées bancaires, aucun fichier/photo ni publication publique dans ce lot.
Les biographies sont du texte à échapper à l’affichage. Les écrans, listes et
recherche restent HUM-003 ; les profils de jeu restent HUM-002.

La migration additive `2026_10_11_215159_create_people_table` est à appliquer après
sauvegarde avec `php artisan migrate` depuis `back/`, avant utilisation de cette API.
Aucune fiche ni aucun lien n’est créé automatiquement pour les comptes existants.
Les identifiants/tenants sont immuables en Eloquent et SQL ; la suppression physique
est refusée. Le statut `former` conserve la fiche ; le départ et la révocation
des accès restent HUM-004. Le rollback d’une table non vide est refusé.

Les changements sont audités dans la même transaction : `domain.changed`, cible
organisation, UUID de personne dans l’état fermé, statut, présence d’un lien,
version et noms des champs soumis, sans données privées. Une panne d’audit annule
la mutation. Les sauvegardes complètes opérateur incluent les fiches du seul tenant,
avec les colonnes chiffrées brutes ; aucun export métier n’est ajouté.

Les verrous suivent compte puis organisation puis fiche ; les permissions sont
relues sous verrou. Cette sérialisation par organisation protège les révocations
concurrentes ; profiler contention et latence avant de changer sa granularité.
Aucun benchmark de millions d’utilisateurs ni cache durable d’autorisation.
Le client TypeScript `front/src/api/people.ts` utilise les protections CSRF et
la révision tenant existantes. Voir les exemples dans `docs/conventions-api.md`.

### Profils de jeu et comptes externes — UCG-HUM-002

`People` fournit plusieurs profils par personne, chacun dans un contexte immuable
jeu/plateforme/région. Rang et rôle de jeu sont des libellés manuels, indépendants
des permissions et de l’éligibilité sportive. Chaque profil peut avoir plusieurs
comptes externes ; aucune connexion éditeur, vérification de propriété ou collecte
de mots de passe/tokens n’est ajoutée.

`config/game_profiles.php` propose League of Legends, VALORANT, Counter-Strike 2
et Rocket League, avec les plateformes compatibles PC, PlayStation, Xbox et Switch
selon le jeu. Les associations initiales s’appuient sur les pages officielles :
[League of Legends](https://www.leagueoflegends.com/en-sg/download/),
[VALORANT](https://playvalorant.com/en-gb/platform-selection/),
[Counter-Strike 2](https://www.counter-strike.net/cs2),
[Rocket League](https://www.rocketleague.com/play). PC désigne une famille de
plateformes, pas une validation des systèmes d’exploitation ou générations de consoles.
Les régions `global`, `eu`, `na`, `apac` sont des zones de contexte applicatif,
pas les shards officiels des éditeurs. Le rôle/rang ne sont pas normalisés pour
comparaison entre jeux. Le catalogue administrable est livré dans TEAM-001 ci-dessous.

Depuis TEAM-001, ajouter ou désactiver une référence passe par le catalogue en base
et son écran d’administration. Les liens jeu → plateformes → fournisseurs restent
explicites et les clés stables. La migration reprend une fois enabled_games et les
tenant_enabled_games[UUID] valides ; les changements ultérieurs de configuration
ne remplacent pas les réglages en base. Un nouveau jeu doit être activé explicitement
par l’organisation. Une désactivation bloque les nouvelles utilisations ; les profils
historiques restent lisibles et archivables. Aucune clé ne doit être réutilisée.
Teams implémente le port public GameProfileCatalogue de People, sans dépendance
inverse ni cycle.

Les API profils/comptes sont sous `tenants/{tenant}/people/{person}/game-profiles`
et `/{profile}/external-accounts`, avec GET paginés/unitaire, POST et PATCH.
`sports.read/write` et la projection IAM-004 séparent lecture/écriture ; aucun
droit métier implicite. Auth/session/MFA, CSRF, confirmation et révision tenant
restent actifs. Chaque PATCH exige sa version courante ; aucun déplacement de
profil, changement de fournisseur ou remplacement d’un identifiant établi.
Un identifiant encore inconnu peut être renseigné une fois. Les comptes sans
identifiant stable exigent un nom, sans déduplication automatique par ce nom.

Unicité d’un profil par personne/jeu/plateforme/région ; unicité d’un identifiant
externe dans organisation/jeu/plateforme/région/fournisseur, avec casse conservée
et caractères ASCII imprimables sans espaces. L’archivage conserve cette réservation.
Ces liens ne créent ni compte global, ni adhésion, ni permissions. Les listes
sont bornées à 100 éléments ; les limites techniques initiales, archives incluses,
sont 50 profils/personne et 20 comptes/profil.

`observed_at` est une date ISO avec secondes/fuseau, non future, convertie en UTC.
La fraîcheur dérive de cette date : `unknown`, `recent`, `stale`, seuil serveur
`freshness_days=30` par défaut. Elle ne constitue aucune vérification de propriété
ou du rang. Changer rang/rôle ou identifiant/nom sans nouvelle observation remet
la date à null ; les autres champs omis sont conservés.

La migration additive `2026_10_11_223914_create_person_game_profiles_and_external_accounts`
ajoute deux tables avec FK composites tenant/contexte, unicités, index et gardes
d’identité/suppression SQL. Aucun backfill ni enregistrement métier automatique.
Appliquer après sauvegarde avec `php artisan migrate` depuis `back/` avant usage.
Rollback vide possible ; rollback avec profils/comptes refusé.

Les mutations et leur audit sont atomiques, sans rang, nom ni identifiant externe
dans les états du journal. Le catalogue de sauvegarde opérateur inclut uniquement
les profils/comptes de son tenant. Les comptages de comptes sont chargés en bloc,
la projection partage les contrôles pour la page ; pas de requête par ligne.
Profiler contention des verrous et plans d’exécution avant forte charge ; aucun
benchmark de millions d’utilisateurs. Les écrans et la recherche restent HUM-003.
Le client TypeScript est `front/src/api/gameProfiles.ts` ; exemples dans le contrat API.

### Fiches, listes et recherche des personnes — UCG-HUM-003

L’organisation active affiche désormais un panneau **Personnes** selon les droits
explicites. Il permet de créer/modifier une fiche, consulter ses vues générale,
sportive et administrative, et gérer les profils de jeu et comptes externes.
Les données sont effacées lors d’un changement d’organisation ou de droits.
Chaque écriture est confirmée pour l’organisation affichée, utilise la révision
tenant et la version de la fiche ; seules les valeurs modifiées sont envoyées.

GET `/api/v1/tenants/{tenant}/people` ajoute liste, filtres, tri et pagination.
Recherche par début de pseudonyme (2..80 caractères), filtres statut/langue et
jeu/plateforme sur un même profil actif : sports.read requis. Le filtre compte
lié exige administrative.read. Aucun filtre sur une identité chiffrée ; une
permission d’écriture n’accorde pas la lecture. Les résumés excluent biographies
et téléphone, avec comptages de profils en bloc et pagination limitée à 100.

Les rangs, rôles et comptes externes restent déclaratifs, avec date d’observation.
Modifier un rang/alias sans nouvelle date remet la fraîcheur à inconnue. Les
références retirées restent visibles et archivables. Le statut ancien membre
reste descriptif : le départ et la révocation effective seront traités dans HUM-004.
Le catalogue initial de back/config/game_profiles.php est repris à la migration
TEAM-001 ; les références et les choix des organisations se gèrent ensuite en base.

HUM-003 n’ajoute aucune migration. HUM-002 (`3c799ff`) a été poussé et migré par
l’utilisateur (batch 11), puis sa CI validée avant ce lot. Après push HUM-003,
valider les CI avant HUM-004. Aucun enregistrement métier réel créé par l’agent.

Validation : 45 nouveaux tests backend ; suite SQLite 763/760 réussis et PostgreSQL
17 763/761, exclusions attendues. 57 tests Vitest, Pint/PHPStan 8, lint/build et
parcours Playwright verts, contrôles mobile 375/320. Les plans de recherche doivent
être profilés à volume réel ; aucun benchmark de millions d’utilisateurs.

### Affectations, indisponibilité et départ — UCG-HUM-004

Les fiches proposent désormais les missions organisationnelles datées et les
indisponibilités, ainsi qu’une checklist privée d’équipements/obligations. Les
rosters d’équipes et contrats seront raccordés dans leurs modules ultérieurs.
Les libellés et notes de checklist sont chiffrés ; aucune raison médicale collectée.

« Confirmer le départ » ferme les périodes, enregistre la personne comme ancien
membre et retire adhésion, rôles et invitations en attente du compte lié dans
l’organisation affichée, en une seule transaction. Les autres organisations et
l’historique sont conservés. Le propriétaire doit transférer la propriété avant
départ. Les éléments ouverts exigent une reconnaissance explicite et restent
régularisables ensuite ; ils ne retardent pas la révocation des accès. Le statut
descriptif seul et l’indisponibilité ne retirent aucun accès. Une personne clôturée
ne peut plus changer de statut/compte lié ; réadmission métier contrôlée ultérieure.

HUM-003 (`2382db8`) a été poussé et ses CI validées par l’utilisateur. HUM-004
ajoute `2026_10_12_173809_create_person_lifecycle_tables` : après push et CI,
sauvegarder la base puis exécuter depuis `back/` :

```powershell
php artisan migrate
```

Migration principale vérifiée Pending, aucune migration ni opération métier
réelle effectuée par l’agent. Rollback refusé si les tables lifecycle contiennent
des données. Documentation et tests inclus dans le commit de tâche.

Validation locale : 821 tests SQLite et 822 PostgreSQL réussis, exclusions
attendues ; 65 tests frontend et 7 parcours Playwright verts, mobile 375/320.
Pint, PHPStan niveau 8, lint/build verts ; base PostgreSQL de test supprimée.
Pagination et limites par personne bornent les lectures ; profilage des verrous
nécessaire à volume réel. Prochain P0 après validation : HUM-005, import CSV.

### Import CSV de personnes — UCG-HUM-005

Le panneau Personnes propose un import privé de création, avec fichier UTF-8,
séparateur virgule/point-virgule, choix des colonnes et prévisualisation paginée.
Le dépôt et la prévisualisation créent uniquement le staging technique. Les fiches
sont créées après confirmation métier et confirmation de l’organisation affichée.
Les lignes invalides ou en doublon exigent une reconnaissance explicite pour être
exclues. Le rapport téléchargeable contient lignes, états et codes, sans valeurs privées.

Les quatre droits sports.read/write et administrative.read/write sont requis.
Aucun compte lié, invitation, rôle ou écrasement de fiche existante. Les candidats
en doublon sont détectés par pseudonyme dans ce tenant, y compris anciens membres ;
pas de rapprochement automatique par nom ou e-mail chiffré. Source et staging
privés chiffrés. Limites par fichier dans `back/config/person_imports.php` :
1 Mio, 1000 lignes, 32 colonnes, en plus des quotas techniques du tenant.

Le traitement utilise la queue `reports` et des lots transactionnels de 25.
Les droits sont revérifiés avant chaque lot ; les créations et leur audit sont
enregistrés avec le résultat de ligne. Les lots terminés sont conservés après un
échec ; une reprise confirmée traite les seules lignes restantes. Le redépôt du
même fichier/séparateur retrouve le registre existant. Actualiser le suivi puis la
liste des personnes après traitement. Pour le worker local déjà configuré :

```powershell
cd back
composer queue:work:local
```

HUM-004 et son correctif `51fb9b1` sont poussés, CI validées par l’utilisateur,
migration appliquée (batch 12). Après push/CI HUM-005 et sauvegarde, appliquer depuis
`back/` la migration `2026_10_12_211057_create_person_import_tables` avec
`php artisan migrate`. Elle ajoute les tables, contraintes et index du suivi
d’import ; rollback refusé si un registre existe. La base principale reste à la
main de l’utilisateur. Aucun CSV réel ni donnée métier réelle importé par l’agent.

API et exemples : `docs/conventions-api.md`. Code, tests, CI et docs de reprise
regroupés dans le même commit. Prochain P0 après validation : TEAM-001, catalogue
administrable de jeux et plateformes.

## Catalogue de jeux — UCG-TEAM-001

Le bouton « Catalogue commun des jeux » permet de consulter les jeux, plateformes,
fournisseurs, régions et les modes/rôles/cartes/versions rattachés à un jeu.
Un superadmin vérifié avec MFA peut ajouter, renommer et désactiver une référence,
après une confirmation explicite pour toutes les organisations. Les clés et
rattachements restent stables ; le retrait conserve les historiques.

Dans une organisation, « Jeux de l’organisation » exige sports.read. La gestion
exige aussi games.manage explicitement attribué à portée organisation, avec MFA
et confirmation tenant. Chaque jeu peut être activé ou désactivé, avec des choix
locaux par catégorie. Hériter suit les références communes ; une sélection vide
n’autorise aucun choix. Aucun réglage local ne modifie une référence commune.
Le propriétaire ou un opérateur technique n’obtient aucun droit métier implicite.

La migration additive 2026_10_13_120201_create_game_catalogue_and_organization_games
reprend une fois la configuration game_profiles et ses overrides tenant valides,
sans modifier les profils existants. Les quatre jeux initiaux gardent leur
activation compatible ; les nouveaux jeux demandent une activation locale.
Modes/cartes/rôles/versions peuvent être ajoutés par l’opérateur, sans données
arbitraires créées par l’agent. Freshness_days reste configurable pour les observations.

Les API game-catalogue et tenants/{tenant}/games sont versionnées et paginées.
Les détails/erreurs et exemples sont dans docs/conventions-api.md. Le port People
est fourni par Teams sans cycle ; profils et comptes refusent les références
retirées tout en conservant lecture/archivage. Réglages tenant exportés isolément,
cache global versionné de métadonnées uniquement, audit atomique et gardes SQL.
Les rôles de jeu ne remplacent pas les rôles IAM ou les observations game_role.

Validation locale : 65 tests backend ajoutés, SQLite complet 946/943 et PostgreSQL
17 946/944 (exclusions attendues), 89 tests frontend, 10 Playwright dont catalogue
global/local en mobile 375/320, Pint global/dirty, PHPStan 8, lint/build.
HUM-005 f960be8 poussé, CI validées et migration Ran batch 13 confirmés par
l’utilisateur. TEAM-001 reste Pending sur la base principale. Un seul commit
regroupe code/tests/CI/docs ; après push et CI, sauvegarder puis migrer depuis back/
avec php artisan migrate. Le prochain P0 est TEAM-002.

Confirmation avant TEAM-002 : commit 427dae1 poussé, CI et migration validées par
l’utilisateur ; contrôle en lecture seule de TEAM-001 : Ran, batch 14.

## Équipes par organisation — UCG-TEAM-002

« Équipes de l’organisation » permet de consulter les équipes avec sports.read.
La création, l’édition et l’archivage exigent aussi teams.manage explicitement
attribué au niveau organisation, MFA et confirmation humaine du tenant.
Le propriétaire et les opérateurs techniques n’ont aucun droit métier implicite.

Choisir un jeu activé, une saison descriptive et une catégorie : première,
académie, réserve, événementielle ou spécifique. Nom, nom court, description et
couleur composent l’identité ; ces champs restent privés. Aucun site public
n’est publié. Jeu, slug et saison restent fixes pour ce cycle ; créer une autre
équipe avec un nouveau slug pour un nouveau cycle. Le slug reste réservé dans
l’organisation après archivage.

Les équipes peuvent être brouillon, actives ou archivées. L’archive est terminale
et reste consultable, même si le jeu est retiré ; aucune suppression ou réactivation.
Les listes sont filtrées/paginées, les éditions partielles et les conflits de
version explicites. Limite technique : 1000 équipes par organisation, archives
incluses. Les appartenances et fonctions sportives arrivent avec TEAM-003.

API et exemples : docs/conventions-api.md. Migration additive
2026_10_13_143631_create_teams_table avec FK, index et gardes SQLite/PostgreSQL ;
rollback refusé si une équipe existe. Elle reste Pending sur la base principale.
Après push et CI, sauvegarder puis appliquer depuis back/ avec php artisan migrate.
Aucune équipe réelle, permission attribuée ou migration principale par l’agent.

Validation locale : 50 nouveaux tests backend / 204 assertions ; suites complètes
SQLite 996/993, 4212 assertions et PostgreSQL 17 996/994, 4213 assertions (exclusions
attendues), 98 tests frontend et 11 Playwright verts, dont mobile 375/320.
Pint complet/dirty, format:check, PHPStan 8, architecture, lint/build verts.
Code, tests, CI et point de reprise dans le même commit. Prochain P0 après
validation et migration : UCG-TEAM-003, appartenances datées.

Confirmation avant TEAM-003 : 11872a3 poussé, CI et migration validées par
l’utilisateur ; contrôle en lecture seule de TEAM-002 : Ran, batch 15.

## Appartenances et mouvements sportifs — UCG-TEAM-003

Depuis une équipe, « Effectif et historique » affiche les personnes et leurs
fonctions datées : titulaire, remplaçant, capitaine, coach et analyste. Rechercher
une personne existante pour l’affecter ; listes et historique sont paginés.
Consulter l’effectif à une date permet de retrouver les périodes alors effectives.
La lecture exige sports.read organisation ; la gestion exige aussi teams.manage
organisation, MFA et confirmation humaine. Aucune identité administrative affichée.

Les périodes sont [début, fin) ; titulaire et remplaçant ne se chevauchent pas
pour une même personne dans cette équipe, et capitaine peut compléter une fonction.
Personne, équipe, fonction et début restent fixes. Clôturer raccourcit la période,
annuler concerne une période non commencée ; l’historique n’est jamais supprimé.
Une fin future conserve l’accès jusqu’à sa date d’effet. Le départ organisationnel
termine automatiquement les fonctions en cours et annule les futures.

« Changer d’équipe » prépare un mouvement du même jeu ; la promotion va d’académie
vers équipe première. À la date d’effet, les fonctions sources sont terminées,
les périodes commençant à partir de cette date annulées et la fonction choisie
créée dans la destination, avec motif et auteur. Les autres fonctions et permissions
ne sont pas copiées. Clôtures possibles après retrait du jeu ; archiver une équipe
demande d’abord la clôture ou l’annulation de ses appartenances en cours/futures.

Les fonctions sportives n’accordent aucun droit automatiquement. Les grants IAM
explicites à portée équipe sont désormais liés à une appartenance du compte ;
un retour ne réactive pas les anciens grants. sports.read équipe permet le détail
de cette équipe. Pour un retour, révoquer l’ancien grant et créer une nouvelle
attribution explicite liée à la nouvelle appartenance. Les écrans de liste/roster
et leur gestion gardent leurs droits organisation. Les gestionnaires avec des
droits organisation explicites conservent
leur accès sans appartenir sportivement à chaque équipe.

API et exemples : docs/conventions-api.md. Migration additive
2026_10_13_152226_create_team_membership_and_movement_tables : périodes, mouvements
et liaisons IAM, avec FK tenant, index et gardes PG/SQLite. Down refuse toute donnée
historique. Migration appliquée par l’utilisateur après le push de f0681e7 ;
statut confirmé en lecture seule : Ran, batch 16.
Aucune migration principale, appartenance ou attribution réelle par l’agent.
Limites techniques : 1000 périodes par personne/organisation, historiques inclus,
et 1000 liaisons IAM par période. Code, tests, CI et point de reprise dans un seul
commit. Prochain P0 : UCG-TEAM-004, lineups et instantanés historiques.

Validation locale : 48 nouveaux tests backend / 228 assertions ; suites complètes
SQLite 1044/1041, 4440 assertions et PostgreSQL 17 1044/1042, 4441 assertions
(exclusions attendues), 107 tests frontend, 12 Playwright verts dont mobile 375/320.
Pint complet/dirty, format:check, PHPStan 8, architecture, lint/build verts.
La CI frontend 38440198679 a ensuite révélé un débordement mobile de l’effectif
après promotion. Correctif : colonnes à largeur contrainte et règles de retour
à la ligne cohérentes ; contrôles Playwright renforcés à 375/320 px, avec police
habituelle et police de repli plus large. Lint/build, 107 tests frontend et
12 Playwright réussis localement sous Chrome Windows ; CI Linux à revalider
après push du correctif. Aucune nouvelle migration.

## Compositions et instantanés — UCG-TEAM-004

Depuis une équipe, « Compositions et instantanés » prépare un brouillon pour un
match ou une inscription : libellé, date, titulaires et remplaçants choisis dans
l’effectif couvrant cette date. Une place par personne, au moins un titulaire.
La fonction dans l’effectif et la place dans cette composition sont distinctes.
Les listes sont paginées ; seules les identités sportives sont consultées.

Enregistrer, modifier, annuler et figer demandent une confirmation humaine dans
l’organisation active. Le figement recontrôle les versions et capture les
pseudonymes, les noms d’équipe/jeu, l’ordre des participants et son auteur.
Ensuite, transfert, départ, renommage ou retrait du jeu ne réécrivent pas cet
instantané. Une correction exige une nouvelle composition ; une annulation de
brouillon conserve ses données. Consultation : sports.read organisation ;
gestion : aussi teams.manage organisation, MFA et CSRF.

Les fiches Matchs/Compétitions seront intégrées dans leurs tickets : le libellé
identifie aujourd’hui le contexte préparé, sans rattachement fictif. Le port
HistoricalLineups fournit leur future lecture des seules compositions figées.
Les règles complètes d’éligibilité arrivent avec TEAM-005 ; ce lot ne valide
pas les règles de compétition, contrats, âges ou rangs. Les noms conservés
décrivent l’identité connue lors du figement, sans reconstitution rétroactive.

Limites techniques : 50 participants, 1000 compositions par équipe, historiques
inclus ; lecture paginée, participants eager-loaded et audit minimal atomique.
Migration additive `2026_10_13_204834_create_team_lineup_tables` : deux tables,
FK tenant, index et protections SQL SQLite/PostgreSQL. Down refuse toute donnée.
Statut principal vérifié Pending ; aucune migration principale ni composition
réelle créée par l’agent. Après push/CI, sauvegarder puis `php artisan migrate`
depuis `back/`. API et exemples : docs/conventions-api.md.

Le correctif TEAM-003 `8a997ab` est poussé et sa CI confirmée par l’utilisateur.
Code, tests, CI et point de reprise TEAM-004 dans le même commit.
Prochain P0 après validation/migration : UCG-TEAM-005, éligibilité sportive.

Validation locale : 49 nouveaux tests backend / 256 assertions ; SQLite complet
1093/1090, 4696 assertions et PostgreSQL 17 1093/1091, 4697 assertions (exclusions
attendues). Base PostgreSQL temporaire supprimée. 115 tests frontend et
13 Playwright verts, mobile 375/320 et noms longs. Pint complet/dirty,
format:check, PHPStan niveau 8, architecture, lint/build verts.

## Éligibilité sportive — UCG-TEAM-005

Dans « Compositions et instantanés », ouvrir « Règlements d’éligibilité » pour
créer un règlement du jeu : contexte de compétition nommé, titulaires minimum et
maximum, plafond de remplaçants, plateforme, régions, rangs déclarés, âge facultatif
et échéance. Les critères publiés restent immuables ; archiver conserve l’historique
et les changements passent par un nouveau règlement.

« Contrôler l’éligibilité » permet de lier définitivement un règlement au brouillon,
puis de conserver une pré-évaluation avec les explications. Les écritures demandent
une confirmation dans l’organisation active. Le figement lié refait les contrôles :
admissible exigé, donnée manquante/ambiguë/périmée = incomplet. À l’échéance,
modifier/figer est refusé ; annuler reste possible. Les rapports finaux ne changent
pas après un renommage, un départ ou un changement de profil/règlement.

Sans règlement, une composition ancienne reste « Non évaluée » ; elle n’est pas
une preuve d’admissibilité. Le contexte sera rattaché aux futures fiches Competition
dans leur ticket, sans identifiant fictif. Contrat, fonction et éligibilité restent
distincts. Région/rang sont déclaratifs et datés, sans certification externe.

L’âge se contrôle à la date UTC de composition. Date de naissance facultative dans
la fiche administrative, chiffrée ; administrative.write pour saisir,
administrative.read pour lire et faire ce contrôle. Jamais copiée dans un rapport
ou l’audit. Lecture sportive/gestion équipes organisation nécessaires ; MFA, CSRF,
pagination et notifications suivent le socle.

Migration additive `2026_10_13_215251_create_team_eligibility_rules_and_reports` :
trois tables tenant, naissance chiffrée nullable, FK et gardes SQLite/PostgreSQL.
Aucune modification des migrations déployées ou des snapshots antérieurs.
Down refuse les données. Base principale réservée à l’utilisateur : après push/CI,
sauvegarder puis `php artisan migrate` depuis `back/`.
TEAM-004 `59023ce` poussé/CI/migration confirmés ; migration Ran, batch 17.
API et exemples dans docs/conventions-api.md. Code/tests/CI/point de reprise
TEAM-005 regroupés dans un seul commit. Prochain P0 : UCG-TEAM-006,
références d’adversaires et d’organisations externes.

Validation locale TEAM-005 : 51 nouveaux tests backend / 227 assertions ; suites
complètes SQLite 1144/1141, 4923 assertions (3 exclusions) et PostgreSQL 17
1144/1142, 4924 assertions (2 exclusions Redis). Base de test temporaire supprimée.
118 tests frontend, 14 Playwright verts, mobile 375/320 et police de repli large.
PHPStan 8, architecture, Pint/format:check, lint/build verts. CI distante à valider
après push. Migration TEAM-005 Pending confirmée en lecture seule.

## Adversaires et organisations externes — UCG-TEAM-006

Le panneau « Adversaires et organisations externes » fournit un référentiel privé
par organisation : organisations externes, équipes et pseudonymes adverses,
source publique déclarée et date d’observation. Lecture avec sports.read organisation ;
gestion aussi avec teams.manage, MFA, CSRF et confirmation du tenant actif.
La saisie ne crée aucun compte ni droit pour l’adversaire.

Clé, type et jeu fixes ; édition partielle avec version UUID, archive définitive
et lisible. Les URL HTTPS sont conservées comme citations, sans accès réseau.
L’utilisateur confirme que les faits sont publics et autorisés pour ce suivi.
Les références ne publient aucune donnée sur un site public.

« Instantanés conservés » copie les informations actuelles pour un contexte sportif
nommé. Renommer/archiver la référence ne modifie pas cette preuve.
Les futurs modules Match/Competition utiliseront ExternalOpponents ;
aucune fiche fictive ni inscription n’est créée par ce ticket.
Contrat API et exemples : docs/conventions-api.md, section TEAM-006.

Migration additive `2026_10_14_121838_create_external_opponents_and_snapshots`.
Après sauvegarde et validation des CI, appliquer `php artisan migrate` depuis
back/. Aucun migrate:fresh sur la base principale. Down refuse de supprimer les
données conservées ; préférer une correction en avant. Aucune donnée réelle à
reprendre ni référence réelle préchargée. Export de maintenance enrichi des
deux tables. Pages API ≤100/UI 10, 1000 références par tenant et 1000 instantanés
par référence ; ces limites sont techniques, sans benchmark de forte charge.

Validation TEAM-006 : 1212 tests backend (SQLite : 1209 réussis, 5258 assertions,
3 exclusions attendues ; PostgreSQL : 1210 réussis, 5259 assertions, 2 exclusions
Redis), 129 tests frontend, lint/build et 15 Playwright verts. PHPStan niveau 8,
Pint complet/dirty, composer format:check et validate verts. La base PostgreSQL
utilisée pour les tests a été créée séparément puis supprimée. Migration principale
TEAM-006 toujours Pending ; aucune donnée réelle créée par l’agent.

## Contrats administratifs — UCG-CTR-001

Le panneau « Contrats » permet de préparer un dossier privé, ses parties,
son responsable de suivi, ses dates et ses obligations. Types : joueur, coach,
manager, analyste, contributeur et sponsor. Le parcours va du brouillon à
l’archive, avec validation par une personne distincte du préparateur, déclaration
de signature reçue hors plateforme et historique conservé des statuts.

Lecture : administrative.read ; gestion : aussi administrative.write ;
approbation, signature, activation, échéance et résiliation : aussi
administrative.validate. MFA, CSRF, révision et confirmation du tenant actif
s’appliquent aux mutations. Les références People restent internes au tenant,
y compris celles conservées après un départ ; elles n’attribuent aucun accès.

Les termes sont modifiables au brouillon ou en revue. Après approbation, le
responsable peut être réaffecté et les obligations régularisées ; revenir en
revue annule l’approbation. L’archive est définitive et exige des obligations
régularisées. « Signé », « en vigueur » et « éligible sportivement » restent
distincts : en vigueur exige le statut actif et une période valide à la date UTC,
avec date de fin incluse. Le suivi des statuts reste manuel à ce stade.

La signature est une déclaration administrative ; la copie signée et sa version
peuvent être rattachées via Documents et avenants (CTR-002). Analyse de contenu
et téléchargement privé : CTR-003 ; montants et approbations
financières : CTR-004 ; rappels : CTR-005. Aucun montant n’est collecté par CTR-001.
API et exemples : docs/conventions-api.md, section CTR-001.

Migration additive : `2026_10_14_184245_create_contract_records_and_lifecycle`.
Après push et CI vertes, sauvegarder puis appliquer `php artisan migrate`
depuis back/. CTR-001 87953e0 : push/CI/migration validés par l’utilisateur,
Ran, batch 20. Aucune donnée métier réelle créée par l’agent.
Down refuse un dossier ou ses enfants déjà conservés.
TEAM-006 `564dd59` : push/CI/migration confirmés par l’utilisateur ; Ran, batch 19.

Pagination API ≤100/UI 10 ; 1000 dossiers par tenant, 10 parties, 50 obligations
et 200 transitions par dossier. Champs privés chiffrés, audit atomique minimal,
FK tenant composites et export de maintenance fermé. Les requêtes restent
constantes avec la taille de page ; mesurer la charge et la contention du verrou
organisation avant de relever les limites.

Validation CTR-001 : 65 tests backend ajoutés ; suite SQLite complète :
1277 tests, 1274 réussis, 5549 assertions, 3 exclusions attendues. 141 tests
frontend et 16 parcours Playwright verts ; lint/build, PHPStan niveau 8,
Pint et composer validate verts. PostgreSQL complet : 1277 tests, 1275 réussis,
5550 assertions, 2 exclusions Redis attendues, sur une base temporaire
distincte supprimée après validation. Budget mémoire PHPUnit fixé à 512 Mo pour la suite complète,
sans modification du PHP utilisé par l’application.

## Documents et avenants — UCG-CTR-002

« Documents et avenants », dans le détail d’un contrat, permet de créer son
document original puis de conserver chaque version PDF. Le serveur calcule
l’empreinte SHA-256 sur les octets reçus, numérote les versions et chiffre le
contenu et le nom du fichier. Versions, auteur, date et empreinte restent immuables.

Un utilisateur habilité à valider peut déposer une copie signée puis identifier
précisément cette version comme celle reçue hors plateforme. La date de l’original
doit correspondre à la déclaration du contrat. Cette liaison est définitive :
les évolutions passent par un avenant distinct lié à l’original signé. La date
d’effet déclarée d’un avenant ne modifie pas les dates ni les parties du contrat.

Les PDF ≤5 Mo sont conservés dans le stockage privé TEN-005, sous quotas tenant,
en attente d’analyse. Aucun téléchargement, aperçu, URL ou chemin de stockage
n’est exposé à ce stade ; CTR-003 ajoutera l’analyse de contenu et la délivrance
sécurisée. Identifier une copie signée ne certifie pas la signature ou le fichier.
Les contrats antérieurs gardent leur déclaration sans document inventé.

Lecture administrative.read ; création et préparation aussi administrative.write ;
copie signée et identification aussi administrative.validate. MFA, CSRF, révision
et confirmation du tenant actif ; version UUID du contrat exigée à chaque mutation.
Terme original préparé uniquement au brouillon/en revue ; nouvelles copies
signées après déclaration de signature ; archive en lecture seule. Un nouveau
dépôt de préparation actualise le préparateur, sans permettre l’auto-approbation.

Limites : un original et jusqu’à 100 avenants par contrat, 100 versions/document,
pages API ≤100/UI 10. Index tenant/contrat/date et tenant/document/numéro,
relations et comptages chargés en amont ; nombre de requêtes constant vérifié.
Entrées et sorties de stockage hors transaction métier ; si celle-ci échoue,
seul le nouveau fichier est nettoyé. Une suppression incertaine conserve sa
réservation pour réconciliation opérateur. Audit minimal atomique sans texte ni hash.

Migration additive : `2026_10_14_193828_create_contract_documents_and_immutable_versions`.
Aucun backfill ni modification des migrations déployées. Gardes SQL sur identité,
appartenance au tenant, numérotation, statut, fichiers prêts et liaison de signature.
Down refuse les données documentaires ; export de maintenance enrichi des trois
tables et conservant le chiffrement. La migration principale reste Pending.

Après push et CI vertes, sauvegarder puis appliquer `php artisan migrate`
depuis back/. Prochain P0 : CTR-003. Contrat API et exemples :
docs/conventions-api.md, section CTR-002.

Validation CTR-002 : 48 tests backend ajoutés ; SQLite complet 1325 tests,
1322 réussis, 5783 assertions, 3 exclusions attendues ; PostgreSQL 17 complet
1325 tests, 1323 réussis, 5784 assertions, 2 exclusions Redis attendues.
152 tests frontend, 17 Playwright, lint/build, PHPStan niveau 8,
Pint/format:check et composer validate verts. Suites SQLite/PostgreSQL lancées
successivement pour isoler leurs fichiers factices ; base PostgreSQL temporaire
distincte supprimée après validation. Aucune donnée réelle créée.


## Sécurité documentaire et téléchargement privé — UCG-CTR-003

CTR-002 (`ce268f6`) a été poussé, validé en CI et migré par l’utilisateur :
migration Ran, batch 21, vérifiée en lecture seule avant cette tâche.
CTR-003 ajoute l’analyse ClamAV après chaque dépôt et la relance explicite des
versions anciennes. Les fichiers restent chiffrés et privés pendant l’analyse.
Le serveur vérifie taille, enveloppe PDF et empreinte SHA-256 ; aucun résultat
envoyé par le navigateur ne peut autoriser un téléchargement.

Un verdict clean de moins de 24 heures, non invalidé et conforme à la politique
courante permet d’obtenir un lien signé de cinq minutes. La session MFA, le compte,
le tenant, les droits administratifs, la version et le dernier verdict sont
recontrôlés à chaque téléchargement, puis après lecture du stockage. Corruption,
panne, analyse expirée ou signatures antivirus trop anciennes bloquent les octets.
Un nouveau scan rend les anciens liens inutilisables. Les PDF sont servis en
pièce jointe, sans cache, avec nosniff et CSP sandbox ; aucun lien de stockage public.

L’interface affiche état/motif, historique paginé, relance après confirmation,
actualisation manuelle et téléchargement avec cookies/CSRF/révision. Le lecteur
administratif peut télécharger ; administrative.write est nécessaire à la relance.
Les archives et copies signées restent immuables, mais leur analyse peut être renouvelée.

### Mise en service de ClamAV

Installer un clamd maintenu et configurer freshclam pour actualiser ses signatures.
Le démon doit utiliser UTC (`TZ=UTC`) car VERSION ne fournit pas de fuseau.
Préparer un endpoint TCP local ou réseau privé protégé, accessible uniquement
aux workers ; le protocole clamd TCP n’authentifie ni ne chiffre le trafic.
En conteneurs, utiliser le réseau interne et ne pas publier 3310 sur Internet.
PHP transmet les octets en mémoire via INSTREAM, sans fichier clair temporaire côté
application. Le répertoire temporaire du démon doit également être privé, avec
nettoyage actif ; laisser Debug/LeaveTemporaryFiles/GenerateMetadataJson désactivés.

Dans clamd.conf, retirer Example et vérifier notamment :

```conf
TCPSocket 3310
TCPAddr 127.0.0.1
ScanPDF yes
HeuristicAlerts yes
AlertEncrypted yes
AlertExceedsMax yes
StreamMaxLength 6M
MaxFileSize 6M
MaxScanSize 25M
```

Ces alertes empêchent qu’un PDF chiffré ou une analyse dépassant les limites soit
assimilé à une analyse complète. Adapter TCPAddr au réseau interne si nécessaire.
Les options du démon ne sont pas réglables depuis Laravel. Références :
[scan et réseau](https://docs.clamav.net/manual/Usage/Scanning.html),
[protocole](https://docs.clamav.net/manual/Usage/ClamdProtocol.html),
[configuration officielle](https://github.com/Cisco-Talos/clamav/blob/main/etc/clamd.conf.sample).

Configurer l’environnement backend (exemple également dans back/.env.example) :

```dotenv
DOCUMENT_CLAMAV_ENABLED=true
DOCUMENT_CLAMAV_HOST=127.0.0.1
DOCUMENT_CLAMAV_PORT=3310
DOCUMENT_CLAMAV_CONNECT_SECONDS=3
DOCUMENT_CLAMAV_OPERATION_SECONDS=20
DOCUMENT_CLAMAV_MAX_DATABASE_AGE_SECONDS=604800
```

Sans configuration réussie, l’analyse échoue et le téléchargement reste bloqué.
La limite d’ancienneté des signatures est de sept jours maximum ; elle peut être
réduite. Les délais acceptés sont 1–3 s de connexion et 1–20 s par échange.
Après changement d’environnement, reconstruire le cache de configuration utilisé
par le déploiement et redémarrer les workers. Le job utilise la queue reports,
un timeout de 60 s, trois tentatives et le contexte tenant TEN-006. En local,
`composer queue:work:local` écoute déjà cette queue ; en production, utiliser les
superviseurs/Horizon existants. Éviter QUEUE_CONNECTION=sync pour les dépôts lourds.

Vérification opt-in sans toucher à la base ni aux documents métier (PowerShell,
depuis back/, clamd déjà configuré et en cours d’exécution) :

```powershell
$env:CONTRACT_TEST_CLAMAV='true'
php artisan test --compact tests/Feature/ClamAvIntegrationTest.php
Remove-Item Env:CONTRACT_TEST_CLAMAV
```

Ce test envoie uniquement un PDF factice et la sonde inoffensive EICAR décodée en
mémoire. Il doit réussir avant l’ouverture des téléchargements en environnement
cible. Aucun clamd réel disponible sur ce poste : protocole vérifié avec un pair
TCP de test (chunks, résultat explicite, erreur, timeout, réponse ambiguë et base
ancienne). Ce pair ne détecte pas les virus ; le test réel reste à exécuter par
l’opérateur après installation de ClamAV.

Migration additive `2026_10_14_204339_create_contract_document_scans` :
historique retenu, tentatives consécutives ≤1000/version, FK composites tenant,
gardes SQL SQLite/PostgreSQL et rollback refusé si des analyses existent.
Aucun backfill automatique : les versions CTR-002 sont bloquées jusqu’à relance
explicite. Aucun ancien fichier ni migration déployée modifié.
Après push et CI vertes, sauvegarder puis appliquer `php artisan migrate`.
ClamAV et worker doivent être opérationnels avant d’annoncer le téléchargement disponible.
Prochain P0 : CTR-004, approbations et champs financiers.

Validation locale : SQLite complet final 1386 tests, 1382 réussis, 6031 assertions,
4 exclusions attendues (PostgreSQL spécifique, deux Redis, ClamAV réel opt-in).
168 tests frontend et 17 Playwright, PHPStan niveau 8, lint/build verts.
PostgreSQL 17 complet : 1384 tests, 1381 réussis, 6026 assertions, 3 exclusions
attendues (deux Redis et ClamAV réel). Après l’ajustement d’affichage des leases
expirées, 75 tests ciblés PostgreSQL réussis, 335 assertions, incluant les deux
nouveaux cas de frontière de lease. 61 cas backend ajoutés au total.
Suites de fichiers factices lancées successivement ; base PostgreSQL temporaire
recréée avant chaque suite puis supprimée. Pint/format:check et composer validate
verts. Aucun push ni migration de la base principale effectué par l’agent.

## Conditions financières et approbations — UCG-CTR-004

Les contrats et avenants disposent de blocs structurés chiffrés : rémunération,
périodicité, prime, part des gains, devise et modalités de paiement. Les montants
restent des chaînes décimales à deux chiffres après le point, sans flottants ni
conversion de devise. Ces valeurs sont absentes des réponses administratives
ordinaires et de l’audit.

Les permissions IAM sont explicites, sans privilège métier implicite :

- Lecture : administrative.read + financial.read.
- Préparation : ces lectures + administrative.write + financial.write.
- Approbation financière : droits administratifs de validation + financial.read
  + financial.validate. L’approbateur est distinct du préparateur.
- Dès qu’un bloc financier existe dans le dossier ou un avenant, tous les
  documents du dossier exigent financial.read ; les préparer exige financial.write.
  Les liens temporaires déjà émis sont recontrôlés à leur utilisation.

Activer « Exiger un déclarant de signature distinct… » pendant la préparation
pour imposer un troisième intervenant ; cette option est figée après approbation.
La déclaration enregistre une signature reçue hors plateforme. CTR-004 ne fournit
pas de signature électronique.

Un avenant exige une préparation PDF et une approbation distincte avant la copie
signée. Toute nouvelle préparation ou modification financière demande une
réapprobation, puis une nouvelle copie. Les anciennes décisions, copies liées
et signatures historiques sont conservées ; aucune approbation passée inventée.

Migration à appliquer par l’utilisateur après sauvegarde, avant réouverture
des écritures de contrats avec le nouveau code :

~~~bash
cd back
php artisan migrate
~~~

Migration : 2026_10_14_231650_add_contract_financial_governance.
Trois tables ajoutées et deux colonnes dans contracts ; aucun backfill ni droit
attribué automatiquement. Les migrations déployées restent inchangées.
Rollback refusé si ces nouvelles données ont été utilisées : correction en avant.
Clés composites et gardes SQL SQLite/PostgreSQL ; exports opérateur chiffrés.
Ne pas saisir de montants dans les champs administratifs génériques : les PDF
historiques ne sont pas classifiés automatiquement par analyse de leur texte.

Voir docs/conventions-api.md, section CTR-004, pour les routes et exemples.
ClamAV réel et worker reports restent à vérifier selon CTR-003.
CTR-004 : push, CI et migration confirmés par l’utilisateur avant CTR-005 (Ran,
  batch 23). Le ticket CTR-005 est décrit ci-dessous.


## Échéances et rappels — UCG-CTR-005

Le dossier configure sa fin (date déjà portée par le contrat), un préavis et un
renouvellement facultatifs, ses délais (90/30/7 jours par défaut, 0 à 365,
dix valeurs distinctes maximum), et ses canaux application/e-mail. Le destinataire
est le responsable du dossier, via son compte lié vérifié et ses droits actuels.
Les contrats approuvés, envoyés, signés ou actifs sont éligibles. Une date dépassée
ne génère rien ; après interruption, seul le seuil atteint le plus urgent est
rattrapé, sans rafale des anciens seuils. Un nouveau seuil produit un nouveau rappel.

Après sauvegarde et migration utilisateur depuis back/ :

```powershell
php artisan migrate
```

La migration additive 2026_10_15_184218_create_contract_reminders crée les réglages
et le journal d'envoi, sans activer ni remplir les contrats existants. Le rollback
refuse de supprimer des réglages ou un historique déjà utilisés.

Activer ensuite explicitement dans l'environnement cible :

```dotenv
CONTRACT_REMINDERS_ENABLED=true
TENANCY_CACHE_STORE=database
TENANCY_SCHEDULE_CONNECTION=database
CONTRACT_REMINDERS_EMAIL_ENABLED=true
CONTRACT_REMINDERS_MAILER=smtp
MAIL_TIMEOUT=5
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-secret
MAIL_FROM_ADDRESS=no-reply@your-verified-domain.example
```

Utiliser les paramètres TLS et expéditeur validés par le fournisseur, conserver
les secrets hors Git, actualiser la configuration déployée et redémarrer les
workers après modification. Un cache partagé durable est obligatoire pour le
scheduler ; aucun cache array/failover en production. Le worker doit consommer
la queue default, avec le retry_after déjà supérieur au timeout des jobs.
Installer le déclenchement système de php artisan schedule:run chaque minute
(ou schedule:work en développement). Le scheduler utilise onOneServer et
withoutOverlapping ; il distribue la tâche tenant contract-reminders dans la
file durable TEN-006. Pour un contrôle opérateur :

```powershell
php artisan schedule:list
php artisan tenancy:dispatch-scheduled contract-reminders
```

Les deux drapeaux CONTRACT_REMINDERS restent false par défaut : préparer les
réglages ne déclenche aucun e-mail réel. L'API expose scheduler_enabled/email_ready
comme indicateurs de configuration, sans prétendre mesurer la santé du worker.
L'envoi attend une configuration SMTP avec un timeout de 1 à 5 secondes ; le
transport log n'est jamais présenté comme un e-mail accepté. Les notifications
dans l'application fonctionnent avec le scheduler même si l'e-mail reste désactivé.
La configuration SMTP Laravel est décrite dans la
[documentation officielle](https://laravel.com/docs/13.x/mail#configuration).
L'interface ContractReminderMailer permet un futur adaptateur Brevo API ; aucune
intégration HTTP Brevo ni clé API n'est installée dans CTR-005.

Le traitement parcourt 25 réglages à la fois, au plus toutes les quinze minutes
par réglage, puis traite trois e-mails par passage. Pour les grands tenants, le
délai dépend du nombre de dossiers et du backlog : surveiller checked_at,
email_status, les jobs échoués et les logs contract_reminder.email_uncertain ;
profiler avant d'augmenter les lots ou de paralléliser par contrat. Les listes
et l'historique sont paginés, avec index tenant/contrat, balayage, boîte et outbox.

Le journal durable et sa clé unique dédupliquent les relances et les seuils.
SMTP ne garantit pas un envoi exactement une fois : si l'accusé est perdu, si un
listener annule l'envoi ou si le worker disparaît après la prise en charge,
l'envoi devient uncertain (après cinq minutes pour un sending abandonné).
Aucune relance automatique de cet envoi. Après contrôle fournisseur, un
gestionnaire autorisé peut confirmer l'acceptation ou autoriser une nouvelle
tentative en acceptant explicitement un risque de doublon ; la décision est
auditée. Une confirmation manuelle date la vérification dans email_sent_at.
Maximum vingt tentatives autorisées par occurrence. Les modifications de dates,
responsable, canaux ou statut sont recontrôlées avant tout e-mail en attente.
Un e-mail accepté par SMTP n'est pas une preuve de livraison dans la boîte finale.

L'e-mail est générique, sans titre, date, nom, montant, pièce jointe ou lien privé.
La boîte personnelle et l'historique recontrôlent les droits administratifs et,
pour les dossiers financiers, financial.read. Les anciens rappels ne confèrent
aucun droit après réaffectation. L'audit ne conserve que les identifiants et
actions ; les tables figurent dans l'inventaire fermé d'export opérateur.

Validation locale : suites SQLite/PostgreSQL, transport Laravel en mémoire,
tests frontend et Chromium mobile ; aucun e-mail réel ni migration de la base
principale exécutés par l'agent. Après push/CI/migration utilisateur, prochain
P0 : CTR-006, audit des consultations et exports sensibles.
