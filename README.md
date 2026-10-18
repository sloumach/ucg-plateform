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


## Audit des accès sensibles — UCG-CTR-006

Les consultations HTTP réussies des contrats, informations financières, documents,
versions, approbations, analyses et rappels sont maintenant journalisées.
La création d'un lien temporaire et l'autorisation de servir le PDF sont des
événements distincts. Une réponse prête à être servie ne prouve pas sa réception
par le client. Les requêtes refusées gardent l'audit de refus IAM existant et ne
créent pas de faux événement de consultation réussie.

Les exports opérateur TEN-007 existants tracent leur demande, leur relance,
la génération de l'archive et son ouverture en lecture. L'identité de l'opérateur
est recontrôlée après l'accès au stockage. Il s'agit de la traçabilité de la
portabilité existante ; aucun nouvel export métier CSV ni accès navigateur
aux archives opérateur n'est ajouté.

Après sauvegarde, appliquer depuis back/ la migration additive :

```powershell
php artisan migrate
```

Redémarrer ensuite les workers reports existants (par exemple php artisan
queue:restart) pour que les générations d’archives chargent aussi le nouvel audit.

2026_10_15_192732_create_organization_sensitive_access_events crée une table
d'audit dédiée, sans backfill ni modification des contrats. Elle conserve les
identifiants tenant/acteur/ressource/requête, l'action, un motif catégorisé,
l'horodatage et éventuellement l'IP. Aucun titre, montant, nom de fichier, nom de
personne, contenu du contrat, URL signée, empreinte de fichier ou motif libre
n'est recopié. Le motif opérateur existant reste dans son circuit protégé ; le
journal ne reçoit que la catégorie operator_portability.

Créer la table avant de rouvrir les lectures lors du déploiement. Sans possibilité
d'enregistrer l'accès, la réponse contractuelle est bloquée avec 503
ACCESS_AUDIT_UNAVAILABLE et Retry-After: 30 ; ni JSON confidentiel ni PDF ne sort.
Les erreurs techniques sont journalisées avec classe d'exception et identifiants,
sans message susceptible de contenir un secret. Les réponses contractuelles et
le journal tenant sont private, no-store.

Dans l'espace organisation, « Journal des accès sensibles » exige audit.view et
la MFA actuelle. L'auditeur ne reçoit aucun droit de lecture du contenu d'un
contrat par cette permission. Le panneau charge à la demande les métadonnées,
filtre par action ou compte et utilise les curseurs chiffrés IAM-006, liés à
l'auditeur, au tenant et aux filtres. Un profil technique plateforme reste exclu
du journal tenant. Aucune permission nouvelle ni attribution automatique.

API : GET /api/v1/tenants/{tenant}/audit-events?source=tenancy.sensitive.
Pour une consultation contractuelle, X-Access-Purpose est facultatif :
operational (défaut), internal_control ou legal_review. Ce champ est une catégorie
déclarée et non une justification vérifiée. Le contenu libre est refusé en 422.
Les requêtes existantes sans cet en-tête restent compatibles.

L'audit est enregistré synchroniquement avant remise de la réponse : le déplacer
dans une file sans stockage durable préalable ferait perdre cette garantie.
La lecture emploie les index tenant/date, tenant/acteur/date et tenant/action/date,
avec des lots bornés et sans jointure aux contenus contractuels. La politique
IAM-006 de conservation illimitée sans purge reste appliquée. Les gardes SQL
interdisent UPDATE/DELETE et un rollback refuse de supprimer un historique utilisé.
Surveiller la latence d'insertion, le volume et contract.access_audit_failed ;
profiler avant toute partition ou évolution de la politique de conservation.

Aucun événement passé n'est reconstitué. L'export opérateur inclut les événements
déjà présents au moment de son snapshot ; sa fin de génération et ses lectures
ultérieures restent dans le journal vivant. Le catalogue fermé d'export est mis
à jour avec les seules colonnes de métadonnées.

Après push/CI/migration utilisateur de CTR-006, les tickets P0 du module Contrats
sont implémentés. La prochaine tâche P0 du backlog est UCG-SPON-001, prospects et
contacts partenaires. La signature électronique CTR-007 reste P1. Les essais
de mise en service ClamAV et des rappels SMTP restent à effectuer dans l'environnement cible.


### Prospects et contacts partenaires — UCG-SPON-001

Le panneau « Prospects et partenariats » est chargé à la demande pour les comptes
habilités. Il fournit sociétés prospects, contacts commerciaux, opportunités et
historique manuel des échanges, avec recherche par préfixe et pagination séparée.
Les contacts ne sont jamais des fiches People, des utilisateurs ni des adhésions.
Aucun e-mail n’est envoyé par la consignation d’un échange.

Attribuer explicitement les permissions `partnerships.read` et `partnerships.write`
via les rôles existants, à un autre membre selon les règles IAM-003. Lecture et
écriture nécessitent un compte enrôlé MFA et une preuve courante ; propriétaire,
administrateur et opérateur technique n’obtiennent aucun droit métier implicite.
Ces permissions n’ouvrent ni les dossiers RH ni les contrats confidentiels.

Appliquer après sauvegarde la migration additive
`2026_10_15_211008_create_partnership_prospects_and_contacts`
avec `php artisan migrate` depuis `back/`. Elle ajoute quatre tables sans backfill,
seed ou attribution automatique de droits. Elle n’a pas été appliquée à la base
métier par l’agent. Ne pas utiliser `migrate:fresh` sur la base principale.
Le rollback refuse de supprimer des données utilisées : privilégier un correctif
en avant. Les archives restent lisibles mais deviennent immuables, avec leurs
contacts et opportunités ; les échanges sont en ajout seul, correction par un
nouvel échange. « Gagnée » et « Partenaire déclaré » sont des déclarations,
sans création automatique de contrat, accord, montant, exposition ou portail.
Ces parcours relèvent de SPON-002 et suivants.

Les mutations exigent la confirmation de l’organisation et sa révision de
session ; les modifications exigent la version courante. Les listes acceptent
`page`, `per_page` (1 à 100), `q` (préfixe, 80 caractères maximum) et un statut.
L’historique accepte aussi un canal et des UUID contact/opportunité du dossier.
Les liens inter-tenant ou inter-société sont refusés. Toutes les réponses métier
sont privées et non stockables en cache partagé. L’audit conserve uniquement
identifiants, catégorie et statut, sans coordonnées ou contenu des échanges.
Les exports opérateur complets incluent les quatre nouvelles tables.

Exemple SPA, après connexion Sanctum/MFA et confirmation de l’organisation :

```typescript
import { tenancyRequest } from './front/src/api/tenancy'
import type { ApiSuccessResponse } from './front/src/api/contracts'
import type { Sponsor } from './front/src/api/partnerships'

// tenantId et revision viennent du contexte serveur connecté et confirmé.
const result = await tenancyRequest<ApiSuccessResponse<Sponsor>>('tenants/' + tenantId + '/sponsors', {
  method: 'POST', revision,
  body: { name: 'Société exemple', industry: 'Matériel', status: 'prospect' },
})
await tenancyRequest('tenants/' + tenantId + '/sponsors/' + result.data.id + '/contacts', {
  method: 'POST', revision,
  body: { name: 'Contact commercial', email: 'commercial@example.test' },
})
```

Les index ciblent tenant, statut, dossier et tri stable. Les contacts et
opportunités sont chargés par pages, sans chargement de collections imbriquées.
Les écritures utilisent le verrou organisation déjà établi par le socle, puis
le verrou dossier ; profiler contention, recherche insensible à la casse et
pagination profonde sur des volumes représentatifs avant de les faire évoluer.
Aucun résultat de charge à plusieurs millions d’utilisateurs n’est revendiqué.

### Accords et périmètres d’exposition — UCG-SPON-002

L’onglet « Accords » d’un dossier partenaire fournit période, responsable,
opportunité d’origine facultative, rattachement à un contrat sponsor, conditions
financières ou apport en nature et périmètres d’exposition. Les listes, sélecteurs
et historiques sont paginés. Aucun envoi, portail sponsor ou publication publique
n’est déclenché.

Les droits sont explicites, avec MFA :

- `partnerships.read` : accords et références utiles, sans coordonnées RH.
- `partnerships.write` : préparation et modification des accords/périmètres.
- `partnerships.validate` : approbation et clôture.
- `financial.read` / `financial.write` : consultation / préparation des apports,
  en plus des droits partenariats requis.
- `financial.validate` et `financial.read` : approbation financière de l’accord,
  en plus des droits de validation partenariat.

L’approbateur doit être distinct du dernier préparateur de l’accord et du
préparateur financier. Préparer au moins un périmètre actif et un apport valide,
puis faire approuver l’ensemble par un autre compte habilité, après vérification
explicite des références d’autorisation. Toute modification remet l’accord en
brouillon et retire son approbation. Un statut commercial SPON-001 ne vaut jamais
approbation d’exposition.

Organisation, équipe active et personne active/remplaçante non partie sont les
cibles vérifiables actuelles. Les références d’événement et de contenu sont
refusées jusqu’à l’existence de leur référentiel ; le support « présence
événementielle » n’invente pas d’événement. Les sélecteurs People ne donnent que
les pseudonymes utiles, sans identité administrative, coordonnées ou biographie
interne. Les membres sont affichés par nom de compte, sans e-mail ni rôle ; leur
habilitation actuelle est vérifiée à l’affectation et avant exposition.

Chaque catégorie d’exclusivité est réservée de façon conservatrice sur toute
l’organisation, avec dates inclusives, même si ses périmètres diffèrent. Un
chevauchement avec un autre accord approuvé est refusé. Il ne s’agit pas d’une
interprétation automatique du texte contractuel. Clôturer l’accord avant
l’archivage du sponsor pour libérer volontairement une réservation ; un sponsor
archivé bloque l’exposition et conserve les réservations approuvées sur leur
période. Les dates des accords suivent le fuseau tenant ; le contrat lié garde
ses règles calendaires existantes.

`exposure_authorized` est recalculé à la lecture : accord approuvé, période
courante, sponsor disponible, responsable et approbateur toujours habilités,
cible disponible et contrat lié toujours actif dans sa version rattachée.
Un changement du contrat lié impose un nouveau rattachement et une nouvelle
approbation. Cette réponse privée n’est pas une autorisation durable pour une
publication future : les futurs consommateurs devront revérifier le contexte.

Les pièces utilisent le module Contrats existant. Rattacher un contrat exige
`administrative.read` ; consulter ou modifier ses pièces conserve tous les droits
et contrôles financiers/antivirus de ce module. Aucun droit administratif n’est
ajouté aux rôles partenariats. Un compte partenariat sans habilitation
administrative ne reçoit pas les identifiants du contrat lié. La relation ne
prouve pas automatiquement que le sponsor est une partie contractante : cette
cohérence est vérifiée par l’intervenant administratif habilité.

Après revue et CI, sauvegarder puis appliquer depuis `back/` :

```powershell
php artisan migrate
```

La migration additive `2026_10_16_000918_create_sponsorship_agreements_and_exposures`
ajoute quatre tables, sans backfill ni attribution de droits. Elle n’a pas été
appliquée à la base principale par l’agent. Son rollback refuse de supprimer un
historique utilisé. Les apports et références d’autorisation sont chiffrés ; les
journaux ne contiennent ni montants ni descriptions. Toute consultation
financière est tracée avant réponse ; un échec du journal bloque la réponse en
503. Le catalogue de portabilité opérateur inclut ces données privées.

Exemple SPA après connexion Sanctum, MFA et confirmation du tenant :

```typescript
import { tenancyRequest } from './front/src/api/tenancy'
import type { ApiSuccessResponse } from './front/src/api/contracts'
import { agreementsPath, type SponsorshipAgreement } from './front/src/api/sponsorshipAgreements'

// active et sponsorId proviennent des réponses serveur autorisées.
const root = agreementsPath(active.context!.organization_id, sponsorId)
const result = await tenancyRequest<ApiSuccessResponse<SponsorshipAgreement>>(root, {
  method: 'POST', revision: active.revision!,
  body: { reference: 'accord-exemple', title: 'Accord matériel',
    starts_on: '2026-10-16', ends_on: '2026-12-31',
    responsible_user_id: active.context!.actor_user_id },
})
// Utiliser ensuite result.data.version pour ajouter un périmètre ou un apport.
// Chaque mutation renvoie une nouvelle version, nécessaire à la suivante.
```

Les sélections de responsables et de cibles se chargent par pages ; les noms et
pseudonymes sont projetés en bloc. L’autorisation est relue sans cache durable,
et les écritures sont sérialisées par organisation. Profiler la contention et
les recherches par préfixe avant évolution des index ou du verrouillage. Les
accords sont chargés dans un fragment JavaScript à l’ouverture de l’onglet.
Aucune validation de charge à plusieurs millions d’utilisateurs n’est revendiquée.

### Campagnes et livrables — UCG-SPON-003

Depuis Partenariats → Accords → un accord → Campagnes, préparer une campagne
avec période et responsable, puis ses livrables : publication, vidéo, présence
événementielle ou placement de marque. Chaque promesse désigne un périmètre
existant de cet accord, un membre habilité et une échéance. Les listes, références
et historiques sont paginés ; les campagnes se chargent à la demande.

Les droits restent explicites et soumis au MFA : partnerships.read pour le suivi,
partnerships.write pour préparer/déclarer/annuler, partnerships.validate avec
lecture/écriture pour valider une preuve ou rouvrir un livrable. Aucun droit
financier, administratif, RH ou accès externe n’est accordé par ce parcours.

Le cycle distingue :

1. **Promis** : engagement attendu, modifiable avant déclaration.
2. **Livré** : date réelle et référence de preuve saisies, en attente de revue.
3. **Validé** : preuve contrôlée et confirmation explicite par un membre distinct
   du préparateur, du responsable et de l’auteur de la livraison.

Une déclaration ne vaut jamais autorisation de publier. La validation exige un
accord actuellement approuvé, un périmètre éligible, les habilitations actuelles
et un éventuel contrat toujours actif selon SPON-002. Une validation antérieure
reste historique si l’accord ou le périmètre change ; l’interface signale cet
écart. Rouvrir exige partnerships.validate et efface la preuve courante/la
validation, tout en conservant une capture chiffrée dans le journal métier.
Une promesse ou livraison peut être annulée ; l’annulation est définitive.

Le tableau « Suivi des engagements partenaires » montre les campagnes actives :
livraisons attendues, promesses en retard, preuves à valider et validations
historiques enregistrées. Un retard signifie promis avec échéance strictement
passée ; une livraison tardive conserve séparément sa date et cet indicateur.
Les mutations du parcours actualisent ce tableau ; le bouton Actualiser permet
une nouvelle lecture. Aucun montant ni preuve détaillée n’y est projeté.

La campagne reste dans la période de l’accord, et ses dates couvrent toutes les
échéances conservées. Les échéances et livraisons sont transmises en ISO 8601
avec fuseau, contrôlées selon le fuseau de l’organisation ; la livraison déclarée
ne peut pas être future. Le champ de saisie affiche son fuseau local. Archiver
une campagne exige des livrables validés ou annulés. Archiver accord/partenaire
exige d’abord l’archivage des campagnes actives, pour préserver leur traitement.

Les preuves sont des **références textuelles manuelles**, chiffrées au repos,
jamais téléchargées ni consultées automatiquement. Aucun dépôt de fichier,
publication sociale, mesure d’audience ou vérification externe n’est ajouté.
Les pièces contractuelles conservent le parcours privé/antivirus Contrats.
Le type présence événementielle ne crée pas de référentiel d’événements ; les
cibles event/content SPON-002 restent indisponibles. Rapports sponsor SPON-004
et portail SPON-005 restent ultérieurs.

Après revue, push et CI vertes, sauvegarder puis appliquer depuis back/ :

```powershell
php artisan migrate
```

La nouvelle migration est
2026_10_16_013526_create_sponsorship_campaigns_and_deliverables. Elle ajoute trois
tables et des gardes de cohérence, sans backfill ni attribution de rôle. Elle
n’a pas été appliquée à la base principale par l’agent. Son rollback refuse de
supprimer un historique utilisé ; utiliser une correction en avant dans ce cas.

Exemple SPA après connexion, MFA et confirmation du tenant :

```typescript
import { tenancyRequest } from './front/src/api/tenancy'
import { campaignsPath, type SponsorshipCampaign } from './front/src/api/sponsorshipCampaigns'
import type { ApiSuccessResponse } from './front/src/api/contracts'

const root = campaignsPath(active.context!.organization_id, sponsor.id, agreement.id)
const response = await tenancyRequest<ApiSuccessResponse<SponsorshipCampaign>>(root, {
  method: 'POST', revision: active.revision!,
  body: { agreement_version: agreement.version, title: 'Campagne automne',
    starts_on: agreement.starts_on, ends_on: agreement.ends_on,
    responsible_user_id: active.context!.actor_user_id },
})
// POST root + '/' + response.data.id + '/deliverables' exige agreement_version
// et campaign_version. Chaque mutation d’un livrable renouvelle campaign_version.
```

Les agrégats restent de taille fixe, les retards sont paginés et les noms/cibles
chargés par lots. Des tests vérifient le nombre de requêtes indépendant du nombre
de lignes. Les écritures sont sérialisées par organisation ; profiler agrégats,
contention et pagination profonde sur les volumes réels avant d’ajouter un cache
ou une projection asynchrone. Les droits courants ne sont pas mis en cache durable.
Aucun résultat de charge à plusieurs millions d’utilisateurs n’est revendiqué.

## Streams, VOD et clips — UCG-MEDIA-001

Le catalogue **Streams, VOD et clips** de l’espace organisation référence les
médias Twitch/YouTube d’une équipe active existante. Les fiches de matchs et
d’événements n’existent pas encore : aucun identifiant libre ou fictif n’est
accepté pour ces rattachements. Le contexte « scrim déclaré » qualifie le média
manuellement ; il ne crée pas de match.

Après sauvegarde, appliquer depuis `back/` la migration additive avec
`php artisan migrate --no-interaction`. Le fichier
`2026_10_16_192806_create_media_items_table.php` crée uniquement
`media_items`, sans reprise de données. Ne pas ouvrir ce parcours avant la
migration. Le rollback refuse de supprimer une table contenant des médias.

Accorder explicitement via IAM-003, au niveau de l’organisation :

- `media.read` : consultation des médias effectivement partagés.
- `media.read` + `media.write` : gestion, références privées et archives.
- Ajouter `media.publish` pour autoriser ou modifier un partage ; confirmer
  à chaque mutation d’un média qui reste partagé. Ces droits exigent le MFA
  et n’accordent aucun accès sportif, RH, financier ou partenaire. Le propriétaire
  et les opérateurs plateforme n’ont pas de droit média implicite.

Tous les médias, scrims inclus, sont privés par défaut, visibles seulement par
les gestionnaires médias du tenant. Les visibilités `organization` et
`public` enregistrent une autorisation de partage ; l’API reste authentifiée,
et aucune page publique n’est créée. Un scrim partagé exige une date future
`publish_at` lors de l’autorisation ou de la modification de cette date.
Avant cette date, ni la liste ni le détail ne renvoient le média aux simples
lecteurs. À l’échéance, le serveur applique la visibilité à la lecture, sans job,
notification ou publication chez le fournisseur. Un gestionnaire peut retirer
le partage ou archiver le média sans droit de publication. Une archive est
définitive et ne peut être supprimée via l’API.

Les liens sont contrôlés syntaxiquement par type/fournisseur et normalisés :
HTTPS, hôtes officiels exacts, sans authentifiants, port, fragment ni paramètres
de suivi. Sont pris en charge : chaîne Twitch (stream), vidéos Twitch (VOD),
clips Twitch ; YouTube watch/youtu.be (stream ou VOD), live (stream),
shorts/clip (clip). Retirer les paramètres de partage tels que `si`.
Aucune requête réseau ne vérifie l’existence, la disponibilité ou les droits
sur la vidéo. L’ouverture externe est explicite et ne transmet pas de référent.
Le réglage local ne protège pas une vidéo déjà publique chez le fournisseur.
Les lecteurs intégrés restent MEDIA-002.

Le statut `scheduled/live/ended/unavailable` est une observation manuelle
datée (`status_source=manual`), séparée de toute source de score. VOD et clips :
`ended` ou `unavailable`. Modifier la source ou le statut exige une observation
explicite, non future et non antérieure à la dernière observation. Un changement
de titre ne rafraîchit pas automatiquement cette observation.

Exemple SPA (client existant : CSRF, confirmation tenant puis révision) :

```ts
await tenancyRequest('active-organization/confirmation', {
  method: 'POST', body: { organization_id: organizationId, revision, confirm: true },
})
await tenancyRequest('tenants/' + organizationId + '/media', {
  method: 'POST', revision,
  body: {
    title: 'VOD équipe', team_id: selectedTeamId,
    kind: 'vod', provider: 'youtube',
    url: 'https://www.youtube.com/watch?v=abcdefghijk',
    is_scrim: true, visibility: 'private',
  },
})
```

Cet identifiant vidéo illustre seulement le format ; il ne prouve pas l’existence
d’un contenu. Les UUID d’équipe/révision doivent provenir des lectures autorisées.
Pour éditer : PATCH du média avec sa `version`. Pour archiver : PATCH contenant
seulement `version` et `archive: true`. Un 409 impose une relecture, sans rejeu.

Listes et choix d’équipes paginés, 100 éléments maximum, tri stable, références
chargées par lots. Index tenant/équipe/visibilité ; aucun cache durable des droits.
La recherche est un préfixe littéral. À fort volume, mesurer les plans, la
pagination profonde et la contention du verrou organisation avant d’introduire
un index de recherche ou une pagination par curseur.

## Annonces et discussions internes — socle UCG-COM-001

MEDIA-001 (`1f7cc8c`) est poussé ; CI et migration confirmées par l’utilisateur,
migration vérifiée Ran, batch 29. Le socle COM-001 ajoute un panneau dans l’espace
tenant : Mon fil, gestion des brouillons, commentaires, notifications internes
et préférences personnelles. Les rattachements aux tâches, matchs, séances et
événements restent à livrer avec leurs référentiels ; le ticket garde ce reliquat.

Après revue/push utilisateur et CI vertes, sauvegarder puis exécuter depuis
`back/` : `php artisan migrate`. La migration additive
`2026_10_16_210818_create_communication_tables` crée cinq tables ; elle ne crée
aucun rôle, contenu ou destinataire. Son rollback refuse toute donnée existante.

Accorder explicitement au niveau de l’organisation via IAM-003 :

- `communication.read` : fil ciblé, commentaires accessibles et boîte personnelle.
- Ajouter `communication.write` : brouillons, choix de cibles, mode gestion et
  ouverture/fermeture des commentaires.
- Ajouter `communication.publish` : publication confirmée et retrait.
- Ajouter `communication.comment` : participation, seulement dans l’audience actuelle.

MFA requis ; aucun droit métier implicite du propriétaire ou des opérateurs
plateforme. Les annonces organisation ciblent les lecteurs habilités ; équipe et
jeu ciblent les personnes liées au compte avec une période d’effectif effective
dans une équipe active et un jeu activé. Un rôle cible une attribution effective
à l’échelle de l’organisation, liée à l’adhésion actuelle (ou au propriétaire).
Le mode gestion permet la consultation administrative hors audience mais pas
la participation. Les listes filtrent avant pagination et comptage.

Un brouillon est éditable. Publier exige sa version et une confirmation explicite ;
contenu, cible et expiration sont ensuite figés. Pour corriger, retirer puis créer
un nouveau brouillon. Le retrait est définitif et masque fil, réponses et
notifications aux lecteurs. Les commentaires sont append-only ; leur ouverture
peut changer avec une nouvelle version de l’annonce. Un commentaire n’invalide
pas à lui seul les versions des autres participants.

Les notifications sont une boîte consultée dans l’application, sans liste de
destinataires matérialisée, ni e-mail, Discord ou envoi externe. La publication et chaque
commentaire proposent une notification désactivée par défaut. La préférence
annonces est active par défaut ; les réponses sont désactivées par défaut et
concernent l’auteur ou les participants déjà présents. Les droits, effectifs,
rôles, expiration et préférences sont recalculés à chaque remise dans la boîte,
y compris pour les notifications déjà lues. Aucune copie de contenu n’est stockée
dans les événements ou accusés de lecture. Actualisation manuelle, sans polling.

Titres/messages chiffrés en base ; rendu texte simple, aucun HTML ni pièce jointe.
Audit transactionnel limité aux identifiants/actions, export opérateur explicitement
étendu. Pagination 100 maximum, références bornées et requêtes par lots ; pas de
cache durable de droits. Au-delà de 1 000 équipes ou rôles effectifs par compte,
la consultation échoue explicitement au lieu de tronquer les autorisations.
À fort volume, mesurer les sous-requêtes de visibilité et les pages profondes
avant d’introduire une pagination par curseur. Aucune installation supplémentaire.

Exemple SPA, après sélection et confirmation de l’organisation active :

```ts
const draft = await tenancyRequest<ApiSuccessResponse<Announcement>>(
  'tenants/' + organizationId + '/communication/announcements',
  { method: 'POST', revision, body: {
    title: 'Informations équipe', body: 'Le message interne.',
    target_type: 'team', target_id: selectedTeamId, comments_enabled: true,
  } },
)
await tenancyRequest('tenants/' + organizationId + '/communication/announcements/' + draft.data.id, {
  method: 'PATCH', revision,
  body: { version: draft.data.version, action: 'publish', confirm_publication: true, notify_members: false },
})
```

Les types proviennent des clients `api/contracts` et `api/communication`.
Les UUID viennent des lectures autorisées. Toute réponse 409 impose une relecture,
sans rejeu automatique. COM-002 Discord et MOD-001 modération restent distincts.

## Calendrier des activités — UCG-CAL-001

COM-001 (`8d48651`) est poussé, CI et migration confirmées par l’utilisateur ;
migration vérifiée Ran, batch 30. CAL-001 ajoute un agenda chronologique privé
dans l’espace tenant, avec filtres de période, fuseau, type et mode gestion.
La migration additive `2026_10_17_094942_create_calendar_activities_and_participants`
crée deux tables sans rôle ni donnée métier. Après push et CI vertes, sauvegarder
puis exécuter `php artisan migrate` depuis `back/`. Le rollback refuse les données existantes.

Accorder explicitement au niveau organisation via IAM-003 :

- `calendar.read` : consulter les activités accessibles.
- Ajouter `calendar.write` : créer/modifier, gérer les participants et archiver ;
  le mode gestion explicite permet de lire toutes les activités, y compris privées.
- Ajouter `calendar.publish` : autoriser le partage interne, avec confirmation
  à chaque mutation d’une activité qui reste partagée.

MFA requis ; aucun droit calendrier implicite propriétaire ou opérateur plateforme.
Privé par défaut, scrim inclus : créateur et participants explicitement inscrits.
Le partage équipe ajoute les comptes liés à un effectif actuellement effectif,
dans une équipe active et un jeu activé ; le partage organisation ajoute les
lecteurs calendrier habilités. Un départ d’effectif retire cette voie d’accès ;
une inscription explicite reste une voie distincte. Le départ de la personne ou
la révocation des droits/adhésion coupe l’accès correspondant. Les choix de
participants proviennent de personnes non parties du tenant, 50 maximum ; une
inscription ne vaut pas convocation et n’accorde pas elle-même la permission read.

Types : entraînement, scrim, match officiel, réunion, séance académie, bootcamp,
événement sponsor, déplacement. Début/fin en UTC, fuseau IANA d’origine conservé.
L’interface interprète les heures locales dans ce fuseau, rejette les heures
inexistantes et exige un choix en cas d’heure répétée. Le serveur vérifie la
cohérence heure/décalage/fuseau avant normalisation UTC. Durée strictement positive,
31 jours maximum. Affichage dans le fuseau de la personne liée au compte, sinon
celui de l’organisation ; le lecteur peut choisir un autre fuseau pour sa vue.
Le fuseau et les heures d’origine restent visibles.

La consultation exige une période de 1 à 93 dates locales, fin exclue. Elle inclut
les activités qui chevauchent cette période, et applique les droits avant comptage
et pagination (100 maximum). L’agenda est présenté en liste paginée, pas en grille
mensuelle. Index tenant/période/équipe, participants chargés par lot, pas de cache
durable des autorisations. À fort volume, mesurer les plans des chevauchements,
les pages profondes et la contention organisation avant d’adapter les index ou
d’introduire une pagination par curseur.

Titres, descriptions, lieux et liens chiffrés, rendu texte échappé, audit minimal
atomique identifiant/action. Liens HTTPS ouverts volontairement, sans référent ;
aucune requête ne vérifie leur contenu. Conserver une version courante pour tout
PATCH ; un 409 impose une relecture sans rejeu. Une archive est définitive,
conserve les participants et ne déclenche pas de notification.

Exemple SPA, après sélection et confirmation du tenant :

```ts
await tenancyRequest('tenants/' + organizationId + '/calendar/activities', {
  method: 'POST', revision,
  body: {
    title: 'Entraînement équipe', kind: 'training',
    starts_at: '2026-10-25T02:30:00+01:00',
    ends_at: '2026-10-25T03:30:00+01:00',
    origin_timezone: 'Europe/Paris',
    team_id: selectedTeamId, participant_ids: selectedPersonIds,
  },
})
```

Cet exemple choisit la seconde occurrence de 02 h 30 lors du changement d’heure.
UUID issus des lectures autorisées. Les droits read restent nécessaires aux
participants ; un lien de réunion peut contenir un jeton, donc choisir sa
confidentialité avant tout partage.

CAL-002 livre les disponibilités dans un panneau dédié. CAL-003 à CAL-006 couvrent conflits, convocations, ICS,
reports/annulations et leurs notifications/historiques. Récurrences : CAL-007 P1.
Cette activité de planning ne crée pas une fiche Match/Académie/accord sponsor.
Les UUID de ces futurs dossiers sont refusés ; aucun score ni notification n’est
inventé. Les rattachements COM-001 et MEDIA-001 restent un travail distinct via
des contrats publics ; aucune dépendance circulaire ajoutée.

## Disponibilités et absences — UCG-CAL-002

CAL-001 (`3051aa8`) est poussé ; CI et migration validées par l’utilisateur.
Migration précédente vérifiée Ran, batch 31. CAL-002 ajoute le panneau
« Disponibilités et absences » dans l’espace tenant, chargé à l’ouverture.

Après push et CI vertes, sauvegarder puis lancer `php artisan migrate` depuis
`back/`. La migration additive
`2026_10_17_105547_create_availabilities_and_immutable_revisions` crée deux tables
vides ; aucun rôle, compte ou créneau n’est créé. Son rollback refuse les données.

Permissions à attribuer explicitement au niveau organisation via IAM-003 :

- `availability.self` : lire/modifier ses propres créneaux et leur historique,
  pour la personne actuelle liée au compte dans cette organisation.
- `availability.read` : consulter le planning collectif et les références
  id/pseudonyme, sans les commentaires ni l’historique des autres personnes.
- `availability.read` + `availability.manage` : gérer les créneaux des personnes
  actuelles du tenant, même sans compte, et consulter leurs commentaires/historique.

MFA requis pour les trois permissions ; aucun droit implicite propriétaire,
administrateur ou opérateur plateforme. Les permissions calendar ne donnent pas
de droit sur les disponibilités. Un compte sans personne actuelle liée ne peut
pas faire de déclaration personnelle ; l’interface l’explique. Une liaison
réattribuée change l’accès personnel selon l’identité actuelle, pas selon le
créateur du créneau. Les gestionnaires conservent les historiques des personnes
parties et peuvent annuler leurs créneaux, sans en réécrire les valeurs.

Statuts disponibles, indisponibles ou incertains. Commentaire facultatif (1000),
sans motif médical demandé, chiffré dans le créneau et ses versions, visible
uniquement par la personne habilitée ou les gestionnaires. Une absence de
déclaration n’est jamais interprétée comme une disponibilité.

Même contrat horaire que CAL-001 : instants UTC, fuseau IANA d’origine conservé,
offset vérifié, heures inexistantes refusées et choix explicite des heures
répétées. Durée positive jusqu’à 31 jours réels ; consultation de 1–93 dates
locales, fin exclue, 100 résultats/page. Vue personnelle par défaut ; lecture
collective explicite avec filtres personne/statut/annulé. Fuseau préféré de la
personne, sinon tenant ; autre fuseau possible pour la vue.

Un chevauchement de créneaux actifs de la même personne est refusé, quel que
soit le statut ; les créneaux adjacents sont autorisés. Modifier ou annuler le
créneau précédent pour corriger la déclaration. Version UUID requise au PATCH,
identité de la personne fixe. L’annulation est définitive, conserve l’historique
et libère la période ; aucun DELETE. Tout 409 impose une relecture sans rejeu.

Chaque création/modification/annulation produit un instantané immuable numéroté,
dans la même transaction que l’état courant et l’audit minimal. FKs composites
tenant/personne/parent, gardes SQL SQLite/PostgreSQL et inventaire de portabilité
avec contenu chiffré. Requêtes bornées, labels par lot, aucun cache durable des
droits. À grande échelle, mesurer les plans des chevauchements, les pages
profondes et la contention du verrou organisation avant d’affiner les index ou
de passer à une pagination par curseur.

Exemple SPA, après sélection et confirmation du tenant :

```ts
await tenancyRequest('tenants/' + organizationId + '/calendar/availabilities', {
  method: 'POST', revision,
  body: {
    status: 'unavailable',
    starts_at: '2026-10-25T02:30:00+01:00',
    ends_at: '2026-10-25T03:30:00+01:00',
    origin_timezone: 'Europe/Paris',
  },
})
```

Le serveur déduit la personne du compte ; les gestionnaires peuvent transmettre
person_id issu du référentiel autorisé. Les types Availability/AvailabilityPage
sont dans api/availability. Voir les conventions API pour les six routes.

Les créneaux ne changent pas le statut RH ni les périodes HUM-003 et ne créent
ni convocation, notification ni récurrence. Les conflits avec activités, équipes
et ressources sont traités par CAL-003 ci-dessous ; CAL-004 à CAL-007 restent distincts.

## Conflits de réservation — UCG-CAL-003

CAL-002 (`2c786d2`) est poussé ; CI et migration validées par l’utilisateur.
Migration vérifiée Ran, batch 32. CAL-003 ajoute le contrôle des réservations et
un référentiel minimal de ressources unitaires (salle, matériel, autre).

Après push et CI vertes, sauvegarder puis exécuter `php artisan migrate` depuis
`back/`. La migration additive
`2026_10_17_112802_create_calendar_resources_reservations_and_conflict_overrides`
crée trois tables vides, sans modifier les activités existantes ni créer de
ressource/rôle réel. Son rollback refuse les données de réservation.

Droits organisationnels explicites via IAM-003, toujours avec MFA :

- calendar.read + calendar.write : réserver, vérifier les conflits, choisir
  des ressources et consulter leur référentiel.
- Ajouter calendar.resources.manage : créer, renommer ou archiver une ressource.
- Ajouter calendar.conflicts.override : accepter les conflits examinés, avec
  confirmation explicite et motif opérationnel de 10–1000 caractères.
- Ajouter availability.read : inclure les créneaux indisponibles/incertains des
  participants et consulter l’historique des exceptions, qui peut en contenir.

Aucun droit implicite propriétaire, administrateur ou opérateur technique.
Les lecteurs calendrier ordinaires voient seulement les ressources des activités
accessibles ; le contrôle des conflits exige write, qui permet déjà le mode
gestion des activités privées. Il n’expose aucun commentaire de disponibilité,
description, lieu ou lien d’une autre activité.

Un conflit concerne un participant explicitement inscrit, la même équipe
associée ou une ressource commune sur des intervalles UTC [début,fin[.
L’adjacence ne crée pas de conflit et les activités archivées libèrent les
réservations. Inscrire les personnes réellement mobilisées : l’équipe associée
ne développe pas automatiquement son effectif. Sans availability.read, les
disponibilités ne sont pas consultées et l’interface le signale. Sans déclaration,
aucune disponibilité n’est présumée ; available ne constitue pas un conflit.

L’interface vérifie avant de proposer l’enregistrement, affiche les conflits et
demande une exception motivée si nécessaire, puis confirme le tenant via le
mécanisme existant. Un changement du formulaire invalide le contrôle.
Le serveur recontrôle sous verrou organisation, puis activité : un aperçu
chiffré valable cinq minutes lie acteur, tenant, activité/version, horaires,
participants, équipe, ressources et résultats. Si le périmètre ou les conflits
changent, un 409 impose une nouvelle vérification, sans rejeu automatique.

Les API existantes peuvent enregistrer directement une activité sans conflit.
Un conflit ne peut être contourné : aperçu courant, permission distincte,
confirmation et motif sont tous nécessaires. Chaque nouvelle modification
d’une activité qui reste en conflit exige un nouvel examen ; une ancienne
exception n’accorde aucun droit permanent. Plus de 100 conflits : blocage,
même avec permission d’exception, pour garder un résultat complet et borné.

Chaque ressource représente une unité, pas un stock ni une capacité multiple.
Référence unique par tenant, nom chiffré, type/référence immuables, versions UUID.
Archivage définitif : pas de nouvelle affectation ni déplacement d’une réservation
conservant une ressource archivée ; retirer cette référence avant de déplacer
l’activité. Renommer une activité sans changer sa réservation la conserve.
20 ressources maximum par activité, choix/référentiel paginés (100/page).

Les exceptions conservent motif et instantané chiffrés avec la version de
l’activité, l’acteur et la date. Journal append-only et audit minimal atomiques
avec l’activité et les réservations ; aucune raison privée dans l’audit générique.
L’historique paginé exige calendar.read/write + availability.read. Les gardes
SQLite/PostgreSQL, FKs tenant et portabilité conservent aussi les anciennes
réservations. Aucun cache durable de droits ou de résultats de conflit.

Exemple SPA, après sélection du tenant :

```ts
const draft = {
  title: 'Entraînement', kind: 'training',
  starts_at: '2026-10-18T10:00:00+02:00',
  ends_at: '2026-10-18T11:00:00+02:00',
  origin_timezone: 'Europe/Paris',
  participant_ids: selectedPersonIds,
  resource_ids: selectedResourceIds,
}
const review = await tenancyRequest<ApiSuccessResponse<CalendarConflictReport>>(
  'tenants/' + organizationId + '/calendar/activity-conflicts',
  { method: 'POST', revision, body: draft },
)
// Afficher review.data, recueillir la décision, puis confirmer le tenant.
await tenancyRequest('tenants/' + organizationId + '/calendar/activities', {
  method: 'POST', revision,
  body: { ...draft, conflict_review: review.data.review_token },
})
```

En présence de conflit, ajouter conflict_reason et confirm_conflicts:true
uniquement après décision explicite d’une personne habilitée. Pour modifier,
inclure activity_id/version dans l’aperçu ; conserver version dans le PATCH.
Les types sont dans api/calendarConflicts. Utiliser le transport SPA existant
pour CSRF, révision et confirmation ; UUID issus des lectures autorisées.

Requêtes filtrées par tenant, période et références avant limite ; relations
chargées par lot. Mesurer les plans des intersections et la contention du verrou
organisation avant optimisation à grande échelle. Toutes les écritures de
réservation doivent passer par ce protocole ; les FKs/triggers ne remplacent pas
le contrôle applicatif des chevauchements ni l’autorisation d’une exception.
Les collisions existantes ne sont pas corrigées automatiquement par migration.
Aucune convocation, notification, réservation récurrente, stock, transport ou
hébergement ajouté : CAL-004 à CAL-008 restent des lots distincts.

## Convocations, réponses et rappels — UCG-CAL-004

Depuis le calendrier, le panneau « Convocations de l’activité » permet aux
gestionnaires habilités de préparer une convocation pour chaque participant
explicitement inscrit. Le formulaire confirme les destinataires, une échéance
future (au plus tard au début) et zéro à trois rappels de 1 à 10080 minutes
avant l’échéance. L’échéance du formulaire est saisie en UTC ; les cartes
l’affichent dans le fuseau de l’organisation, avec son décalage visible.

Les permissions organisationnelles sont explicites :
`calendar.read` + `calendar.write` + `calendar.invitations.manage` pour émettre
et suivre les réponses ; `calendar.read` + `calendar.invitations.respond`
pour répondre à sa propre convocation. MFA requis ; aucun droit implicite
de propriétaire ou de compte technique. Une convocation ne crée ni compte,
ni adhésion, ni permission. Un destinataire sans compte lié ou accès actuel
reste bloqué et figure dans le suivi du gestionnaire.

« Mes convocations » contient les convocations réellement remises à la personne
actuellement liée au compte. Présent, absent et en attente restent modifiables
jusqu’à l’échéance, avec version UUID. Aucune réponse par procuration. Une
modification de l’activité, même de son titre, invalide ses anciennes convocations :
les gestionnaires conservent leur historique et doivent préparer explicitement
celles de la nouvelle version. Échéance et rappels d’une émission sont figés ;
changer leur configuration exige une nouvelle version de l’activité.

La remise est une notification **interne à l’application**. Son historique
persistant contient convocation/rappel, date et seuil. La tâche système tenant
`calendar-invitations` passe par le scheduler TEN-006 et la queue `default`.
Aucun e-mail, webhook ou message Discord n’est envoyé par ce lot.

Après sauvegarde et application de la migration additive
`2026_10_17_153712_create_calendar_invitations_and_deliveries`, configurer :

```dotenv
CALENDAR_INVITATIONS_ENABLED=true
TENANCY_CACHE_STORE=database
TENANCY_SCHEDULE_CONNECTION=database
```

Le flag est **false par défaut** dans l’exemple ; le fichier `.env` actuel
n’est pas modifié par la livraison. En environnement distribué, utiliser Redis
ou une base partagée pour le cache/verrou, et une queue durable commune.
Démarrer/superviser le scheduler (`php artisan schedule:work` en local,
cron `schedule:run` en production) et le worker `default` ; le script
`composer queue:work:local` traite déjà cette queue. Recharger la configuration
et redémarrer les workers après déploiement selon les procédures habituelles.
Ne pas exécuter `schedule:run` uniquement pour tester : il déclenche les tâches réelles.

L’interface indique quand la tâche n’est pas activée ; ce statut de configuration
ne prouve pas que le scheduler et le worker fonctionnent. Recetter deux comptes,
une échéance future, un rappel, une révocation et un changement d’activité avant
usage réel. Surveiller `failed_jobs` et les logs structurés `tenant.schedule.*`.

Les droits du responsable et du destinataire, le lien au compte, la personne
non partie, les participants, la version et l’échéance sont relus en transaction.
Les remises et leurs états sont atomiques, avec unicité durable par occurrence,
indépendamment du cache de fin de job. Une reprise choisit le seuil atteint
le plus urgent ; aucun rappel n’est envoyé après réponse ou échéance, et les
seuils déjà dépassés à la première remise ne déclenchent pas de rappel redondant.
Le traitement est borné à 25 convocations par organisation et par passage ;
mesurer les retards et la contention avant augmentation de charge. Aucun
objectif de cadence à grande échelle n’est revendiqué sans mesure.

Exemple avec le transport SPA existant et les objets réellement chargés :

```ts
await tenancyRequest('tenants/' + organizationId + '/calendar/activities/' + activity.id + '/invitations', {
  method: 'POST', revision,
  body: {
    activity_version: activity.version,
    response_deadline: '2026-10-18T07:00:00+00:00',
    reminder_offsets: [60, 120],
    confirm_invitations: true,
  },
})
// Après remise et relecture de la convocation courante :
await tenancyRequest('tenants/' + organizationId + '/calendar/invitations/' + invitation.id, {
  method: 'PATCH', revision,
  body: { version: invitation.version, response: 'present' },
})
```

Adapter la date d’exemple à une échéance future cohérente. Le client applique
CSRF et la confirmation tenant avant écriture. Après 409, relire et décider
explicitement ; aucun rejeu automatique. Les nouvelles tables sont inventoriées
pour la portabilité et les remises sont immuables. Un rollback refuse de
supprimer un historique existant : préférer une correction en avant.
## Calendrier ICS privé — UCG-CAL-005

CAL-004 et son correctif `defc140` sont poussés ; CI confirmées vertes par
l’utilisateur avant ce lot. CAL-005 ajoute un abonnement personnel ICS révocable.
Dans le calendrier, « Gérer mon lien ICS » exige `calendar.read` et la nouvelle
permission explicite `calendar.export`, un compte vérifié et la MFA. L’owner et
les comptes techniques ne disposent d’aucun accès métier implicite.

Le panneau permet de créer, renouveler et révoquer son propre lien, après
confirmation du tenant et de l’export. Copier le secret immédiatement : le serveur
ne conserve que SHA-256 du jeton aléatoire de 256 bits. Le lien disparaît à la
fermeture du panneau et n’est jamais enregistré dans localStorage/sessionStorage.
Un renouvellement invalide l’ancien lien ; le nouvel abonnement remplace l’URL
à configurer dans l’application de calendrier externe.

Chaque téléchargement sans session vérifie à nouveau l’organisation, le compte,
la MFA, les droits, la personne et ses équipes actuelles. Il utilise l’audience
personnelle même pour un gestionnaire. Seuls titres, horaires UTC et identifiants
ICS opaques sont exportés : ni description, lieu, lien de réunion, participant,
ressource, absence, note interne ou contrat. Les titres eux-mêmes sont exportés :
les relire avant de confier le lien à un service tiers.

Fenêtre glissante UTC `[minuit − before_days, minuit + after_days[` : 0–30 jours
passés, 1–90 jours futurs, cumul ≤ 93. Validité du secret : 1–90 jours. Défauts SPA :
7/60 jours et validité 30 jours. Au-delà de 500 activités ou 512 Kio, le flux refuse
l’export entier ; réduire la fenêtre. Pas de troncature silencieuse. Le quota
technique d’export du tenant reste applicable. Limites de téléchargement :
12/minute par empreinte du lien et 120/minute par IP, avec `Retry-After`.

Réponses `private, no-store`, sans ETag ni 304. Révoquer bloque les prochaines
synchronisations ; une réponse déjà en cours et les copies déjà importées ne
peuvent pas être rappelées. La fréquence de rafraîchissement et la suppression
des événements retirés dépendent du calendrier externe. Un lien expiré/révoqué,
un jeton erroné et une perte d’habilitation donnent le même 404.

Exemple SPA (client existant avec session/CSRF, sans jeton réel dans le code) :

```ts
import { tenancyRequest } from './api/tenancy'
import type { ApiSuccessResponse } from './api/contracts'
import type { CalendarFeed, CalendarFeedSecret } from './api/calendarFeed'

const path = `tenants/${organizationId}/calendar/feed`
const previous = await tenancyRequest<ApiSuccessResponse<CalendarFeed | null>>(path, { revision })
// Après confirmation humaine de l’organisation :
await tenancyRequest('active-organization/confirmation', {
  method: 'POST', body: { organization_id: organizationId, revision, confirm: true },
})
const issued = await tenancyRequest<ApiSuccessResponse<CalendarFeedSecret>>(path, {
  method: 'POST', revision,
  body: { expected_version: previous.data?.version ?? null, before_days: 7,
    after_days: 60, expires_in_days: 30, confirm_export: true },
}) // data = { feed, url }, url révélée une seule fois
await tenancyRequest(path, {
  method: 'PATCH', revision,
  body: { version: issued.data.feed.version, confirm_revocation: true },
})
```

Avant usage réel : appliquer la migration additive
`2026_10_17_164002_create_calendar_feeds_table` (aucune migration principale par
l’agent), configurer `APP_URL` HTTPS de confiance en staging/production, attribuer
explicitement `calendar.export` via IAM-003 puis recetter avec deux comptes et
une révocation. Ne jamais journaliser la query string `token` dans reverse proxy,
CDN, APM, analytics ou support. L’application retire le jeton de l’URL de requête
avant le traitement interne ; l’infrastructure doit aussi le masquer à l’entrée.
Aucun cache partagé du flux, aucun connecteur externe ni tâche planifiée requis.
Après restauration d’une sauvegarde, révoquer les liens restaurés avant ouverture.
Les reports/annulations et leurs notifications sont livrés par CAL-006 ci-dessous.

## Reports, annulations et messages internes — UCG-CAL-006

Modifier les horaires ou le fuseau d’origine d’une activité existante exige un
motif de 10 à 1000 caractères, dès le contrôle des conflits. Les mêmes contrôles
de réservation CAL-003 s’appliquent au nouveau créneau. Une modification du titre
seul ou la réaffirmation des mêmes horaires ne produit pas de message de report.

« Annuler l’activité » demande un motif puis la confirmation de l’organisation.
L’annulation est définitive : le créneau est libéré, les participants et
réservations sont conservés pour l’historique, les convocations précédentes
deviennent obsolètes et l’activité sort du calendrier courant et du flux ICS.
Les anciens clients PATCH doivent fournir change_reason avec archive:true ;
ce champ historique de transport désigne désormais une annulation explicite.
Les archives antérieures à CAL-006 restent distinguées des annulations.

Les gestionnaires calendar.read + calendar.write consultent l’historique paginé :
anciens/nouveaux horaires, fuseaux, motif, titre au changement et nombres de
destinataires, remises et blocages. « Mes notifications de planning » expose
uniquement ses propres messages remis, sous calendar.read et les accès actuels.
Un message ancien est signalé ; le calendrier courant reste la référence.
Aucune identité de compte ni liste de destinataires n’est rendue dans ces messages.

Les personnes concernées sont les participants explicitement inscrits avant ou
après le changement (100 au plus). L’équipe entière n’est pas développée en
destinataires. L’identité personne/compte est figée à l’émission ; un compte absent
n’est pas créé ou réaffecté automatiquement. Les droits du responsable et du
destinataire, leur compte vérifié/MFA, le rattachement et l’audience courants sont
relus à la remise. Perte d’accès = remise bloquée, sans création de permission.
Les messages déjà remis sont également filtrés par les accès actuels à la lecture.

Exemple avec les objets chargés, après confirmation du tenant :

```ts
import { tenancyRequest } from './api/tenancy'
import type { ApiSuccessResponse } from './api/contracts'
import type { CalendarActivity } from './api/calendar'
import type { CalendarChangePage } from './api/calendarChanges'

const path = 'tenants/' + organizationId + '/calendar/activities/' + activity.id
// Utiliser les dates réelles et le review_token du contrôle CAL-003 correspondant.
const moved = await tenancyRequest<ApiSuccessResponse<CalendarActivity>>(path, {
  method: 'PATCH', revision,
  body: { version: activity.version, starts_at: newStart, ends_at: newEnd,
    origin_timezone: activity.origin_timezone, change_reason: reason,
    conflict_review: reviewToken },
})
await tenancyRequest<CalendarChangePage>(path + '/changes?page=1&per_page=10', { revision })
await tenancyRequest(path, {
  method: 'PATCH', revision,
  body: { version: moved.data.version, archive: true, change_reason: cancellationReason },
})
```

Appliquer la migration additive
2026_10_17_220751_create_calendar_changes_and_recipients avant déploiement.
Elle conserve un historique immuable et chiffré ainsi que les destinataires ;
un rollback refuse de détruire ces données. Le traitement asynchrone utilise le
scheduler TEN-006 et la queue default, avec une nouvelle tâche calendar-changes :

```dotenv
CALENDAR_CHANGES_ENABLED=true
```

Le flag est false par défaut et le fichier .env réel n’est pas modifié.
Le scheduler et le worker partagés décrits pour CAL-004 doivent fonctionner ;
ce flag est indépendant de CALENDAR_INVITATIONS_ENABLED. La remise reste interne
à l’application, sans e-mail ni connecteur externe. Le lot traite 25 messages par
organisation et par passage, sous verrous et avec remise atomique sans doublon.
Mesurer retard et contention avant augmentation de charge ; la configuration
activée ne prouve pas que les workers sont opérationnels.

Après push/CI et migration, recetter avec deux comptes habilités, une perte
d’accès, un report et une annulation ; aucune donnée réelle ou permission n’a été
créée par l’agent. Les récurrences CAL-007 et la logistique CAL-008 restent P1.

## Dossiers Match et statuts — UCG-MATCH-001

« Scrims et matchs » fournit les rencontres de l’organisation, distinctes des
parties, scores et statistiques. CAL-006 064df63 est poussé, migré et ses CI
sont confirmées par l’utilisateur avant ce lot ; migration Ran batch 36
vérifiée en lecture seule. MATCH-001 n’applique aucune migration principale.

Le dossier contient titre, jeu, scrim/match officiel, format, période UTC avec
fuseau IANA, responsable parmi les personnes actives et participants ordonnés.
BO1/BO3/BO5 exigent deux participants ; lobby et chacun pour soi en acceptent
de 2 à 100. Ces formats décrivent le dossier ; aucun score de série n’est calculé.
Un participant peut être une personne interne, une équipe interne du même jeu,
ou un instantané d’adversaire déjà autorisé dans ce tenant. Le modèle n’impose
pas deux équipes. Aucun compte, adhésion ou accès n’est accordé à l’adversaire.

Les noms du jeu, du responsable et des participants sont conservés lors de la
sélection ; les libellés personnels, le titre et les motifs sont chiffrés.
Les renommages ultérieurs ne modifient pas ces instantanés. Le jeu reste immuable.
Les participants externes utilisent uniquement leurs instantanés existants :
préparer ceux-ci dans « Références d’adversaires » avec les droits correspondants.
Un nom libre, un identifiant d’un autre tenant ou un score dans le payload est refusé.

Permissions explicites existantes : sports.read pour consulter, sports.read +
sports.write pour créer/modifier/changer les statuts et sélectionner les références ;
sports.publish supplémentaire et confirmation pour élargir le partage interne.
Le propriétaire et les comptes techniques n’ont aucun accès métier implicite.
Par défaut, un dossier privé est visible au créateur, au responsable actuellement
lié à son compte et aux personnes explicitement inscrites, avec sports.read.
La visibilité équipe ajoute les membres actuels des équipes participantes ;
la visibilité organisation ajoute ses lecteurs sportifs. Le mode gestion est
un choix explicite de l’éditeur qui inclut les dossiers privés du tenant.

Parcours opérationnel : proposé → confirmé → en cours → terminé à valider.
Une rencontre confirmée peut revenir à proposé avant correction ; les champs
et participants ne sont modifiables qu’à l’état proposé. Annulation depuis
proposé/confirmé/en cours, définitive. Chaque changement de statut exige une
version courante et un motif de 10 à 1000 caractères, conservé dans l’historique
paginé. La confirmation et le démarrage recontrôlent jeu, responsable et
participants actuels sans réécrire les noms conservés. Un retrait de référence
n’empêche pas une annulation opérationnelle autorisée.

Le modèle prévoit aussi validé, contesté et forfait, avec leurs transitions
de domaine ; les commandes publiques qui touchent ces états sont bloquées
jusqu’au workflow des résultats et preuves MATCH-003/004. Aucune validation
sportive sans résultat n’est fabriquée. Lineups/remplaçants et éligibilité
relèvent de MATCH-002 ; les notes/VOD/débrief relèvent de MATCH-005.
Un horaire Match ne crée pas de réservation calendrier et ne prouve pas
l’absence de conflits. Aucun score, convocation, notification ou publication
publique n’est produit automatiquement par ce lot.

Exemple SPA après confirmation du tenant, avec références réellement chargées :

```ts
import { tenancyRequest } from './api/tenancy'
import type { ApiSuccessResponse } from './api/contracts'
import type { MatchRecord } from './api/matches'

const path = 'tenants/' + organizationId + '/matches'
const created = await tenancyRequest<ApiSuccessResponse<MatchRecord>>(path, {
  method: 'POST', revision,
  body: { title: 'Préparation', game_id: game.id, kind: 'scrim', format: 'bo1',
    starts_at: startWithOffset, ends_at: endWithOffset, origin_timezone: 'Europe/Paris',
    responsible_person_id: responsible.id,
    participants: [{ kind: 'team', source_id: localTeam.id },
      { kind: 'opponent_snapshot', source_id: opponentSnapshot.id }] },
})
await tenancyRequest(path + '/' + created.data.id + '/transitions', {
  method: 'POST', revision,
  body: { version: created.data.version, status: 'confirmed',
    reason: 'Participants et créneau vérifiés.' },
})
```

La SPA utilise les mêmes sélecteurs temporels que le calendrier : heure inexistante
refusée et choix explicite de l’occurrence répétée. Tout enregistrement/statut
passe par la confirmation humaine du tenant. Après 409, relire et décider ;
aucun rejeu automatique. Seuls les champs modifiés sont envoyés à l’édition.

Déploiement : sauvegarder puis appliquer la migration additive
2026_10_17_225945_create_matches_participants_and_status_events après push/CI.
Elle crée matches, match_participants et match_status_events, avec FK tenant,
contrôles SQL, instantanés gelés après confirmation et historique immuable.
Le rollback refuse des données conservées. Aucun seed, permission ou match
réel n’est créé par l’agent. Recetter deux comptes habilités, le partage interne,
la perte d’audience et une demande périmée. Le lot suivant, MATCH-002, est décrit ci-dessous.

## Compositions et remplaçants du match — UCG-MATCH-002

MATCH-001 (`6589368`) : push, CI et migration confirmés par l’utilisateur ;
migration vérifiée en lecture seule au batch 37 avant ce lot. MATCH-002 est
livré localement, sans migration principale ni création de données réelles.

Dans **Scrims et matchs**, ouvrir **Composition et remplaçants** sous une équipe
interne participante. Préparer d’abord une composition de purpose match dans
**Équipes**, puis la finaliser. Un compte avec sports.read/write peut l’affecter
au dossier avant démarrage. Sur un match partagé, sports.publish et confirmation
du partage sont nécessaires. Ces droits doivent déjà être attribués explicitement ;
aucune permission métier n’est ajoutée automatiquement au propriétaire.

Le démarrage exige une composition par équipe interne et refuse une personne
dans plusieurs camps. Les matchs individuels restent possibles sans composition
d’équipe imposée ; aucun roster adverse n’est consulté. Un remplacement échange
un titulaire et un remplaçant de la sélection retenue, avant ou pendant le match,
avec motif et confirmation. L’historique conserve le compte auteur, la date,
la phase, le motif et les noms retenus, même après modification du roster.
Les participants du dossier sont conservés dès la première affectation.
Après fin ou annulation, les compositions restent consultables et figées.

L’éligibilité est recontrôlée à chaque affectation/remplacement et au démarrage,
au moment sportif du dossier. Un règlement archivé, une preuve incomplète ou une
personne devenue inadmissible bloque la nouvelle opération sans réécrire le passé.
Sans règlement associé, l’écran affiche explicitement **éligibilité réglementaire
non évaluée** ; la présence au roster reste contrôlée. Les contrôles d’âge demandant
des faits privés restent soumis aux permissions administratives nécessaires.

Exemple depuis la SPA authentifiée et un tenant déjà confirmé (version et IDs
issus des lectures autorisées ; conserver la confirmation humaine du parcours) :

```ts
const path = `tenants/${organizationId}/matches/${matchId}/participants/${participantId}/lineups`
const assigned = await tenancyRequest<{ data: { match_version: string } }>(path, {
  method: 'POST', revision: active.revision,
  body: { version: match.version, lineup_id: finalizedLineupId,
    reason: 'Sélection vérifiée avant la rencontre.' },
})
await tenancyRequest(path + '/substitutions', {
  method: 'POST', revision: active.revision,
  body: { version: assigned.data.match_version, out_person_id: starterId,
    in_person_id: substituteId, reason: 'Remplacement demandé par le capitaine.' },
})
```

Ajouter confirm_sharing:true à l’affectation sur un dossier partagé après
consentement explicite. Recharger le dossier après un 409 ; ne pas rejouer la
commande. Choix et historique paginés, 500 changements au plus par dossier,
révisions courantes et faits sportifs groupés ; mesurer volumes/contention avant
d’augmenter ces limites. Réponses sans cache privé partagé ni données administratives.

Après push et CI : sauvegarder puis appliquer la migration additive
`2026_10_18_101019_create_match_lineup_revisions`. Elle crée le journal chiffré,
les FK tenant et les protections SQL append-only ; son rollback refuse une table
peuplée. Les migrations antérieures ne changent pas. Recetter avec deux comptes
habilités : affectation, remplacement en cours, fin figée, perte de permission,
renommage, roster devenu invalide et version périmée. Aucun seed, match réel,
notification externe ni attribution de droit n’est créé par l’agent.
Prochain P0 : **MATCH-003 — parties, cartes et score de série**, puis MATCH-004
pour les preuves et l’approbation des résultats.


## Parties, cartes et score de série — UCG-MATCH-003

MATCH-002 : push/CI/migration confirmés par l’utilisateur avant ce lot ; migration
vérifiée en lecture seule **Ran, batch 38**. MATCH-003 est livré localement ; sa
migration principale reste à appliquer par l’utilisateur après push et CI vertes.

Dans **Scrims et matchs**, ouvrir **Parties et scores**. Le match doit être en
cours ou terminé en attente de validation. Fixer d’abord les règles (motif,
nombre prévu et autorisation explicite des égalités), puis confirmer. Ces règles
et chaque partie sont définitives dans ce lot : vérifier avant de confirmer.
Il n’existe pas encore de parcours de correction ; celui-ci est prévu en MATCH-006.

BO1/BO3/BO5 comptent les victoires et s’arrêtent à la majorité ou au nombre prévu
1/3/5. Un nul autorisé consomme une partie sans victoire ; à la limite, le nombre
de victoires départage la série. Lobby/individuel additionnent les points déclarés
sur le nombre prévu ; le total le plus élevé gagne. Si les égalités sont interdites,
ni un nul de partie ni une égalité finale ne sont acceptés. Aucun total de série
n’est saisi manuellement. Les résultats restent **non approuvés** ; preuves et
approbation distincte sont réservées à MATCH-004.

Pour une partie : carte ou mode, version du jeu, résultat, score entier de chaque
participant et motif. Forfait/abandon : indiquer les scores attribués selon votre
règlement et expliquer la décision ; aucun barème officiel n’est inventé. Un
remake est une tentative sans score : saisir ensuite la partie rejouée ; il ne
supprime jamais une partie déjà enregistrée. Limite : 100 tentatives, remakes inclus.
Les noms et références de compositions retenus sont ceux présents **à la saisie**,
sans reconstitution automatique d’une composition passée lors d’une saisie tardive.

sports.read permet la lecture selon l’audience du dossier ; sports.write est
nécessaire à la saisie. Sur un match partagé, sports.publish et confirmation du
partage sont aussi exigés. MFA, confirmation tenant et versions courantes restent
obligatoires. Après 409, recharger et décider ; aucun rejeu automatique.

Exemple SPA authentifiée, tenant déjà confirmé et IDs issus de lectures autorisées,
après confirmation humaine des règles puis de la partie :

```ts
const path = `tenants/${organizationId}/matches/${matchId}`
const configured = await tenancyRequest<{ data: { match_version: string } }>(path + '/score-rules', {
  method: 'POST', revision: active.revision,
  body: { version: match.version, game_limit: 3, allow_draw: false,
    reason: 'Règlement BO3 confirmé avant saisie.' },
})
const result = await tenancyRequest<{ data: { match_version: string } }>(path + '/games', {
  method: 'POST', revision: active.revision,
  body: { version: configured.data.match_version, outcome: 'played', map: 'Carte pilote',
    game_patch: '1.0', reason: 'Score relevé après la partie jouée.',
    results: [{ participant_id: firstMatchParticipantId, score: 13 },
      { participant_id: secondMatchParticipantId, score: 8 }] },
})
// La commande suivante utilise result.data.match_version.
```

Sur un dossier partagé, ajouter confirm_sharing:true après consentement explicite
aux deux mutations. Historique : GET /games?page=1&per_page=10 ; le score de série
calculé et la version courante se trouvent dans scorecard. Contrat détaillé dans
[conventions API](docs/conventions-api.md).

Après push/CI : sauvegarder puis `php artisan migrate` pour appliquer
`2026_10_18_135008_create_match_score_rules_and_game_instances`. Deux nouvelles
tables chiffrées/append-only, sans backfill ni modification des anciennes migrations.
Le rollback refuse tout historique peuplé : privilégier une correction en avant.
Aucun migrate/seed principal, match réel, attribution de permission ni notification
externe n’est exécuté par l’agent. Recetter avec deux comptes habilités : BO3 2–0,
BO3 au meilleur des trois, refus d’une quatrième partie, remake, nul autorisé,
forfait/abandon, lobby à trois participants, révocation et version périmée.
Prochain P0 : **MATCH-004 — soumission, preuves et approbation des résultats**.
