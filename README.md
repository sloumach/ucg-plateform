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

Pour une modification de schéma ou de clé étrangère, SQLite seul ne valide pas
la livraison : exécuter aussi **toute la sélection PostgreSQL du workflow
backend-quality.yml**, sur une base de test temporaire distincte de la base
courante. Une sélection locale réduite doit être présentée comme telle, même si
la suite SQLite complète passe. Ne pas lancer deux suites PHP simultanément sans
isoler également leurs fichiers Storage::fake.

Les tests qui appellent directement `down()` maintiennent des dépendances manuelles.
Avant de livrer une nouvelle FK, rechercher ses tables parentes dans tous les tests
(`rg -n -- 'nom_table|nom_migration|->down\(' tests` en adaptant le motif) et vérifier
les chemins indirects : un rejeu Teams ou GameCatalogue peut aussi démonter Calendar.
Retirer les enfants avant les parents, les recréer après et vérifier leur présence
ainsi que le contrôle global du schéma. Ne pas contourner un oubli avec DROP CASCADE.

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
OPS-007 ajoute ensuite une suppression logique réversible avec `deleted_at` : voir
la décision de conservation illimitée et les API de suppression logique ci-dessous.

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


## Résultats, preuve référencée et approbation — UCG-MATCH-004

MATCH-003 (`085e3cc`) : push et CI validés par l’utilisateur avant ce lot ;
migration constatée en lecture seule **Ran, batch 39**. MATCH-004 est livré
localement ; sa nouvelle migration reste à appliquer après push et CI vertes.

Dans **Scrims et matchs → Résultats et validation**, soumettre un résultat joué
après saisie complète des parties, ou déclarer un forfait/abandon de rencontre
avec bénéficiaire explicite et motif. Aucun score fictif n’est créé pour une
décision attribuée ; les scores partiels restent conservés. La soumission fige
les scores et place le match en attente de validation.

Indiquer une référence précise vers la preuve conservée dans votre espace privé
(rapport, capture, document) et confirmer qu’un approbateur habilité peut la
consulter. **La plateforme conserve la référence, pas le fichier.** Elle ne
récupère pas de pièce externe et ne garantit pas son intégrité/disponibilité.
Le responsable conserve la pièce et contrôle ses accès hors de ce parcours.

Un autre compte avec sports.read + sports.validate consulte personnellement la
preuve et approuve avec un motif et une déclaration explicite. Il doit être
distinct du soumissionnaire et de tous les auteurs des règles/parties ; posséder
sports.validate ne permet pas d’approuver ses propres scores soumis par un autre.
Le résultat joué/abandon devient validé ; un forfait devient forfeited.

La contestation motivée conserve toutes les décisions/références antérieures et
passe le dossier en disputed. Une nouvelle proposition avec preuve référencée,
puis une nouvelle approbation indépendante, est nécessaire. Les corrections des
parties restent prévues en MATCH-006 ; aucune partie passée n’est réécrite ici.

sports.read/write pour soumettre ; read et write ou validate pour contester.
Le validateur sans write reste soumis à l’audience du match : ne pas lui attribuer
write pour contourner un problème de partage. Sur un match partagé, chaque auteur
et validateur doit aussi avoir sports.publish et confirmer le partage de la
preuve/décision à cette audience. MFA, tenant confirmé et version fraîche restent
obligatoires. Aucun droit n’est attribué automatiquement au propriétaire.

Exemple depuis la SPA authentifiée, après confirmation humaine, dans un tenant
confirmé et sur un résultat joué complet :

```ts
const path = `tenants/${organizationId}/matches/${matchId}/results`
const submitted = await tenancyRequest<{ data: MatchResult }>(path, {
  method: 'POST', revision: active.revision,
  body: { version: match.version, outcome: 'played', proof_reference: 'Rapport privé conservé R-2026-42',
    confirm_proof: true, reason: 'Résultat proposé selon le rapport conservé.' },
})
```

Après connexion avec le compte approbateur indépendant, lecture autorisée fraîche,
consultation personnelle de la preuve et confirmation humaine :

```ts
const current = await tenancyRequest<MatchResultPage>(path + '?page=1&per_page=10', { revision: active.revision })
await tenancyRequest(path + '/approvals', {
  method: 'POST', revision: active.revision,
  body: { version: current.result.match_version, submission_id: current.result.submission!.id,
    confirm_evidence: true, reason: 'Preuve consultée et résultat vérifié personnellement.' },
})
```

Types MatchResult/MatchResultPage dans `front/src/api/matches.ts`. Ajouter
confirm_sharing:true aux mutations d’un match partagé après consentement explicite.
Contestation : POST /disputes avec version, submission_id et reason (sans
confirm_evidence). Après 409 : recharger et décider, aucun rejeu automatique.
Historique paginé, 200 événements au plus ; détails dans [conventions API](docs/conventions-api.md).

Déploiement : push, CI vertes, sauvegarde puis `php artisan migrate` pour
`2026_10_18_144624_create_match_result_events_and_guards`. Migration additive sans
backfill : nouveau journal, adaptation des gardes Match/statuts et gel SQL des
scores. Rollback refusé si le journal contient des données ; privilégier une
correction en avant. Recetter avec deux comptes explicitement habilités : résultat
complet, auto-approbation refusée, preuve non confirmée, contestation après
validation/forfait, nouvelle proposition, droits révoqués et deux décisions concurrentes.
Aucun migrate/seed principal, compte/droit/match réel, e-mail ni notification
externe n’a été créé par l’agent. Prochain P0 : MATCH-005, préparation/VOD/débrief.


## Préparation, VOD et débrief — UCG-MATCH-005

MATCH-004 (f25f966) est poussé ; CI et migration validées par l’utilisateur,
constatées Ran batch 40 en lecture seule. MATCH-005 est livré localement.

Dans Scrims et matchs, ouvrir « Préparation, VOD et débrief » : créer objectifs,
notes, débriefs, liens VOD annotés avec horodatage en secondes, et actions de suivi
à faire/terminées avec échéance UTC. Les versions sont conservées et paginées.
Un débrief reste modifiable après approbation sans toucher au résultat sportif.

Les espaces internal, strategy et shared sont séparés et fixes. La lecture
exige sports.read et l’audience du match ; internal exige sports.notes.read,
strategy sports.strategies.read. La modification exige aussi sports.write et
sports.notes.write ou sports.strategies.write. Ces permissions sont disponibles
dans le catalogue des rôles mais ne sont attribuées à aucun compte automatiquement.
Même administration et propriété du tenant ne dispensent pas des délégations.

Shared exige sports.publish et une confirmation à chaque version. Il est visible
uniquement par les lecteurs authentifiés du match, sans endpoint anonyme. Les
notes internes ne deviennent jamais partageables par un changement de visibilité
du match ; rédiger une synthèse distincte et vérifier ses données avant partage.

Les VOD sont des liens HTTPS sans identifiants, ouverts à la demande sans référent.
Aucun téléchargement ou lecteur intégré ; une annotation réunit lien, horodatage
et notes, plusieurs annotations pouvant référencer la même vidéo. Limites : 500
contenus/match, 100 versions/contenu, texte 10000 caractères et pages de 100 maximum.
Aucun cache partagé de ces données sensibles.

Migration additive à appliquer après sauvegarde et CI :
`2026_10_18_155140_create_match_notebook_revisions` ; down refuse un historique
peuplé. Aucun migrate principal, seed, attribution réelle, push ou envoi externe
par l’agent. Prochain P0 après validation : MATCH-006, correction et recalcul.


Vérifications locales : suite SQLite complète 2083 tests, 2058 réussis et 25
exclusions attendues (Redis/PostgreSQL/ClamAV) ; sélection PostgreSQL complète
1871 tests, 1869 réussis et deux exclusions Redis. Les deux derniers scénarios
(débrief après approbation et sélection tenant périmée/non confirmée), ajoutés
pendant le lancement PostgreSQL complet, passent ensuite dans les 42 tests finaux
de MATCH-005 (288 assertions), dont une course réelle à deux processus. SQLite
final ciblé : 41 réussis, 275 assertions. Première commande Composer SQLite arrêtée
par son délai standard ; relance complète réussie avec COMPOSER_PROCESS_TIMEOUT=0.
319 tests frontend/46 fichiers, cinq parcours navigateur sportifs à API simulée,
build, lint, TypeScript strict, PHPStan niveau 8, Pint et Composer strict réussis.
Sept tests frontend du carnet relancés après l’ajustement de son libellé de filtre ;
parcours carnet relancé après normalisation des UUIDs simulés. Captures 320/375 px
inspectées, sans débordement ni exécution HTML. Bases PostgreSQL de test supprimées,
serveur Vite de recette arrêté ; aucun effet sur les données métier principales.


## Corrections de scores et recalcul — UCG-MATCH-006

MATCH-005 (f40d2a6) est poussé ; CI et migration validées par l’utilisateur,
constatées Ran batch 41 en lecture seule. MATCH-006 est livré localement.

Ouvrir « Corrections des scores » depuis un match : sélectionner les parties,
proposer leurs scores/résultats et un motif, puis confirmer. Les valeurs avant/après
sont conservées et chiffrées ; les parties d’origine restent intactes. La permission
sports.correct est distincte et doit être explicitement déléguée, avec sports.read/write.
Sur un match partagé, sports.publish et confirmation de partage sont requis.

Une approbation existante est contestée atomiquement. Le total est masqué pendant
le recalcul ; actualiser l’état ou utiliser « Relancer le recalcul » si nécessaire.
Après traitement, soumettre une nouvelle preuve avec la révision des scores actuelle,
puis faire approuver par une autre personne habilitée, distincte aussi des correcteurs.
La répétition d’une demande ou d’une tâche ne double ni score ni historique.

Configuration d’exploitation : file persistante database/redis/sqs/beanstalkd et
worker de la queue default. Les connexions sync/null/deferred/background sont
refusées à la création d’une correction. Aucune configuration .env ou activation
de worker principal par l’agent. Examiner les travaux en attente avant de démarrer
un worker ; une panne d’envoi ou un droit révoqué laisse la demande pending et
permet une reprise explicite par une personne actuellement habilitée.

Limites : parties existantes uniquement, règles/cartes/compositions/remakes fixes,
série cohérente et résultat complet restant complet. Au plus 100 corrections par
match, 100 parties/participants et 10 événements par page. Cache privé de série
isolé par version/correction et invalidé ; aucune diffusion publique automatique.
Les vues concernées actuellement livrées sont les scores, résultats et corrections
internes ; les canaux temps réel viendront avec MATCH-007.

Migration à appliquer après sauvegarde et CI :
`2026_10_18_184341_create_match_score_corrections_and_recalculations`.
Down refuse un historique peuplé. Aucun migrate/seed principal, droit réel,
push ou notification externe par l’agent. Prochain P0 : MATCH-007.


Vérifications finales MATCH-006 : SQLite complet 2129 tests, 2101 réussis,
28 exclusions attendues et 10792 assertions ; sélection PostgreSQL complète
1919 tests, 1917 réussis, deux exclusions Redis et 10139 assertions. Sous-ensemble
PostgreSQL final : 88 réussis, dont trois courses réelles (deux corrections,
répétition de la même clé, deux tâches de recalcul). Contrôles finaux SQLite de
correction/worker/schéma/policy et architecture : 47 réussis, 519 assertions.
Frontend complet : 327 tests dans 47 fichiers ; six parcours sportifs navigateur
à API simulée réussis, nouveau parcours correction → attente → reprise → preuve
et approbation indépendante relancé après correction de la synchronisation du test.
Captures 320/375 px inspectées, sans débordement ni interprétation du HTML.
PHPStan niveau 8, Pint, Composer strict, build/lint et TypeScript strict réussis.
Un test réseau ClamAV existant a échoué transitoirement dans un lancement ; ses
10 cas isolés puis la suite SQLite complète ont repassé, sans changement ClamAV.
Le dernier passage PostgreSQL utilise le code figé après correction du contrat de
file ; les échecs du lancement antérieur chargé avant ce changement sont remplacés
par ce passage complet réussi. Serveur Vite de recette arrêté ; bases PostgreSQL
de test supprimées et absence de reliquat contrôlée en lecture seule.
Migration principale MATCH-006 toujours Pending, aucune attribution réelle,
activation de worker, modification .env ou notification externe par l’agent.


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


## 18 octobre 2026 — UCG-COMP-001 : suivi privé des compétitions externes

MATCH-007 (`2c5d622`) poussé et CI confirmées par l’utilisateur avant ce lot.
Sa recette réelle Reverb/worker broadcasts reste à faire avant activation supervisée.
COMP-001 suit des compétitions organisées ailleurs, au sein du module Competition.

- Dossier interne : référence et jeu immuables, organisateur, région/saison,
  URL HTTPS et dix contacts structurés. Édition motivée, historique chiffré et
  archivage conservant la lecture, sans suppression ni réouverture.
- Documents privés : un règlement logique et des notes, texte chiffré, versions
  immuables et téléchargement authentifié `.txt`, y compris des anciennes versions.
  Aucun upload binaire ; pièces et échéances d’inscription réservées à COMP-002.
- Lecture : sports.read + sports.competitions.read, MFA. Écriture : droit
  sports.competitions.write supplémentaire, CSRF et contexte tenant confirmé.
  Aucun droit attribué automatiquement aux rôles existants.
- Verrous organisation puis dossier, versions attendues, audit atomique et
  gardes SQL. Un conflit 409 impose une relecture et une nouvelle confirmation ;
  aucune nouvelle tentative automatique. Réponses privées sans cache.
- Nouvelle migration additive `2026_10_18_210127`, en attente sur la base principale.
  Aucun migrate/seed principal, donnée métier réelle ou worker activé par l’agent.
  Après revue/push et CI vertes : sauvegarder, migrer depuis back/, déléguer les
  droits via IAM-003 puis recetter deux comptes et révocations. Prochain P0 : COMP-002.

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


## 19 octobre 2026 — UCG-COMP-002 : participation et échéances externes

COMP-001 (`abdb569`) poussé ; CI/migration confirmées par l’utilisateur.
Migration précédente constatée Ran, batch 43, branche staging initialement propre
et synchronisée. Ce lot ajoute un suivi interne par compétition externe.

- Statuts déclarés de l’inscription, du paiement, du roster et du check-in,
  référence/source externes et exigences d’âge, résidence et rang. Le paiement
  reste un statut renseigné ; aucun montant bancaire ni transaction financière.
- Roster borné à 20 personnes du tenant et 50 éléments de suivi : pièces demandées,
  inscription, paiement, roster, check-in ou tâches manager. Chaque élément a son
  état, une échéance ISO avec fuseau, un responsable et une note facultatifs.
  Les dates sont normalisées en UTC et les secondes sont conservées dans le formulaire.
- Pièces suivies par leur état et leur remise ; rattachement facultatif à une
  version texte privée de COMP-001. Aucun dépôt de PDF/fichier binaire dans ce
  parcours. Les originaux et leur transmission restent chez l’organisateur.
- Chaque écriture ajoute une révision chiffrée, attribuée et motivée. Contrôle de
  la version du suivi et du parent, verrous tenant/compétition, historique SQL
  protégé. Une modification du roster déjà approuvé impose de revoir son statut.
  Archivage de la compétition = suivi en lecture seule, historique conservé.
- Lecture : sports.read, sports.competitions.read et sports.registrations.read.
  Écriture : sports.registrations.write supplémentaire, MFA et tenant confirmé.
  Aucun besoin implicite sports.write/competitions.write ni grant automatique.
- Rappels internes personnels : seuils 7 jours, 1 jour et dépassement. Un rattrapage
  choisit le seuil le plus urgent, sans rafale. Clé durable par élément, échéance,
  responsable/compte et seuil, indépendante des notes et du cache de fin de job.
  Droits/MFA/appartenance actuels revérifiés ; aucun rappel pour élément terminé,
  responsable sans compte habilité ou compétition archivée. Aucun e-mail.

Nouvelle migration additive `2026_10_18_231153_create_competition_registration_tracking`,
Pending sur la base principale. Après push/CI vertes : sauvegarder puis
`php artisan migrate` depuis back/. Recetter les droits dédiés avec deux comptes.
Les rappels utilisent TEN-006 (scheduler, cache partagé et worker default), avec
`COMPETITION_REGISTRATION_REMINDERS_ENABLED=false` par défaut. Examiner la file
et recetter en environnement isolé avant activation supervisée du flag.
Aucun migrate/seed principal, attribution réelle, rappel métier ou push par l’agent.
Prochain P0 : COMP-003 ; recette réelle Reverb/broadcasts toujours distincte et requise.

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

Voir le contrat multipart dans [les conventions API](docs/conventions-api.md).

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

### Imports statistiques confirmés — UCG-STAT-003B

Le dépôt CSV prépare un aperçu privé ; seule une confirmation explicite autorise
la création des observations. STAT-003B ajoute confirmation, revalidation,
déduplication par organisation entre fichiers/déposants, résultats par ligne et
reprise sans recréer les observations acquises. Une valeur différente sur la même
origine est refusée sans écrasement : utiliser la correction autorisée STAT-002.

La migration additive `2026_10_19_175141` crée le suivi et les reçus immuables.
Appliquer après push, CI et sauvegarde, puis recetter en environnement isolé.
Ne pas modifier les migrations A/STAT-002 ni utiliser migrate:fresh.
`STATISTICAL_IMPORT_PROCESSING_ENABLED=false` reste la valeur par défaut.
Activer explicitement après recette et recharger configurations/workers selon
les procédures habituelles. Aucun .env réel, worker, fournisseur ou droit réel modifié.

La file reports traite dix lignes maximum par job et une transaction par ligne.
Un worker database/Redis est requis pour le traitement asynchrone ; le worker local
documenté ci-dessus écoute déjà cette file. Après panne, consulter le suivi puis
confirmer une reprise ; anciennes générations de jobs inertes, résultats conservés.
Limites 1 Mio/100 lignes inchangées. Reçus indexés par organisation/identité ; suivi
chargé en bloc pour les listes. Profiler les verrous tenant/catalogue avant tout
élargissement des quotas ou parallélisme supplémentaire.

Voir [le contrat API et ses exemples](docs/conventions-api.md) et
[les résultats de validation](docs/etat-projet.md).

### Agrégats et ratios — UCG-STAT-004

Les observations permettent de sélectionner un échantillon fixe et de demander
un calcul privé. Somme, moyenne pondérée, minimum, maximum, dernière valeur et
ratios par composants additifs versionnés ; les formules documentaires ne sont
pas exécutées. Taille d’échantillon, données absentes/invalidées et dénominateur
nul sont explicites. Les ratios ne sont jamais moyennés naïvement.

Une correction marque les seuls calculs dépendants obsolètes et déclenche un
recalcul sous les droits actuels du demandeur initial. L’interface masque l’ancien
résultat courant, conserve son historique et propose une reprise confirmée.
Une génération protège contre les jobs concurrents et les callbacks anciens.
Définitions et sélection restent figées ; nouvelles observations à sélectionner
dans un nouveau calcul. Les profils et comparaisons contextualisés restent STAT-005.

Migration additive 2026_10_19_183908 après push, CI et sauvegarde.
STATISTICAL_CALCULATIONS_ENABLED=false par défaut, indépendant du traitement CSV.
Recetter avant activation, puis recharger configuration/workers ; reports requis
pour l’exécution asynchrone. Aucun .env réel ni worker activé pour ce lot.
Ne pas modifier les migrations précédentes ni lancer migrate:fresh.

Limites : 1 000 sources, 100 calculs par demandeur/tenant et 1 000 révisions.
Valeurs/définitions privées chiffrées, calculs décimaux hors verrou, publication
courte protégée, dépendances indexées et fanout par pages de 25. Profiler les
volumes et verrous tenant avant d’augmenter ces limites ou la concurrence.

Voir [le contrat et les exemples API](docs/conventions-api.md) et
[la validation locale](docs/etat-projet.md). Commit demandé par l’utilisateur ;
push et CI à suivre. Aucune migration/seed principale ni activation réelle
effectuée pour STAT-004.

### Profils et comparaisons — UCG-STAT-005

Les lecteurs habilités peuvent consulter un joueur ou comparer deux joueurs,
par jeu et version de métrique, avec filtres de saison, patch, rôle, mode, carte,
adversaire, région, source et période. Chaque contexte reste séparé ; chronologie,
échantillons, données absentes/invalidées et dates d’observation/correction visibles.
L’historique du dictionnaire permet de consulter une ancienne définition.

Un écart n’est présenté que pour des groupes compatibles, disponibles et complets
couvrant les mêmes périodes. Les tailles différentes sont signalées ; aucun
classement automatique ni moyenne naïve de ratios. Les contextes non renseignés
restent explicitement non renseignés, sans équivalence métier déduite.

Consultation en lecture seule : les nouvelles sources/corrections entrent à
l’actualisation ; un changement de filtre masque l’ancien résultat. Les calculs
enregistrés STAT-004 conservent leur sélection fixe et leur historique séparés.
Aucun job, calcul enregistré, import ou profil administratif créé par cette lecture.
Aucune nouvelle migration ou activation de worker pour STAT-005.

Limites : un ou deux joueurs, fenêtre de 366 jours inclusifs, 1 000 candidats
maximum par joueur avant filtrage des champs chiffrés, dix groupes/observations
par page. Dépassement refusé plutôt que résultat partiel. Index et audiences
existants, relations chargées en bloc ; profiler les données et les plans avant
tout élargissement. Aucun cache privé susceptible de conserver un ancien contexte.

Voir [le contrat API](docs/conventions-api.md) et [les validations](docs/etat-projet.md).
Commit STAT-005 demandé par l’utilisateur ; push et CI distante à suivre. Aucun .env réel,
seed/migration principale, worker, fournisseur ou droit réel modifié.

### Tableaux de bord par rôle — UCG-REP-001

Cinq vues en lecture seule : direction, manager, coach, joueur et administration.
L’entrée « Tableaux de bord par rôle » est chargée à l’ouverture ; chaque carte
possède sa définition, son périmètre, sa période, sa date de lecture et sa
pagination indépendante. Fenêtre de 31 jours inclusifs maximum, dans le fuseau
de l’organisation ; les instantanés actuels restent indépendants de cette fenêtre.

Attribuer explicitement les permissions `reporting.direction`, `reporting.manager`,
`reporting.coach`, `reporting.player` ou `reporting.administration` via les rôles
configurables de l’organisation. Elles ne donnent aucun droit métier supplémentaire :
chaque carte exige aussi les permissions de son module et respecte son audience.
Aucun rôle ou compte réel n’est modifié automatiquement, même propriétaire.

Sources raccordées : effectif actif, contrats à échéance, livrables sponsors en
retard, activités, matchs, échéances d’inscription persistées, convocations et
disponibilités personnelles, calculs statistiques privés déjà enregistrés,
fiches sans compte et statuts d’import de personnes. Aucun terme financier,
contact civil, commentaire personnel ou contenu de fichier n’est projeté.
Les budgets et la progression agrégée restent explicitement indisponibles ;
les autres indicateurs du CDC attendent leurs sources métier. Aucun calcul,
import ou envoi n’est lancé depuis ces vues. Les agrégats/cache versionnés,
la fraîcheur et les recalculs automatiques relèvent de REP-002.

Aucune nouvelle migration, dépendance ou activation de worker. Implémentation
incluse dans le commit UCG-REP-001 ; à pousser et valider en CI puis en recette avec les droits
choisis. Contrat HTTP détaillé dans `docs/conventions-api.md`.

### Indicateurs différés — UCG-REP-002

Six cartes disposent d’un comptage versionné par statut, calculé en arrière-plan :
effectif actif, fiches sans compte, imports de personnes, contrats à échéance,
livrables sponsors en retard et échéances d’inscription persistées.
Les cartes utilisent les mêmes droits et périmètres organisationnels que REP-001.
Les résultats sont privés au demandeur, datés, conservés en révisions immuables
et masqués dès qu’une source change ou que leur validité maximale de cinq minutes
expire. Une lecture ne calcule rien et ne lance aucun job. Le recalcul est ciblé
sur la carte et les filtres explicitement confirmés ; pas de recalcul global automatique.

Dans l’interface, ces cartes affichent l’indicateur différé puis proposent les
détails actuels à la demande. Les autres cartes conservent leur lecture directe.
Les budgets, la progression sportive contextualisée et les métriques composites
sans définition métier ne sont pas estimés par ce comptage de statuts.

Migration additive `2026_10_20_184653_create_dashboard_indicator_snapshots` :
trois tables tenant et déclencheurs d’invalidation sur les seules sources suivies.
Sauvegarde, CI, migration et vérification de la file `reports` avant activation
par un opérateur. `DASHBOARD_INDICATORS_ENABLED=false` par défaut ; aucune
activation réelle effectuée. Les connexions synchrones sont refusées pour ce calcul.
L’interface conserve l’accès aux détails lorsque le traitement est indisponible.
Le réglage et les workers STAT-004 restent indépendants. Voir conventions-api.md
pour les endpoints et etat-projet.md pour les contrôles locaux.


La recherche **UCG-REP-003** est disponible dans l’espace organisation pour les
catégories permises : personnes, équipes, matchs visibles, compétitions et titres
ou références documentaires autorisés. Elle propose des suggestions et une
continuation explicite par curseur. Les parties peuvent être vides avec une suite ;
aucun total exhaustif n’est annoncé. Aucun contenu documentaire, contact privé,
index en clair ni nouvelle migration. Les droits financiers restreignent aussi les
métadonnées des documents contractuels. Voir `docs/conventions-api.md` pour les
filtres, limites, erreurs et exemples.

## Exports privés de pilotage — UCG-REP-004

Les utilisateurs habilités peuvent demander un CSV depuis les tableaux de bord,
puis consulter et télécharger leurs propres exports. La permission explicite
`reporting.export` s'ajoute aux permissions de la vue et du rapport. Elle est
privilégiée, limitée à l'organisation et n'est attribuée à personne automatiquement.

Six projections organisationnelles sont proposées : effectif actif, personnes sans
compte, qualité des imports de personnes, contrats à échéance, livrables en retard
et échéances de participation. Les vues à audience individuelle, les corps des
documents et les montants financiers détaillés ne sont pas exportés par ce parcours.
Les rapports datés acceptent une fenêtre inclusive de 31 jours ; les autres sont
des instantanés des données actuelles, sans filtre de période effectif.

La génération s'exécute sur la file persistante `reports` sous le contexte tenant
restauré par TEN-006. Limites : 500 lignes, 8 Mio de CSV, dix demandes non expirées
par tenant et une heure de disponibilité à partir de la demande. Les quotas
tenant peuvent être plus restrictifs ; aucun fichier partiel n'est publié.
Le CSV utilise UTF-8, le séparateur point-virgule et neutralise les formules.
Il est chiffré au repos, stocké via `TenantArtifacts`, sans URL publique,
et ses téléchargements sont réautorisés et audités.

Une modification transactionnelle de la source invalide le fichier. Le téléchargement
ne relance aucun calcul ; demander un nouvel export après un refus de fraîcheur.
Le nettoyage serveur supprime uniquement les artefacts dont la date d'expiration
est atteinte, même après révocation de l'utilisateur. Il libère le quota et laisse
les fichiers permanents et l'historique des demandes intacts. Les artefacts orphelins
d'une interruption de génération portent aussi cette expiration.

Migration additive :
`2026_10_21_224633_create_report_exports_and_artifact_expiry`.
Elle crée `report_exports` et ajoute une expiration nullable aux artefacts existants,
sans backfill ni modification de leur durée de conservation. Appliquer cette
migration après sauvegarde et validation CI, avant de déployer les nouveaux accès
aux artefacts. Son retour arrière refuse de supprimer un historique ou une
expiration déjà utilisée.

Valeurs par défaut dans `.env.example` :

```dotenv
REPORT_EXPORTS_ENABLED=false
EXPIRE_TENANT_ARTIFACTS_ENABLED=false
```

La recette doit vérifier dans un environnement isolé la migration, les permissions,
TEN-006, le cache partagé database/Redis, le worker reports et le nettoyage.
Les queues sync/null sont refusées. Après cette recette, l'opérateur peut activer
les deux réglages et le scheduler ; aucune activation réelle n'est effectuée
dans cette livraison. Le nettoyage borné peut aussi être exécuté par un opérateur :

```bash
php artisan tenancy:expire-artifacts
```

Un lot examine au plus 100 fichiers et arrête sa boucle après 45 secondes ; les
erreurs de stockage conservent la réservation et sont réessayables. Ne pas modifier
la date d'expiration pour contourner un échec. Les règles du stockage et les
traitements STAT-004/REP-002 restent indépendants.

### Académie — UCG-ACA-001

L’espace « Académie : cohortes et cycles » exige `academy.read`. La gestion
exige aussi `academy.manage`, sans permission automatiquement accordée aux mentors.
Après sauvegarde, appliquer la migration additive `2026_10_22_100213` avec le
parcours de livraison habituel. Elle n’a pas été appliquée à la base courante
pendant cette tâche. Un retour arrière refuse d’effacer des tables déjà utilisées ;
préférer une correction en avant. Conserver la clé de chiffrement des révisions.

Routes sous `/api/v1/tenants/{tenant}/academy` :

- `GET/POST cohorts`, `PATCH cohorts/{cohort}` ;
- `GET/POST cohorts/{cohort}/cycles`, `PATCH cohorts/{cohort}/cycles/{cycle}` ;
- `GET/POST cohorts/{cohort}/enrollments`, `PATCH cohorts/{cohort}/enrollments/{enrollment}` ;
- `GET cohorts/{cohort}/history`, `GET people` (sélecteur réservé aux gestionnaires).

Listes paginées par `page` et `per_page` (1 à 100). Les inscriptions peuvent être
filtrées par `cycle_id`. Les PATCH exigent la `version` UUID courante. Les retraits
ou fins d’inscription sont définitifs ; leur historique est conservé. Une personne
peut être inscrite sans compte, mais un mentor doit avoir un compte vérifié
actuellement membre ou propriétaire de l’organisation. Un compte lié suspendu ou
révoqué empêche une nouvelle affectation. Une affectation ne vaut jamais permission.

Exemple après connexion SPA avec cookie, CSRF et confirmation d’organisation :

```bash
# API_BASE et TENANT proviennent de votre environnement ; cookies.txt contient
# votre session de recette, CSRF_TOKEN et REVISION les valeurs courantes.
curl -X POST "$API_BASE/tenants/$TENANT/academy/cohorts" \
  -b cookies.txt -H "Origin: $FRONTEND_URL" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $CSRF_TOKEN" \
  -H "X-Tenant-Revision: $REVISION" -d '{"name":"Académie pilote"}'
```

Pour créer un cycle, envoyer `name`, `starts_on`, `ends_on`, `timezone` et
`timetable` (éventuellement `[]`). Un créneau contient `day` (1=lundi à 7=dimanche),
`starts_at` et `ends_at` (`HH:mm`). Jusqu’à 28 créneaux sans chevauchement, terminés
le même jour. Le programme ne crée aucune occurrence dans le calendrier. ACA-002
permet de rattacher une séance à une activité existante. Exemple de corps d’inscription :

```json
{"cycle_id":"UUID_CYCLE","person_id":"UUID_PERSONNE","role":"student","starts_on":"2026-10-01","ends_on":"2026-12-31"}
```

Les dates d’un cycle ayant des inscriptions sont figées. Les noms et créneaux
peuvent évoluer avec historique. Les listes n’exposent aucun dossier administratif,
coordonnée, note interne ou snapshot historique déchiffré. L’historique consultable
contient les métadonnées des changements ; les instantanés chiffrés sont conservés
pour maintenance. Les sauvegardes opérateur incluent explicitement les quatre tables.

### Académie — UCG-ACA-002

Dans un cycle actif, ouvrir « Séances, présences et exercices ». La lecture exige
`academy.read`, la gestion des séances `academy.manage` et la saisie/correction des
présences `academy.attendance.manage`. Ces permissions sont explicites : ni le rôle
de mentor ni le droit de gérer les séances ne donnent le droit de présence.
`calendar.read` est nécessaire pour choisir/confirmer une activité et saisir une
présence. Sans ce droit, le contenu Académie reste lisible selon ses propres droits,
mais les références et horaires du calendrier sont masqués.

Créer d’abord dans le calendrier une activité de type `academy`, partagée avec
l’organisation, non annulée et non archivée. La séance référence cette activité
existante, sa version et ses horaires ; elle ne publie aucune activité privée et
ne génère pas les occurrences du programme hebdomadaire. Son mentor responsable
est inscrit dans le même cycle, actif sur toute la période, avec un compte vérifié
actuellement membre ou propriétaire du tenant. Le cycle doit couvrir la séance.

Routes sous `/api/v1/tenants/{tenant}/academy/cohorts/{cohort}/cycles/{cycle}` :

- `GET session-activities?from_date=2026-10-01&to_date=2026-11-01` : borne finale
  exclusive, fenêtre de 1 à 93 jours ;
- `GET/POST sessions`, `PATCH sessions/{session}` ;
- `GET/POST sessions/{session}/attendances`,
  `PATCH sessions/{session}/attendances/{attendance}` ;
- `GET sessions/{session}/history` : métadonnées des modifications.

Listes et sélecteurs paginés par `page`/`per_page` (1 à 100 lignes par page).
Exemple de création après connexion, CSRF et confirmation du tenant :

```bash
curl -X POST "$API_BASE/tenants/$TENANT/academy/cohorts/$COHORT/cycles/$CYCLE/sessions" \
  -b cookies.txt -H "Origin: $FRONTEND_URL" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $CSRF_TOKEN" \
  -H "X-Tenant-Revision: $REVISION" \
  -d '{"title":"Placement collectif","activity_id":"UUID_ACTIVITE","activity_version":"UUID_VERSION_ACTIVITE","responsible_enrollment_id":"UUID_INSCRIPTION_MENTOR","exercises":[{"title":"Communication","instructions":null,"minutes":20}],"report":null}'
```

Chaque correction PATCH exige la `version` courante et un `change_reason` de
10 à 1 000 caractères. Une présence est créée avec `enrollment_id` et `status`
(`present`, `absent`, `excused`, `late`), plus `late_minutes` de 1 à 240 uniquement
pour `late`. L’inscription doit être active et couvrir toute la séance. Une ligne
non renseignée ne signifie jamais une absence. Les raisons et instantanés sont
chiffrés ; l’historique public ne dévoile pas leur contenu. Ne pas saisir de données
médicales ou psychologiques dans les exercices, comptes rendus ou motifs.

Un changement de version du calendrier bloque les nouvelles saisies jusqu’à
confirmation explicite par un gestionnaire de séances. Dès qu’une présence existe,
l’activité, la période et le responsable sont figés ; une modification ultérieure
de période nécessite une nouvelle séance. L’annulation est définitive et son PATCH
contient seulement `version`, `status: cancelled` et `change_reason`. Une séance ne
peut être terminée qu’après sa fin et ne peut ensuite redevenir planifiée.

Migration additive `2026_10_22_172532_create_academy_sessions_and_attendances` :
`academy_sessions`, `academy_attendances`, `academy_session_revisions`, plus un
index composite sur les inscriptions. FK tenant/cycle, unicités et gardes SQL
protègent les rattachements et l’historique sur SQLite/PostgreSQL. Le retour arrière
refuse les tables utilisées. Conserver la clé de chiffrement et inclure ces tables
dans les sauvegardes. Aucune migration courante ni permission réelle modifiée dans
cette livraison. Avant usage : revue/push, CI distante, sauvegarde, migration,
attribution explicite des droits puis recette avec comptes et API de recette.
Les objectifs, feedback et notes internes restent du périmètre ACA-003.

### Académie — UCG-ACA-003

Le suivi pédagogique se trouve dans « Objectifs et feedback » du cycle. L’espace
« Mes objectifs et retours » est indépendant des écrans de gestion du staff.
Les droits sont explicites, sans attribution automatique liée à une inscription :

| Permission | Accès |
| --- | --- |
| `academy.read` | Lecture staff des objectifs, retours partagés et historique filtré |
| `academy.manage` + `academy.read` | Création, modification et clôture des objectifs |
| `academy.feedback.manage` + `academy.read` | Publication, correction et retrait du feedback partagé |
| `academy.notes.manage` + `academy.read` | Lecture, rédaction, correction et retrait des notes internes justifiées |
| `academy.self.read` | Lecture personnelle des objectifs accessibles et retours partagés actifs |

Pour un objectif individuel, choisir une inscription étudiant du cycle ; `null`
signifie collectif. Le destinataire reste fixe après création, afin d’éviter de
partager d’anciens retours avec une nouvelle personne. Le mentor responsable doit
avoir un compte vérifié actuellement membre/propriétaire du tenant. Responsable
et destinataire doivent avoir une inscription active couvrant l’échéance, située
dans le cycle. Le destinataire peut ne pas encore avoir de compte.

La lecture personnelle résout la personne liée au compte courant côté People ;
aucun person_id fourni par le client. Elle exige une inscription étudiant active
à la date courante dans le fuseau du cycle. Les objectifs individuels d’autrui,
notes internes et retours retirés sont exclus avant pagination. Une personne partie,
une inscription clôturée/expirée, ou un compte sans personne liée ne reçoit aucun
objectif. Une adhésion révoquée perd aussi l’accès au tenant. Les mentors consultent
le suivi par les permissions staff ; leur affectation ne leur donne aucun droit.

La limite est de 100 inscriptions personnelles candidates simultanées, avec une
sentinelle de dépassement et un refus explicite, sans réponse tronquée. Les dates
candidates sont bornées en UTC puis comparées dans chaque fuseau IANA. Les listes
et sélecteurs sont paginés par `page` et `per_page` (1 à 100). Le sélecteur conserve
ses choix entre les pages sans charger l’ensemble des inscrits.

Routes staff sous `/api/v1/tenants/{tenant}/academy/cohorts/{cohort}/cycles/{cycle}` :

- `GET/POST objectives`, `PATCH objectives/{objective}` ;
- `GET/POST objectives/{objective}/feedback`,
  `PATCH objectives/{objective}/feedback/{feedback}` ;
- `GET/POST objectives/{objective}/notes`,
  `PATCH objectives/{objective}/notes/{feedback}` ;
- `GET objectives/{objective}/history` : métadonnées des révisions autorisées.

Routes personnelles sous `/api/v1/tenants/{tenant}/academy` :
`GET my-objectives`, `GET my-objectives/{objective}/feedback`. Aucun endpoint de
mutation ni d’historique personnel. Ces projections ne retournent pas les versions
de commande, identifiants des inscriptions, auteur du feedback ou justifications.

Exemple après connexion SPA, CSRF et confirmation de l’organisation :

```bash
curl -X POST "$API_BASE/tenants/$TENANT/academy/cohorts/$COHORT/cycles/$CYCLE/objectives" \
  -b cookies.txt -H "Origin: $FRONTEND_URL" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $CSRF_TOKEN" \
  -H "X-Tenant-Revision: $REVISION" \
  -d '{"title":"Améliorer les placements","description":"Préparer les rotations collectives","due_on":"2026-12-01","target_enrollment_id":"UUID_INSCRIPTION_ETUDIANT","responsible_enrollment_id":"UUID_INSCRIPTION_MENTOR"}'
```

Pour publier un feedback, POST sur `/objectives/{objective}/feedback` avec
`{"content":"Retour pédagogique partagé"}`. Pour une note interne, POST sur
`/objectives/{objective}/notes` avec `content` et `justification` (10 à 1 000
caractères). Ne pas envoyer de champ `audience` : la route fixe la classification,
qui reste immuable. Aucun passage direct d’une note interne vers le partagé.
Les titres/descriptions des objectifs et le feedback partagé doivent être rédigés
pour leurs destinataires. Ne pas saisir de données médicales ou psychologiques.

Chaque PATCH exige la version UUID courante et `change_reason` (10 à 1 000
caractères). Statuts d’objectif : active/completed/cancelled. Une clôture envoie
seulement version, status et change_reason ; elle demeure possible après retrait
d’une inscription sans réaffecter l’objectif. Elle est définitive. Un objectif
annulé ou un cycle/cohorte archivé bloque les nouvelles commandes de feedback.
Le retrait d’un retour (`status: withdrawn`) est définitif ; le staff conserve
son contenu et l’historique, tandis que la projection personnelle le masque.

Les textes, justifications et instantanés sont chiffrés au repos. L’historique
consultable expose seulement auteur, date, type et identifiant de ressource ; les
révisions internes sont exclues en SQL pour les lecteurs sans permission de notes,
y compris du total paginé. L’audit transverse ne contient pas les textes ou motifs.
Les anciennes valeurs ne sont pas exposées via l’API. Les sauvegardes et exports
opérateur autorisés restent des données privées, avec leur propre gouvernance.

Migration additive `2026_10_22_224821_create_academy_objectives_and_feedback` :
`academy_objectives`, `academy_feedback`, `academy_coaching_revisions`. Appliquer
après ACA-001/002 (l’index composite des inscriptions vient d’ACA-002), sauvegarde
et CI verte. FK tenant/cycle, index, gardes d’audience et historique append-only
sur PostgreSQL/SQLite. Le retour arrière refuse les tables utilisées. Inclure
les trois tables dans les sauvegardes et conserver la clé de chiffrement.
Aucune migration de la base courante ni attribution réelle de permissions effectuée
par cette livraison ; recette avec comptes et API de recette avant usage.

## Préparation des environnements — UCG-OPS-001

La migration ACA-003 et sa CI distante sont confirmées par le responsable du projet
le 23 octobre 2026. Les exemples locaux restent dans `back/.env.example` et
`front/.env.example`. Les nouveaux profils `back/.env.staging.example`,
`back/.env.production.example`, `front/.env.staging.example` et
`front/.env.production.example` sont des gabarits sans secrets, indépendants du
fournisseur. Ils utilisent des domaines `.invalid`, des secrets vides et gardent
les traitements métier désactivés. Ils ne sont pas directement exécutables.

### Préparer une recette isolée

1. Provisionner des ressources distinctes : PostgreSQL/base/rôle, Redis et ACL,
   volumes privés, domaines, identifiants Reverb, secrets et comptes opérateur.
   Les préfixes `ucg_staging` et `ucg_production` facilitent les contrôles ; ils
   ne remplacent pas les ACL ni la séparation réseau. Utiliser deux domaines
   de cookies disjoints : la recette ne doit pas être sous le domaine de cookie
   de production. Adapter les `.invalid` aux domaines réellement choisis.
2. Fournir les paramètres côté serveur par injection runtime ou fichier monté
   hors du dépôt, avec accès réservé au compte applicatif. Ne pas envoyer de
   secrets dans les arguments shell, logs CI ou artefacts. Injecter `APP_KEY`,
   mots de passe DB/Redis et `REVERB_APP_SECRET` depuis le futur gestionnaire.
   Le diagnostic suppose des variables DB/Redis séparées, sans `DB_URL` ni
   `REDIS_URL`, un disque local privé et Redis pour cache/queues.
3. Monter un volume persistant propre au profil, hors de `public`, et exporter
   `LARAVEL_STORAGE_PATH` avant le démarrage de PHP (y compris avant le chargement
   de `.env`). Créer ses sous-dossiers Laravel et droits d'accès dans le futur
   déploiement. Les noms d'exemple `/srv/ucg_staging/storage` et
   `/srv/ucg_production/storage` doivent être adaptés sur Windows. Les sessions,
   logs et fichiers privés ne doivent jamais partager un volume entre profils.
4. Partir d'une base vide et de scénarios fictifs : organisation de recette,
   propriétaire, staff, joueur et utilisateur d'un autre tenant, sans données
   personnelles réelles. Créer les comptes par le parcours d'inscription et
   attribuer les droits explicitement. Ne pas exécuter `DatabaseSeeder` en recette
   (réservé au local, mot de passe de démonstration connu), ni importer une base,
   un export, une sauvegarde, des fichiers, logs ou clés de production. Les
   factories des tests fournissent des données synthétiques pour la CI ; elles
   ne constituent pas un peuplement de l'environnement de recette.
5. Après injection et construction du cache de configuration dans la release
   cible, lancer le contrôle depuis `back` :

   ```bash
   php artisan ops:check-environment --expect=staging --json --no-interaction
   # Sur la release de production : --expect=production
   ```

   Code 0 : contrôles locaux conformes ; 1 : configuration incomplète ; 2 : profil
   attendu absent/invalide. Le rapport ne contient aucune valeur de configuration.
   Il ne contacte ni base, ni Redis, ni fichiers distants, et ne valide pas les
   ACL, la connectivité, la provenance des données, la qualité ou la non-réutilisation
   des secrets. `OPS_SECRETS_SOURCE` et `OPS_DATA_CLASSIFICATION` sont déclaratifs.
   Vérifier ces points sur l'infrastructure avant de clôturer OPS-001.
6. Pour le frontend, injecter uniquement les variables publiques du gabarit et
   sélectionner explicitement `VITE_DEPLOYMENT_ENV=staging` ou `production` avant
   `npm run build`. Le contrôle Vite refuse les noms `VITE_` non documentés et,
   pour un profil distant, les URL HTTP, domaines `.invalid`, identifiants dans
   l'URL et paramètres Reverb incohérents. La clé `VITE_REVERB_APP_KEY` est publique ;
   `REVERB_APP_SECRET`, `APP_KEY`, jetons et mots de passe restent côté serveur.
   Une valeur secrète placée sous un nom public autorisé reste exposée : la revue
   des variables reste nécessaire. Le mode Vite `production` seul ne sélectionne
   pas le profil distant ; sans `VITE_DEPLOYMENT_ENV`, le profil reste local.

Les fichiers `.env*` réels sont exclus de Git dans les deux applications ; seuls
les trois gabarits par application sont autorisés. Ne jamais réutiliser un cache
de configuration Laravel ou un build frontend provenant d'un autre environnement.
La recette utilise le transport mail `log` ; les corps de messages et logs restent
privés et soumis à rétention. Le gabarit production conserve également `log` en
attendant le choix explicite du transport. Aucun worker, scheduler, serveur Reverb
ou traitement métier n'est activé par cette préparation.

### Gestion et rotation des secrets

- Inventorier par profil le responsable, usage, emplacement dans le gestionnaire,
  droits d'accès, date de rotation et procédure de révocation, sans inscrire les
  valeurs dans les tickets. Accorder au runtime uniquement les secrets de son
  profil et au CI uniquement les droits nécessaires. Les fichiers temporaires,
  caches de configuration et sauvegardes contenant des secrets restent privés.
- Pour DB/Redis/mail/intégrations : créer le nouvel identifiant dans le service,
  injecter sa version dans la release, reconstruire sa configuration, redémarrer
  les processus longs concernés, vérifier les accès puis révoquer l'ancien.
  Préparer un retour à la version précédente avant révocation. Les redémarrages
  et déploiements automatisés relèvent d'OPS-002 ; rien n'est exécuté ici.
- Pour Reverb : coordonner serveur, diffuseur et frontend ; rebâtir le frontend
  si la clé publique change, vérifier connexion et autorisation des canaux privés
  avant de retirer les anciens identifiants. Le secret ne passe jamais dans Vite.
- `APP_KEY` protège des données persistées : ne jamais lancer `key:generate` sur
  un environnement peuplé pour effectuer une rotation. Préparer une sauvegarde
  restaurable (OPS-004), conserver les anciennes clés dans `APP_PREVIOUS_KEYS`,
  injecter la nouvelle clé puis vérifier lecture des données historiques et
  nouvelles écritures. L'ancienne clé ne peut être supprimée qu'après réchiffrement
  contrôlé de toutes les données concernées et traitement des sauvegardes selon
  leur rétention. Cette livraison ne fournit pas ce réchiffrement et ne tourne
  aucune clé. Générer les clés initiales uniquement pour des environnements vides.
- En cas d'exposition : révoquer, remplacer et rechercher les usages ; retirer
  le fichier de Git ne suffit pas à rendre un secret publié confidentiel.

OPS-001 dispose ainsi d'un socle de préparation local. Son acceptation complète
attend le choix de l'hébergement/gestionnaire, les ressources de recette, le
peuplement fictif et la vérification effective de leur séparation. OPS-002/003/004
restent des tâches distinctes.

## Parcours navigateur avec API réelle — UCG-QA-003

Depuis `front`, après installation des dépendances frontend/backend et d'un
navigateur pris en charge par Playwright :

```bash
npm run test:e2e:harness
npm run test:e2e:real
# Facultatif, navigateur visible : npm run test:e2e:real -- --headed
```

Pour utiliser Chrome déjà installé, définir `PLAYWRIGHT_BROWSER_CHANNEL=chrome`
dans le shell. Sinon la suite utilise le Chromium installé pour Playwright.
Elle demande PHP avec SQLite/PDO SQLite et les dépendances Composer de développement.
Ne pas lancer directement la configuration `playwright.real-api.config.ts` : le
lanceur prépare et nettoie les ressources dont elle dépend.

La suite exerce le navigateur et Laravel sans réponse API simulée :
identifiants incorrects, connexion, MFA TOTP réel, sélection d'organisation,
confirmation, création d'une personne, rechargement, changement de tenant et
déconnexion. Elle contrôle aussi les refus CSRF, MFA, ressource étrangère, ancien
contexte et écriture sans permission, ainsi que les largeurs 375 et 320 px.

Le second scénario crée une personne active et deux équipes par l'interface,
ajoute une appartenance puis transfère la personne avec changement de fonction
sportive et clôture la période de destination. Il vérifie la persistance et
l'historique après rechargement, le chevauchement, les identifiants étrangers,
la version périmée et la clôture définitive. Les écritures dans l'organisation
en lecture seule sont refusées. Les formulaires et actions sont exercés à 320 px.

Le troisième scénario vérifie le calendrier : indisponibilité signalée sans
exposer son commentaire, exception refusée sans habilitation, choix d'un créneau
libre, activité privée avec équipe et participants, changement d'horaires et de
participants puis annulation. Horaires stockés en UTC et affichés en Asia/Tokyo,
persistance après rechargement, historique, refus de versions périmées et accès
inter-tenant sont contrôlés. Les formulaires sont utilisés à 320 px, sans envoi
réel de notification.

Le quatrième scénario crée un match privé BO1 entre deux équipes, affecte leurs
compositions finalisées puis confirme et démarre le match. Il fixe les règles,
saisit une partie et soumet une preuve fictive. Un second compte habilité approuve
le résultat ; l'auto-approbation reste refusée même avec le droit de validation.
Une correction inverse les scores, déclenche un vrai job de recalcul dans la
SQLite temporaire et invalide l'approbation. Les valeurs d'origine restent figées,
une nouvelle preuve et une seconde validation indépendante sont nécessaires.
Historique avant/après, persistance, version périmée, références étrangères,
lecture seule et formulaires à 320 px sont contrôlés.

Le cinquième scénario crée et modifie un contrat avec partie interne et obligation,
enregistre ses conditions financières puis le passe en revue. L'auto-approbation
est refusée. Un compte administratif sans accès financier ne reçoit ni montant ni
modalité sensible, ne peut pas modifier les conditions ni approuver ce dossier.
Un approbateur indépendant peut les consulter et approuver, sans les modifier.
Le parcours déclare ensuite une signature reçue hors plateforme, active puis
résilie le contrat. L'archivage reste bloqué tant que l'obligation est ouverte ;
son accomplissement permet l'archivage définitif. Versions périmées, gel des
conditions, historique, rechargements, isolation et mobile sont vérifiés.

Le sixième scénario demande un export d'effectif actif, attend sa génération par
le worker et télécharge le CSV. Il vérifie les lignes attendues, l'absence d'identité
administrative et de données étrangères, ainsi que la neutralisation d'une formule.
Un même identifiant de demande retourne le même export. Un autre compte disposant
des mêmes permissions ne peut pas le lire ; un lecteur sans reporting.export ne
peut pas le demander. Une donnée d'export déjà échue vérifie le refus d'expiration,
sans avancer l'horloge des sessions. Une modification de personne invalide le CSV
précédent. Un second export dans Beta confirme la séparation des contenus.

Le panneau réutilise la confirmation explicite d'organisation des autres modules :
une session nouvellement ouverte peut confirmer sa première demande depuis les
exports, sans devoir écrire dans un autre module au préalable.

Le septième scénario couvre les invitations d'organisation, avec un gestionnaire
et un destinataire vérifié déjà enrôlé MFA. Invitation expirée et refus d'une autre
organisation sans accès accordé, création avec rôle Membre et période, doublon et
délégation administrateur refusés, révocation puis réémission et acceptation par
le destinataire : le parcours utilise les formulaires et confirmations réels.
Une seule adhésion est créée, sans permission de gestion ajoutée. Invitations
étrangères, autre compte, rôle injecté et nouvelle acceptation sont refusés.
Historique, accès après rechargement et largeurs 375/320 sont contrôlés. Les e-mails
restent dans la file notifications, non consommée ; aucun envoi SMTP réel.

Le huitième scénario utilise un propriétaire fictif sans MFA initial : accès métier
bloqué, mot de passe incorrect refusé, activation liée à la session et clé TOTP
réellement générée par le serveur. Un client RFC 6238 indépendant calcule les codes
depuis cette clé. Après activation, les dix codes de secours sont lus une seule
fois dans l'interface ; ils ne réapparaissent pas au rechargement. Un code TOTP
rejoué et un code de secours consommé sont refusés. Récupération, connexion TOTP
sur un nouveau pas temporel, renouvellement des codes et révocation des autres
sessions sont contrôlés avec deux contextes navigateur. Le propriétaire ne peut
pas désactiver son MFA obligatoire. Les limites d'opérations restent actives : le
scénario vérifie le refus 429 puis attend le Retry-After avant de reprendre. Il
peut donc durer plusieurs minutes. La suite n'avance pas l'horloge serveur.

Chaque lancement crée un dossier temporaire propre, une SQLite vide, une clé de
chiffrement éphémère et des sessions/fichiers/caches isolés. Il applique les
migrations uniquement à cette nouvelle base et peuple seize organisations
fictives : sept comptes de scénario avec deux organisations chacun, un propriétaire
MFA avec sa propre organisation et un tenant inaccessible. Les comptes de contrôle
comprennent un destinataire d'invitation,
un approbateur pour les matchs,
un approbateur et un gestionnaire administratif pour les contrats, deux comptes
de contrôle des droits d'export, soit quatorze comptes de scénario/intervenants.
Les scénarios n'utilisent pas les écritures de l'autre. Les identifiants de ces fixtures sont publics et réservés
aux tests ; ne pas les employer en recette ou production. Aucun contournement
de connexion, MFA, CSRF ou autorisation n'est installé dans l'application.

Les deux serveurs de test démarrent sur des ports locaux disponibles, sans
réutilisation de serveurs existants. Un worker de test consomme uniquement les files
`default,reports` de cette même SQLite, avec une durée maximale de dix minutes. Son entrée
PHP refuse une file synchrone ou une connexion de file externe. Tous ces processus sont lancés directement
puis arrêtés avant suppression du dossier temporaire, y compris en cas d'échec.
Les paramètres applicatifs hérités et les `.env` de développement sont exclus.
Les requêtes du navigateur vers des origines autres que ces deux serveurs sont
bloquées. Seul `--headed` peut être ajouté ; une autre configuration ne peut pas
être substituée au lanceur par argument CLI.

Le bootstrap réservé aux tests configure cette SQLite en WAL, avec délai d'attente
de cinq secondes et transactions IMMEDIATE, afin de sérialiser les écritures du
serveur et du worker. Ces réglages ne modifient pas `back/config/database.php` ni
les bases de développement. Le profil e-mail reste `array`, même si le poste
fournit des paramètres SMTP.

La CI frontend exécute ces scénarios après la suite Playwright existante. Rapports :
`front/reports/playwright-real.xml`, `playwright-real-html` et
`playwright-real-results`. En cas d'échec, une trace, une capture et, s'il existe,
le journal applicatif fictif `playwright-real-application.log` sont conservés.
Les rapports CI ont une rétention de 14 jours ; ils ne doivent contenir que des
données de test. Les données et la clé temporaires sont supprimées.

Le scénario a révélé un cache du catalogue global incompatible avec le refus de
désérialiser des classes. Le cache utilise désormais des attributs simples sous
une clé de format `v2`, puis reconstruit les modèles en mémoire. Les anciennes
clés `v1` expirent selon leur durée existante de cinq minutes ; aucune purge
globale ni modification du réglage `cache.serializable_classes=false` nécessaire.

QA-003 reste ouvert pour les cas avancés MFA et d'invitation,
rapports exportables autres que l'effectif actif, les documents/avenants/rappels des contrats et les cas avancés des matchs, ainsi que les cas de
réservation, récurrence et notifications du calendrier. Cette
suite locale/CI utilise SQLite ; elle ne remplace pas une recette PostgreSQL ni
la validation des workers de recette/production et du temps réel Reverb.

### Bilan QA-003 au commit c1d5f34 et recette PostgreSQL préparée — 24 octobre 2026

Les huit parcours couvrent le périmètre principal de QA-003, à 375/320 px sous
Chrome avec API Laravel réelle. La matrice et les limites de couverture figurent
dans `docs/etat-projet.md`, section « Bilan de couverture QA-003 ». La matrice
REC-23 à REC-36 appartient à QA-002 ; concurrence et performances relèvent aussi
de QA-004/005. Les huit parcours ne constituent pas une validation globale de ces
tickets ni une autorisation de mise en production.

La CI backend possède déjà PostgreSQL 17 et une sélection de tests, y compris les
tests de schéma et de concurrence. Cette sélection inclut désormais les quatre
fichiers Contrats auparavant exécutés seulement dans la suite SQLite : audit des
accès, gouvernance financière, rappels et e-mails de rappel. Le rapport est
`back/reports/phpunit-postgresql.xml`, conservé 14 jours. La CI frontend exécute
les huit parcours avec SQLite ; son lanceur refuse volontairement PostgreSQL.

Ordre de recette à appliquer **dans le job CI et sa base PostgreSQL jetable**,
depuis `back`, avec les variables du step PostgreSQL déjà positionnées :

```bash
# Régressions des démontages de parents et refus de perte d'historique.
php artisan test --compact tests/Feature/PersonSchemaTest.php tests/Feature/GameCatalogueSchemaTest.php tests/Feature/TeamSchemaTest.php --log-junit=reports/phpunit-postgresql-schema.xml
# Droits financiers, audit, rappels et déduplication.
php artisan test --compact tests/Feature/ContractAccessAuditTest.php tests/Feature/ContractFinancialGovernanceTest.php tests/Feature/ContractReminderTest.php tests/Feature/ContractReminderMailTest.php --log-junit=reports/phpunit-postgresql-contracts.xml
# Collisions entre processus ; aucune exclusion SQLite ne doit masquer ces courses.
php artisan test --compact tests/Feature/CalendarConflictConcurrencyTest.php tests/Feature/MatchResultConcurrencyTest.php tests/Feature/MatchCorrectionConcurrencyTest.php --log-junit=reports/phpunit-postgresql-concurrency.xml
```

Ces groupes sont déjà inclus dans le step complet du workflow backend ; les
commandes ciblées servent au diagnostic d'un échec. Leurs noms de rapports sont
compatibles avec l'upload existant `phpunit*.xml` lors d'un diagnostic CI.

Préconditions : service PostgreSQL 17 neuf, base dédiée `ucg_platform_test`, rôle
de test pouvant créer les bases temporaires des courses, `DB_CONNECTION=pgsql`,
`DB_URL` vide, profil testing et clé applicative de CI. Ces tests peuvent reconstruire
leur schéma : ne pas réutiliser une base de développement ou de recette partagée.
Les rollbacks sont exercés par les tests de schéma : aller-retour à vide et refus
de supprimer un historique peuplé, sans rollback global d'une base réelle.

La validation attend zéro échec, des exclusions expliquées (notamment Redis dans
la sélection PostgreSQL), et l'exécution effective des courses PostgreSQL. Relever
le SHA, les versions et les JUnit. Les parcours navigateur sur PostgreSQL demandent
un futur lanceur distinct, qui devra créer et vérifier sa base propre, isoler ses
sessions/fichiers/caches et la supprimer après arrêt des processus ; remplacer
simplement DB_CONNECTION est refusé par les garde-fous actuels.

Lors de ce bilan, PDO PostgreSQL et psql sont présents, mais le moteur Docker n'a
pas répondu. Aucun test PostgreSQL local ni nouvelle CI distante n'est donc déclaré
réussi. La qualification hébergée, SMTP, antivirus, Reverb et restauration restent
des validations séparées ; l'hébergement reste différé.

Vérification distante du 24 octobre 2026 : au SHA `c1d5f34`, les CI
[backend](https://github.com/sloumach/ucg-plateform/actions/runs/40046960453) et
[frontend](https://github.com/sloumach/ucg-plateform/actions/runs/40046960495)
sont terminées avec succès. L'API GitHub confirme notamment les étapes PostgreSQL,
Redis et navigateur avec API réelle. Les trois artefacts de rapports sont présents
et non expirés à cette vérification. Leur contenu n'a pas été téléchargé ni relu :
les décomptes et exclusions des JUnit restent à examiner avant clôture de QA-003.
Ce résultat ne valide pas les parcours navigateur sur PostgreSQL, absents du
lanceur de ce SHA, ni les autres tickets QA ou la recette hébergée.

### Parcours navigateur sur PostgreSQL temporaire — UCG-QA-003

Le lanceur propose maintenant un mode PostgreSQL 17 explicite. SQLite reste le
défaut de `npm run test:e2e:real`. Le mode PostgreSQL crée un **nouveau cluster**
dans son répertoire temporaire, écoute uniquement sur `127.0.0.1` avec un port
libre et utilise un compte/mot de passe propres au lancement, avec authentification
SCRAM. Aucun serveur existant ni variable `DB_*` ou `PG*` héritée n'est réutilisé.
Docker et le service PostgreSQL local ne sont pas nécessaires.

Prérequis : PHP avec `pdo_pgsql`, PostgreSQL **17** et ses outils `initdb`,
`pg_ctl`, `createdb` dans le PATH, ou leur répertoire absolu canonique dans
`UCG_BROWSER_PG_BIN`. Exécuter avec un utilisateur ordinaire, pas root.
Depuis `front`, sous Windows :

```powershell
$env:UCG_BROWSER_PG_BIN = 'C:\Program Files\PostgreSQL\17\bin'
npm run test:e2e:harness
npm run test:e2e:postgresql:harness
npm run test:e2e:postgresql
```

Sous Linux, par exemple avec les binaires PGDG installés :

```bash
export UCG_BROWSER_PG_BIN=/usr/lib/postgresql/17/bin
npm run test:e2e:harness
npm run test:e2e:postgresql:harness
npm run test:e2e:postgresql
```

Le garde PHP vérifie le jeton, le manifeste, le profil testing, les coordonnées
exactes, la version 17 et le `data_directory` renvoyé par le serveur **avant**
de charger Laravel. Le seed exige un schéma public vide avant toute migration.
Les sessions, fichiers, caches, queues, clé applicative et données fictives restent
propres au lancement ; les e-mails utilisent array et les broadcasts log.
La file database/reports est réellement consommée dans cette base temporaire.

Après arrêt des serveurs HTTP/worker, `pg_ctl` arrête uniquement le cluster dont
le chemin, le jeton et le fichier PID correspondent au lancement ; le répertoire
est ensuite supprimé. L'arrêt fonctionne aussi après annulation. En cas de doute
sur la propriété ou d'échec d'arrêt, le lanceur échoue et conserve le chemin pour
diagnostic : ne jamais supprimer un répertoire contenant une instance encore active.
Un arrêt brutal du système/SIGKILL ne peut pas garantir ce nettoyage.

Les rapports PostgreSQL sont séparés : `reports/playwright-real-postgresql.xml`,
`reports/playwright-real-postgresql-html` et `reports/playwright-real-postgresql-results`.
Le workflow frontend a une matrice SQLite/PostgreSQL et des artefacts distincts
`playwright-reports-sqlite` / `playwright-reports-postgresql`. Le job PostgreSQL
installe les binaires 17 depuis le dépôt PGDG officiel, sans création de cluster
standard ; le lanceur crée son propre cluster sans utiliser les services du runner.
Cette nouvelle matrice doit encore être validée sur la CI distante après livraison.

Validation locale du lot : huit parcours réussis sur PostgreSQL (environ 6,2 min)
et huit sur SQLite (environ 4 min), sans échec, erreur ou exclusion dans les XML.
Arrêt et suppression des environnements confirmés. Les six tests de garde-fous,
le test d'intégration PostgreSQL, les 493 tests frontend, le typage, le lint,
Pint et PHPStan sont verts. Ces résultats ne ferment pas les autres tickets QA
ni les validations de services externes ou d'exploitation.

### Matrice d'isolation — premier lot UCG-QA-002

Le premier lot a introduit neuf cas critiques dans le groupe PHPUnit
`tenant-isolation`, dont huit nouveaux : URL étrangère non révélatrice, changement de contexte, recherche et
suggestions, révocation avant traitement d'export et révocation d'accès aux
caches/fichiers. Les tests d'exports capturent le job demandé par HTTP, le
sérialisent puis l'exécutent et le rejouent via les middlewares du bus ; aucun
worker ni stockage de production n'est utilisé.

Depuis `back`, sur un profil de test sans configuration compilée :

```powershell
if (Test-Path bootstrap/cache/config.php) { throw 'Configuration compilée : utiliser un checkout de test isolé.' }
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
php vendor/bin/phpunit --group=tenant-isolation --log-junit=reports/phpunit-qa002-critical.xml
```

Cette sélection critique ne couvre pas tout QA-002. La matrice REC-23–36, les
quinze suites auditées et les limites sont décrites dans `docs/etat-projet.md`.
Validation locale : 312 tests SQLite et 120 tests sur PostgreSQL 17 temporaire
pour les cinq suites modifiées, sans échec. Ces tests sont déjà inclus dans les
sélections CI Feature/PostgreSQL existantes. Nouvelle CI du lot à confirmer après
livraison ; Reverb réel, webhooks, charge et restauration restent distincts.

### Isolation Personnes/Équipes — deuxième lot UCG-QA-002

À la fin du deuxième lot, le groupe `tenant-isolation` comptait **19 cas** (270 assertions). Le
deuxième lot ajoute neuf cas et renforce les scénarios étrangers Personne/Équipe :
départ ou archivage refusé, droits révoqués après une lecture réussie, retrait
d'une permission avec maintien des lectures autorisées, rattachements d'effectif
et transferts refusés entre tenants, même avec un gestionnaire commun.

La commande du groupe ci-dessus exécute également ces cas. Neuf suites liées
ont été validées sur SQLite en mémoire et sur PostgreSQL 17 temporaire :
180 tests, 1 094 assertions sur chaque moteur, sans échec, erreur ou exclusion.
Les versions, historiques et audits sont contrôlés après refus, et les droits
Beta encore légitimes restent utilisables. Aucun code métier ni migration modifié.
La nouvelle CI reste à confirmer après livraison. Le détail des suites, les
rapports et les limites de couverture figurent à la fin de `docs/etat-projet.md`.

### Isolation des ressources rattachées — troisième lot UCG-QA-002

À la fin du troisième lot, le groupe `tenant-isolation` comptait **34 cas, 489 assertions**. Douze
cas ajoutés et des scénarios étrangers renforcés couvrent profils/comptes de jeu,
périodes/checklist et compositions : mauvais tenant ou parent, révocation après
accès réussi et retrait des permissions d'écriture avec maintien des lectures.
Les refus préservent données, versions et audits.

Onze suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**220 tests, 1 160 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; code métier et migrations
inchangés. Ces cas sont inclus dans la CI backend existante ; la CI du nouveau
lot reste à confirmer après livraison. QA-002 reste ouvert ; rapports et limites
dans `docs/etat-projet.md`.

### Isolation des imports — quatrième lot UCG-QA-002

À la fin du quatrième lot, le groupe `tenant-isolation` comptait **47 cas, 804 assertions**. Douze
cas ajoutés et un scénario étranger renforcé couvrent le job d'import sérialisé
après bascule HTTP, le rejeu, la révocation avant exécution ou entre blocs,
les rapports privés et les fichiers identiques déposés dans deux tenants.
Les refus préservent les lignes déjà validées et empêchent les créations restantes.

Cinq suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**69 tests, 488 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; changements limités aux tests
et à la documentation. CI du nouveau lot à confirmer après livraison ; QA-002
reste ouvert. Rapports et limites dans `docs/etat-projet.md`.

### Isolation des documents contractuels — cinquième lot UCG-QA-002

À la fin du cinquième lot, le groupe `tenant-isolation` comptait **57 cas, 1 024 assertions**. Neuf
cas ajoutés couvrent l'analyse sérialisée après bascule HTTP, son rejeu, la
révocation avant exécution et les liens signés après révocation. Le retrait de
l'écriture conserve les lectures autorisées ; fichiers et versions restent intacts.

Six suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**89 tests, 618 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; scanner et stockage simulés,
aucun changement métier ou de migration. Exécuter les commandes SQLite utilisant
le même stockage simulé successivement. CI du nouveau lot à confirmer après
livraison ; QA-002 reste ouvert. Rapports et limites dans `docs/etat-projet.md`.

### Isolation des rappels contractuels — sixième lot UCG-QA-002

À la fin du sixième lot, le groupe `tenant-isolation` comptait **65 cas, 1 173 assertions**. Sept
cas ajoutés couvrent deux tenants avec un destinataire commun, les tâches
planifiées sérialisées/rejouées, les révocations avant envoi et les historiques
ou résolutions sous le mauvais tenant/contrat. Les droits Beta restent utilisables
sans autoriser l'email Alpha révoqué ; le retrait de l'écriture conserve les lectures.

Quatre suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**81 tests, 538 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; emails simulés, aucun changement
métier ou de migration. CI du nouveau lot à confirmer après livraison ; QA-002
reste ouvert. Rapports et limites dans `docs/etat-projet.md`.

### Isolation des inscriptions aux compétitions — septième lot UCG-QA-002

À la fin du septième lot, le groupe `tenant-isolation` comptait **76 cas, 1 395 assertions**. Dix
cas ajoutés couvrent les rappels planifiés sérialisés/rejoués pour deux tenants,
les révocations avant génération ou après consultation, le retrait de l'écriture
et les références documentaires d'une autre compétition ou d'un autre tenant.
Les droits Beta ne permettent pas de générer un rappel Alpha révoqué.

Six suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**66 tests, 531 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; aucun changement métier ou de
migration. Les documents sont les versions de texte privées existantes. CI du
nouveau lot à confirmer après livraison ; QA-002 reste ouvert. Rapports et limites
dans `docs/etat-projet.md`.

### Isolation des imports statistiques — huitième lot UCG-QA-002

À la fin du huitième lot, le groupe `tenant-isolation` comptait **86 cas, 1 695 assertions**. Neuf
cas ajoutés couvrent le job confirmé par HTTP, sérialisé après bascule de tenant,
les révocations avant traitement ou entre blocs, les anciennes générations et les
résultats privés. Les observations, révisions et reçus déjà validés sont conservés ;
les imports Beta autorisés restent utilisables sans autoriser Alpha après révocation.

Quatre suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**104 tests, 1 082 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; aucun changement métier ou de
migration. CI du nouveau lot à confirmer après livraison ; QA-002 reste ouvert.
Rapports et limites dans `docs/etat-projet.md`.

### Isolation des observations — neuvième lot UCG-QA-002

À la fin du neuvième lot, le groupe `tenant-isolation` comptait **95 cas, 1 921 assertions**. Huit
cas ajoutés couvrent les observations et versions étrangères, les révocations
après lecture, la séparation création/correction et un match devenu privé.
Les refus préservent les observations, révisions et audits ; les droits Beta
encore légitimes restent utilisables.

Quatre suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**112 tests, 1 459 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; aucun changement métier ou de
migration. CI de `a581415` annoncée en lancement, résultat encore à confirmer ;
CI du nouveau lot à obtenir après livraison. QA-002 reste ouvert. Rapports et
limites dans `docs/etat-projet.md`.

### Isolation des séances Académie et présences — dixième lot UCG-QA-002

À la fin du dixième lot, le groupe `tenant-isolation` comptait **105 cas, 2 131 assertions**. Neuf
cas ajoutés couvrent les identifiants étrangers ou sous le mauvais parent, les
révocations après lecture et les droits distincts de gestion des séances et
présences. Sans accès au calendrier, les horaires sont masqués et les mutations
liées refusées ; les lectures Académie encore autorisées restent disponibles.

Quatre suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**77 tests, 607 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; aucun changement métier ou de
migration. CI de `5172f83` validée par l'utilisateur ; dixième lot réuni dans un
commit dédié, sans push, sa CI reste à obtenir après livraison. QA-002 reste ouvert. Rapports
et limites dans `docs/etat-projet.md`.

### Isolation du suivi pédagogique Académie — onzième lot UCG-QA-002

À la fin du onzième lot, le groupe `tenant-isolation` comptait **117 cas, 2 421 assertions**. Onze
cas ajoutés couvrent les identifiants étrangers ou sous le mauvais parent, les
révocations après lecture, les droits distincts sur les retours partagés et notes
internes et l'accès personnel de l'élève. Les historiques masquent les références
internes après retrait du droit correspondant ; les accès Beta légitimes restent
utilisables après révocation dans Alpha.

Quatre suites liées passent sur SQLite en mémoire et PostgreSQL 17 temporaire :
**69 tests, 777 assertions par moteur**, sans échec, erreur ou exclusion.
Pint/PHPStan verts, cluster arrêté et supprimé ; aucun changement métier ou de
migration. CI de `22ac7d5` validée par l'utilisateur ; onzième lot réuni dans un
commit dédié, sans push, sa CI reste à obtenir après livraison. QA-002 reste ouvert. Rapports
et limites dans `docs/etat-projet.md`.

### Consolidation UCG-QA-002 — isolation et révocations

Le groupe `tenant-isolation` compte désormais **246 cas, 3 831 assertions**.
La consolidation ajoute 40 cas sur dix familles de ressources, deux cas de
révocation financière et trois cas après signature d'un canal temps réel.
Elle intègre également 69 méthodes existantes, soit 84 cas avec leurs jeux de
données, sans supprimer leurs assertions. Les accès Beta restent utilisables
après révocation Alpha ; les refus préservent les données et audits de mutation.

```bash
cd back
php vendor/bin/phpunit --group=tenant-isolation
```

Le test de transport réel nécessite Node 24 et un port local libre. Il démarre
son propre Reverb sur `127.0.0.1`, obtient les signatures par les routes tenant,
vérifie deux abonnés autorisés et une signature invalide, puis ferme ses processus.
La CI l'exécute séparément ; il est explicitement ignoré dans la suite ordinaire.

```bash
# Bash / CI
UCG_TEST_REVERB=1 php vendor/bin/phpunit tests/Feature/ReverbDeliveryTest.php
```

```powershell
$env:UCG_TEST_REVERB='1'
php vendor/bin/phpunit tests/Feature/ReverbDeliveryTest.php
Remove-Item Env:UCG_TEST_REVERB
```

Le test de cache sans transaction utilise sa propre base jetable sur PostgreSQL,
pour conserver les fonctions de triggers de la base partagée entre les autres
tests. La sélection PostgreSQL de CI inclut cette régression et la nouvelle matrice.
Suites backend complètes : **2 748 réussites/50 exclusions sur SQLite**, et
**2 794 réussites/4 exclusions sur PostgreSQL 17**, aucun échec ni erreur.
Reverb réel : trois exécutions réussies, chacune 1 test et 26 assertions.
Pint/PHPStan verts, YAML et client Node vérifiés, ressources temporaires nettoyées.
Livraison en trois commits dédiés, sans push ; CI distante à obtenir. Résultats
et limites dans `docs/etat-projet.md`. L'utilisateur confirme le 25 octobre 2026
qu'aucun webhook entrant n'est prévu au P0 : REC-30 est non applicable à ce
périmètre ; API-002 reste prévu pour une future intégration. QA-002 reste ouvert : charge
asymétrique (QA-005), politique de purge exhaustive (OPS-007) et restauration
avec réconciliation (OPS-004/005). Le test Reverb local ne certifie pas le proxy,
TLS, workers hébergés ou l'éviction des sockets déjà abonnées après révocation.

## Préparation des données — UCG-DATA-001

Premier lot : **audit local en lecture seule avant import**, avec inventaire des
fichiers explicitement déclarés et contrôle des CSV Personnes/Statistiques ainsi
que des jeux JSON Équipes/Matchs/Contrats. Aucun
balayage automatique des dossiers. Le manifeste lie chaque source à l’UUID attendu,
à une catégorie et à des références de responsable, de finalité et de conservation.
Ces références sont des déclarations à valider ; elles ne désignent pas à elles
seules un responsable réel ni une politique approuvée.

Depuis `back/`, les deux démonstrations utilisent uniquement des données fictives :

```powershell
php artisan data:audit tests/Fixtures/data-preparation/clean.manifest.json --organization=10000000-0000-4000-8000-000000000001
php artisan data:audit tests/Fixtures/data-preparation/issues.manifest.json --organization=10000000-0000-4000-8000-000000000001
```

Le premier manifeste constitue un exemple complet du format version 1 :
`organization_id`, `dataset_kind=fictitious|real`, `sources[]`. Chaque source porte
`id`, `organization_id`, `category`, `file`, `owner_reference`, `purpose_reference`,
`retention_reference`. Pour Personnes : `delimiter`, `mapping` (champ → indice de
colonne à partir de zéro), `defaults` (langue, fuseau, statut explicitement choisis)
et `ignored_columns[]` (`column`, `reason_reference`). Les choix fr/Africa-Tunis/
candidate des exemples sont exclusivement fictifs, pas des réglages métier imposés.

Les catégories reprennent les 18 familles de l’inventaire. Dans cette version,
**les CSV Personnes/Statistiques et JSON Équipes/Matchs/Contrats sont contrôlés hors base**.
Tableurs, documents contractuels et autres familles reçoivent une empreinte et un signalement d’audit dédié nécessaire,
sans conversion ni import implicites. Le contrôle Personnes réutilise son parseur,
ses champs d’import autorisés et ses règles actuelles. `user_id` et `birth_date`
ne sont pas des champs de ce format CSV ; leur présence nécessite un mapping
distinct à concevoir, pas une extension silencieuse de l’import existant.

Pour une source `category=statistics`, utiliser les en-têtes canoniques de l’import
statistique existant, sans `mapping`, `defaults` ou `ignored_columns` non vides.
Ajouter `statistics_context: {"as_of": "2026-10-28"}` à la source : cette date
explicite sert de borne de contrôle des observations futures. Elle reste une
déclaration à vérifier avec la date locale de l’organisation avant la reprise.
Les fixtures suivantes donnent le format complet et uniquement fictif :

```powershell
php artisan data:audit tests/Fixtures/data-preparation/statistics-clean.manifest.json --organization=10000000-0000-4000-8000-000000000001
php artisan data:audit tests/Fixtures/data-preparation/statistics-issues.manifest.json --organization=10000000-0000-4000-8000-000000000001
```

L’audit statistique réutilise le parseur et les règles de champs de l’import,
ainsi que la normalisation métier des valeurs : décimales exactes sans flottants,
zéro distinct d’une donnée absente, cohérence disponibilité/motif, périodes,
UUID et version obligatoires, échantillon borné et création au statut actif.
Une partie de match exige un match. Les observations identiques sont distinguées
des observations de même identité portant une valeur, une version, un échantillon
ou une note différente. Les comparaisons couvrent les fichiers du lot ; aucune
fusion n’est effectuée. Les compteurs `people_rows` et `statistics_rows` sont séparés.
Une ligne conforme aux champs reste soumise à la revue des références réelles,
droits, unités, périmètre d’agrégation, catalogue et règles propres à la métrique.
Ces contrôles nécessitent la base et restent explicitement non vérifiés.

Contrôles livrés : syntaxe/encodage CSV, colonnes, champs invalides ou obligatoires
manquants, mapping explicite et exclusif, colonnes non affectées ou volontairement
écartées, cellules ressemblant à des formules. Les numéros de ligne physiques sont
conservés, y compris après une cellule multiligne. Les pseudonymes sont comparés
après trim et passage en minuscules, dans le fichier et entre les sources du même
lot. Coordonnées ou noms identiques déclenchent une **revue de rapprochement** :
une adresse partagée n’est pas une preuve de doublon. Aucune fusion ou correction
automatique. Un fichier de contenu identique est également signalé.

Le rapport JSON indique sources techniques, tailles, empreintes, compteurs et
positions des écarts. Il ne recopie ni en-têtes libres, chemins, noms, coordonnées,
biographies, valeurs des cellules ou clés de rapprochement. Les comparaisons
temporaires utilisent des HMAC non conservés. Les empreintes de fichiers et les
UUID restent des informations privées d’exploitation ; ne pas publier le rapport.
`schema_valid_rows` compte seulement les lignes conformes aux règles de champs,
pas des lignes approuvées pour import ni un nombre de personnes uniques.

- Code de sortie **0** : `ready_for_business_review`, prêt pour la revue métier.
- Code de sortie **2** : `blocked`, écarts bloquants ou périmètre partiellement lu.
- Code de sortie **1** : manifeste/périmètre invalide ou échec de l’audit.

Dans tous les cas, `import_authorized=false`. Le rattachement à l’organisation
est vérifié dans le manifeste, **sans consultation de la base applicative** :
existence/droits réels du tenant, doublons déjà enregistrés, liens intermodules et
quotas tenant restent à contrôler avant DATA-002. L’accès est celui d’un opérateur
local autorisé à lire ces fichiers ; aucune route web n’est exposée par cet outil.
La présence de `dataset_kind=fictitious` est une déclaration, pas une preuve
indépendante de l’origine des données.

Les fichiers doivent être réguliers, dans le dossier du manifeste ou ses
sous-dossiers, avec chemins relatifs ASCII simples. Liens symboliques, sorties du
dossier, chemins réseau, URL et wrappers de flux sont refusés. Lecture `rb` sous
verrou partagé, contrôles de taille et de stabilité du fichier ; aucune réécriture
des sources. Limites : manifeste 64 Kio, 20 sources, 1 Mio par fichier, 10 Mio par
lot, au plus 1 000 lignes par CSV Personnes, 100 par CSV Statistiques et 10 000
lignes analysées au total par lot, avec 100 enregistrements par jeu JSON ; les limites
Personnes configurées peuvent être plus strictes. Les détails sont limités à 200
écarts, avec compteurs complets pour la portion analysée et indicateur de troncature.
Les sources non lues sont signalées, jamais assimilées à des fichiers valides.

Le lot fictif d’écarts déclare trois fichiers, six lignes Personnes, huit problèmes
bloquants et cinq revues : identité répétée entre fichiers, champs invalides,
cellule manquante, responsable/règle à préciser et schéma statistique incompatible.
Le lot propre reste soumis à la validation métier. DATA-001 reste ouvert pour
l’inventaire réel, les autres formats et la validation des responsables ; DATA-002
sera une reprise à blanc distincte. Aucun fichier réel n’a été audité dans ce lot.

### Suite DATA-001 : autres sources, références et revue finale

Le 28/10/2026, l’utilisateur confirme **aucun fichier réel disponible pour le moment**.
L’inventaire de disponibilité des 18 familles est conservé dans
`back/tests/Fixtures/data-preparation/no-real-sources.inventory.json`. Il ne prouve
pas l’absence définitive de données à reprendre. La préparation technique peut
avancer ; l’audit réel et la validation métier restent en attente.

Les jeux JSON sont des **formats d’audit version 1**, pas de nouveaux imports :
`version`, `organization_id`, `records[]` contenant `source_key` (référence technique
unique) et `data` (champs métier de création existants). Aucun champ d’organisation,
version de mise à jour ou transition de signature n’est ajouté implicitement à `data`.
Le manifeste garde la catégorie `teams`, `matches` ou `contracts` et un fichier
`.json`, sans mapping, valeurs par défaut ou colonnes écartées non vides.
Les exemples complets sont `teams-clean.json`, `matches-clean.json`,
`contracts-clean.json` et `structured-clean.manifest.json`, tous fictifs.

```powershell
php artisan data:audit tests/Fixtures/data-preparation/structured-clean.manifest.json --organization=10000000-0000-4000-8000-000000000001
```

Les validations des modules restent la référence : catégorie personnalisée des
équipes, périodes/fuseaux des matchs et participants distincts, parties des contrats
et ordre des dates. Les parties, participants et obligations restent des listes
JSON fermées. Les identités comparées sont jeu/slug/saison pour une équipe,
jeu/début UTC/participants pour un match, référence pour un contrat. À identité
égale, les contenus normalisés distinguent doublon et contradiction. Un signalement
ne décide jamais d’une fusion. `line` représente le numéro d’enregistrement JSON
à partir de 1 lorsque `position_kind=record_number` ; le CSV garde sa ligne physique.

Une vérification en base est **distincte et explicite** :

```powershell
php artisan data:references CHEMIN_MANIFESTE.json references.json --organization=UUID_ORGANISATION --actor=ID_COMPTE
```

`references.json` doit rester dans le dossier du manifeste. Copier et compléter
`references.template.json` : `version`, `organization_id`, `plan_digest` issu de
l’audit actuel, `items[]` avec `source_id`, `line`, `kind`, `value`, `expect`.
Les types sont `person_id`, `person_pseudonym`, `team_id`, `team_slug`, `match_id`,
`contract_id`, `contract_reference`, `metric_id` ; `expect=present|absent`.
Maximum 500 contrôles, 64 Kio. Les sources doivent passer l’audit de forme et
chaque position doit exister. Un changement de source/manifeste invalide le digest.
Ne pas exécuter le modèle fictif comme une vérification de données réelles.

Le compte est résolu dans l’organisation active, doit pouvoir l’administrer,
avoir la MFA confirmée et les droits métier explicites du module (`sports.read`,
`administrative.read`, et `sports.statistics.read` pour les métriques). Le CLI est
destiné à un opérateur serveur autorisé ; `--actor` identifie le compte de contrôle,
sans constituer une authentification interactive. Un propriétaire ne reçoit pas
automatiquement les permissions métier. Les requêtes sont bornées, groupées par
type et sans verrou d’écriture. Les ressources d’un autre tenant et absentes donnent
le même échec ; les métriques appartiennent au catalogue global. Le pseudonyme
recherche aussi les fiches archivées, avec comparaison `LOWER` SQL ; ses possibilités
Unicode dépendent du moteur et ne prouvent pas l’absence de toute identité similaire.
Les slugs d’équipes sont recherchés dans toute l’organisation : un résultat peut
nécessiter une revue du jeu/saison, sans preuve d’unicité métier à lui seul.

Le résultat n’énumère pas les valeurs ni les identifiants recherchés. Un succès
`requested_checks_passed` (sortie 0) ne concerne que la liste fournie :
`coverage_verified=false`, `import_authorized=false`. Écarts : sortie 2 ; document
invalide, périmètre ou droits insuffisants : sortie 1. Restent à vérifier la
complétude de cette liste, les versions/unités/agrégations des métriques, états et
compatibilités de jeu des références, rapprochements d’identité, matchs et quotas.
Ce contrôle ponctuel ne remplace pas la validation transactionnelle de l’import.

La revue déclarée se vérifie ensuite contre les fichiers actuels :

```powershell
php artisan data:review CHEMIN_MANIFESTE.json review.json --organization=UUID_ORGANISATION
```

Compléter `review.template.json` avec le digest actuel, un responsable de revue et
une date, puis les 18 catégories (`included`, `no_source` justifié ou `deferred`).
Chaque source doit avoir sa décision et ses références de responsable/finalité/
conservation. Les six contrôles portent sur références, doublons, quotas, données
inutiles, conservation et inventaire complet, chacun avec une référence de preuve.
Les références de preuve restent des déclarations d’opérateur à vérifier ; ne pas
recopier des données personnelles dans ces références techniques.

Le rapport distingue source fictive, revue incomplète, blocage et approbation
**déclarée** (`business_approval_declared`, sortie 0). Les autres états donnent 2 ;
une revue invalide ou périmée donne 1. Une déclaration ne vaut ni signature
authentifiée ni preuve d’exécution en base : `business_signature_verified=false`,
`database_checked=false`, `data001_closed=false` et `import_authorized=false`.
Une fixture ne ferme jamais le ticket, même déclarée approuvée. La clôture réelle
exige les fichiers réels, les contrôles complémentaires applicables et une
validation métier vérifiable. DATA-002 reste une opération distincte.

## Reprise à blanc — préparation UCG-DATA-002

Une répétition reproductible utilise uniquement des données fictives, dans un
processus de test séparé et une base SQLite `:memory:` neuve. Elle ne prend aucun
fichier réel ni identifiant de base cible en argument et n’ouvre pas la connexion
applicative du processus appelant. Les dépendances de développement sont requises.

Depuis `back/` :

```powershell
php artisan data:rehearse --no-interaction
```

La commande lance uniquement `phpunit-rehearsal.xml` et `tests/Rehearsal`, hors des
suites ordinaires. Le bootstrap enfant impose environnement de test, clé locale
éphémère, absence de cache de configuration partagé, SQLite en mémoire, cache et
session en mémoire, ainsi que les protections contre les livraisons externes.
Les requêtes HTTP sortantes imprévues sont refusées. Files, mails, notifications,
diffusion et queues externes sont remplacés par des dispositifs de test ; les
processors d’import réels sont appelés explicitement. Aucun worker hébergé n’est
qualifié par cette répétition.

Cinq contrôles obligatoires :

- chaîne complète : import CSV de 2 personnes, création métier de 1 équipe,
  1 match et 1 contrat, import de 2 observations statistiques ; contrôle des totaux,
  de 4 échantillons et de 5 liens, avec distinction entre zéro et valeur absente ;
- reprise après interruption d’un chunk et rejeu sans personnes supplémentaires,
  réutilisation d’un import identique et détection d’un doublon statistique par reçu ;
- refus de confirmation des lignes invalides sans acquittement explicite et
  rejet d’une observation contradictoire sans écrasement de la valeur existante ;
- refus des références d’une autre organisation et des écritures d’un lecteur
  sans permission, avec contrôle de l’intégrité de la fiche étrangère ;
- interruption contrôlée après une première personne créée, puis retour arrière.

Les services d’import, de validation et de création existants sont exercés avec
leurs autorisations, quotas et protections SQL. Les données de préparation du
scénario sont fictives ; ces opérations ne créent aucun nouveau format d’import
réel pour les équipes, matchs ou contrats.

Chaque contrôle est entouré d’une transaction explicite. Après rollback, la
répétition compare l’empreinte de toutes les lignes de chaque table à l’état de
préparation, y compris journaux et suivi d’import. Les fichiers nouveaux sont
supprimés uniquement du disque de test après vérification de son chemin ; la liste
des fichiers est comparée à son état initial. La base disparaît à la fin du
processus. **Cette preuve concerne une transaction dans un seul processus** : elle
ne garantit pas l’annulation de transactions déjà validées par des workers distincts,
ni une restauration de sauvegarde sur un hébergement.

Le JSON de sortie contient les compteurs et contrôles, les assertions, l’empreinte
du scénario et de ses dépendances versionnées. Il exclut données de cellules,
identifiants métier, chemins locaux et détails d’exception. Les éléments techniques
fictifs JUnit et JSONL restent dans
`back/storage/framework/testing/data-rehearsal/<UUID>/` pour diagnostiquer un échec.
La base et les fichiers d’import ne sont pas conservés dans ces éléments.

Sortie **0** : les cinq contrôles et tous les retours arrière sont vérifiés ;
**2** : échec ou preuve incomplète ; **1** : exécution non vérifiable, dépendance
absente ou erreur technique. Un test ignoré, un contrôle manquant, une mauvaise
quantité, un fichier résiduel ou un échec de rollback interdit le succès.
Même en sortie 0 : `dataset_kind=fictitious`, `data002_closed=false`,
`import_authorized=false`, `business_signature_verified=false`. Le résultat ne
constitue ni une reprise réelle ni un rapprochement signé.

La CI backend exécute aussi la commande et conserve son rapport
`reports/data002-rehearsal.json` et les éléments fictifs JUnit/JSONL de diagnostic.
Cela s’ajoute aux tests de la commande, dont un
essai avec une base parent volontairement inaccessible et un cache Redis hérité :
l’enfant doit rester isolé et aucune requête ne doit atteindre la base parent.

### Passage à une reprise réelle en recette

1. Terminer DATA-001 sur les fichiers réels : inventaire, écarts corrigés,
   références, conservation et rapport validé. Enregistrer son digest et la révision
   du code. L’absence de fichiers actuelle ne remplit pas cette condition.
2. Préparer un tenant et un hébergement de recette isolés, sans accès des utilisateurs
   réels, avec sauvegarde de référence et restauration qualifiée, incluant les
   fichiers privés. Vérifier acteurs, permissions et limites avant toute reprise.
3. Reprendre avec les imports existants et les parcours métier autorisés. Conserver
   la correspondance entre références sources et identifiants générés, puis contrôler
   les liens avant les matchs, contrats et observations. Ne pas transformer des
   contrats historiques en contrats signés par un simple chargement de fichier.
4. Rapprocher par source et par lot attendus, acceptés, rejetés, doublons et créés.
   Vérifier échantillons, valeurs décimales, données absentes et liens, ainsi que
   l’absence de données hors tenant. Documenter les rejets et les décisions prises.
5. Tester le rejeu et une interruption avec les vrais workers de recette. Pour un
   lot déjà validé par plusieurs transactions, suivre la procédure de restauration
   qualifiée ; ne pas supposer qu’un rollback local efface des commits antérieurs.
6. Compléter le modèle `back/tests/Rehearsal/reconciliation.template.json` avec
   références de preuves, compteurs, imports, échantillons, restauration, responsable,
   date et signature vérifiable. Ce modèle est un support de revue manuelle et
   n’est pas interprété comme une autorisation par la commande.

DATA-002 reste en attente de cette qualification réelle et de la validation métier.
L’hébergement et les fichiers réels ne sont pas disponibles dans le lot actuel.

## Conservation des données — cadrage UCG-OPS-007

**Brouillon de travail du 25 octobre 2026, sans autorisation de suppression.**
L'utilisateur confirme que l'établissement d'UCG est en **Tunisie (TN)**.
Cette information de cadrage ne remplace pas automatiquement les valeurs FR
des fixtures ou la configuration d'une organisation existante. Le pilote est
désormais confirmé pour des participants vivant uniquement en Tunisie, avec
accueil de mineurs ; MENA/Europe reste le périmètre ultérieur. Les responsables
des traitements et le lieu de l'hébergement restent à préciser. L'inventaire porte sur les
sources du dépôt, pas sur les données ou paramètres effectifs d'une base réelle.

### Référence et décisions existantes

La [loi organique tunisienne n° 2004-63, texte publié par le ministère](https://www.mtc.gov.tn/fileadmin/texte_juridiques/L2004-0063.pdf)
constitue une référence de cadrage à faire confirmer pour UCG. Ses articles 31
et 45 portent notamment sur l'information relative à la durée, la fin de
conservation et les formalités de destruction ; les cas des articles 46 et
51–52 doivent aussi être examinés selon destinataires et transferts. Le
responsable doit faire valider les délais et modalités applicables avant
paramétrage. Aucune durée juridique chiffrée n'est déduite de ce seul texte.
La [méthode CNIL de détermination des durées](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees)
reste une aide de classement, pas une règle française appliquée automatiquement
à UCG. L'établissement tunisien ne suffit pas à qualifier tous les traitements.

La décision antérieure IAM-006 conserve actuellement les journaux **sans limite**,
avec `audit.retention.mode=unlimited` et `purge_enabled=false`. Cette décision
technique provisoire est préservée ; elle ne constitue pas une validation de sa
conformité et doit être réexaminée dans le cadrage tunisien. La préparation de
OPS-007 ne la remplace pas et ne désactive aucun garde append-only.

### Matrice technique à compléter avant politique exécutable

Le catalogue fermé `DatabasePortabilityRepository::TABLES` recense **116 tables**.
Le classement suivant couvre chacune une seule fois. Il décrit les données à
examiner ; il ne signifie pas que leur suppression est disponible ou autorisée.
Toutes les durées actives et d'archive de cette matrice restent **à valider**.
Les événements proposés servent à préparer le calcul futur, pas à lancer un délai.

| Famille | Tables du catalogue | Événement de départ proposé | Dépendances et décision à prendre |
| --- | ---: | --- | --- |
| Organisation et habilitations | 7 | Fin d'adhésion, expiration d'invitation, clôture de l'organisation | Racine, paramètres, membres, invitations, rôles/affectations et accès support. Distinguer révocation d'accès et effacement ; ne pas supprimer un compte global partagé. |
| Journaux et historique tenant | 5 | Événement ou clôture du dossier associé | Accès, cycle de vie, support, audit et accès sensibles. Conservation illimitée actuelle à réexaminer ; protéger preuves et références sans supposer une conservation perpétuelle justifiée. |
| Dossiers de personnes | 6 | Candidature clôturée, départ ou fin de relation, selon le dossier | Séparer candidats, actifs et anciens membres ; profils de jeu, comptes externes, périodes et départs. Préserver les références aux équipes/matchs sans conserver automatiquement toute l'identité. |
| Imports de personnes | 2 | Import terminé, abandonné ou en échec | Distinguer CSV source, rapport d'erreurs, lignes et personnes créées. La fin de vie du fichier ne supprime pas les fiches issues de l'import. |
| Équipes, effectifs et compositions | 9 | Fin de saison, d'affectation ou de composition | Historique des mouvements, éligibilité et lineups. Maintenir la cohérence des matchs et statistiques en cas d'évolution de l'identité d'une personne. |
| Jeux activés dans l'organisation | 1 | Désactivation du jeu ou clôture du tenant | `organization_games` ; désactiver n'efface pas les références sportives ni le catalogue global. |
| Adversaires et snapshots | 2 | Dernière utilisation ou clôture de la relation | Distinguer fiche courante et copie historique utilisée dans un match. |
| Matchs, parties et résultats | 10 | Fin de rencontre, validation ou clôture d'une contestation | Participants, scores, preuves, carnet, corrections et recalculs. Définir les données nécessaires à l'historique avant toute anonymisation ou suppression. |
| Compétitions et inscriptions | 7 | Fin de compétition/inscription et des obligations associées | Documents, révisions, rappels et résultats ; ne pas effacer une inscription encore liée à un contrat ou un résultat conservé. |
| Contrats, conditions et documents | 13 | Fin du contrat et des obligations/contentieux applicables | Parties, obligations, conditions financières, avenants, versions, signatures déclarées, scans, rappels et copies approuvées. Définir les sous-catégories et leurs justifications, pas une durée unique pour tout contrat. |
| Statistiques et observations | 8 | Fin de finalité ou de période d'analyse | Imports, calculs, sources et révisions. Distinguer agrégat effectivement anonyme et données encore rattachables à une personne. |
| Académie et suivi pédagogique | 10 | Fin de cohorte, cycle ou inscription | Séances, présences, objectifs, retours, notes et révisions ; qualifier les mineurs et les accès restreints. |
| Calendrier et disponibilités | 12 | Fin d'activité, d'invitation ou d'abonnement | Ressources, réservations, conflits, disponibilités, changements, livraisons et jetons de flux ; expiration d'un accès distincte de l'historique. |
| Communication | 5 | Retrait/expiration de l'annonce ou fin de relation | Annonces, commentaires, événements, préférences et reçus. Traiter les copies et destinataires déjà servis séparément. |
| Sponsors et partenariats | 11 | Fin de relation, accord, campagne ou livrable | Contacts individuels, opportunités, interactions, conditions, expositions et événements ; distinguer prospection et obligations contractuelles. |
| Médias référencés | 1 | Dépublication ou fin d'autorisation/finalité | Le retrait d'un lien dans la plateforme ne supprime pas le contenu chez son fournisseur. |
| Tableaux de bord et exports métier | 4 | Export demandé/généré ou fin de période d'indicateur | Rapports temporaires, indicateurs, révisions et versions de sources ; supprimer un export ne supprime pas ses sources. |
| Artefacts et portabilité | 3 | Échéance technique du fichier ou opération de portabilité | Ledger privé, quotas et opérations ; relier chaque objet physique à sa catégorie métier. Un fichier contractuel ne devient pas jetable parce qu'il utilise le même stockage qu'un export. |

En dehors de ces 116 entrées, inclure les comptes et journaux Identity globaux,
catalogues communs, sessions, jetons, cache/verrous, jobs/échecs, logs serveur,
rapports de tests et copies hors application. `TableOwnership::GLOBAL_TABLES`
recense les exceptions d'architecture ; `organizations` est aussi la racine
explicitement incluse dans la portabilité. Une demande tenant ne vaut pas
autorisation d'effacer ces données partagées.

### Expirations techniques existantes, sans durée métier implicite

| Élément | Valeur livrée observée dans le code | Portée réelle |
| --- | --- | --- |
| Archive de portabilité | 24 heures par défaut ; réglage borné entre 1 et 168 heures | Durée de l'archive privée, pas des dossiers exportés. |
| Export de tableau de bord | 60 minutes | Échéance du rapport et de son artefact, pas de la source métier. |
| Lien documentaire temporaire | 300 secondes | Fin de validité du lien ; le document reste stocké selon sa propre règle. |
| Fraîcheur d'un résultat antivirus | 86 400 secondes | Condition de téléchargement/scannage ; aucune suppression automatique du document. |
| Invitation d'organisation | 7 jours par défaut | Fin du droit d'accepter ; ce n'est pas une durée de conservation de la ligne ou de son audit. |
| Session | 120 minutes d'inactivité par défaut | Accès et nettoyage technique selon driver ; pas une politique de dossier personnel. |
| Journal applicatif | Rotation `daily` configurable, valeur 14 fichiers ; pile livrée utilisant `single` par défaut | Aucun effacement général garanti par le simple réglage du canal daily. |

Les valeurs ci-dessus sont les défauts des sources ; les overrides et services
réellement exécutés ne sont pas inspectés. La planification d'expiration des
archives est désactivée par défaut, et celle des artefacts dépend d'un flag faux
par défaut. L'absence d'un flag actif n'est pas une durée de conservation validée.
Un fichier expiré peut être inaccessible avant son enlèvement physique.

### Limites de la purge disponible

`OrganizationPurgeService` accepte seulement six choix de catégorie : settings,
artifacts, exports, access_history, lifecycle_history et organization_identity.
Les trois dernières doivent rester à retain ; les comptes globaux sont protégés.
Les chemins de suppression concernent les paramètres d'organisation, les objets
privés du ledger et les archives techniques. Ils ne suppriment pas les tables
de personnes, contrats, matchs ou autres modules métier recensés ci-dessus.

La configuration livrée `tenancy.portability.purge_policies` est vide : aucune
politique opérateur n'y est autorisée par défaut. Une configuration ultérieure
exige tenant, confirmation, organisation archivée, export source adéquat,
référence de politique, échéance not_before et procédure de sauvegarde, avec
vérification du catalogue et reprise bornée. La présence de ces champs ne prouve
pas que les formalités applicables sont remplies ni que l'effacement est exhaustif.

Certaines FK et protections d'historique empêchent volontairement des suppressions
physiques ordinaires. OPS-007 doit prévoir une évolution dédiée de ces chemins,
sans désactiver globalement les déclencheurs et sans transformer le soft delete,
le statut former, l'archivage ou la révocation en preuve d'effacement.

### Fiche de décision et copies hors application

Pour chaque sous-catégorie de la matrice, renseigner : organisation et responsable,
finalité, périmètre des personnes/pays, événement de départ, durée active,
archivage éventuel et habilitations, justification/référence applicable, action
finale, dépendances conservées, éventuel gel documenté, version de politique,
date et validateur. **Non défini** signifie décision manquante, pas illimité,
zéro jour ou autorisation de suppression. Cette fiche reste documentaire ici.

Compléter la chaîne des copies : base principale, fichiers/versions d'objets,
exports, caches, jobs/rapports d'erreur, réplicas, journaux techniques et
sauvegardes/PITR, ainsi que fournisseurs et téléchargements déjà remis.
Identifier qui peut agir sur chaque copie et quel constat permettra de prouver
sa disparition. Pour une restauration, prévoir comment réappliquer les décisions
d'effacement déjà autorisées ; aucune procédure de sauvegarde réelle n'est
supposée exister dans ce dépôt. OPS-004/005 restent nécessaires.

Avant tout moteur exécutable : faire valider les durées et formalités pour UCG,
concevoir les dépendances intermodules, puis tester un inventaire/simulation sur
fixtures avec Alpha/Beta, refus sans politique, reprise, cas gelés et cohérence
base/fichiers. Toute future exécution réelle doit être une opération distincte,
explicitement autorisée. **OPS-007, CAD-004 et REC-35 restent ouverts.**

### UCG-CAD-004 — Fiche de décision des dossiers de personnes

**Préparation du 25 octobre 2026, sans règle de suppression exécutable.**
L'utilisateur confirme l'accueil de **mineurs** et des personnes en **zone MENA
et en Europe**, en plus de l'établissement tunisien d'UCG. Ces zones ne sont pas
une liste de pays : les pays exacts, les entités concernées et les lieux de
traitement restent à identifier. Le périmètre Europe ne permet pas, à lui seul,
de conclure sur un régime applicable. La fiche prépare des choix métier et leur
validation locale ; elle n'ajoute ni seuil d'âge, ni durée, ni formalité juridique.

#### Données observées et finalités proposées

Inventaire des sources, sans lecture d'une base réelle. Les finalités sont des
propositions à confirmer, pas des décisions déjà approuvées.

| Référence | Données observées | Finalité à confirmer et séparation attendue |
| --- | --- | --- |
| PERS-01 | `people` : pseudonyme, langue, fuseau, statut, biographie dite publique | Gestion du profil sportif ; le nom du champ `biography_public` ne vaut pas autorisation de publier. Distinguer usage interne et diffusion externe. |
| PERS-02 | `people` : prénom, nom, courriel, téléphone, biographie interne, date de naissance facultative | Gestion administrative et éligibilité ; justifier chaque champ selon candidat, membre ou ancien membre. Éviter de transformer les notes libres en collecte de pièces ou informations inutiles. |
| PERS-03 | `people.user_id`, compte Identity et adhésions | Relier le dossier au compte, lorsqu'il existe. Une personne peut ne pas avoir de compte ; le compte global peut servir à plusieurs organisations. |
| PERS-04 | `person_game_profiles` : jeu, plateforme, région de jeu, rang, rôle, date d'observation, statut ; `person_external_accounts` : fournisseur, identifiant/nom externe et observation | Évaluation et suivi sportifs. Une région de jeu, une langue ou un fuseau ne prouve pas le pays de résidence ; un identifiant externe peut permettre de retrouver la personne. |
| PERS-05 | `person_periods` : affectations/indisponibilités, rôle, début/fin et statut | Organisation sportive et historique des périodes ; distinguer fin d'une période et fin de relation avec UCG. |
| PERS-06 | `person_departure_items` : matériel/obligations, titre, notes, échéance, résolution/dispense | Suivi de restitution ou d'obligations ; une obligation ouverte appelle une décision ciblée, pas la conservation automatique de tout le dossier. |
| PERS-07 | `person_departures` : motif, date de départ, acteur, lien au compte, versions et nombre d'éléments restants | Preuve du départ et cohérence des opérations. Définir le minimum nécessaire séparément des coordonnées et biographies. |
| PERS-08 | Imports CSV, `person_imports`, `person_import_rows`, exports et copies associées | Acquisition/correction des fiches et remise de copies ; distinguer fichier source, valeurs/erreurs de lignes et dossier créé. Cette ligne recoupe les familles imports/artefacts de la matrice générale, sans ajouter de table au décompte des six tables de dossiers. |

Les six tables du noyau sont `people`, `person_game_profiles`,
`person_external_accounts`, `person_periods`, `person_departure_items` et
`person_departures`. Les équipes, compositions, matchs, contrats, compétitions,
académie, disponibilités et autres modules peuvent conserver des références ou
copies : cette fiche n'est pas un inventaire exhaustif de leurs données.

Les champs administratifs de PERS-02 sont chiffrés par les casts du modèle et
projetés selon `administrative.read` ; les champs sportifs sont projetés selon
`sports.read`. Les titres/notes d'éléments de départ et les valeurs/erreurs
d'import ont aussi des casts chiffrés. Ces protections ne constituent ni un
archivage distinct ni une preuve d'effacement. Source principale : `PersonInput`,
modèles People et `PersonProjectionService`.

#### Parcours et point de départ à décider

Pour chaque ligne, **durée active, archive éventuelle, accès d'archive et action
finale restent à valider**, par sous-catégorie PERS et contexte de pays. Aucun
compteur de conservation n'est ajouté par cette fiche.

| Parcours | État constaté dans le code | Événement proposé et décision manquante |
| --- | --- | --- |
| Candidat | `candidate` dans le même dossier `people` | Clôture explicite de candidature : retenue, refusée, retirée ou abandonnée. Aucun événement dédié de clôture n'est identifié dans le module examiné ; ne pas utiliser arbitrairement `updated_at` ou la dernière connexion. Décider si une réutilisation pour une autre candidature a une finalité distincte. |
| Essai | `trial` | Fin/décision d'essai à définir et tracer ; le statut seul ne fournit pas une date de clôture vérifiée. Déterminer si le parcours rejoint candidature ou membre. |
| Membre en activité | `active`, `unavailable`, `substitute` | Besoin courant à justifier ; une indisponibilité ou un changement d'effectif ne signifie pas automatiquement fin de relation. Limiter les champs conservés à chaque usage. |
| Ancien membre | `former`, avec ou sans opération de départ | Utiliser une date de départ métier validée ; `person_departures.departed_at` existe si l'opération dédiée a été exécutée. Un simple statut `former` ne prouve pas ce départ ni la révocation des accès. Prévoir les dossiers sans événement fiable. |
| Obligation ou contestation en cours | Éléments de départ ouverts et dépendances à examiner | Définir périmètre, responsable, justification, revue et fin du gel éventuel. Le suivi de matériel n'est pas un moteur général de gel de conservation. |

`PersonStatus` précise qu'un statut ne donne ni ne retire de droit de connexion
ou d'adhésion. Le parcours `PersonDepartureService`, distinct du changement de
statut, clôture des périodes et appelle les participants de départ (dont les
effectifs d'équipe), puis passe à `former` et enregistre l'événement. Son service
d'accès traite les habilitations du tenant ; il ne supprime pas le compte global
ni le dossier. Les déclencheurs interdisent actuellement la suppression ordinaire
de `people` et des historiques de cycle de vie. Ne pas contourner ces gardes pour
appliquer une future durée ; une évolution dédiée devra être conçue et testée.

#### Mineurs et territoires : décisions à compléter

- **Confirmé par l'utilisateur** : établissement en Tunisie, mineurs accueillis,
  périmètre MENA et Europe. **À préciser** : pays exacts des personnes, entités
  responsables, prestataires, hébergement, accès distants et transferts.
- **Âge et représentants** : définir le parcours applicable par pays, les
  informations nécessaires, qui vérifie quoi et les preuves réellement requises.
  La date de naissance facultative ne prouve ni vérification d'âge ni qualité de
  représentant. Aucun parcours dédié de représentant légal n'est identifié dans
  le module People examiné ; ne pas stocker ces preuves dans la biographie libre.
- **Information et autorisations** : décider des usages internes, contacts,
  publication du profil et partage avec fournisseurs/compétitions, puis des
  modalités à faire valider pour chaque contexte. Un compte utilisateur ou une
  adhésion ne vaut pas preuve d'autorisation pour tous ces usages.
- **Changement de situation** : prévoir correction d'une date, changement de
  représentant, passage à la majorité, changement de pays et fin de relation.
  Les conséquences et seuils restent à définir ; aucune règle automatique n'est
  déduite de cette fiche.

#### Décision attendue et préparation de la simulation

Pour chaque couple parcours/PERS, compléter la fiche générale de conservation :
responsable et finalité, pays/population, champs utiles, événement vérifiable,
durée active, archive/habilitations, justification, action finale et dépendances,
gel éventuel, version/date/validateur. À ce stade, ces valeurs sont **non décidées**,
hors faits explicitement confirmés ci-dessus ; la conservation d'audit existante
reste inchangée. Faire examiner les choix et formalités applicables avant de
les transformer en paramètres.

Après validation, préparer des fixtures Alpha/Beta sans données réelles :
candidature clôturée et sans date fiable, essai terminé, indisponibilité encore
active, départ enregistré et simple statut `former`, obligation ouverte puis
résolue, compte partagé entre tenants, mineur/date inconnue et changement de
situation. La simulation devra expliquer chaque sélection et refus, vérifier
les copies/imports et dépendances, ainsi que l'absence d'effet sur Beta. Ces cas
sont un **plan futur**, pas des tests exécutés ni un moteur livré dans ce lot.
**CAD-004, OPS-007 et REC-35 restent ouverts.**

#### Décisions du premier pilote — 25 octobre 2026

Ces précisions complètent le périmètre général ci-dessus :

| Sujet | Décision utilisateur | Ce qui reste à compléter |
| --- | --- | --- |
| Participants du premier test | Personnes vivant uniquement en Tunisie | Équipe, participants et modules exacts du pilote dans CAD-006. Aucun filtre de pays n'est ajouté au produit par cette décision documentaire. |
| Extension ultérieure | Zone MENA et Europe | Pays précis et examen des règles avant extension ; aucun autre pays n'est déclaré validé pour le premier test. |
| Vérification des parents/tuteurs des mineurs | Une personne de l'équipe UCG, à désigner | Désigner la personne et son remplaçant, définir la procédure et les habilitations. Ce rôle opérationnel ne désigne pas à lui seul le responsable des traitements. |

Le fonctionnement suivant est **proposé, à valider avant mise en œuvre** : la
personne désignée examine la situation du mineur, vérifie les éléments et
autorisations requis selon la procédure applicable, puis consigne un résultat
daté et limité aux usages concernés. Une vérification en attente ou incomplète
ne doit pas être présentée comme approuvée. Le contenu des preuves, leur stockage,
leurs accès et leur durée doivent être définis ; aucune copie de pièce d'identité
n'est demandée ou stockée par ce cadrage. Prévoir ensuite la correction, le
changement de représentant et le retrait d'une autorisation selon l'usage.

Ce parcours n'est pas implémenté. Le ticket **ACA-005** prévoit déjà les
représentants, autorisations, visibilité et conservation, avec activation après
validation juridique locale. La présence de mineurs confirmée rend ce ticket
pertinent pour le pilote ; sa condition d'activation reste ouverte. La validation
doit couvrir les usages réellement choisis, sans supposer que la seule résidence
en Tunisie détermine tous les lieux de traitement ou toutes les règles applicables.

Avant de développer ce parcours ou la purge, il reste à faire valider pour le
pilote tunisien : les informations nécessaires et modalités de vérification,
les usages autorisés, les événements de fin de candidature/relation et les durées
par sous-catégorie PERS. Aucune durée n'est encore fixée. L'hébergement différé
reste une dépendance distincte pour les accès, prestataires et copies.

### UCG-CAD-004 — Dossier consolidé et conditions de clôture

**Situation au 25 octobre 2026.** « Pilote » désigne le premier essai de
l'application. L'utilisateur confirme qu'UCG / Ultra Cyber Game est sa véritable
structure et que cet essai utilisera **uniquement des données fictives pour le
moment**. L'accueil de personnes réelles, notamment de mineurs, est un objectif
ultérieur ; il n'est pas autorisé par la préparation de cet essai. La mention de
participants vivant en Tunisie décrit la population cible du premier essai réel,
pas l'existence d'un fichier de participants réels déjà collecté.

Cette section consolide les décisions précédentes. **La préparation documentaire
est consolidée ; CAD-004 reste ouvert pour validation des points ci-dessous.**
L'hébergement est toujours différé. Aucun nom de responsable, mandat, prestataire,
durée métier ou avis juridique n'est réputé acquis à partir du nom UCG.

#### Tableau de clôture unique

| Décision | État confirmé | Élément nécessaire pour clôturer le cadrage réel | Porteur attendu |
| --- | --- | --- | --- |
| CAD4-01 — Pays et territoires | UCG établie en Tunisie ; première population cible vivant en Tunisie ; MENA/Europe ensuite ; essai actuel fictif | Liste des pays et usages examinés avant toute extension ; lieux de traitement couverts par CAD4-06 | Direction UCG |
| CAD4-02 — Responsabilités | L'utilisateur confirme le 27/10/2026 qu'il décide des informations demandées aux membres et de qui peut les voir ; vérificateur parents/tuteurs à désigner | Préciser en quelle qualité il agit, l'identité de la personne/structure, son contact et la répartition avec exploitant/prestataires. Distinguer décision opérationnelle, compte propriétaire technique et qualification juridique ; qualifier séparément la plateforme et chaque organisation | Utilisateur, décideur opérationnel UCG, avec validation compétente |
| CAD4-03 — Mineurs | Accueil futur confirmé ; date de naissance facultative dans le code ; aucun parcours complet de représentant livré | Nommer vérificateur/remplaçant, valider la procédure ci-dessous, l'âge pertinent, les éléments nécessaires et les formalités ; rattacher la décision à ACA-005 | Direction UCG et personne compétente en droit tunisien |
| CAD4-04 — Conservation | Inventaire des 116 tables en 18 familles, huit sous-catégories PERS et expirations techniques disponibles | Décision datée sur chaque catégorie utilisée : finalité, événement, durée active, archive/accès, justification, action finale, copies, gel et validateur. Documenter aussi les catégories hors périmètre du premier essai réel et le contrôle avant leur activation | Responsables métier et validation locale |
| CAD4-05 — Information et demandes | Usages internes/publics et copies distingués dans la fiche | Texte adapté aux usages retenus, destinataires, contact de demande, vérification du demandeur, traitement des corrections/retraits/départs et procédure de décision tracée | Direction UCG et référent à désigner |
| CAD4-06 — Hébergement et prestataires | Choix différé par l'utilisateur | Prestataire, pays des bases/fichiers/sauvegardes/logs, accès support, services externes, transferts et responsabilités contractuelles ; vérifier la chaîne effective dans OPS-001/004/005 | Direction UCG et exploitation |

Le décideur opérationnel est désormais identifié par sa réponse : l'utilisateur.
Les rôles juridiques ne sont pas attribués automatiquement ; la qualité dans
laquelle il agit et l'identité de la personne/structure restent à préciser.
Le vérificateur des parents/tuteurs n'est pas non plus une personne déjà nommée.
La résidence tunisienne des personnes n'identifie pas le pays d'un futur service
d'e-mail, d'un stockage ou d'un accès de support.

#### Premier lot de décision opérationnelle — 27 octobre 2026

**Base de travail présentée simplement, puis accueillie par « ok » :**
informations sportives accessibles aux personnes autorisées pour le sportif ;
coordonnées/identité aux personnes autorisées pour l'administration ; contrats et
montants avec accès séparé. Au départ d'un membre, retirer ses accès et examiner
les informations encore nécessaires. Identifier les informations devenues inutiles
avant toute suppression ; aucune nouvelle suppression automatique à ce stade.
Ces principes servent à préparer la liste des informations à garder, restreindre
ou supprimer. Ils ne fixent aucune durée et ne changent aucune permission réelle.

Dans cette fiche, UCG désigne Ultra Cyber Game, la structure esport de l'utilisateur.
Les données désignent les informations enregistrées dans l'application : pseudonymes,
identité/contact lorsque nécessaires, équipes, présences, résultats et autres dossiers.
Le premier essai réel désigne un essai avec quelques membres et leurs véritables
informations ; il est distinct de l'essai fictif actuel.

Après reformulation, l'utilisateur confirme le 27 octobre 2026 : « c'est moi qui
décide ». Cette réponse identifie qui choisit les informations demandées aux
membres et qui peut les voir. Elle ne précise pas encore la qualité dans laquelle
il agit ni l'identité de la personne/structure. Le choix des fonctions à essayer
en premier avec quelques membres réels reste à confirmer.
L'option de travail proposée est une gestion interne des personnes, équipes,
planning et matchs ; elle n'est ni un périmètre approuvé ni une exclusion des
mineurs déjà envisagés. Académie, contrats/finances et diffusion publique doivent
être décidés explicitement avant leur ajout au périmètre réel. Le pilote actuel
reste entièrement fictif et aucun module n'est activé ou désactivé par cette fiche.

Le premier lot porte sur PERS-01 à PERS-08. Les propositions suivantes précisent
les décisions à prendre sans choisir une durée, une base juridique ou un seuil
d'âge à la place d'UCG et de la validation applicable.

| Décision | Proposition opérationnelle à examiner | Source disponible et travail restant |
| --- | --- | --- |
| PERS-D01 — Données courantes | Pour la gestion sportive interne, partir du pseudonyme, langue, fuseau, statut et des références de jeu utiles. Justifier séparément nom, prénom, contact, date de naissance et chaque biographie ; la disponibilité d'un champ ne suffit pas à le rendre nécessaire. | `PersonInput::SPORTS_FIELDS` / `ADMINISTRATIVE_FIELDS`, projection par permissions. Les biographies restent facultatives ; aucun formulaire ni champ obligatoire n'est modifié. |
| PERS-D02 — Candidature et essai | Définir une clôture explicite et datée, avec résultat et auteur ; distinguer retrait, refus, abandon et intégration. Choisir ensuite les règles de conservation par sous-catégorie. | `candidate` et `trial` sont des statuts ; aucun événement dédié de clôture de candidature/essai n'est identifié. Ne pas démarrer un délai sur `updated_at`, la dernière connexion ou une date supposée. |
| PERS-D03 — Membre et départ | Maintenir le dossier utile tant que la relation définie par UCG continue. Au départ enregistré, revoir les coordonnées, biographies, accès et références historiques séparément. | `PersonDepartureService` enregistre `person_departures.departed_at`. `unavailable`, `substitute` ou `former` seul ne prouvent pas une fin de relation ; un dossier sans événement fiable nécessite une revue. |
| PERS-D04 — Obligation ouverte | Définir précisément quelles données restent nécessaires, pour quelle obligation, qui revoit le besoin et ce qui marque sa résolution. | Éléments de départ et `pending_items_count` disponibles. Un élément ouvert ne justifie pas automatiquement le maintien de tout le dossier et ne constitue pas un gel juridique général. |
| PERS-D05 — Mineur ou situation inconnue | Séparer la revue du dossier, la qualité du représentant et l'accord pour chaque usage. Fixer les preuves, accès et réexamens dans la procédure approuvée ; ne pas placer de justificatifs dans les biographies. | La date de naissance est facultative ; aucun état de vérification du représentant ne peut être déduit du dossier actuel. ACA-005 reste à concevoir après décision et validation locale. |
| PERS-D06 — Liens et copies | Décider des données minimales encore nécessaires dans équipes/matchs/contrats, imports, exports et sauvegardes ; prévoir le traitement d'une restauration. | Le compte global et les autres organisations restent distincts. Les gardes SQL et historiques ne doivent pas être contournés ; effacer une copie ne prouve pas l'effacement de sa source. |

Pour chaque décision retenue, compléter dans la fiche CAD4-04 existante :
responsable, finalité précise, population/pays, champs, événement fiable, durée
active, éventuelle durée/permission d'archive, justification et validation,
action finale, copies, exceptions ciblées et date de réexamen. Une durée non
définie reste une décision manquante ; elle ne devient ni zéro ni illimitée.
Le rôle d'archive est à définir : `administrative.read` n'est pas supposé donner
automatiquement un accès à toute future archive.

#### Simulation OPS-007 — premier laboratoire fictif

Le premier laboratoire est livré dans `front/tests/real-api/retention-review.mjs`
et `retention-run.mjs`. Il utilise un instant explicite et seulement les organisations
fictives fixture-alpha/fixture-beta. Il explique chaque décision sans identité ni
contenu privé. Les exemples ont un délai fictif de 30 jours uniquement pour tester
l'arithmétique ; ce n'est ni une proposition ni une règle de conservation UCG.
Les résultats ci-dessous sont des décisions de laboratoire, pas des états de
dossiers réels ni une autorisation de supprimer :

| Cas fictif | Résultat attendu du laboratoire |
| --- | --- |
| Politique fictive, référence, durée ou revue manquante | Décision à compléter ; aucune échéance ou suppression autorisée déduite. |
| Candidat/essai sans événement de clôture fiable | Revue de l'événement ; aucun délai inventé. |
| Indisponibilité ou simple statut `former` | Aucun départ présumé ; examen du parcours et de ses preuves. |
| Départ enregistré avec règle complète | Calcul expliqué de l'échéance ; ce résultat ne donne aucun droit d'exécuter une purge. |
| Obligation ou contestation documentée | Examen du périmètre retenu, du responsable et de la prochaine revue ; aucun gel global implicite. |
| Mineur, âge ou représentation non établis | Revue de la procédure et des usages ; aucune autorisation déduite d'une date de naissance. |
| Identité rattachée à un compte utilisé par Beta | Conservation des droits et données de Beta ; aucun effacement du compte global par décision Alpha. |
| Document copié dans un export ou une sauvegarde | Suivi distinct de la source et des copies ; pas de preuve d'effacement exhaustif limitée à la base active. |

Exécution depuis `front` :

```powershell
npm run test:retention:harness
npm run test:retention:simulation
```

Le lanceur refuse tout argument, URL, fichier ou base externe. Il n'importe aucun
code de l'application, ne lit ni données ni configuration UCG et ne possède aucun
adaptateur de suppression. Il écrit seulement son rapport JSON sur la sortie
standard. Toute règle fictive manquante/incomplète ou tout cas nécessitant une revue
reste à examiner, sans échéance déduite ; même une échéance atteinte n'autorise pas
de suppression. Les fixtures sont bornées et la politique doit être marquée fictive.

Résultat local : 11 cas, dont 3 usages courants à réexaminer, 7 revues requises
et 1 échéance fictive atteinte. Neuf tests Node passent, dont UTC/bissextile,
événement absent, mineur/âge inconnu, copies, compte partagé, schéma fermé et
refus inter-organisation. Ils ne prouvent pas un effacement réel, une isolation
SQL ou la prise en charge des 116 tables. Le workflow frontend ajoute ces tests
et un rapport `retention-review.json` ; son exécution distante reste à confirmer.
Un besoin courant doit être explicitement renseigné dans la fixture : un simple
statut actif ne le prouve pas. La revue fictive d'une règle ne valide ni responsable,
procédure mineurs, copies réelles, durées UCG ou formalités applicables.

Le plan opérateur déjà disponible dans `OrganizationPurgeService::plan()` inventorie des
tables après autorisation ; il ne calcule pas cette politique métier. Son chemin
`execute()` est distinct et ne doit pas être appelé pour simuler PERS-D01–06.
La configuration `tenancy.portability.purge_policies` reste vide et les paramètres
de conservation de l'audit restent inchangés.

#### Lots OPS-007 — aperçu Laravel et limites d'exécution

L'aperçu natif suit le parcours Console → Service → Repository → DTO JSON.
Il est réservé à un accès opérateur au serveur, sans route HTTP ni permission
implicite du propriétaire. `config/retention.php` livre deux registres vides :
`review_operators` (identifiant du compte → liste explicite d'UUID tenant) et
`review_policies` (UUID tenant → règles versionnées). Aucun compte n'est inscrit
par défaut et aucune durée métier n'est fournie. Les comptes doivent être vérifiés
et enrôlés MFA ; les comptes techniques plateforme sont refusés. Cette vérification
d'enrôlement ne constitue pas un challenge TOTP de session : l'accès serveur reste
une condition distincte, comme pour les autres commandes opérateur.

Après revue et configuration serveur des accès appropriés, exemples de lecture
(remplacer les identifiants ; aucune commande n'a été exécutée ici sur une base utilisée) :

```powershell
php artisan organizations:retention-review UUID_ORGANISATION --actor=ID_COMPTE --reason="Revue periodique" --section=inventory --page=1 --per-page=6 --as-of=2026-10-27
php artisan organizations:retention-review UUID_ORGANISATION --actor=ID_COMPTE --reason="Revue periodique" --section=people --page=1 --per-page=25 --as-of=2026-10-27
```

Le rapport contient mode review_only, date UTC, version/empreinte de règle,
request_id, pagination et raisons de revue. La section inventory couvre exactement
les 18 familles/117 tables de portabilité, une seule fois par table (dont le journal
de conservation ajouté après le premier aperçu à 116 tables). Une dérive
non revue de schéma bloque le résultat. La section people lit seulement identifiant,
statut, date de départ et présence d'un lien au compte global ; aucun pseudonyme,
nom, contact, naissance, texte de contrat ou montant n'est sélectionné pour le rapport.
Les références directes sont comptées en bloc, dont équipes, matchs, contrats,
calendrier, académie, statistiques et imports. Les comptes externes passent par
leur profil de jeu, avec égalité de tenant des deux côtés. Les comptes globaux
ne sont jamais suivis vers les autres organisations pour en révéler l'activité.

Les règles serveur ont les clés fermées version, reviewed_on,
responsible_reference, validation_reference, reviewed et rules. Chaque règle
de famille précise event, active_days, archive_days et final_action=review.
L'événement recorded_departure est calculable uniquement pour la famille people ;
les autres familles restent en manual_review. Les jours sont des jours calendaires
UTC ; null ne devient pas zéro ni une conservation illimitée. Une durée explicitement
égale à zéro est distincte d'une décision absente. reviewed=true et les références de
configuration ne prouvent pas une validation juridique : le statut retourné reste
configured_review_only, jamais une autorisation d'effacement.

Les copies/imports, historiques embarqués, caches, journaux, jobs, réplicas,
sauvegardes/PITR, prestataires et téléchargements remis doivent être examinés
séparément. L'absence de référence directe ne prouve pas l'absence de copie ;
dependency_inventory_complete et deletion_authorized restent faux. Les dates
active_review_on/archive_review_on ne prouvent ni archivage effectué ni accès
d'archive restreint. L'aperçu ne donne pas de garantie de snapshot d'exécution.
L'accès et l'empreinte de règle sont revérifiés avant publication du rapport ;
une dérive le refuse sans émettre un résultat partiel. La trace structurée
tenant.retention.review contient identifiants techniques, page, taille et empreinte,
sans motif libre ni contenu de dossier. Le JSON produit doit rester un rapport privé.

| Lot | Travail réalisé | Ce qui ne peut pas être déclaré terminé |
| --- | --- | --- |
| Catalogue | Classement fermé des 18 familles/117 tables, contrôle de dérive, inventaire paginé | Une durée propre à chaque sous-catégorie n'est pas décidée par ce classement. |
| Règles et accès | Configuration versionnée fermée, empreinte, accès opérateur par compte/tenant, valeurs manquantes signalées | Responsabilités, usages, durées et validations CAD-004 restent à compléter. |
| Aperçu de dossiers | Lecture Laravel réelle sur fixtures, événements fiables, dépendances groupées, dates de revue conditionnelles | Les autres familles ont un inventaire, pas un moteur de décision par dossier. |
| Copies et reprise | Quarantaine manuelle d'organisation inactive, reprise bloquée sans reçu versionné de revue des 11 catégories de copies ; reçu conservé dans l'audit | Pas de réconciliation automatique des effacements ni de preuve chez un fournisseur, dans un réplica ou une sauvegarde ; qualification d'hébergement différée. |
| Archivage et gels | Restriction d'archive pour toute l'organisation ; soft delete/restauration People par fiche ; accès export réservé aux opérateurs de conservation ; gels indépendants sur suppressions techniques | Archivage par dossier des autres familles et gel général couvrant aussi les réécritures métier restent absents. |
| Purge métier | Purge technique existante soumise aux gels/quarantaine, lots/reprises préservés, aucun pont depuis les règles de revue | Aucun handler d'effacement exhaustif des données métier ou des historiques immuables n'est livré. |
| Validation/livraison | Tests SQLite/PostgreSQL, analyse statique, documentation et couverture PostgreSQL regroupée en CI | La nouvelle CI distante et la recette réelle restent à confirmer. |

La purge technique existante garde son propre registre de politiques, ses
confirmations, export d'organisation archivée, lots bornés et reprises. Ajouter
une règle review_policies n'y inscrit aucune politique et ne peut pas la déclencher.
La nouvelle commande refuse --execute. Aucune garde SQL, conservation d'audit,
planification d'expiration ou politique de purge existante n'est désactivée.
Lors d'une future restauration, les décisions d'effacement devront être réconciliées
avant remise en service ; cet aperçu ne réalise pas cette réapplication métier.
**OPS-007/CAD-004/REC-35 restent ouverts.** Les lots de préparation ne doivent pas
être confondus avec une purge métier complète et qualifiée.

#### OPS-007 — contrôles opérateur des six blocs (livraison 9151df0)

Cette section décrit le lot précédent. Son extension du 28 octobre est décrite
ci-dessous ; les limitations historiques ne constituent pas le bilan du nouveau lot.

La commande `organizations:retention-control` complète l'aperçu. Elle retourne
l'état et un bilan explicite des six blocs : règles, gels, archives, effacement,
copies/reprise et qualification. Le bilan ne déclare jamais `production_qualified`
ou `business_deletion_supported` vrai dans cette version. Il distingue une revue
déclarée par l'opérateur d'une vérification indépendante sur l'hébergement réel.

Les écritures exigent `retention.control_enabled=true` et un registre serveur
`control_operators` (compte → UUID tenant), distinct de `review_operators`.
Les deux sont livrés désactivé/vide. L'accès exige un compte vérifié, enrôlé MFA,
sans rôle technique plateforme. Chaque décision exige un motif, une référence
technique sans contenu personnel, l'UUID confirmé et la version attendue.
`--request-id` peut être conservé pour une reprise identique ; une requête réutilisée
avec d'autres paramètres est refusée. Une ancienne levée rejouée ne lève jamais un
gel acquis plus récemment. L'inspection reste en lecture seule.

Exemples opérateur, à adapter après revue de configuration ; non exécutés sur une
base utilisée. Le dernier exemple ne fonctionne qu'après activation explicite :

```powershell
php artisan organizations:retention-control UUID_ORGANISATION --actor=ID_COMPTE --reason="Etat de conservation"
php artisan organizations:retention-control UUID_ORGANISATION --action=hold --actor=ID_COMPTE --reason="Preservation du dossier de revue" --reference=REVUE-001 --expected-version=0 --confirm=UUID_ORGANISATION --request-id=UUID_REQUETE
```

- `hold` ajoute un gel nommé ; `release-hold` retire exclusivement ce gel. Aucune
  échéance ne libère un gel automatiquement. Les gels portent sur toute
  l'organisation, avec au plus 1 000 références actives ; ils bloquent les suppressions
  techniques de fichiers, leurs expirations, la suppression des exports et la purge technique des
  paramètres. Ils ne rendent pas immuables toutes les modifications métier.
- La vérification et la suppression physique partagent le verrou transactionnel
  de l'organisation avec l'acquisition des gels. Les E/S externes n'ont pas de retry
  transactionnel automatique. Un échec conserve les métadonnées pour reprise ; les
  autres tenants peuvent poursuivre leur nettoyage. Désactiver la commande ne
  désactive pas les gels déjà enregistrés. La migration doit précéder le nouveau code.
- `restrict-archive` exige une organisation déjà archivée. Les usages ordinaires
  restent refusés par le statut ; demande, génération, relance et lecture d'export
  exigent alors l'opérateur explicitement autorisé. Le droit de lire est revérifié
  après la mise en mémoire du flux ; un refus ferme le flux. `release-archive`
  retire cette restriction mais ne réactive pas l'organisation.
- `quarantine` exige une organisation inactive. Elle bloque suppression technique,
  récupération et retour au statut actif. Elle est volontaire : aucune détection
  automatique d'une restauration externe n'est revendiquée.
- `release-quarantine` exige un reçu serveur `restoration_approvals[UUID]` fermé :
  `reviewed=true`, `reference`, `quarantine`, `control_version`, `policy_fingerprint`,
  `hosting_reference`, `copies`. Les trois références sont techniques, la version
  correspond exactement à l'état courant et l'empreinte à la configuration actuelle.
  Les 18 familles doivent avoir une règle de revue et `copies` doit contenir les
  11 clés de `UNVERIFIED_COPIES`, chacune avec une référence de revue non vide.
  Le reçu complet est figé dans l'audit lors de la levée. Cette déclaration manuelle
  n'atteste pas un effacement fournisseur et ne déclenche aucune reprise de jobs.

`organization_retention_events` garde chaque décision, son auteur, sa requête,
sa version et son état résultant. UPDATE/DELETE sont refusés par PostgreSQL/SQLite ;
un rollback refuse de supprimer ce journal s'il contient des décisions. La table
est comprise dans l'export privé et le catalogue de conservation. Le JSON
de commande n'inclut ni motif libre ni reçu détaillé ; la trace structurée ne contient
que tenant, acteur, requête, version et indicateur de rejeu. Les sorties restent privées.

Avant un exercice de restauration, arrêter l'admission métier et les workers,
conserver hors du jeu restauré les décisions les plus récentes, restaurer en
environnement isolé/inactif, puis ouvrir la quarantaine. Comparer l'ensemble des
effacements et gels au registre conservé hors sauvegarde, examiner les 11 catégories
de copies et consigner les références de revue avant toute levée. Ce parcours doit
encore être exercé sur le futur hébergement ; le code ne réapplique pas les décisions
d'effacement et ne remplace pas ce registre externe.

**Les six blocs ne sont donc pas clôturés.** Pour People, la décision ci-dessous
fixe désormais une conservation illimitée. Restent le cadrage des autres
sous-catégories, les événements fiables des autres modules, les règles de champs
et de conservation des historiques, l'archivage des autres familles et l'effacement définitif par dossier,
le gel de leurs réécritures, la réconciliation effective des copies et les essais
sur les stockages, réplicas et sauvegardes du futur hébergement.

Validation locale du lot : 2 912 cas backend, 2 853 réussites, 59 exclusions
conditionnelles, 18 210 assertions, aucune erreur/échec ; PostgreSQL temporaire
139 tests/662 assertions. Pint, PHPStan et syntaxe YAML CI verts. La qualification
de l'hébergement et la nouvelle exécution distante restent distinctes de ces tests.

Correctif CI avant push : les tests OPS-007 sont intégrés à la suite PostgreSQL
existante dans un seul processus PHPUnit. Une seconde initialisation de la même
base vidait les tables mais conservait les fonctions de déclencheurs, provoquant
SQLSTATE[42723]. Le problème a été reproduit avec deux processus ; l'exécution
groupée corrigée passe 158 tests/725 assertions. Les 168 cibles des deux anciennes
étapes restent couvertes par les 164 cibles regroupées (dont le répertoire Tenancy).
Les rapports PostgreSQL restent collectés par le glob JUnit existant.

#### OPS-007 — gel métier, effacement manuel et réconciliation (28 octobre 2026)

Décisions utilisateur : l’effacement manuel vise une personne ou une organisation
entière. **L’effacement individuel est bloqué tant que des contrats, matchs ou
historiques partagés restent liés à la personne.** Un import partagé avec d’autres
personnes bloque également l’opération. L’hébergement reste reporté : aucune recette
sur une infrastructure réelle ni qualification de sauvegardes n’est déclarée.

Le gel nommé protège désormais les INSERT/UPDATE/DELETE des 117 tables métier et
d’historique du catalogue initial, dans les services et dans PostgreSQL/SQLite.
Les écritures de preuve et le suivi technique nécessaire restent autorisés.
Chaque décision fait avancer `organizations.retention_version` sous verrou ; un
écrivain PostgreSQL utilisant un ancien instantané REPEATABLE READ doit abandonner
sa transaction plutôt qu’ignorer un gel acquis depuis. La quarantaine bloque aussi
les accès métier, les exports et l’admission des travaux en arrière-plan.

Le catalogue comprend maintenant 120 tables tenant, dont trois tables de contrôle :
`tenant_erasure_operations`, `tenant_erasure_events`, `tenant_erasure_permits`.
Les trois migrations du 28 octobre doivent précéder ce code ; aucun déploiement ou
effacement sur une base utilisée n’est effectué dans ce lot. Les retours arrière
refusent de jeter des décisions d’effacement ou de conservation existantes.

**Activation serveur explicite, livrée désactivée.** `retention.erasure.enabled`
et `retention.erasure.operators[ID_COMPTE]` complètent le registre des opérateurs de
conservation. Le compte doit rester vérifié, enrôlé MFA et sans rôle technique de
plateforme. Les commandes recontrôlent ces conditions à chaque reprise. Le profil
de journal et sa clé doivent rester hors des sauvegardes de la base métier :

- `RETENTION_JOURNAL_CONNECTION=retention_journal` désigne la connexion PostgreSQL
  distincte fournie dans `config/database.php`. Ses paramètres
  `RETENTION_JOURNAL_DB_URL` ou `RETENTION_JOURNAL_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`
  ne reprennent aucun identifiant `DB_*`. TLS exige `verify-full` par défaut ;
  `RETENTION_JOURNAL_DB_SSLMODE` doit correspondre au profil revu.
- `RETENTION_INSTALLATION_ID` est un UUID stable de cette installation.
  `RETENTION_JOURNAL_KEY` est un secret indépendant de 64 à 256 caractères,
  différent de `APP_KEY`, conservé hors dépôt et hors sauvegarde métier.
- `retention.erasure.journal_independence_reviewed=true` est une déclaration de revue
  opérateur. La séparation logique est contrôlée ; elle ne prouve pas à elle seule
  une séparation physique des sauvegardes, des comptes ou des infrastructures.
- Initialiser le journal pour chaque organisation concernée avant réouverture.
  Cette initialisation reprend aussi ses gels historiques. Dès que le profil est
  configuré, un journal absent ou indisponible bloque les chemins protégés.
  Garder ce profil après désactivation de nouvelles demandes d’effacement.

Exemples de commandes serveur à adapter sur l’environnement autorisé, jamais à
exécuter tels quels sur la base utilisée :

```powershell
php artisan organizations:erase UUID_ORGANISATION --action=initialize-journal --actor=ID_COMPTE --reason="Initialisation revue"
php artisan organizations:erase UUID_ORGANISATION --scope=person --target=UUID_PERSONNE --actor=ID_COMPTE --reason="Previsualisation du dossier"
php artisan organizations:erase UUID_ORGANISATION --action=begin --scope=person --target=UUID_PERSONNE --digest=EMPREINTE_SOURCE --reference=DOSSIER-001 --confirm=UUID_PERSONNE --actor=ID_COMPTE --reason="Effacement approuve"
php artisan organizations:erase UUID_ORGANISATION --action=inspect --operation=UUID_OPERATION --actor=ID_COMPTE --reason="Etat de progression"
php artisan organizations:erase UUID_ORGANISATION --action=resume --operation=UUID_OPERATION --actor=ID_COMPTE --reason="Reprise du lot"
php artisan organizations:erase UUID_ORGANISATION --action=acknowledge --operation=UUID_OPERATION --category=backups_pitr --reference=REVUE-SAUVEGARDE-001 --actor=ID_COMPTE --reason="Revue des copies"
```

Pour l’organisation entière, utiliser `--scope=organization` et son UUID comme
cible et confirmation. Elle doit déjà être archivée. Pour une personne, l’organisation
doit être inactive ; la personne doit être supprimée logiquement, partie et sans
obligation ouverte. Les gels, restrictions et quarantaines préexistants empêchent
une nouvelle demande. La prévisualisation est sans suppression : compteurs, liens
bloquants et empreintes, sans contenu des fiches. L’approbation exige exactement
l’empreinte courante ; toute modification impose une nouvelle prévisualisation.

`begin` enregistre l’opération et sa quarantaine, puis écrit l’intention signée dans
la base indépendante **avant toute suppression**. Si cette seconde écriture échoue,
le dossier reste bloqué ; récupérer l’UUID dans l’inspection des contrôles et utiliser
`resume`, sans recréer une demande. Chaque reprise traite au plus 100 fichiers ou
100 lignes. Les fichiers privés sont visés par UUID tenant et disque ; les suppressions
sont idempotentes. Les compteurs de stockage suivent la suppression vérifiée des
objets ; une erreur conserve les métadonnées et la possibilité de reprise.

Les dépendances sont supprimées dans l’ordre enfant → parent, avec traitement des
révisions auto-référencées et des liens nuls nécessaires aux cycles. Un schéma ou
une protection SQL modifiés suspendent l’exécution. Les protections d’historique
restent actives pour les requêtes ordinaires : les exceptions sont limitées à une
opération, une transaction, une organisation, une table, une ligne et, pour une
mise à jour technique, ses valeurs attendues. Aucun FK ou déclencheur n’est désactivé.
Les générations des projections sont invalidées après un effacement individuel.

Les comptes globaux restent préservés. Pour une organisation, une racine anonymisée
et les preuves opérationnelles minimales restent conservées ; sa quarantaine est
permanente. Pour une personne, la quarantaine propre à l’effacement ne peut être
levée qu’après la revue des copies ; l’organisation reste inactive jusqu’à une
décision distincte de reprise. Les journaux et identifiants de décision restent
des données privées d’exploitation, pas une promesse de disparition de tout identifiant.

L’état `awaiting_copies` suit le retrait local. Les 11 catégories de copies exigent
chacune une référence de revue : exports, versions d’objets, instantanés, imports,
caches, jobs, logs, réplicas, sauvegardes/PITR, fournisseurs et téléchargements des
destinataires. `completed` signifie retrait local vérifié et attestations opérateur
enregistrées. **Ces attestations ne prouvent pas un effacement chez le fournisseur.**
`production_qualified` reste faux. Aucun connecteur fournisseur ni effacement des
sauvegardes, messages distribués ou téléchargements distants n’est simulé comme réel.

**Après restauration**, couper l’admission, les workers et les planificateurs ;
restaurer en environnement isolé. Conserver le journal indépendant courant, son
profil et sa clé. Activer `RETENTION_RESTORE_MODE=true` avec un nouveau
`RETENTION_RESTORE_EPOCH=UUID_EXERCICE`, puis :

```powershell
php artisan organizations:reconcile-erasure UUID_ORGANISATION --actor=ID_COMPTE --reason="Inspection apres restauration"
php artisan organizations:reconcile-erasure UUID_ORGANISATION --head=EMPREINTE_JOURNAL --confirm=UUID_ORGANISATION --actor=ID_COMPTE --reason="Reconciliation approuvee"
```

Les gels absents de la sauvegarde sont réimportés depuis le journal signé et exigent
une revue explicite, même si une levée ultérieure n’est plus dans la base restaurée.
Les intentions d’effacement sont rejouées dans de nouvelles opérations de cet
exercice ; reprendre leurs lots et revoir les copies avec `organizations:erase`.
Le manifeste indépendant permet aussi de retirer un fichier restauré dont l’ancienne
base ne contient plus les métadonnées. Relancer la réconciliation jusqu’au bilan
final ; une organisation effacée reste en quarantaine. Ne réutiliser jamais l’UUID
d’exercice pour une nouvelle restauration. Retirer la maintenance seulement après
la revue de toutes les organisations et de tous les services externes concernés.

Avec ce profil conservé, une base antérieure aux décisions ne peut pas reprendre
les accès métier ou les exports : l’état du journal indépendant doit être rapproché
des preuves locales. Ce contrôle ne peut pas détecter une restauration simultanée
du journal indépendant et de la base métier, ni la suppression de tout le profil
serveur dans une sauvegarde ancienne. L’isolation réelle, les privilèges du journal,
les réplicas, le PITR, les versions d’objets et les objectifs RPO/RTO restent à exercer
sur le futur hébergement.

Limites d’exploitation : 100 000 lignes par prévisualisation, délai borné de lecture
du contenu, 1 000 intentions/gels externes par organisation. Un dépassement bloque
l’opération et exige un traitement dédié ; aucune suppression partielle implicite
n’est autorisée. Les grands volumes, les systèmes externes et la montée en charge
doivent être qualifiés avant activation. Aucun écran d’effacement SPA n’est ajouté.

Validation locale de cette extension : **2 937 cas backend, 2 876 réussites,
61 exclusions conditionnelles, 18 430 assertions, aucun échec/erreur**. PostgreSQL 17
temporaire : périmètre élargi 160 tests/870 assertions ; protocole final 42 tests/293
assertions ; cas complémentaires 15 tests/81 assertions et retours arrière historiques
13 tests/57 assertions, tous verts. Ces sélections se recouvrent et ne s’additionnent
pas comme des cas distincts. PHPStan et Pint verts. Les clusters temporaires sont
arrêtés et supprimés après contrôle de propriété. Les fichiers fictifs des suites
parallèles utilisent des `TEST_TOKEN` distincts. Rapports locaux ignorés dans
`back/reports` ; aucune validation distante de ce nouveau commit n’est revendiquée.

#### Décision utilisateur — conserver sans limite et supprimer logiquement

Le 27/10/2026, l'utilisateur précise : « on conserve ses info pour toujour avec
soft delete jusqua suppression manuelle ulterieurement ». La règle produit pour
les fiches People est donc **conservation illimitée, suppression logique réversible,
effacement définitif uniquement manuel**. Aucun compte à rebours d'effacement
métier n'est ajouté. Les contrats, résultats et historiques restent conservés.
Cette décision produit ne certifie pas le cadrage CAD-004 et n'est pas extrapolée
à des règles de destruction des autres modules.

Le registre de revue peut représenter une conservation illimitée explicitement :
`event=manual_review`, `active_days=null`, `archive_days=null`, `final_action=preserve`.
Un `null` isolé avec `final_action=review` reste invalide ; aucune absence de durée
n'est transformée implicitement en politique illimitée. Les anciennes durées de
fixtures servent uniquement à éprouver le calcul de dates de revue.

La migration additive `2026_10_27_213817_add_soft_deletes_to_people_table` ajoute
`deleted_at` et un index tenant/date/identifiant. L'API offre :

| Appel | Comportement |
| --- | --- |
| `DELETE /api/v1/tenants/{tenant}/people/{person}` | Suppression logique après un départ enregistré, statut former et absence d'obligation de départ ouverte ; conserve les champs et les références. |
| `GET /api/v1/tenants/{tenant}/people?archive=only&per_page=20` | Liste paginée des fiches masquées, avec la projection de droits existante. |
| `POST /api/v1/tenants/{tenant}/people/{person}/restore` | Retire le marqueur logique ; conserve le statut former et l'événement de départ. |

Les deux écritures exigent `{ "version": "UUID_VERSION_ACTUELLE", "confirm": true }`.
Form Requests et Service partagent les règles fermées ; les champs supplémentaires
sont refusés. Le droit de modifier les données sportives **et** administratives
est requis pour masquer/restaurer et lire la liste masquée ; aucune permission
implicite du propriétaire. Sessions MFA, confirmation récente, isolation tenant,
garde métier et CSRF restent requis. Une version périmée donne 409 et impose une
relecture. Une panne d'audit annule la mutation dans la même transaction.

Exemple avec le client Axios déjà configuré pour la session applicative et le CSRF
(racine d'API à adapter au baseURL du client) :

```javascript
const root = `/api/v1/tenants/${tenantId}/people`;
const removed = await client.delete(`${root}/${personId}`, {
  data: { version: currentVersion, confirm: true },
});
const archives = await client.get(root, { params: { archive: 'only', per_page: 20 } });
await client.post(`${root}/${personId}/restore`, {
  version: removed.data.data.version, confirm: true,
});
```

Les listes ordinaires, recherches, accès directs et nouveaux choix métier utilisent
le scope Eloquent qui masque les fiches supprimées logiquement. L'export opérateur
privé et l'inventaire de conservation incluent toujours la ligne, ses champs
chiffrés et son marqueur logique. Les réponses de mutation ne contiennent que
UUID/version/marqueur et mode de conservation, avec notification localisée ;
l'audit fermé contient `person_archived`, sans recopier l'identité. Aucun écran
SPA de corbeille n'est ajouté dans ce lot : ce parcours est livré par l'API.

Une suppression logique ne supprime ni profil de jeu, contrat, résultat, compte
global, ni copie d'historique ; elle n'est pas un effacement physique. Les gardes
SQL DELETE et le refus de suppression directe du modèle restent actifs. Le rollback
refuse de retirer le marqueur s'il existe des fiches masquées. La restauration
rend la fiche visible sans réadmission sportive, retour d'adhésion ni reprise de job.
Les exports temporaires gardent leur expiration technique ; le nettoyage d'une
copie temporaire ne constitue pas l'effacement du dossier conservé.

#### Point juridique à intégrer avant données réelles

Référence consultée le 25 octobre 2026 : [loi n° 2004-63 publiée par l'INS](https://www.ins.tn/sites/default/files-ftp3/files/2020-04/Loi%2063-2004%20Fr.pdf).
L'article 28 mentionne le consentement du tuteur et l'autorisation du juge de la
famille pour les données d'un enfant : une validation interne ne les remplace
pas. Les articles 7–8, 31, 45–46 et 50–52 éclairent les formalités, l'information,
la destruction et les transferts à faire examiner. Ce repérage ne certifie pas
l'applicabilité exacte ni l'état exhaustif du droit. La [fiche officielle ARP
095/2025](https://www.arp.tn/loi/project/4237) affiche une proposition en commission ;
elle ne constitue pas une loi de remplacement promulguée. Faire confirmer les
textes et modalités en vigueur avant collecte réelle.
Les deux pages officielles ci-dessus ont été relues le 27 octobre 2026 : le texte
publié par l'INS conserve les points signalés et la fiche ARP affiche toujours
un examen en commission. Cette vérification ciblée ne constitue pas un examen
exhaustif des textes applicables ni une validation juridique du pilote.

#### Procédure proposée pour les mineurs — à valider, non implémentée

1. **Préparer l'admission** : préciser l'activité, les informations strictement
   nécessaires, les destinataires et la procédure applicable avant de demander
   des informations réelles. Ne pas ouvrir une collecte de justificatifs pour
   simplement compléter ce document.
2. **Orienter les cas** : prévoir les situations adulte, mineur et âge non établi,
   avec les critères validés localement. Une date absente ou déclarée ne vaut pas
   vérification ; les cas indéterminés restent en attente d'examen.
3. **Vérifier la représentation** : le vérificateur UCG désigné applique la méthode
   approuvée ; il traite aussi les désaccords ou changements de représentant et
   distingue le contact fourni de la qualité de représentant effectivement revue.
4. **Séparer les usages** : participation interne, communication, publication
   du profil/image et transmission à un tiers font l'objet de décisions propres.
   La création d'un compte ou l'accès sportif ne doit pas approuver ces usages.
5. **Vérifier le dossier applicable** : contrôler les éléments/formalités validés
   localement, leur portée et leur validité. Prévoir des résultats en attente,
   incomplet, refusé ou validé pour un usage déterminé, avec motif minimal ; ce
   vocabulaire est une proposition de conception, pas de nouveaux états livrés.
6. **Tracer avec accès restreint** : prévoir référence de dossier, usage/version
   de l'information, identité de l'intervenant, résultat/date de revue et échéance
   de réexamen. Le détail des preuves, leur support et leur durée restent à
   approuver. Aucun document d'identité n'est collecté par défaut ; ne pas utiliser
   les biographies ou notes générales comme coffre de justificatifs.
7. **Réexaminer** : correction, retrait, changement de représentant, majorité,
   pays ou activité provoquent une nouvelle décision sur les usages concernés.
   Distinguer suspension d'un usage, retrait d'accès, fin de relation et effacement.
8. **Terminer la relation** : appliquer les décisions de conservation approuvées
   aux sous-catégories et copies ; un litige/gel doit avoir un périmètre et une
   revue. La purge sera conçue dans OPS-007, sans désactiver les gardes SQL actuels.

#### Conservation et essais : décisions distinctes

Les durées métier restent **non validées pour les 18 familles**, y compris les
contrats, justificatifs, statistiques, journaux et sauvegardes, pas seulement
les dossiers de personnes. La fiche PERS est une première déclinaison ; les
autres catégories doivent recevoir leurs propres décisions avant usage réel.
La conservation d'audit illimitée existante demeure une décision technique à
réexaminer ; elle ne clôt pas CAD4-04. Aucun délai français ni durée numérique
arbitraire n'est ajouté pour rendre la matrice artificiellement complète.

Pour l'essai actuel, utiliser des scénarios et personnes entièrement inventés,
sans copie de fichiers réels, identité de parent ou coordonnées de membre réel.
Les comptes des développeurs, adresses d'envoi, journaux et services utilisés
peuvent toutefois concerner de vraies personnes : « données métier fictives »
ne certifie pas que l'ensemble de l'installation est dépourvu de données
personnelles. Les outils existants de test isolé/nettoyage ne deviennent pas
une politique de conservation de production.

#### Passage aux autres tickets

L'essai fictif permet de préparer conception et simulations sans présumer les
décisions ci-dessus. Les scénarios futurs doivent couvrir : accès inter-tenant
refusé, vérificateur sans droit, dossier incomplet, usage non approuvé, retrait,
changement de représentant, âge inconnu, compte partagé, événement de fin absent,
gel et cohérence des copies. Aucun de ces tests n'est déclaré exécuté par ce lot.

Pour clore CAD-004 sur un essai réel, chaque ligne CAD4 doit disposer d'une
décision datée, d'un responsable et des références/validations nécessaires ;
un choix différé reste ouvert. **ACA-005** porte l'implémentation des mineurs,
**OPS-007** celle de la conservation/purge, **OPS-001/004/005** les environnements
et copies, **CAD-006/REL-001** le périmètre et la conduite du premier essai.
Ce dossier ne clôt aucun de ces tickets, REC-35 ou la recette QA-005 différée.

## UCG-OPS-004 — Exercice local de sauvegarde et restauration

Le test `front/tests/real-api/recovery.test.mjs` crée deux clusters PostgreSQL 17
temporaires distincts avec les garde-fous du lanceur navigateur existant. Il ne
prend aucun chemin de sauvegarde, URL, base existante ou cible externe en argument.
Les paramètres applicatifs/PG hérités sont écartés. Le bootstrap PHP vérifie
testing, jeton, manifeste, coordonnées et data_directory avant chargement de
Laravel ; le seed exige une base vide. Les clés et mots de passe sont générés
pour l'exercice, jamais repris de l'installation utilisée.

Depuis `front`, avec PHP 8.5/pdo_pgsql, dépendances installées et outils
PostgreSQL 17 (`initdb`, `pg_ctl`, `createdb`, `pg_dump`, `pg_restore`) :

```powershell
$env:UCG_BROWSER_PG_BIN = 'C:\Program Files\PostgreSQL\17\bin'
npm run test:recovery:harness
npm run test:recovery
```

Sur Linux, utiliser le répertoire canonique des binaires 17, par exemple
`/usr/lib/postgresql/17/bin`, avec un utilisateur ordinaire. Le job PostgreSQL
de la CI frontend exécute également ces deux commandes et conserve le rapport
`front/reports/recovery-local.json`. Une CI distante nouvelle reste à confirmer.

L'exercice migre le schéma complet et crée deux organisations fictives Alpha/Beta,
chacune avec une personne, des champs chiffrés et un fichier privé du ledger.
Aucun serveur applicatif ou worker n'est démarré : la source est **sans écriture
concurrente** pendant la sauvegarde base/fichiers. Le dump natif au format custom
et les deux fichiers sont réunis dans une enveloppe AES-256-GCM authentifiée,
avec une clé de sauvegarde distincte de la clé applicative. Une modification
postérieure est apportée à Alpha, puis le cluster source est arrêté avant de
restaurer dans le second cluster vide. La restauration conserve le point de
sauvegarde ; elle ne recopie pas la modification postérieure.

Le test compare les empreintes et comptes de lignes de **toutes les tables
publiques**, y compris celles vides, l'état des séquences, l'inventaire des
contraintes, les déclencheurs et leurs fonctions. Les définitions CHECK peuvent
être réécrites de façon équivalente par PostgreSQL : l'inventaire est comparé et
des refus CHECK/FK/historique sont réellement exercés. Les fichiers sont comparés
octet par octet, lus via TenantArtifacts et refusés depuis l'autre tenant ; les
champs sont déchiffrés avec la clé applicative originale.

Les cas négatifs couvrent sauvegarde altérée, mauvaise clé de sauvegarde, mauvaise
clé applicative, coordonnées étrangères, base cible remplie, stockage cible
occupé et fichier restauré manquant. Les refus préalables laissent la cible
intacte. Les liens symboliques, chemins sortants, doublons et entrées hors format
sont refusés ; le format est limité à 100 artefacts et 32 Mio de JSON déchiffré.
Les objets sont traités en mémoire pour cette petite fixture : **ce format de
test n'est pas un outil de sauvegarde de production**. Les dumps de travail non
enveloppés restent dans les répertoires temporaires jusqu'au nettoyage.

Le rapport ne contient ni contenu privé ni clé : résultat, versions de contexte,
empreintes des sources, durées techniques, couverture et nettoyage. Les clusters
sont arrêtés et les répertoires canoniques appartenant au lancement supprimés.
Un doute de propriété ou échec d'arrêt conserve les ressources et fait échouer
le test ; un arrêt brutal du système ne garantit pas le nettoyage.

**État du premier exercice, complété ci-dessous le 30/10/2026** : pas de PITR/WAL, copie distante isolée, rotation durable
des clés, conservation réelle, sauvegarde des rôles/ACL du cluster ou exercice
de panne sur infrastructure représentative. Le dump utilise no-owner/no-acl et
la restauration single-transaction, sans clean/create ni désactivation des gardes.
Les durées locales ne sont ni un RTO de service ni un engagement RPO. Les modules
autres que People/artefacts ont leur schéma restauré, pas un scénario métier
peuplé. La récupération sélective d'un tenant avec réconciliation relève
d'OPS-005/REC-36 ; cet exercice restaure une base complète dans une cible distincte.

## UCG-OPS-005 — Premier exercice de récupération sélective

Depuis `front`, avec les mêmes prérequis PostgreSQL 17 que l'exercice OPS-004 :

```powershell
$env:UCG_BROWSER_PG_BIN = 'C:\Program Files\PostgreSQL\17\bin'
npm run test:recovery:harness
npm run test:recovery:tenant
```

Le test `tenant-recovery.test.mjs` et la fixture PHP `tenant-recovery.php` sont
réservés aux environnements temporaires attestés du lanceur. Le laboratoire
commun `recovery-lab.mjs` sert aussi au test OPS-004. Aucune commande opérateur,
route d'import ou restauration vers une base utilisée n'est ajoutée.

Le scénario sauvegarde Alpha/Beta fictifs, restaure le dump et les fichiers dans
un second cluster, puis y sélectionne la fiche et le fichier Alpha. Ce cluster
de lecture est ensuite arrêté. Dans le cluster courant, Alpha est modifié et son
fichier privé supprimé pour simuler une perte ; Beta reçoit une modification,
une nouvelle fiche et un nouveau fichier après sauvegarde. Les données à rétablir
proviennent du cluster restauré, pas de la fiche Alpha courante.

La comparaison préalable est sans écriture et porte sur un périmètre fermé :
**pseudonyme, prénom et courriel d'une fiche Alpha existante, ainsi qu'un artefact
Alpha manquant dont le ledger doit être intact et identique à la sauvegarde**.
La confirmation du tenant, les identifiants de fiche/artefact, le contenu et son
empreinte sont vérifiés. Le plan est signé avec le jeton éphémère du laboratoire
courant et fixe version/empreinte de la fiche et du ledger ; une modification
du plan ou un changement de la fiche depuis sa préparation entraîne un refus.
Cette signature lie le plan au laboratoire, sans constituer une approbation
métier réelle ni une signature juridique.

La réapplication utilise PersonService et ses validations/autorisations ; les
droits métier sont accordés explicitement dans les fixtures, indépendamment de
la qualité de propriétaire. La fiche reçoit une nouvelle version et un événement
d'audit Alpha. Les gardes SQL et les historiques restent actifs. Le fichier
manquant est créé sans écraser un objet existant et sans modifier le ledger ou
son quota. Une erreur synchrone simulée après écriture annule la transaction et
supprime seulement le fichier nouvellement créé. Ce test de compensation ne
garantit pas l'atomicité base/fichiers en cas d'arrêt brutal de machine.

Les contrôles comparent tous les comptes/empreintes de lignes Beta sur les
**116 tables du catalogue fermé**, les **20 tables globales restantes** et les
octets des deux fichiers Beta récents. Les refus portent sur absence de droit,
confirmation étrangère, données Beta présentées pour Alpha, fiche/artefact
étrangers, plan altéré, plan devenu obsolète et réapplication après succès.
Un échec simulé doit laisser toutes les lignes et fichiers courants inchangés.
Les modèles métier peuplés restent People, rôles et artefacts ; les autres tables
figurent au contrôle, sans scénario métier peuplé dans chaque module.

Le rapport ignoré `front/reports/tenant-recovery-local.json` contient le résultat,
les empreintes des sources/de la sauvegarde chiffrée, les durées, contrôles et
nettoyage, sans identités, contenu de fichier, plan privé ou clé. Le job PostgreSQL
CI exécute ce test et collecte ce rapport ; son exécution distante reste à valider.

**Limite du premier exercice, complété par les lots ci-dessous** : il ne reconstitue pas une organisation
entière perdue, ses références manquantes, contrats/matchs/historiques ou comptes
globaux. Il ne transforme pas OrganizationRecoveryService (désarchivage des données
préservées) en moteur d'import. Réconciliation intermodules, gels/effacements
autorisés depuis sauvegarde, reprise durable après crash et qualification sur
infrastructure relèvent des lots suivants ; aucune réactivation automatique ni
suppression de données réelles n'est autorisée par cette fixture.

### Consolidation locale OPS-005 — lots 1 à 4

La CI du lot 1 (`da068af`) est validée par l'utilisateur. Les lots locaux
complémentaires sont regroupés dans un seul nouveau changement ; la qualification
sur l'hébergement futur reste distincte. Le nouvel exercice se lance depuis
`front`, avec les prérequis PostgreSQL 17 précédents :

```powershell
npm run test:recovery:graph
```

| Lot | Couverture locale |
| --- | --- |
| 1 — Réparation ciblée | Test précédent conservé : trois champs d'une fiche existante, fichier manquant, Beta préservé. |
| 2 — Fiche absente et dépendances | Nouvelle personne avec identité chiffrée, profil de jeu, compte externe et période close, réellement absents de la cible. |
| 3 — Intermodules et conflits | Équipe, affectation, composition et entrée, match proposé/participant, contrat brouillon/partie/obligation/transition, document/version liés à la même personne. Plan explicite et conservation des lignes récentes divergentes. |
| 4 — Reprise et procédure | Rollback SQL injecté, arrêt du sous-processus après commit SQL puis après installation du fichier, reprise avec un seul marqueur/audit, détection d'une dérive post-commit, fichier inattendu refusé et réexécution sans doublon. |

Le scénario utilise trois clusters **exclusivement fictifs**. Une sauvegarde de
base permet de construire la cible sans le nouveau graphe ; une seconde
sauvegarde, enrichie du graphe, est restaurée dans le cluster de lecture. Cela
simule des lignes absentes sans désactiver les gardes ni supprimer d'historiques.
Les paquets Alpha/Beta proviennent de ce cluster restauré. La source et le
cluster de lecture sont arrêtés avant la réconciliation de la cible courante.
Beta reçoit ensuite des modifications et ajouts, y compris après le plan Alpha.

RecoveryGraphRepository est une **aide de fixture**, non un repository d'import
enregistré en production. Il vérifie le catalogue fermé et ses colonnes, la
propriété tenant de chaque ligne, les doublons et références. Les lignes manquantes
sont ordonnées selon leurs FK réelles, y compris composites ; référence absente,
collision d'identité ou cycle bloquent la préparation. Aucune ligne globale n'est
insérée. Une ligne existante différente n'est jamais écrasée : son maintien doit
figurer explicitement dans les décisions du plan, sinon l'application est refusée.

Les compteurs `dashboard_source_versions` sont signalés comme **dérivés** et
exclus du rejeu : les triggers les régénèrent lors des insertions métier. Cela
préserve l'invalidation des indicateurs et évite de réintroduire une ancienne
génération. Le snapshot conserve ces lignes pour l'inventaire, sans les restaurer.
Les habilitations manquantes, nouveaux liens à un compte global et divergences du
ledger/quota sont refusés. Le premier graphe couvre contrats brouillons et matchs
proposés ; le scénario de qualification ci-dessous reconstruit aussi les contrats
approuvés et matchs validés avec leurs dépendances, sans désactiver les triggers.

Le paquet sélectionné est authentifié avec une clé éphémère de l'exercice, et le
plan est signé pour la cible. Le plan fige l'état Alpha actuel, sans figer Beta.
Un changement Alpha impose une nouvelle revue. Les droits sports.write,
teams.manage et administrative.write sont revérifiés. Un gel documentaire
**simulé** dans le laboratoire ou la présence d'une opération de purge bloque
plan/application/reprise ; ces sondes ne remplacent pas les décisions CAD-004 ni
une intégration de tous les gels/effacements réels.

RecoveryGraphJournal conserve le plan chiffré sous la clé applicative dans le
répertoire appartenant au lancement. L'écriture est synchronisée avant mutation.
Les insertions et l'audit sont validés dans une transaction, avec un marqueur
recovery/pending contenant les empreintes. Les triggers demeurent actifs. Après
commit, les fichiers sont préparés, vérifiés et installés par lien physique sans
écrasement. Un fichier déjà identique est accepté à la reprise ; un autre contenu
est conservé et bloque l'opération. Les lignes insérées sont revérifiées avant
de reprendre les fichiers. Le marqueur passe à completed seulement après ces
contrôles ; la reprise ne réinsère ni données ni audit. Les dossiers n'incluent
ici que des artefacts locaux prêts, sans expiration, dans la limite de 100 fichiers
de 8 Kio ; paquet limité à 2 Mio et 500 lignes. Ce n'est pas une validation à
l'échelle de millions de lignes.

#### Procédure de lecture des résultats et décisions opérateur

Complément du 30 octobre 2026 : le même scénario `test:recovery:graph` exerce
aussi la reprise après commit SQL avec mauvaise clé applicative/source, journal
absent, tronqué ou altéré, et gel posé pendant l’interruption. Il exige un refus
sans modification des tables, marqueurs/audits, fichiers privés ou journal reçu.
Après rétablissement du matériel original et levée du gel fictif, la reprise
existante doit encore aboutir sans doublon. Ces contrôles ne récupèrent pas une
clé définitivement perdue et ne simulent pas une panne machine.

1. Pour les essais locaux, lancer les tests OPS-004, OPS-005 ciblé et
   test:recovery:graph. Ils créent leurs propres cibles ; aucun argument ne permet
   de sélectionner une installation utilisée. Ne pas transformer les fixtures
   en commandes d'exploitation.
2. Consulter les rapports recovery-local.json, tenant-recovery-local.json et
   tenant-graph-local.json dans front/reports : résultat, empreintes des sources
   et sauvegarde, couverture, durées et cleanup. La CI PostgreSQL les conserve.
3. Avant toute récupération réelle future, identifier l'organisation, l'incident,
   la sauvegarde et ses clés, l'acteur habilité, les contraintes de conservation
   et la barrière d'écriture Alpha. Restaurer d'abord une copie isolée de lecture ;
   ne pas restaurer la sauvegarde globale sur une base partagée courante.
4. Examiner les lignes manquantes, divergences, références et états non couverts.
   Décider explicitement de préserver les lignes courantes ; une décision ancienne
   ne vaut pas approbation d'un plan renouvelé. Les identités globales et accès
   nécessitent leurs propres contrôles, sans recréation automatique.
5. Si l'opération SQL n'a pas commité, la reprise doit retrouver l'état attendu
   ou s'arrêter pour nouvelle revue. Si un marqueur pending existe, conserver
   journal/clé/source et reprendre les fichiers après vérification des lignes ;
   ne pas lancer un second import ou retirer un fichier inattendu sans examen.
6. Exiger la concordance base/fichiers, l'audit unique, la lisibilité des champs
   chiffrés et les empreintes Beta/globales inchangées avant décision de reprise
   de service. Aucun statut d'organisation ou worker n'est réactivé par cet essai.
7. Conserver les preuves validées selon la politique retenue, puis nettoyer les
   environnements appartenant à l'exercice après arrêt vérifié. En cas d'échec
   d'arrêt ou doute de propriété, conserver le chemin et traiter l'incident.

Les étapes pour le réel sont des critères de préparation, **pas une commande
opérateur de production livrée**. Les reprises testées traversent des arrêts de
sous-processus PHP avec le même parent de test et ses clés éphémères disponibles.
Ni panne machine, perte définitive de clés, durabilité du stockage distant,
RPO/RTO représentatif ou import de tous les états métier ne sont qualifiés.
Le complément suivant couvre une concurrence locale bornée et la récupération
des états avancés. Le critère REC-36 demande une simulation de récupération
préservant les autres organisations ; la qualification de production relève
des tickets d’exploitation et de mise en service, distincts.

### Complément OPS-005 — concurrence, états protégés et recette réelle

Depuis `front`, avec les exécutables PostgreSQL 17 dans le PATH :

```powershell
npm run test:recovery:qualification
```

L’essai crée quatre clusters temporaires attestés et des données fictives. Les
paquets du graphe et des états avancés sont lus depuis des sauvegardes restaurées.
Un contrat passe par la revue puis l’approbation d’un autre acteur. Un second
match passe par confirmation, saisie des scores, soumission et validation indépendante.
Le contrat reçoit aussi une obligation terminée après approbation : sa version
courante diffère donc de celle du dernier changement d’état. Sur une cible où
ces dossiers sont absents, le scénario récupère réellement le contrat approuvé,
son obligation, ses parties/document/version et toutes ses transitions, ainsi
que le match validé, ses participants, règles, score, soumission et approbation.
Les identités, versions, acteurs, dates et champs chiffrés archivés sont conservés.

`RecoveryGraphReplay` regroupe les agrégats selon les FK externes du plan. Pour
un contrat, il insère l’état initial, reconstruit les enfants et les transitions
archivées, puis rétablit la version courante. Pour un match, il reconstruit l’état
proposé et les participants, les transitions opérationnelles, règles/scores,
puis chaque preuve de résultat avant la transition qu’elle autorise. Les enfants
conditionnés par un état sont essayés dans des savepoints : un refus de garde
annule entièrement l’essai, le passage à l’état suivant permet une nouvelle
tentative bornée. Un enfant impossible à réinsérer annule la transaction complète.
Aucun état avancé n’est inséré directement et aucun trigger n’est désactivé.

Le test refuse les historiques/preuves incomplets et l’auto-approbation. Un score
archivé invalide doit annuler tout le graphe, sans marqueur ni fichier installé ;
son journal est conservé dans la racine temporaire pour l’examen du test. Les
empreintes de chaque ligne récupérée sont revérifiées avant les fichiers et le
marqueur completed. La reprise n’invente pas une nouvelle validation métier :
elle restaure les traces authentifiées depuis la sauvegarde isolée.

La récupération utilise un verrou de session PostgreSQL par organisation, conservé
entre commit SQL et fichiers ; une tentative concurrente retourne `GRAPH_BUSY`.
Le contrôle métier et le verrou de ligne de l’organisation sont repris dans la
transaction des fichiers. Deux points de rendez-vous bornés de fixture permettent
de lancer de vrais processus PHP concurrents pendant chaque phase. Le test observe
l’attente du rédacteur Alpha dans PostgreSQL, la progression de Beta, le refus
des applications/reprises concurrentes, puis les commits attendus après libération.
Le verrou de session est libéré dans `finally` ou à la fermeture de la connexion
du processus interrompu. La reprise après le code 73 doit donc fonctionner.

Ce mécanisme reste propre aux fixtures et aux écritures métier utilisant le
verrou d’organisation ; il ne bloque pas une écriture SQL directe non coopérative.
Les checkpoints sont absents des services applicatifs. Aucune route, commande
opérateur, migration ou modification de droits de production n’est ajoutée.
La transaction des fichiers conserve le marqueur pending si le processus est
arrêté après installation : le fichier est revérifié lors de la reprise.

Le rapport ignoré `front/reports/tenant-qualification-local.json` contient les
empreintes des sources/sauvegardes, contrôles, durée et nettoyage. Il garde
`productionQualified=false` et `hostedRecipeExecuted=false`. La CI PostgreSQL
exécute ce scénario et conserve le rapport 14 jours. Une simulation locale ne
qualifie ni toutes les combinaisons d’états ni la charge d’un hébergement.
La [recette OPS-005-FINAL](docs/procedures-incident-support.md#ops-005-final--recette-de-récupération-sélective)
définit les essais externes, les décisions sur les états exclus et les preuves
attendues avant toute récupération réelle. L’hébergement et les données réelles
restent indisponibles ; cette partie est préparée, sans exécution réelle.
Ces contraintes d’exploitation ne changent pas l’acceptation d’OPS-005/REC-36 :
la simulation des données et fichiers avec préservation des autres tenants est
livrée et testée par les trois commandes OPS-005. L’aide reste un laboratoire
borné, sans commande de réimport utilisable sur une installation opérationnelle.

## UCG-OPS-006 — Fichiers, antivirus et dépendances

Les trois lots sont regroupés dans un changement : vérification des fichiers,
blocage antivirus/quarantaine et contrôles des dépendances. Les contrôles déjà
livrés sont conservés : Form Request, droits/MFA/tenant, extension PDF, MIME
détecté côté serveur, enveloppe bornée, chiffrement des artefacts, empreinte,
quota et vérification des accès au téléchargement. La limite de **5 Mio** est
centralisée dans DocumentSecurity ; l'en-tête et la fin PDF ne remplacent pas
un parseur/assainisseur complet de PDF.

Les tests HTTP refusent le MIME déclaré PDF lorsque les octets sont exécutables,
les doubles extensions, PDF tronqués, données ajoutées après la fin et fichiers
trop gros, sans réservation de stockage, version ou scan. La borne exacte de
5 Mio et le premier octet excédentaire sont contrôlés séparément. Les téléchargements
restent des pièces jointes avec nosniff et no-store.

### Réponse complète du scanner et renouvellement des verdicts

Le client ClamAV attend désormais la fermeture de la connexion non-session après
**un unique enregistrement terminé par NUL**, dans les bornes de taille/temps
existantes. Un OK reçu dans un premier paquet n'est pas définitif : un second
verdict dans un paquet ultérieur, une fermeture absente, une réponse tronquée ou
débordante ne peuvent pas autoriser le fichier. Un OK correctement fragmenté
reste accepté. Le protocole est vérifié avec un pair TCP local déterministe,
qui n'est pas un antivirus. [Protocole officiel ClamAV](https://docs.clamav.net/manual/Usage/ClamdProtocol.html).

La politique passe à **clamav-pdf-v2**. Les anciens verdicts clean en v1 sont
considérés périmés pour le téléchargement, même si leur âge serait encore valable.
Un utilisateur habilité doit demander une nouvelle analyse avec expected_scan_id ;
aucune analyse en masse ni modification des anciennes lignes d'audit n'est lancée.
Les nouveaux liens ne sont émis qu'après un verdict clean récent sous la politique
courante. Un nouveau scan négatif sur la même version bloque aussi les liens
déjà émis ; le fichier chiffré, son quota et les versions historiques sont conservés.

La quarantaine est **logique** : stockage privé chiffré et refus des octets clairs
tant que le verdict n'est pas concluant. Elle ne déplace pas les objets vers un
répertoire public et ne supprime pas automatiquement les fichiers rejetés.
Malware, indisponibilité, réponse ambiguë et signatures périmées restent bloquants.

### Vérifications locales et antivirus réel en CI

Depuis back :

```powershell
php vendor/bin/phpunit tests/Unit/DocumentSecurityTest.php tests/Feature/ClamAvDocumentScannerTest.php tests/Feature/ContractDocumentControllerTest.php tests/Feature/ContractDocumentSecurityControllerTest.php
composer audit --locked --abandoned=fail --format=json
```

Depuis front :

```powershell
npm audit --audit-level=low --json
npm run test:ci
npm run build
```

Un job CI backend dédié installe clamd/freshclam sur son runner éphémère, obtient
les signatures officielles et démarre sa propre instance sur 127.0.0.1:3310, en
UTC. Sa configuration borne flux/fichiers/temps et active ScanPDF, AlertExceedsMax
et AlertEncrypted. Le test réel existant est activé explicitement : PDF propre
accepté et sonde EICAR inoffensive en mémoire rejetée. Version, XML et logs sont
conservés ; seule l'instance identifiée par le job est arrêtée. Une panne du
téléchargement de signatures ou du scanner fait échouer le job, sans faux succès
ou remplacement silencieux par le pair simulé.

La CI de `50e4b37` est validée par l’utilisateur le 26 octobre 2026, avec les deux
sondes réelles décrites ci-dessous. Aucun ClamAV local n'est installé et le
daemon Docker n'est pas disponible sur ce poste ; les essais locaux TCP ne
prouvent pas une analyse réelle. Avant activation sur le futur hébergement,
vérifier les signatures, paramètres effectifs, réseau privé, workers et le parcours
complet dépôt/analyse/téléchargement. Références : [scan](https://docs.clamav.net/manual/Usage/Scanning.html)
et [configuration ClamAV](https://github.com/Cisco-Talos/clamav/blob/main/etc/clamd.conf.sample).

Correctif de préparation CI après le premier lancement : le runner antivirus est
fixé à Ubuntu 24.04. Les configurations utilisent /etc/clamav/freshclam.conf et
/etc/clamav/clamd.conf, les signatures un sous-répertoire dédié de /var/lib/clamav
et les journaux /var/log/clamav/ucg-ci-*.log, conservés en artefacts. Ces chemins
respectent les profils AppArmor du paquet ([FreshClam](https://sources.debian.org/src/clamav/1.4.6%2Bdfsg-1/debian/usr.bin.freshclam/),
[clamd](https://sources.debian.org/src/clamav/1.4.6%2Bdfsg-1/debian/usr.sbin.clamd/)) ;
la configuration initiale sous RUNNER_TEMP pouvait être refusée malgré des droits
Unix corrects. Les services installés automatiquement sont arrêtés avant l'instance
du test ; son PID reste suivi et seul ce processus est arrêté au nettoyage.
AppArmor reste actif. La lecture des configurations, l'écriture des signatures et
journaux sont permises au compte du runner, sans lancer le moteur en root.

La sonde antivirus PDF a ensuite été corrigée : ajouter EICAR dans un commentaire
PDF n'est pas un test de détection garanti par la [spécification EICAR](https://www.eicar.org/download-anti-malware-testfile/).
Le test utilise maintenant un PDF structuré avec catalogue, page, table xref et
pièce jointe /EmbeddedFile non compressée contenant exactement les 68 octets
standards. Un PDF de même structure avec une pièce jointe inoffensive doit rester
clean. Un second test envoie séparément la sonde standard à clamd via INSTREAM,
pour distinguer reconnaissance des signatures et extraction PDF. Les deux tests
réels sont obligatoires dans le job opt-in, sans verdict simulé ni modification
des signatures. Le contenu EICAR reste décodé en mémoire, sans fixture en clair
sur disque. Ces sondes ne qualifient pas tous les formats ou modes de compression
que peut contenir un PDF ; le parcours sur le futur hébergement reste à qualifier.

### Audits et suivi des dépendances

Les workflows auditent maintenant le verrou Composer (avec paquets de développement
et abandon bloquant) et l'arbre npm (tous niveaux, développement compris). Les
rapports composer-audit.json/npm-audit.json sont conservés avec les tests. Un
échec réseau ou un avis détecté n'est pas converti en succès ; aucune exclusion
d'avis n'est ajoutée. Les audits sont ceux des commandes natives [Composer](https://getcomposer.org/doc/03-cli.md#audit)
et [npm](https://docs.npmjs.com/cli/v11/commands/npm-audit/), à l'instant du lancement.

| Constat au 25 octobre 2026 | Traitement dans ce lot |
| --- | --- |
| Vite 8.3.2, six avis remontés par npm, gravité agrégée high | Mise à jour compatible vers 8.3.5 et minimum direct relevé à ^8.3.5 ; dépendances associées de la chaîne Vite/Rolldown mises à jour dans le verrou. |
| Undici 8.11.2, trois avis remontés, gravité agrégée high ; transitif de jsdom | Mise à jour compatible vers 8.12.3, sans ajouter de dépendance directe ni override. [Avis éditeur représentatif](https://github.com/nodejs/undici/security/advisories/GHSA-xjj6-f33q-929c). |
| Composer | Aucun avis ni paquet abandonné remonté ; aucune dépendance PHP modifiée. |
| Audit npm après correction | Zéro vulnérabilité remontée ; aucun niveau omis. |

#### Finalisation OPS-006 — 31 octobre 2026

Les critères applicatifs SEC-06/SEC-09 et OPS-006 sont couverts : analyse des PDF,
quarantaine, contrôles adaptés des CSV/textes, audits bloquants et suivi avec délais.
La CI de `50e4b37`, dont les deux sondes ClamAV réelles, a été validée le 26 octobre.
La CI du correctif `98f34ae` est validée par l’utilisateur avant cette finalisation.
La nouvelle exécution distante reste à confirmer après le push de cette livraison.
La qualification du futur hébergement constitue une opération de déploiement
distincte ; aucune activation de scanner ou modification de données réelles ici.

`ClamAvIntegrationTest` complète les sondes par deux parcours HTTP de bout en bout :
dépôt d’un PDF en attente, chiffrement, job sérialisé et middleware tenant, verdict,
téléchargement exact du fichier propre ou refus du fichier dangereux, conservation
du fichier chiffré et rejeu du job sans changement du verdict. En local, le transport
utilise explicitement le pair TCP simulé avec une pièce jointe inoffensive ; dans
le job antivirus `CONTRACT_TEST_CLAMAV=true` impose le vrai moteur et la pièce jointe
EICAR. Une panne réelle ne déclenche aucun repli simulé. Pour ce parcours HTTP,
la sonde transite par un fichier temporaire d’upload du runner éphémère ; seul le
stockage applicatif de test est conservé chiffré. Les deux sondes directes existantes
restent en mémoire. Les CSV sont soumis aux parseurs bornés (UTF-8, structure,
octets nuls, lignes/colonnes/champs) ; les documents de compétition sont du texte
privé généré par l’application, et ne sont pas des dépôts de PDF supplémentaires.

Le workflow `dependency-security.yml` audite les deux verrous sans installer leurs
paquets, à chaque push/PR, sur lancement manuel et quotidiennement à 06:17 UTC.
GitHub n’exécute le cron qu’une fois ce workflow présent sur la branche par défaut ;
les push/PR et le lancement manuel permettent les contrôles des autres branches.
Les rapports natifs et `dependency-security.json` sont conservés 90 jours. Réseau
indisponible, rapport invalide, paquet abandonné ou avis détecté font échouer le job.
Le candidat de release exige désormais les trois workflows backend, frontend et
sécurité réussis sur son commit et sa branche exacts ; aucun déploiement automatique.

Le registre versionné `.github/dependency-security.json` contient un suivi par
identifiant normalisé : `composer:paquet:PKSA-…`, `composer:paquet:abandoned` ou
`npm:paquet` (incluant les avis et dépendances transitives). Il est vide car les
audits actuels ne remontent aucun avis ; aucun responsable personnel n’est inventé.
À la détection, inscrire la première date du rapport et une personne responsable,
puis analyser l’exposition runtime/outillage et consigner l’évaluation. Exemple
**fictif à remplacer**, sans exemption de l’audit :

```json
{
  "id": "npm:paquet-fictif",
  "owner": "Personne chargée de la correction",
  "status": "open",
  "first_seen_at": "2026-10-31T12:00:00.000Z",
  "assessed_at": "2026-10-31T13:00:00.000Z",
  "assessment": "Exposition examinée ; correction et tests à réaliser."
}
```

| Sévérité | Évaluation au plus tard | Correction au plus tard |
| --- | --- | --- |
| critical | 1 heure | 24 heures |
| high et paquet Composer abandonné | 24 heures | 7 jours |
| moderate / medium | 7 jours | 30 jours |
| low / info | 30 jours | 90 jours |

Les délais sont calculés depuis `first_seen_at` en heures écoulées UTC. Conserver
la première détection lors des revues du registre : un nouveau scan ne réécrit
jamais les dates. Sans suivi, le rapport marque l’avis `untracked`, sans responsable
ni date durable et avec échéances provisoires ; la CI reste rouge. Un avis suivi
reste également bloquant, même avant échéance. Le rapport signale les retards et
réouvre tout avis encore présent malgré une clôture déclarée. Après correction,
réaudit et tests, passer à `resolved` avec `resolved_at` et `evidence` (commit,
rapport et validation). La disparition d’un avis encore `open` impose cette clôture
documentée. Un avis sans correctif appelle une décision et des mesures documentées,
sans suppression du contrôle ni ajout d’exclusion. Le registre reste dans Git et
ne dépend pas de la durée de conservation des artefacts. Aucun ticket ni message
externe n’est créé automatiquement.

Reproduire le rapport à la racine, après les audits natifs :

```powershell
node front/security-audit.mjs back/reports/composer-audit.json front/reports/npm-audit.json .github/dependency-security.json front/reports/dependency-security.json
node --test front/tests/real-api/security-audit.test.mjs front/tests/real-api/release.test.mjs
```

Un audit vert ne prouve pas l’absence de toute vulnérabilité. La qualification
du réseau privé, du stockage, des signatures, des workers et du scanner sur le
futur hébergement reste à effectuer avant son activation.

## UCG-OPS-008 — Procédures d’incident et de support

Les [procédures d’incident et de support](docs/procedures-incident-support.md)
regroupent les trois lots : sept fiches d’intervention, rôles/seuils/escalade et
preuves/messages, puis huit exercices fictifs sur table. Les parcours couvrent
indisponibilité, fuite suspectée, dépendance, file, restauration, fichier et accès
exceptionnel. Les commandes et barrières sont confrontées au code existant ;
aucun incident réel ni envoi de message n’est déclenché.

Les fonctions sont définies, mais les personnes, suppléants et contacts restent
à désigner. Les délais proposés doivent être adoptés ; ils ne constituent pas
une astreinte déjà disponible. **OPS-008 reste ouvert avant exploitation réelle**
sur ces validations et la qualification de l’hébergement, toujours différé.

## UCG-QA-004 — Concurrence et idempotence, trois lots locaux

Le périmètre vérifié est celui des parcours P0 existants : versions de scores et
résultats, réservations, imports, rappels et reprises de jobs. L’inventaire part
des tests déjà présents ; il ne recrée pas une suite parallèle pour les mêmes
invariants. Aucun webhook entrant n’est prévu au P0 selon la décision utilisateur
du 25 octobre ; une future intégration nécessitera ses propres preuves.

### Lot 1 — Inventaire et choix des compléments

| Parcours | Preuves existantes conservées | Complément retenu |
| --- | --- | --- |
| Scores et validation des résultats | MatchScoreConcurrencyTest : deux règles, deux écritures de partie, score/annulation ; MatchResultConcurrencyTest : soumissions, approbations, approbation/litige, score/soumission. Une même version ne permet qu’une mutation, conflit explicite pour l’autre. | Réexécution PostgreSQL, sans dupliquer ces cas. |
| Réservation calendrier | CalendarConflictConcurrencyTest : deux prévisualisations du même créneau puis sauvegardes concurrentes, personne/équipe/ressource. CalendarInvitationConcurrencyTest et CalendarChangeConcurrencyTest couvrent les mutations concurrentes associées. | Deux tenants réservent leurs propres ressources au même horaire : deux succès, une activité et son audit par tenant, aucun conflit global. |
| Import statistique | Deux fichiers portant la même observation : une création, un doublon déclaré. StatisticalImportProcessingTest couvre également reprise partielle, anciennes générations et callback de panne obsolète. | Deux workers sur la même génération : un seul résultat, compteur à un, exécution terminée ; rejeu après achèvement sans modification de l’exécution ni nouveau reçu/révision. |
| Import de personnes | PersonImportProcessorTest : chunks, reprise après rollback, rejeu et refus d’un identifiant d’un autre tenant. | Réexécution des preuves existantes, sans prétendre à une course de deux processus sur ce parcours. |
| Rappels | ContractReminderTest : rejeux, changement de responsable/droits et accusé SMTP incertain ; CalendarInvitationWorkerTest, CalendarChangeWorkerTest et CompetitionRegistrationConcurrencyTest : unicité et réévaluation des destinataires. | Trois courses de rappels contractuels : deux runs Alpha, Alpha/Beta simultanés, accusé incertain Alpha. Un appel au transport simulé par occurrence ; reprise après 16 minutes sans renvoi automatique. |
| Infrastructure de jobs | TenantJobRunnerTest et TenantScheduledTaskTest : contexte sérialisé, retry opérateur, refus après révocation, tâche occupée et progression des autres tenants. | Réexécution de la portée existante ; le cache de fin de tâche n’est pas une garantie universelle exactly-once. |

Les autres suites `*ConcurrencyTest.php` existantes sont également exécutées pour
détecter une régression du worker de test partagé, notamment Académie, compétitions,
observations, métriques et calculs statistiques.

### Lot 2 — Collisions réelles et absence de perte silencieuse

Les tests PostgreSQL créent une base dédiée par course, effectuent les migrations
dans cette base et démarrent deux processus PHP indépendants. Une barrière ne les
libère qu’après leur signal READY ; le calendrier effectue les deux prévisualisations
avant cette libération. Les services et transactions SQL sont réels, avec contraintes
et verrous actifs. Les conflits de domaine sont observés par le worker ; les tests
HTTP métier existants portent la traduction en réponse API.

Les branches historiques restent inchangées dans leur attente de conflit. Pour les
deux tenants du calendrier, les ressources et permissions sont propres à chacun,
le même horaire est utilisé et le résultat exige deux créations sans override.
Les imports exigent le statut completed, le compteur traité exact, l’unicité des
observations/révisions/reçus et une exécution inchangée après rejeu terminal.

### Lot 3 — Reprises et rappels sans double tentative automatique

ContractReminderConcurrencyTest contourne volontairement le verrou de distribution
du scheduler pour confronter directement deux exécutions du handler métier. Chaque
contrat témoin est préparé, doté d’un plan puis approuvé par un autre compte. Les
workers utilisent des run IDs distincts. Le transport FakeContractReminderMailer
déjà présent compte les appels en mémoire dans chaque processus ; leurs clés sont
réunies et comparées aux occurrences persistées. Aucun SMTP réel n’est contacté.

Un accusé simulé perdu doit laisser email_status=uncertain et attempts=1. Une
nouvelle exécution après le délai de sweep ne doit pas renvoyer cet e-mail. Le
cas normal exige sent et attempts=1. L’inbox reste unique ; une course Alpha garde
le contrat Beta inchangé et son plan non balayé, et la course Alpha/Beta produit une seule
occurrence par tenant. Les replays ne modifient pas les lignes de livraison.
Ces preuves portent sur la coordination et les décisions de l’application, sans
promettre une livraison SMTP exactement une fois chez un fournisseur externe.

### Rejouer et lire les résultats

La CI backend exécute les suites existantes dans sa sélection PostgreSQL et ajoute
une étape explicite pour ContractReminderConcurrencyTest. Son JUnit
phpunit-contract-reminder-concurrency.xml est conservé avec les autres rapports.
La découverte SQLite de ces tests exclut les courses réelles : un succès avec des
exclusions ne constitue pas une preuve de concurrence PostgreSQL.

Pour une reproduction manuelle, utiliser uniquement un PostgreSQL 17 jetable avec
compte autorisé à créer les bases de course, une APP_KEY de test, des sessions et
e-mails en array, et les origines de test attendues par les fixtures MFA. Ne pas
pointer ce lancement vers une base partagée ou des données réelles. Exemple depuis
back, après préparation de cet environnement isolé :

```text
php vendor/bin/phpunit tests/Feature/MatchScoreConcurrencyTest.php tests/Feature/MatchResultConcurrencyTest.php tests/Feature/CalendarConflictConcurrencyTest.php tests/Feature/StatisticalImportConcurrencyTest.php tests/Feature/ContractReminderConcurrencyTest.php --log-junit=reports/phpunit-qa004-focused.xml
```

Le lancement local réutilise TemporaryPostgres du laboratoire existant avec un
répertoire temporaire attesté, port et identifiants propres, puis arrête le cluster
et supprime uniquement ses ressources. Les résultats complets, exclusions et limites
sont consignés dans [l’état du projet](docs/etat-projet.md). Les fichiers de rapport
locaux restent ignorés par Git. L’hébergement différé, les arrêts brutaux de machine,
les transports réels et les futures intégrations conservent leur recette distincte.

Résultat local du 26 octobre 2026 : **178 tests réussis, 1 654 assertions, aucune
erreur/échec/exclusion**, sur 25 suites PostgreSQL 17 (environ 262 s). Elles
comprennent 50 cas dans 17 suites de concurrence et 128 cas de traitement/reprise.
Les cinq compléments sont un cas calendrier, un cas import et trois cas de rappel.
Les sources finales passent aussi Pint complet, PHPStan et les contrôles du diff
et du workflow. La nouvelle CI distante reste à confirmer après push.

## UCG-QA-005 — Corrections ciblées et comparaison locale

Le travail du 26 octobre 2026 reprend les écarts mesurés, avec le même protocole
fixe. Les seuils, volumes, refus comptés et protections restent actifs ; la
capacité de l'hébergement différé n'est pas déduite de ces essais.

### Lot 1 — Diagnostic avant modification

La nouvelle référence profile termine sans erreur avec 48 requêtes SQL par
lecture courante de personnes, pour 25 comme pour 100 lignes. Les p95 Beta des
trois phases sont 328,02 / 336,15 / 400,20 ms. Le profil conserve des lectures
d'autorisation répétées ; la première requête users inclut potentiellement
l'ouverture de connexion et ne suffit pas à justifier un nouvel index.

La référence pressure utilise 500 comptes, 100 sessions, 1 000 matchs et
100 000 parties, quatre processus HTTP de développement et un worker database,
avec trois imports de 1 000 lignes et trois exports de 500 lignes. Les p95 Beta
sont 4 745,58 / 14 509,29 / 5 822,79 ms. Les phases comptent 17 / 23 / 13 refus
429 (tous lecteurs mesurés), sans les remplacer par des réussites. Le protocole,
l'intégrité ciblée et le nettoyage terminent correctement ; la performance échoue.

Trois tests reproduisent les points ciblés avant correction : 18 requêtes pour
une projection multi-classifications, refus immédiat sur contention courte, et
absence d'attente bornée avant refus persistant. Une quatrième régression protège
ensuite le chemin léger des projections publiques seules.

### Lot 2 — Corrections et protections

TenantDataProjectionService utilise une lecture fraîche des permissions
d'affichage pour une projection non vide, purement organisationnelle et composée
de plusieurs classifications au niveau racine. Les décisions sont locales à cet
appel, sans cache d'autorisation entre deux appels ou requêtes. Les projections
limitées aux champs de base organization.view, vides ou à portée équipe/autre scope
gardent leurs vérifications
existantes. Les mutations conservent leurs appels authorize indépendants. Les
tests exigent au plus neuf requêtes pour plusieurs classifications, au plus quatre
pour les seuls champs de base (toujours réservés à un membre autorisé), et
conservent révocations/MFA/tenant/scopes.

LimitTenantRequests garde le verrou par tenant, son bail de cinq secondes,
l'admission atomique, le quota configuré et la fenêtre de 60 secondes. Il tente
l'acquisition au plus onze fois, avec dix pauses de 5 ms au maximum : 50 ms de
pauses cumulées, auxquelles s'ajoutent les appels au cache. Ce n'est pas une borne
murale de 50 ms sur une dépendance lente. Si la contention persiste, le 429 et le
Retry-After d'une seconde restent appliqués, sans consommer le budget ni libérer
le verrou d'un autre propriétaire. Un quota épuisé reste refusé normalement.
Les erreurs de cache continuent à échouer sans bascule permissive.

Les causes quota/contention sont portées par un attribut interne de la requête ;
seul le serveur de charge isolé les expose en diagnostic. Aucune nouvelle donnée
n'est ajoutée à l'API de production. Les rapports classent les autres 429 comme
unclassified et les gardent dans les erreurs, sans déduire leur cause.

### Lot 3 — Vérifications et nouvelle mesure

Les courses PostgreSQL du limiteur utilisent deux/quatre processus sur un budget
de une/deux admissions, puis deux tenants aux budgets distincts. Le résultat
exige exactement le nombre autorisé, les refus de quota restants et aucun verrou
résiduel. Les tests HTTP contrôlent en plus contention persistante, reprise après
libération, séparation Beta, quota inchangé et fermeture en cas de cache invalide.
La CI backend ajoute ces courses et conserve leur JUnit.

La suite complète finale passe : 2 767 tests réussis, 17 852 assertions et
59 exclusions conditionnelles. Les suites PostgreSQL ciblées passent séparément
sans exclusion. Le pressure final termine avec 597 lectures réussies, aucun
refus 429 (53 dans la référence), imports/exports et nettoyage conformes.
Le p95 Beta pendant charge atteint toutefois 16,56 s contre 14,51 s avant :
aucun gain global de latence n'est établi, la cible 500 ms reste non atteinte.

La comparaison finale et les chemins des rapports sont consignés dans
[l'état du projet](docs/etat-projet.md). Les rapports intermédiaires sont conservés
sans les reclasser comme résultats des sources finales. Le poste est partagé avec
le générateur ; les quatre serveurs HTTP de développement exécutent chacun les
requêtes en série. Les latences incluent donc attente, bootstrap et connexion.
Les gains sur ces composants ne certifient ni 100 utilisateurs sur le futur
hébergement, ni le p95 de 500 ms sous forte charge. QA-005/REC-34 restent ouverts
tant que ces critères et la recette représentative ne sont pas satisfaits.

## UCG-QA-005 — Lectures de liste et diagnostic de latence (27 octobre 2026)

Le service de liste People garde son contrôle d'entrée, puis lit un snapshot
frais des permissions organisationnelles pour ses filtres et colonnes. Il ne
conserve aucune décision entre appels et la projection revérifie les droits après
la requête. Les mutations continuent leurs autorisations indépendantes. Le test
qui révoque les affectations pendant la lecture exige une réponse limitée aux
identifiants/version, sans champ sportif ou administratif.

Une projection organisationnelle multi-classifications vérifie l'accès de base
dans son propre snapshot ; les autres projections réutilisent uniquement leur
décision organization.view déjà autorisée. Les budgets testés passent de 7 à 5
requêtes pour plusieurs classifications, de 4 à 2 pour les seuls champs de base,
et de 22 à 16 pour une liste directe avec les deux permissions de lecture.
Tenant, MFA, scopes, révocations et projection indépendante restent contrôlés.

Le rapport de charge ajoute outsideApplicationMs : résidu calculé par lecture
réussie entre durée client et bootstrap/traitement mesurés. Il peut inclure attente et
admission du serveur, transport et travail client ; il
ne mesure pas exclusivement une file d'attente. Les gardes d'isolation exécutées
pendant le bootstrap sont déjà comprises dans bootstrapMs. Le SQL est inclus dans le
traitement, sans seconde soustraction. Les erreurs restent dans les mesures
globales et aucun quota, volume ou seuil n'est réduit.

Les rapports de schéma 2 détaillent aussi connectionMs (résolution paresseuse du
PDO par le pilote) et firstQueryMs (première requête observée après bootstrap).
La connexion n'est pas préchauffée : son coût reste compris dans la première
requête SQL et le traitement. Ces sous-temps se recouvrent et ne s'additionnent
pas. Une valeur connectionMs de zéro signifie qu'aucune résolution paresseuse
instrumentée n'a eu lieu pendant le traitement ; elle ne qualifie pas les connexions
éventuellement ouvertes pendant le bootstrap. Les anciennes mesures restent lisibles,
avec diagnostics absents plutôt que remplacés par des zéros.

Chaque échantillon porte l'indice du serveur généré et le nombre de lectures
simultanées côté client vers ce serveur au départ de la requête, y compris la
lecture Alpha bruyante. Ce nombre ne mesure pas directement une file d'attente
serveur et exclut les appels de préparation/suivi des imports et exports. Les
centiles par serveur conservent les refus et erreurs. Une instrumentation manquante
ou invalide refuse la mesure ; une modification des sources suivies entre le
démarrage et la fin des phases empêche de déclarer le protocole terminé.

Les références, résultats finaux et limites de qualification sont consignés dans
[l'état du projet](docs/etat-projet.md). L'hébergement reste différé et les données
fictives ; cette optimisation locale ne clôture pas la recette représentative.
Mesures finales : 41 requêtes courantes par lecture People contre 47 avant ;
pressure sans erreur HTTP et p95 Beta pendant charge 10,20 s contre 18,06 s.
La cible de 500 ms reste dépassée ; QA-005/REC-34 restent ouverts.

## UCG-REL-001 — Dossier de conduite du premier pilote

REL-001 signifie **faire utiliser l’application pendant une semaine par une équipe**,
traiter ses anomalies puis décider explicitement de la suite. La préparation et
la répétition automatiques ci-dessous sont locales et fictives. Elles ne valident
ni une semaine d’usage réel, ni l’adoption du staff, ni une signature de contrat,
ni le passage en production. Aucun pilote réel n’est lancé par une commande de test.

Décision utilisateur du 29 octobre 2026 : aucune équipe prête pour le moment,
« Pas encore : préparer et tester localement ». Le périmètre actuel est donc la
préparation et la répétition fictive locale décrites ici ; le lancement réel est différé.

### Périmètre et conditions de départ

Le périmètre proposé est une équipe, son manager/coach et un responsable
administratif habilité : personnes, effectif, disponibilités, calendrier/invitations,
compositions, matchs/résultats, contrats autorisés, tableaux de bord/exports et support.
Le jeu, le nombre de joueurs et les modules effectivement retenus doivent être
confirmés ; la composition fictive à un joueur ne valide pas un règlement sportif.
Les sponsors, connecteurs externes et publications publiques ne sont pas ajoutés
implicitement à ce premier essai. Les participants du premier pilote réel doivent
résider en Tunisie selon CAD-006 ; aucun filtre géographique n’est ajouté au produit.

Dossier de lancement à remplir dans l’espace privé de recette, sans données réelles
ni preuves administratives dans Git :

| Champ | État initial / valeur à renseigner |
| --- | --- |
| Organisation et équipe retenues | À confirmer ; utiliser leurs références internes dans le dossier privé |
| Jeu, saison, effectif et modules | À confirmer ; aucune liste réelle collectée à ce stade |
| Sept dates consécutives et fuseau | À confirmer ; les dates des tests sont fictives |
| Décideur du pilote | L’utilisateur décide ; identité et validation datée à consigner |
| Manager, coach, administrateur, support et remplaçants | À désigner ; les droits plateforme et les droits métier restent distincts |
| Données fictives / réelles et sources reprises | Fictives actuellement ; DATA-001/002 et rapprochement à valider avant reprise réelle |
| Environnement et version testée | Hébergement reporté ; conserver le commit déployé et les rapports de recette |
| Contrats du périmètre | Accès administratif explicite ; aucun contrat réel rendu visible au staff sportif |
| Mineurs | Parcours et validation applicables requis avant inclusion réelle ; CAD-004/ACA-005 ne sont pas clôturés ici |
| Seuils et parcours de réussite | Propositions ci-dessous à approuver avant le démarrage |
| Accès, support, sauvegarde et récupération | Responsables, procédure et vérifications sur l’environnement retenu |
| Risques ouverts | QA-005, données, hébergement et autres dépendances consignés ; arbitrage explicite, aucune réserve masquée |

Avant ouverture : vérifier connexion/MFA, sélection et confirmation d’organisation,
rôles courants, comptes de test, périmètre des personnes et accès aux pièces privées.
Ne pas affecter automatiquement des droits métier à un propriétaire ou à un
opérateur support. Aucun compte ne doit recevoir des droits d’approbateur uniquement
pour contourner une validation indépendante. Réaliser le contrôle de récupération
prévu par OPS-004/005 sur l’environnement choisi ; la répétition en mémoire ne le remplace pas.

### Programme J1–J7 et preuves attendues

| Jour | Parcours à réaliser par les personnes habilitées | Preuve minimale / résultat attendu |
| --- | --- | --- |
| J1 — Démarrage | Connexion/MFA, choix d’organisation, effectif, équipe et planification de la semaine | Périmètre confirmé, effectif rapproché et planning visible seulement aux destinataires autorisés |
| J2 — Administration | Vérifier uniquement les contrats retenus et leurs responsables/échéances | Accord d’un autre approbateur lorsque requis ; signature reçue distincte de l’activation et de l’éligibilité sportive |
| J3 — Préparation sportive | Disponibilités, invitations et composition du match | Composition finalisée, membres effectifs et choix affecté au bon match ; anomalies d’invitation examinées |
| J4 — Rencontre | Confirmer/démarrer le match, saisir les parties et soumettre le résultat | Score cohérent, historique conservé, résultat en attente de validation ; aucun droit inventé |
| J5 — Validation | Autre personne habilitée vérifie le résultat et les preuves | Résultat approuvé ou contesté ; aucune auto-approbation et délais de validation mesurés |
| J6 — Support et reprises | Traiter les signalements, retester les corrections, vérifier les accès retirés | Fiches d’incident à jour, retraits effectifs et absence d’exposition hors périmètre |
| J7 — Bilan | Rapprocher effectif/planning/contrats/résultats, exports autorisés et indicateurs agrégés | Bilan daté, réserves explicites et décision humaine de poursuite, prolongation ou arrêt |

Chaque jour : revue courte des anomalies et incidents, responsable de suivi nommé,
état des traitements différés et traces techniques utiles. Une absence de signalement
n’est pas assimilée à une validation. Les étapes non réalisées restent « non testées ».
Les parcours P0 retenus doivent tous avoir un résultat, un vérificateur et une date.

### Répétition technique isolée

Depuis `back`, avec les dépendances de développement installées :

```powershell
php vendor/bin/phpunit --configuration phpunit-pilot.xml --no-progress --colors=never --log-junit reports/phpunit-pilot.xml
```

Le bootstrap remplace les paramètres hérités dans getenv/ENV/SERVER : clé de test,
SQLite `:memory:`, URL DB vide, caches/sessions array, mail array, journal et broadcast
null. Il utilise un chemin de cache de configuration aléatoire absent et un token
de stockage de test distinct. Il ne migre pas la base du projet. Les suites existantes
conservent leurs fakes de stockage/queue/transport ; le nouveau scénario interdit
le HTTP sortant et neutralise queues, mails et notifications.

Les origines APP_URL/FRONTEND_URL et SANCTUM_STATEFUL_DOMAINS sont aussi fixées
par ce bootstrap. Les requêtes des helpers depuis localhost:5173 doivent être
reconnues comme SPA de test pour que Sanctum attache la session contenant la preuve
MFA. La suite fonctionne ainsi avant création du `.env` en CI, sans dépendre des
domaines configurés sur le poste. Les middlewares de session et MFA restent actifs.

La semaine fictive traverse les vrais endpoints Laravel, Form Requests, services,
repositories et autorisations : une équipe et son membre, sept activités, contrat
approuvé par un autre compte puis déclaré signé/activé, composition finalisée et
affectée, score et résultat validé par un autre acteur, refus de contrat sportif,
référence inter-tenant et droit calendrier retiré. L’horloge est déplacée sur sept
jours ; aucune semaine réelle ne s’écoule pendant ces quelques secondes de test.

La sélection de régression couvre aussi disponibilités, invitations, effectifs,
compositions d’équipe/de match, résultats, contrats, accès support temporaires,
tableaux de bord et exports. PilotIsolationTest relance la semaine dans un enfant
avec une base/cache/configuration hérités incompatibles : aucun accès SQL dans le
parent et aucun fichier de base hérité créé. Le rapport JUnit donne les résultats
techniques ; aucune réussite ne donne automatiquement un accord de lancement ou de généralisation.
La CI backend exécute cette sélection et publie le rapport via l’artefact backend existant.

### Registre d’anomalies et traitement du support

Réutiliser le canal privé de support et les procédures de
[gestion des incidents](docs/procedures-incident-support.md). Fiche à compléter pour
chaque anomalie, y compris après correction :

| Champ | Contenu attendu |
| --- | --- |
| Référence | Identifiant de suivi sans nom de personne, par exemple REL-I-001 |
| Date / version / parcours | Horodatage, commit déployé, J1–J7 et étape concernée |
| Gravité et impact | Critique, bloquante, majeure ou mineure ; personnes/parcours affectés sous forme de volumes |
| Reproduction | Étapes minimales, attendu et observé ; preuve technique expurgée et request ID si utile |
| Affectation | Responsable et suppléant ; cible de résolution convenue, sans SLA inventé |
| Traitement | Nouveau → pris en charge → corrigé → retesté/clos, ou rouvert après échec |
| Correction / retest | Commit, environnement/version retestés, vérificateur, date et résultat |
| Décision | Contournement autorisé éventuel, arrêt/reprise et réserve signée si applicable |

Accès indu, mélange de tenants, altération/perte de données ou impossibilité d’un
parcours P0 : suspendre le périmètre affecté et appliquer la procédure d’incident.
Ne pas copier de contrat, pièce d’identité, secret, token, nom ou coordonnées dans
les logs partagés. L’opérateur support utilise ses actions explicitement autorisées,
limitées dans le temps et auditées ; il n’endosse pas le rôle d’un utilisateur.
Une correction livrée n’est pas une anomalie close sans retest probant. Un incident
critique ou bloquant ouvert interdit de présenter le pilote comme accepté.

### Mesure d’usage agrégée et bilan

Les cibles suivantes viennent du cahier des charges et restent des propositions
à valider. Collecte réservée au bilan du pilote, avec compteurs agrégés, période,
fuseau, périmètre et date de calcul ; pas de liste nominative ni de contenu métier
privé dans le rapport de synthèse. Les tableaux de bord existants restent soumis aux
droits métier. Les exports détaillés éventuels restent privés et limités aux habilités.

| Indicateur | Numérateur / dénominateur | Cible proposée |
| --- | --- | --- |
| Effectif renseigné | Personnes vérifiées / personnes attendues dans l’équipe | 100 % |
| Planning couvert | Activités retrouvées au calendrier / activités du planning réel | Au moins 90 % |
| Contrats suivis | Contrats autorisés avec responsable et échéance / contrats actifs retenus | 100 % |
| Résultats validés sous 48 h | Matchs officiels validés dans le délai / matchs officiels terminés concernés | Au moins 95 % |
| Adoption hebdomadaire du staff | Comptes distincts du staff ayant réalisé un usage retenu / comptes du staff attendus | Au moins 80 % |
| Visibilité et isolation | Incidents confirmés du périmètre | Zéro accès indu ou mélange |

Ne pas additionner les utilisateurs distincts quotidiens pour déduire les utilisateurs
uniques de la semaine. Définir l’événement d’usage retenu avant la mesure ; connexion
seule, appel automatique ou action du compte de test ne prouvent pas une adoption.
La répétition fictive ne collecte ni n’invente des usages réels. Ne pas réutiliser
implicitement le journal d’audit comme une télémétrie nominative. Un rapprochement
par le responsable habilité peut alimenter les seuls totaux du bilan.

Dénominateur nul : indiquer « non applicable » avec justification et accord du
décideur, jamais 100 % par défaut. Donnée manquante : « non mesuré ». Pour le délai
de résultat, utiliser les horodatages de fin de match et de validation, sans assimiler
la date de saisie tardive à la fin réelle. Les objectifs non applicables ou non mesurés
restent visibles dans le bilan ; aucun export n’autorise une généralisation automatique.

### Procès-verbal de fin de pilote

À compléter uniquement après la semaine réelle dans le dossier privé :

- organisation/équipe, jeu, dates et fuseau, version(s) effectivement utilisées ;
- parcours P0 exécutés, preuves et résultats, avec les étapes non testées ;
- totaux des indicateurs, formules, sources autorisées et cibles approuvées ;
- incidents par gravité et état, retests, réserves et risques encore ouverts ;
- état de la récupération, des traitements différés et des accès de fin de pilote ;
- avis du manager/coach, de l’administrateur et du responsable support ;
- décision du décideur : **poursuivre sur le même périmètre**, **prolonger avec actions**,
  **arrêter**, ou **généraliser sur un périmètre expressément défini** ;
- identité du décideur, date, justification, éventuelles conditions et accord formel.

État initial de la décision : **non prise**. Les parcours P0 non validés et les
anomalies critiques/bloquantes non closes empêchent l’acceptation REL-001. Une
extension ne résout pas automatiquement QA-005, DATA-001/002, CAD-004/006 ou REL-002.
L’hébergement, l’équipe, les dates et les participants réels restent à confirmer.

## UCG-REL-002 — Préparer le lancement et l’exploitation

### État du dossier et responsabilités

La préparation décrite ici est locale. L’hébergement et le pilote réel sont toujours
différés ; aucune restauration distante, formation d’utilisateurs réels ou mise en
production n’est déclarée effectuée. Un commit de ce dossier ne vaut pas accord de
lancement. Les procédures existantes restent les références techniques :
[environnements](#préparation-des-environnements--ucg-ops-001),
[sauvegarde/restauration](#ucg-ops-004--exercice-local-de-sauvegarde-et-restauration),
[récupération sélective](#ucg-ops-005--premier-exercice-de-récupération-sélective),
[incidents et support](docs/procedures-incident-support.md) et
[pilote](#ucg-rel-001--dossier-de-conduite-du-premier-pilote).

Décision utilisateur du 29 octobre 2026 : **l’utilisateur gère lui-même les accès
utilisateurs et le support pour le moment**. Ce choix ne lui attribue pas un rôle
technique supplémentaire dans le logiciel ou chez le futur hébergeur.

| Fonction | Attribution actuelle | À compléter avant lancement réel |
| --- | --- | --- |
| Décideur opérationnel UCG | L’utilisateur, selon sa décision existante | Identité/contact dans le dossier privé et accord spécifique à la version déployée |
| Gestion des accès utilisateurs | L’utilisateur, confirmé le 29/10 | Compte habilité, suppléant, moyen de récupération et vérification des révocations |
| Support de premier niveau | L’utilisateur, confirmé le 29/10 | Canal privé, horaires convenus, suppléant et canal de secours indépendant |
| Manager/coach et administration | À désigner pour le périmètre retenu | Titulaires, suppléants, modules/droits et approbateurs indépendants si requis |
| Exploitation et prestataire d’hébergement | À attribuer après choix de l’hébergement | Comptes serveur, supervision, sauvegardes, clés, interventions et contacts du prestataire |
| Coordinateur d’incident et référent sécurité/données | À désigner selon OPS-008 | Pouvoir de contention/reprise, contacts et escalade |
| Vérificateur de restauration et de release | À désigner | Revue distincte de l’exécutant pour les opérations sensibles et validation des preuves |

La gestion des accès, le support plateforme, les permissions métier et l’administration
serveur sont des fonctions distinctes. Une personne peut cumuler des fonctions
explicitement, sans contourner une approbation indépendante imposée par le produit.
Un responsable absent ne doit pas être remplacé par un compte partagé. Les noms,
contacts et preuves restent dans un espace privé à définir avec l’hébergement.

### Guides de prise en main par rôle

Ces guides décrivent les parcours existants. Une fonction ou un bouton absent peut
résulter des droits ou du périmètre retenu ; signaler le besoin plutôt que demander
un rôle global. Les rôles fonctionnels ci-dessous n’accordent aucune permission
par leur seul intitulé.

**Pour tous — connexion et organisation.** Utiliser son compte personnel, terminer
la vérification de compte et le parcours MFA demandé. Conserver les moyens de
récupération dans un emplacement personnel protégé, jamais dans un ticket. Dans
« Mes organisations », sélectionner la bonne organisation et vérifier son nom
avant « Confirmer et continuer ». Après un changement de contexte ou d’accès,
laisser les données se recharger et reprendre depuis la fiche actuelle. Une
confirmation de contexte n’accorde pas de nouveau droit. En cas d’erreur, relever
l’heure, le parcours et le request ID affiché, sans partager cookie ou code MFA.

**Gestion des accès — l’utilisateur pour le moment.** Vérifier la demande, la bonne
organisation, l’identité du destinataire et les droits réellement nécessaires.
Depuis « Mes organisations » et « Rôles et affectations », utiliser uniquement les
actions autorisées à son compte pour inviter et affecter les rôles, périmètres et
périodes approuvés. Une personne enregistrée dans l’effectif n’a pas automatiquement
un compte ou un accès. Contrôler l’acceptation de l’invitation, puis vérifier avec
un témoin fictif le parcours permis et un parcours refusé. Pour un départ ou retrait,
révoquer l’accès concerné et vérifier le refus avec la session déjà ouverte ; ne
pas supprimer les données métier pour résoudre un problème d’accès. Une permission
administrative ou de validation doit être attribuée explicitement, pas déduite du
statut de propriétaire. Tracer demande, décision, périmètre, date et résultat.

**Direction.** Ouvrir « Tableaux de bord par rôle », choisir une vue autorisée,
contrôler la période et le fuseau. Lire les compteurs avec leur périmètre : un
instantané courant n’est pas un historique de la semaine. Un indicateur périmé ne
constitue pas une mesure actuelle ; demander un nouveau calcul seulement lorsque
l’action est autorisée. Pour un export privé, vérifier organisation, rapport et
confirmation, attendre l’état « Prêt », puis conserver le fichier dans l’espace
prévu. Un échec ou un export expiré demande le traitement prévu par l’écran, sans
relance globale des jobs. Décider de la suite du pilote sur les preuves REL-001,
pas sur la seule présence de chiffres ou d’un statut technique vert.

**Manager.** Vérifier les personnes et l’effectif de l’équipe, les périodes
d’appartenance et le jeu concerné. Planifier la semaine dans le calendrier avec
le fuseau correct ; une activité privée ne devient pas partagée sans l’action et
la confirmation prévues. Vérifier les destinataires et l’état des invitations.
Préparer une composition à partir des membres effectifs, la finaliser et l’affecter
au bon match avant démarrage. Recharger la version actuelle après un conflit de
modification ; ne pas écraser la saisie d’un autre utilisateur. Un contrat actif
n’est pas une preuve automatique d’éligibilité sportive.

**Coach et validateur sportif.** Consulter les informations sportives autorisées,
le calendrier, la composition et « Scrims et matchs ». Avant saisie, vérifier le
match, les participants et les règles de score. Saisir les parties, contrôler le
résultat puis le soumettre. La personne habilitée qui valide doit respecter les
règles d’indépendance ; elle consulte les preuves, approuve ou conteste avec motif.
Ne pas remplacer une contestation par un écrasement du score. Les corrections
passent par le parcours prévu et restent historisées. Le rôle de coach ne donne
pas accès aux contrats ni aux coordonnées administratives.

**Joueur.** Vérifier son organisation et ses informations accessibles, renseigner
ses disponibilités dans son propre périmètre et consulter ses activités/invitations.
Répondre uniquement à ses invitations avec l’état actuel ; une activité modifiée
peut demander une nouvelle confirmation. Une plage non renseignée n’est pas une
absence déclarée. Signaler une activité manquante au manager sans chercher le
calendrier d’un autre joueur. La visibilité d’une équipe peut cesser à la fin de
l’appartenance ; ne pas demander le contournement de ce refus.

**Administration.** Dans « Contrats de l’organisation », vérifier personne,
référence, parties, responsable, dates et obligations. Suivre revue, approbation
indépendante, envoi, déclaration de signature reçue puis activation selon les
transitions proposées. Le statut signé conserve une déclaration de suivi, pas une
signature électronique produite par l’application. Conserver l’original dans le
périmètre documentaire autorisé. Un fichier refusé, en attente, périmé ou en
quarantaine n’est pas téléchargeable comme document propre ; suivre la procédure
F6 et demander une nouvelle analyse seulement avec les droits requis. Ne pas
copier de pièce ou de détail contractuel dans une biographie ou un ticket partagé.

**Support de premier niveau — l’utilisateur pour le moment.** Demander parcours,
heure/fuseau, organisation concernée et identifiant de requête, avec une description
minimale. Distinguer problème d’utilisation, permission refusée, traitement différé
et incident. Ne jamais demander mot de passe, code MFA, cookie ou lien privé signé.
Suivre les [fiches OPS-008](docs/procedures-incident-support.md#4-fiches-dintervention).
Une suspicion d’accès indu, de perte ou de mélange de tenants est escaladée sans
explorer d’autres dossiers. L’accès support exceptionnel exige déjà l’habilitation
plateforme, un motif, des actions et une durée limités ; il ne donne pas les droits
métier et doit être révoqué à la fin. Aucun incident n’autorise un rôle ajouté en SQL.

### Formation et transfert des procédures

Préparer une session avec comptes et données fictifs : parcours commun puis atelier
par fonction, avec un parcours réussi et un refus attendu. Le formateur montre,
le participant reproduit, puis explique comment signaler un incident. Revoir les
limites MFA, changement d’organisation, version concurrente et partage de documents.
Une lecture du guide n’est pas une preuve de maîtrise ni une formation effectuée.

| Atelier | Exercice de validation proposé | Preuve de transfert à recueillir |
| --- | --- | --- |
| Accès et organisation | Invitation, MFA, bon tenant, action refusée après retrait | Titulaire/suppléant, date, version et résultat |
| Manager/coach | Planning, composition, score, soumission puis autre validateur | Parcours réalisés et écarts expliqués |
| Joueur | Disponibilité personnelle, invitation et contrôle d’un refus hors périmètre | Résultat individuel conservé en privé, synthèse sans détail nominatif |
| Administration | Dossier fictif, approbateur distinct et document non téléchargeable avant verdict valide | Contrôles et limites restitués correctement |
| Support | Signalement minimal, classement et canal de secours | Fiche d’exercice et confirmation de réception par le destinataire |
| Exploitation | Lecture des alertes, sauvegarde et restauration isolée, décision de retour arrière | Compte rendu exécutant/vérificateur sur l’environnement retenu |

Registre de transfert privé : référence d’atelier, version du guide/application,
formateur, participant/fonction, date, réussi/à reprendre, écart, action et prochaine
revue, confirmation du participant. État initial : **supports prêts, sessions non
réalisées et transfert non attesté**. Aucun contact ou émargement réel n’est créé
par cette préparation.

### Fiche d’exploitation à compléter sur l’hébergement retenu

Renseigner environnement, domaines, version déployée, composants, opérateur et
suppléant, créneaux d’intervention et contacts prestataires. Identifier séparément
base, volumes privés, clés applicatives, clé de sauvegarde, Redis, workers,
scheduler, transport mail, scanner et Reverb lorsqu’ils sont activés. Référencer
les secrets dans le gestionnaire choisi, jamais leurs valeurs dans ce dossier.

Pour chaque sauvegarde : périmètre base/fichiers/journaux nécessaires, cohérence
au point de capture, horodatage/fuseau, état des écritures, protection/chiffrement,
clé requise, emplacement restreint, responsable et dernière preuve de lecture.
Fixer fréquence, rétention, RPO/RTO et rotation avec le responsable compétent ;
aucune valeur n’est déduite des essais locaux. Conserver les clés permettant de
lire les données chiffrées et vérifier leur récupération indépendamment de la
sauvegarde. Ne pas régénérer APP_KEY comme une réparation générique.

Pour une restauration : cible isolée autorisée, source vérifiée, compatibilité de
version, base et fichiers rapprochés, contrôles de déchiffrement, contraintes,
accès, intégrité d’un tenant témoin et traitement des données plus récentes.
Appliquer [F5](docs/procedures-incident-support.md#f5--restauration-ou-récupération-dun-tenant),
avec revue de l’exécutant et du vérificateur. Conserver les durées et résultats
mesurés. Les scripts OPS-004/005 de laboratoire ne sont pas des outils génériques
de restauration sur une base utilisée ; aucune commande destructive de production
n’est fournie ou exécutée par cette fiche.

Pour le monitoring : associer à chaque signal une sonde, une périodicité, un
seuil adopté, un destinataire/suppléant et une preuve d’alerte reçue. Couvrir parcours
HTTP autorisés et refus attendus, erreurs/latence, progression et échecs des files,
workers/scheduler, espace disque, sauvegardes, stockage privé, scanner, mail et temps
réel si activés. Le statut HTTP `operational` est statique : il ne prouve pas que
PostgreSQL, Redis, les workers ou les sauvegardes fonctionnent. `schedule:list`
liste la planification et ne prouve pas l’exécution du cron ; `handled` ne prouve
pas le succès métier d’un job. Reprendre les seuils proposés OPS-008 uniquement
après adoption et essai réel de la chaîne d’alerte.

Le contrôle de configuration disponible, depuis `back` sur le profil autorisé :

```bash
php artisan ops:check-environment --expect=staging --json --no-interaction
# Pour la release de production autorisée : --expect=production
```

Code 0 : configuration conforme aux contrôles locaux ; 1 : configuration incomplète ;
2 : profil absent/invalide. Ce contrôle ne contacte pas les ressources et ne valide
ni connectivité, secrets distincts, ACL, sauvegarde ou restauration. Un test de cette
commande sur configuration fictive n’autorise pas un déploiement.

### Checklist de lancement avec preuves

Pour chaque ligne : résultat **à faire / bloqué / vérifié / non applicable**, preuve
privée datée, environnement, commit, exécutant et vérificateur. Une justification
approuvée est nécessaire pour « non applicable » ; une dépendance non disponible
reste « bloqué », pas « vérifié ». Les lignes de préparation documentaire peuvent
être prêtes sans valider leur exécution réelle.

| ID | Contrôle et preuve attendue | Responsable fonctionnel | État actuel |
| --- | --- | --- | --- |
| R2-01 | Périmètre, équipe, dates, modules et décision de fin du pilote REL-001 | Décideur et manager | Pilote réel différé |
| R2-02 | Commit exact, résultats CI du commit, audits et écarts de recette examinés | Réalisation et vérificateur | Nouvelle CI distante à confirmer |
| R2-03 | Hébergement choisi, ressources/profils séparés, HTTPS/CORS/Sanctum, secrets injectés et configuration contrôlée | Exploitation | Bloqué : hébergement reporté |
| R2-04 | Comptes propriétaires récupérables, MFA, droits métier minimaux, retrait d’accès et absence de compte partagé vérifiés | Utilisateur, gestion des accès | À vérifier sur cible |
| R2-05 | Population, données et reprise autorisées ; dépendances DATA-001/002, CAD-004/006 et mineurs traitées pour le périmètre | Décideur et administration | Sources/périmètre réels non validés |
| R2-06 | Guides disponibles, formateurs/participants et suppléants désignés, exercices et attestations de transfert | Responsables de fonction | Supports prêts ; transfert non réalisé |
| R2-07 | Support, horaires adoptés, contacts/escalade et canal de secours éprouvés | Utilisateur et coordinateur | Responsable support confirmé ; fonctionnement à valider |
| R2-08 | Sauvegarde cohérente base/fichiers/clés et preuve de récupération de la sauvegarde | Exploitation | Non vérifié sur hébergement |
| R2-09 | Restauration sur cible isolée, intégrité/déchiffrement/tenant témoin et durées mesurées | Exploitant et vérificateur | Non réalisée sur hébergement |
| R2-10 | Sondes, seuils adoptés, alertes reçues et responsabilités de réponse confirmées | Exploitation et support | Non éprouvé sur hébergement |
| R2-11 | Files, scheduler, transport mail, scanner et temps réel selon modules activés ; absence de doublons après reprise | Exploitation et responsables métier | À vérifier sur cible |
| R2-12 | Charge/latence et isolation de la configuration retenue, écarts QA-005 traités et limites déclarées | Réalisation et vérificateur | QA-005 ouvert, aucune capacité validée |
| R2-13 | Plan de livraison et retour arrière revu, sauvegarde préalable et déclencheurs d’arrêt | Exploitant et décideur | À préparer pour la cible exacte |
| R2-14 | Aucun incident critique/bloquant ouvert, anomalies retestées, réserves et éléments non testés visibles | Support et vérificateur | À rapprocher à la date de lancement |
| R2-15 | Accord de mise en production spécifique à l’environnement/version/périmètre, fenêtre et responsables | Décideur | Non donné |

La préparation ne permet pas de cocher les preuves d’hébergement, restauration,
formation ou accord métier. La checklist n’a aucun effet sur les permissions et
ne possède pas de validation automatique. Une valeur déclarée « vérifié » doit
être rapprochée de sa preuve, pas seulement copiée depuis un ancien rapport.

### Déroulé de livraison à faire autoriser sur la cible

1. Figer le commit, le périmètre et la fenêtre ; vérifier checklist, participants,
   CI correspondante et plan de retour arrière. Définir qui peut arrêter/reprendre.
2. Confirmer la sauvegarde préalable, ses clés et sa possibilité de restauration.
   Définir l’état des écritures et travaux différés pendant l’intervention.
3. L’exploitant habilité applique le plan propre à la cible : secrets, artefacts,
   schéma, cache et processus supervisés. Une migration ou un redémarrage ne se
   déduit pas d’une commande générique copiée dans un guide utilisateur.
4. Vérifier MFA/session, bon tenant, permissions/refus, parcours autorisés,
   traitements/fichiers et tenant témoin, puis la chaîne d’alerte. Consigner les
   versions et résultats réellement observés avant ouverture aux utilisateurs.
5. En cas d’échec, garder le périmètre protégé et appliquer le plan approuvé.
   Ne pas lancer un rollback SQL global ou un réimport qui écraserait des données
   plus récentes ; toute restauration/reprise passe par les prérequis OPS-004/005.
6. Le décideur autorise explicitement l’ouverture ou le maintien en attente.
   Fixer suivi renforcé, responsable, prochaine revue et critères d’arrêt/reprise,
   avec les durées et horaires convenus plutôt qu’un engagement inventé.

Formulaire d’accord privé à compléter : référence de release, commit, cible,
périmètre, fenêtre/fuseau, preuves R2-01–15, réserves/éléments non testés,
exécutant, vérificateur, responsable du support, critères d’arrêt, plan de retour
arrière, décision **ouvrir / reporter / refuser**, décideur, date et justification.
État initial : **décision non renseignée — aucun accord de production enregistré**. Une
nouvelle version ou un changement de périmètre demande une nouvelle revue adaptée.
REL-002 reste ouvert tant que responsables manquants, transfert, restauration sur
hébergement et accord spécifique ne sont pas établis.

### Revue locale du dossier

Revue sur table par l’agent, sans atelier humain ni intervention d’exploitation :

| Cas examiné | Réponse prévue par le dossier | Limite conservée |
| --- | --- | --- |
| Sauvegarde annoncée, mais restauration non testée | R2-09 reste non vérifié ; pas d’ouverture déclarée prête | Aucune restauration hébergée inventée |
| Endpoint statique operational, worker arrêté | Contrôler la progression et la chaîne d’alerte, traiter selon F1/F4 | Un HTTP 200 ne remplace pas les sondes |
| Utilisateur support sans accès serveur | Escalader à l’exploitant désigné | Aucun droit système ou métier implicite |
| Guide remis, participant non formé | R2-06 distingue supports prêts et transfert non attesté | Aucune formation réelle revendiquée |
| Responsable accès/support nommé, exploitant absent | Conserver les attributions confirmées et bloquer l’étape dépendante | Aucun nom ou prestataire inventé |
| Demande de push du dossier | Versionner la préparation seulement | Aucun déploiement ni accord de production implicite |

## UCG-OPS-002 — Construire et vérifier un candidat de livraison

### Périmètre livré

L’outillage prépare un **candidat**, composé des sources serveur versionnées,
du frontend réellement compilé, d’un manifeste SHA-256 et d’un plan d’installation.
Il ne contacte aucun hébergement et n’exécute ni migration, seeder, restauration,
activation de worker/scheduler/Reverb ou bascule de trafic. L’hébergement reste
reporté et OPS-002 reste ouvert pour le raccordement et la recette du déploiement réel.

Le paquet ne contient pas `vendor` : les dépendances PHP de production devront
être installées depuis son `composer.lock` dans une nouvelle release autorisée,
avant activation. Il n’est donc pas une image exécutable autonome. Les drapeaux
`runtimeDependenciesIncluded` et `deploymentAuthorized` restent toujours faux.
Le profil `production` sélectionne un build public ; il ne donne pas l’accord REL-002.

### Construction locale

Prérequis : Node 24, Git et les dépendances frontend installées depuis le verrou.
Depuis `front` :

```powershell
node --test tests/real-api/release.test.mjs
node buildRelease.mjs build --profile=local
```

Le résultat JSON indique le dossier créé dans `front/reports/releases/`, ignoré
par Git. Chaque construction utilise un nouveau dossier ; les candidats précédents
ne sont pas écrasés. Le frontend est construit avec TypeScript et Vite dans un
répertoire temporaire appartenant à l’opération. Les paramètres d’environnement
sont limités à l’OS et aux variables publiques admises ; le répertoire de lecture
`.env` de Vite est vide. Le profil local impose des adresses loopback et une clé
publique fictive, sans reprendre les paramètres réels du poste. Le constructeur
n’installe pas de dépendances et ne charge pas Laravel.

Les **entrées de livraison** doivent être commitées et propres : application,
configuration et migrations serveur, points d’entrée/bootstrap, langues/ressources,
fichiers publics autorisés, manifestes Composer, sources/public du frontend,
manifestes npm, configurations TypeScript/Vite et validateur des variables publiques.
Fichiers ajoutés, modifiés ou ignorés à l’intérieur de ces chemins font refuser la
construction. Une modification de documentation ou d’outillage hors de ces entrées
ne change pas la révision applicative empaquetée : l’empreinte de l’outillage utilisé
est enregistrée séparément. Une nouvelle entrée runtime exige de mettre à jour ce
contrat avant de prétendre la livrer.

Les entrées sont contrôlées avant et après le build. Le manifeste conserve commit,
profil, empreinte globale des entrées, empreinte des trois fichiers d’outillage,
version Node, empreinte des paramètres publics et inventaire des fichiers livrés.
Un changement des sources ou de l’outillage pendant la construction empêche la
finalisation. L’inventaire inclut le plan d’installation et les fichiers compilés.
Les empreintes décrivent les octets de cet essai ; elles ne promettent pas des builds
identiques entre OS ou versions de Node différents.

Le serveur livré suit une liste de chemins autorisés : `app`, `config`, migrations,
langues, ressources, routes, points d’entrée/bootstrap et manifestes Composer.
Les fichiers de base, `.env`, clés, `auth.json`, logs, caches de fonctionnement,
stockage utilisateur, dépendances, tests/factories/seeders et rapports ne sont pas
ajoutés au paquet. Les noms cachés sont refusés, sauf le `.htaccess` public Laravel
explicitement autorisé. Les liens symboliques/jonctions et fichiers spéciaux sont
refusés ; limites : 12 000 fichiers, 16 Mio par fichier et 128 Mio au total.
Les scripts de test utilisent des dépôts et données fictifs, sans accès à une base.

### Vérifier un candidat reçu

Après récupération depuis la source de confiance et extraction dans un espace
neuf non servi par le web, reprendre **le commit et le profil attendus depuis le
suivi de release**, pas uniquement depuis le manifeste reçu :

Exécuter le vérificateur depuis un checkout d’outillage de confiance avec Node 24,
et non un script ajouté à un paquet non encore vérifié.

```text
node front/buildRelease.mjs verify CHEMIN_DU_CANDIDAT COMMIT_COMPLET PROFIL
```

La commande vérifie schéma, cible, bornes, fichiers requis, tailles et empreintes,
refuse ajouts/suppressions/doublons/chemins sortants/liens et exige un plan toujours
non exécutable/non autorisé. Aucun fichier métier n’est modifié par la vérification.
Code 0 : candidat cohérent ; code 1 : construction ou vérification refusée.
Une construction interrompue peut laisser un dossier incomplet sans manifeste
final : il doit rester hors livraison, jamais être validé au seul nom du dossier.

Une empreinte SHA-256 détecte une altération par rapport au manifeste ; ce n’est
pas une signature ni une preuve d’origine indépendante. Quelqu’un qui remplace le
contenu et ses empreintes peut produire un autre manifeste cohérent. L’authenticité
exige le canal de récupération et les références CI approuvés ; les références
stockées dans un paquet local restent déclaratives. Aucun paquet ne devient un
accord de production par cette seule vérification.

### Préparation manuelle dans GitHub Actions

Le workflow `Release candidate` est déclenché exclusivement par `workflow_dispatch`.
Il doit être présent sur la branche par défaut pour apparaître comme workflow
exécutable manuellement ; sélectionner ensuite la branche souhaitée selon la
[procédure GitHub](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow).
Ce dossier ne pousse, fusionne ou déclenche aucun workflow à la place de l’utilisateur.

Avant construction, le collecteur interroge les workflows fixes `backend-quality.yml`
et `frontend-quality.yml` par l’API GitHub en lecture seule, avec commit, branche et
événement `push`. Les deux exécutions pertinentes les plus récentes doivent être
terminées avec succès sur le même commit, la même branche et le même dépôt.
Un résultat en attente, échoué, annulé, ignoré, mal formé ou manquant bloque la
préparation ; un ancien succès ne remplace pas un résultat plus récent. Si les
CIs ne sont pas encore terminées, attendre leur résultat avant de relancer manuellement.
Le token du collecteur n’est pas transmis aux processus de build.

Le job teste les garde-fous, installe les dépendances frontend avec `npm ci`,
refait les audits npm/Composer et compile le profil choisi. Il publie uniquement
le **dossier exact nouvellement construit et vérifié**, via l’artefact de workflow
`candidate-COMMIT-PROFIL`, conservé 14 jours. Les droits d’accès à cet artefact sont
ceux du dépôt ; il ne contient pas de secrets d’environnement serveur. Le `.htaccess`
est inclus explicitement par la prise en compte des fichiers cachés ; les autres
fichiers cachés sont déjà refusés par le constructeur. Aucun ancien dossier voisin
ou essai partiel n’est sélectionné par un glob d’upload.

Pour un profil distant, configurer les variables **publiques** du dépôt :

| Profil | Variables requises |
| --- | --- |
| staging | `RELEASE_STAGING_API_URL`, `RELEASE_STAGING_REVERB_HOST`, `RELEASE_STAGING_REVERB_PUBLIC_KEY` |
| production | `RELEASE_PRODUCTION_API_URL`, `RELEASE_PRODUCTION_REVERB_HOST`, `RELEASE_PRODUCTION_REVERB_PUBLIC_KEY` |

Le validateur OPS-001 existant impose URL API HTTPS, chemin `/api/v1`, domaine Reverb
correspondant, port 443 et schéma HTTPS. Aucune variable de production manquante ne
retombe sur la recette : les deux étapes ont des sources distinctes. Les valeurs
publiques doivent être revues avant configuration ; ne jamais y placer `APP_KEY`,
un mot de passe ou une clé Reverb secrète. En local hors workflow, les variables
`VITE_` documentées alimentent les profils distants avec les mêmes contrôles.
Les fichiers `.env.*.example` et domaines `.invalid` ne deviennent pas exécutables
par simple copie. Aucun domaine réel n’étant choisi, aucun build distant n’est
revendiqué dans cette livraison.

### Plan d’installation et de récupération à raccorder

`deployment-plan.json` énumère les prérequis et étapes à faire adopter pour la cible :
accord REL-002, exploitant habilité, sauvegarde/clés et restauration isolée qualifiées,
revue de compatibilité des migrations, propriétaire unique du scheduler, supervision
des workers/temps réel et critères d’arrêt/reprise. Toutes les opérations sont
séparées de la construction du candidat et restent non exécutables dans ce plan.

Le futur adaptateur d’hébergement devra vérifier le candidat, installer les seules
dépendances PHP de production verrouillées dans une nouvelle release, créer les
répertoires privés/cache Laravel avec les droits appropriés, injecter les secrets,
contrôler la configuration de cible, puis appliquer uniquement les migrations
compatibles expressément approuvées. La racine du paquet ne doit jamais être
servie comme un site : exposition limitée à `back/public` pour PHP et au dossier
`front` compilé pour le client, selon la configuration du serveur retenu.

La bascule web/workers/Reverb et l’activation d’un seul scheduler nécessitent une
coordination propre à l’hébergement ; aucune commande locale ne les réalise ici.
Une migration destructive reste hors exécution sans stratégie validée. Revenir à
l’ancien code ne restaure pas la base : ne jamais déduire un rollback SQL sûr de la
présence du précédent candidat. Reprendre les prérequis et procédures
[REL-002](#ucg-rel-002--préparer-le-lancement-et-lexploitation) et
[OPS-008](docs/procedures-incident-support.md) avant toute intervention réelle.

### Vérifications locales et limites

Les tests couvrent sources sales/ignorées, noms sensibles suivis par Git, changement
pendant build, sortie incomplète ou trop volumineuse, altération/ajout/suppression,
chemin sortant, liens/jonctions, mauvais commit/profil, plan modifié même avec ses
empreintes recalculées, paramètres publics et qualification des deux workflows.
Ils sont ajoutés à `Frontend quality` pour les prochains push/PR.

Un candidat local a été construit avec le vrai TypeScript/Vite puis vérifié :
commit applicatif `5a1629bb9fa88b554b712e242ce91621bc6f80ef`, profil local,
1 325 fichiers, aucune dépendance PHP installée dans le paquet et aucune autorisation
de déploiement. Les sources runtime étaient inchangées, l’outillage en développement
étant tracé par son empreinte. Ce résultat n’est ni un boot Laravel du paquet ni une
recette d’installation sur Linux. Les résultats détaillés et limites sont consignés
dans l’état du projet ; aucun workflow distant de release n’a été exécuté.

## UCG-OPS-003 — Premiers diagnostics opérationnels

Le diagnostic est une commande réservée aux opérateurs disposant déjà d’un accès
serveur autorisé. Un rôle support dans l’application ne confère pas cet accès.
Depuis `back`, préparer le rapport sans connexion aux dépendances :

```sh
php artisan ops:inspect-runtime --json
```

Cette forme retourne `PROBE_NOT_REQUESTED` et le code de sortie **2**. Pour demander
explicitement les lectures des ressources configurées sur ce serveur :

```sh
php artisan ops:inspect-runtime --probe --json
```

Sans `--json`, la commande affiche un tableau des états et codes. Aucun endpoint,
planning automatique, envoi d’alerte, job, retry, purge ou redémarrage n’est ajouté.
Les lectures ne consultent ni personnes, ni organisations, ni documents métier.

| Contrôle | Lecture et portée du résultat |
| --- | --- |
| Base | `SELECT 1` sur la connexion par défaut ; ne valide ni schéma ni parcours métier |
| Cache | Lecture d’une clé UUID neuve sur les drivers Laravel database/redis ; aucune écriture ni validation de persistance. Array/file restent non vérifiés ; une clé absente sur disque peut masquer un problème de lecture |
| Queue database | Échantillon ordonné par ID des seules colonnes `id`, `queue`, `reserved_at`, `available_at` ; compteurs par queue enregistrée, attente des travaux disponibles observés, troncature explicite |
| Queue redis | Totaux des cinq queues enregistrées via `size()` ; aucune lecture du corps des jobs. Détail disponible/réservé/différé et âge restent non vérifiés |
| Échecs historiques | Échantillon `id`/`queue` du stockage database des failed jobs, limité à la connexion de queue configurée et aux cinq queues enregistrées. Aucune lecture de `payload` ou `exception` |
| Stockage des artefacts local | Métadonnées de la racine : répertoire lisible, racine sans lien symbolique direct, différente de la racine du volume, visibilité non publique et emplacement hors des racines publiques connues, sans les englober. Aucun fichier listé, lu ou écrit |

Les queues sont `critical`, `default`, `notifications`, `broadcasts`, `reports`.
Les queues personnalisées et les autres connexions sont hors périmètre. Les drivers
non pris en charge (dont stockage distant) restent non vérifiés, sans repli silencieux.
Une réservation ne prouve pas qu’un worker est vivant ; un échec historique ne prouve
pas une panne actuelle. Les compteurs constituent un instantané, sans cohérence
transactionnelle entre lectures, et peuvent évoluer pendant l’inspection.

`config/operations.php` définit deux paramètres techniques : échantillon de
1 000 lignes, configurable entre 1 et 10 000 ; avertissement à 300 secondes
d’attente d’un job disponible, configurable entre 1 et 86 400 secondes. Ce seuil
est une proposition technique, pas un engagement de délai de traitement. La lecture
demande au plus limite + 1 lignes par table, puis agrège au plus la limite ; elle
ne calcule pas de total global. Un échantillon tronqué sans problème observé reste
non vérifié. Ces limites bornent les lignes retournées, pas le temps d’exécution
SQL ni le temps réseau : les timeouts et plans de requêtes devront être qualifiés
sur l’hébergement avant toute exécution régulière.

| Sortie | Sens |
| --- | --- |
| 0 / `probes_passed` | Les seules lectures prises en charge ont passé leurs contrôles ; aucune validation globale de disponibilité |
| 1 / `attention_required` | Lecture échouée, configuration invalide, stockage public/illisible, attente observée au seuil ou historique d’échecs à examiner |
| 2 / `unverified` | Aucun contrôle demandé, driver non pris en charge, portée insuffisante ou échantillon tronqué, sans problème prioritaire observé |

Le rapport conserve toujours `scope=partial_read_checks`,
`supervision_validated=false` et `alert_dispatched=false`. Les erreurs sont des codes
fixes par composant ; la commande ne publie pas les exceptions, DSN, identifiants
de connexion, chemins de stockage ou données privées. Elle poursuit les autres
contrôles après une erreur. Le rapport doit rester dans un espace opérationnel
restreint : il contient des compteurs globaux d’infrastructure.

Les [procédures OPS-003](docs/procedures-incident-support.md#ops-003--traiter-les-résultats-du-diagnostic)
associent les codes à une investigation et aux rôles à désigner. Aucun destinataire
d’alerte n’est inventé. Les request IDs et journaux `tenant.work.*` existants sont
conservés ; `tenant.work.handled` signifie retour du handler, pas réussite métier.
Le statut HTTP `operational` existant reste statique et ne doit pas servir de sonde
de dépendances.

**OPS-003 reste ouvert** : disponibilité des workers et du scheduler, erreurs et
latence HTTP représentatives, imports/rappels de bout en bout, connexions temps
réel, mail/scanner réels, stockage distant/ACL/capacité, métriques conservées et
alertes reçues/acquittées avec responsable et canal de secours restent à qualifier.
L’hébergement demeure reporté. Les tests SQLite locaux et les doubles de contrats
Redis ne constituent pas une recette PostgreSQL/Redis ou une preuve d’astreinte.

### Activité des workers, scheduler et travaux différés

Le deuxième lot OPS-003 ajoute `ops:inspect-background`. Cette commande serveur
reste sans lecture par défaut ; l’option `--probe` autorise les lectures, mais
la collecte doit aussi être explicitement activée. La configuration livrée est
**désactivée**, y compris dans les bootstraps de tests/pilote/reprise fictive.

```sh
php artisan ops:inspect-background --json
php artisan ops:inspect-background --probe --json
```

Pour une cible de test autorisée, préparer `OPS_RESOURCE_NAMESPACE` distinct par
environnement, puis sélectionner `OPS_BACKGROUND_CACHE_STORE` (driver Laravel
database ou redis exclusivement). Les workers et le diagnostic doivent partager
ce store, son préfixe et le namespace. Vérifier la connexion de queue par défaut
(database/redis), les files effectivement consommées et les timeouts avant de
passer `OPS_BACKGROUND_MONITORING_ENABLED=true`. Les limites et `expected_queues`
sont revues dans `config/operations.php` ; par défaut les cinq queues enregistrées
sont attendues. Redémarrer les processus avec la configuration revue et le cache
de configuration reconstruit selon la procédure de livraison. Aucun `.env` réel
n’est modifié par ce lot et aucun service n’est démarré sur l’hébergement reporté.

| Signal | Collecte / lecture et interprétation |
| --- | --- |
| Worker | L’événement Laravel `Looping` d’un worker daemon de la connexion par défaut marque les queues enregistrées qu’il annonce surveiller. Au plus une tentative d’écriture toutes les 30 s par queue et par processus. Ni les autres connexions, ni les queues personnalisées ne sont marquées |
| Scheduler | Une callback nommée `ops-background-heartbeat`, enregistrée seulement si le suivi est activé au démarrage, marque son passage chaque minute. Elle ne prouve pas l’exécution des autres tâches, ni l’unicité du scheduler |
| Imports de personnes | Échantillon des `person_imports` queued/processing/failed, colonnes `status` et `updated_at` uniquement |
| Imports statistiques | Échantillon des `statistical_import_executions` queued/failed, mêmes colonnes ; les aperçus non confirmés sont exclus |
| Rappels contractuels | Échantillon des livraisons e-mail pending/sending/uncertain, colonnes `email_status` et `updated_at` uniquement. Sent/blocked/not_requested sont exclus ; un refus d’habilitation n’est pas transformé en panne |

Les marqueurs contiennent uniquement un timestamp, sous au plus six clés par
namespace/connexion configurés, sans identifiant de worker, personne, tenant ou
job. Ils sont conservés sans TTL pour distinguer un ancien marqueur d’un marqueur
absent. Les anciennes clés après changement de namespace/connexion et la politique
de conservation des métadonnées opérationnelles restent à gérer par l’exploitant ;
aucune purge du cache n’est lancée. Les écritures sont limitées par processus,
pas globalement entre tous les workers. Elles sont synchrones et restent soumises
aux timeouts du cache : leur coût et ces délais doivent être qualifiés avant activation.
Une erreur est contenue et produit au plus un avertissement expurgé par composant
et intervalle dans le processus ; une erreur du logger est également contenue.

Un marqueur récent signifie **passage observé**, sans certifier qu’un worker est
encore vivant, qu’il prend chaque queue équitablement ou qu’un traitement métier a
réussi. `queue:work --once`/`queue:listen` ne sont pas une source prise en charge
pour ces marqueurs ; utiliser des workers daemon/Horizon qualifiés sur la cible.
Une activité absente ou ancienne exige de vérifier les processus et le cache :
maintenance, pause, job long, arrêt normal, horloge décalée ou perte du cache peuvent
expliquer le signal. Le seuil proposé est **180 s** (bornes 60–86 400), à adapter
au plus long job, aux délais de polling et aux timeouts. Les horloges doivent être
synchronisées ; un timestamp futur/malformé est refusé.

Pour les travaux, le seuil proposé est **900 s depuis `updated_at`** (bornes
60–86 400), qui est une dernière modification et non une mesure de progression
métier. Les échecs/états incertains historiques demandent une revue même récents.
Chaque source est triée par `updated_at`, puis ID, limitée à 1 000 lignes + une
pour détecter la troncature (plafond configurable 10 000), sans total global.
Les résultats sont des compteurs/âges observés, aucune ligne ou identité exportée.
La lecture est globale, réservée à l’accès serveur ; elle ne crée aucun accès
support aux données métier. Les requêtes ne modifient aucun import/rappel.
La limite de lignes ne borne pas le scan, le tri SQL ou le temps de connexion :
profiler les plans sur une cible représentative avant toute collecte régulière.
L’échantillon peut masquer d’autres travaux ; aucune conclusion de totalité
n’est permise s’il est tronqué. Les tenants inactifs/gelés ne sont pas déclarés
en panne : ces enregistrements appellent seulement une revue du contexte.

Les rappels contractuels non encore matérialisés et les échéances de compétition
stockées dans des payloads métier ne sont pas examinés. Une activité scheduler/queue
ne prouve pas que ces rappels ont été créés, reçus ou lus : leur recette de bout en
bout reste requise. Le diagnostic ne contourne pas les droits et ne relance rien.

Sorties : **0** pour les seuls contrôles passés, **1** pour un signal à examiner
ou une lecture échouée, **2** pour suivi désactivé/absence d’option ou échantillon
incomplet sans autre problème observé. Une erreur d’un composant ne masque pas les
autres lectures. Le rapport reste partiel avec `supervision_validated=false`.
`alert_candidates` prépare code, sévérité, clé de déduplication stable par namespace,
composant et code, rôle d’exploitation à désigner, et procédure `OPS-003-BG`.
`delivery=prepared_only` et `alert_dispatched=false` : aucun transport, destinataire,
acquittement, escalade ou déduplication persistante n’est activé. Aucun job, e-mail
ou notification n’est envoyé par cette préparation d’alertes.

Voir la [procédure OPS-003-BG](docs/procedures-incident-support.md#ops-003-bg--activité-et-travaux-différés).
Le raccordement aux alertes réelles et leur exercice de panne/acquittement restent
ouverts avec l’hébergement, les métriques HTTP et le temps réel.

### OPS-003 — Préparation locale complète et registre d’alertes

Le dernier ensemble de lots ajoute mesures API, sondes de dépendances, suivi des
cycles de rappels et cycle de vie des alertes. La **recette sur l’hébergement
reste à réaliser**. Les fonctionnalités sont désactivées par défaut ; les essais
locaux/fournisseurs simulés ne certifient ni disponibilité ni réception d’alertes.

Depuis `back`, sans option de lecture :

```sh
php artisan ops:inspect-supervision --json
php artisan ops:alerts --json
```

Ces formes retournent 2 sans lire les ressources. Sur une cible autorisée avec
configuration revue, `ops:inspect-supervision --probe --json` agrège les contrôles
runtime, traitements, API et stockage local. Les dépendances distantes nécessitent
en plus `--external`. Aucun message, job ou e-mail n’est envoyé par ces commandes.

**Mesures API.** `OPS_API_TELEMETRY_ENABLED=true` et `OPS_API_CACHE_STORE` sur un
store database/redis partagé activent la collecte après réponse, pour les seules
requêtes `/api/*`. Le namespace reste `OPS_RESOURCE_NAMESPACE`, distinct par
environnement. La durée utilise une horloge monotone et mesure le passage dans
le pipeline applicatif ; elle exclut le proxy, les trajets réseau et la diffusion
complète des réponses streamées. La réponse et ses autorisations sont conservées
si la télémétrie échoue. Aucun nom de route, URL, IP, header, cookie, corps,
identité/tenant ou exception n’est collecté dans ces nouveaux compteurs/journaux.

Quatre compteurs par minute : requêtes observées, réponses 5xx, requêtes lentes
et somme des durées en ms. Ce sont des lectures de compteurs indépendants, sans
snapshot transactionnel ni percentile. Fenêtre proposée de cinq buckets minute,
incluant le bucket courant partiel ; minimum proposé de 20 observations. Revue
si au moins 5 % de 5xx ou 10 % de durées >= 1 000 ms. Valeurs validées dans
`operations.api`, non engagements de service. Une fenêtre vide/insuffisante,
un bucket incomplet après éviction ou des compteurs incohérents ne valent pas
validation. Des erreurs de collecte peuvent faire perdre des observations :
la recette doit mesurer la couverture et la charge avant activation régulière.

Les entrées de cache ont un TTL logique de deux heures. Redis expire ces clés ;
sur le cache database, les lignes expirées non relues nécessitent une maintenance
opérateur adaptée. Aucun nettoyage global n’est ajouté. Préférer Redis qualifié
pour une collecte à fort débit et mesurer les appels cache par requête ; le store
database sert notamment aux essais isolés. Les nouvelles métriques restent hors
de toute promesse de capacité ou de latence QA-005.

Le canal local `operations` écrit des lignes JSON dans `storage/logs/operations-*.jsonl`,
avec une rotation de sept fichiers quotidiens. Seuls `request_id` UUID serveur,
statut et durée sont autorisés par le formatter final, après les autres processors.
Les autres contextes/extras et les messages variables sont exclus. Au plus 20
entrées de revue par minute et namespace sont proposées, seulement pour les
5xx/lenteurs ; les autres observations alimentent les compteurs. Ce budget dépend
du cache partagé et ne transforme pas le journal en preuve exhaustive. Garder
les fichiers dans l’espace opérateur restreint et qualifier rotation/expédition.

**Dépendances.** Avec `--probe --external`, Reverb est interrogé via son API
authentifiée `GET /connections` pour un compteur agrégé, sans socket individuel
ni message de canal. Client séparé, timeout connexion 2 s / total 3 s, redirections
désactivées. Le test de canaux privés et d’isolation reste distinct. SMTP vérifie
uniquement la bannière 220, sans authentification ni envoi ; TLS implicite selon
la configuration `smtps` ou le port 465 implicite du transport, sans qualification
STARTTLS/livraison. Ports d’environnement et URL SMTP sont normalisés comme dans
la configuration du mailer. ClamAV reçoit
uniquement `PING`, sans document ni validation de fraîcheur de signatures.
Connexions/lectures socket : 2 s chacune, réponses bornées à 512 octets. Les noms
de cible et ports sont validés côté serveur et ne viennent pas d’une requête HTTP.
Le stockage S3 vérifie l’existence d’une clé UUID neuve absente ; ce contrôle ne
prouve pas ACL privée, écriture, capacité, chiffrement ou restauration. Ses délais
restent ceux du driver configuré. Toutes les exceptions exposées par les sondes
sont remplacées par des codes fixes, puis les autres contrôles continuent.

**Rappels avant matérialisation.** Le diagnostic background inclut désormais les
plans contractuels activés sur contrats éligibles et les dernières révisions de
compétitions actives dont `next_due_at` entre dans le balayage des sept jours.
Seules dates de création/dernier contrôle sont sélectionnées, pas les payloads,
personnes, coordonnées ou droits des destinataires. Un cycle jamais vérifié,
trop ancien ou sans tâche enregistrée demande une revue. Seuil proposé distinct
de 1 800 s, borné 900–86 400, pour respecter le balayage contractuel de quinze
minutes ; échantillon/troncature restent explicites. Les tests exécutent les vrais
traitements fictifs jusqu’à la création d’un rappel. Le diagnostic lui-même ne
déduit pas qu’un rappel doit être envoyé à une personne et ne contourne aucun
refus d’accès, gel ou contrôle métier.

**Registre et acquittement.** La migration
`2026_10_30_143449_create_operations_alert_tables.php` crée deux tables techniques
globales, sans donnée métier tenant. Elle est à appliquer dans une livraison
autorisée avant activation de `OPS_ALERT_LEDGER_ENABLED=true`. Aucun lancement
de migration sur une base existante n’est effectué par ce lot. Le registre est
accessible uniquement par accès serveur autorisé ; un rôle support ne donne pas
ces droits serveur. Les références UUID d’incident sont des liens de suivi, pas
une preuve d’identité : conserver aussi l’audit des accès serveur.

```sh
php artisan ops:inspect-supervision --probe --record-alerts --json
php artisan ops:alerts list --probe --page=1 --json
php artisan ops:alerts acknowledge ALERT_ID --expected-version=1 --incident=11111111-1111-4111-8111-111111111111 --confirm --json
```

Remplacer `ALERT_ID`, version et UUID fictif par les valeurs revues dans l’incident.
`--record-alerts` est une écriture explicite, indépendante de `--external`.
La liste retourne au plus 50 entrées par page. Une transaction et une clé unique
namespace/composant assurent la déduplication ; observations répétées ne créent
pas d’événement en double. Une cause/sévérité différente réouvre la prise en charge.
Un contrôle `passed` résout l’alerte existante de ce composant ; un contrôle
`unverified`, absent ou non demandé la conserve. L’acquittement vérifie la version
pour refuser les actions devenues obsolètes. Seuil d’escalade proposé : 1 800 s
d’alerte ouverte non acquittée, une fois par épisode. Il prépare un signal local,
sans notification. Un acquittement n’est pas une résolution métier.

Les transitions opened/changed/acknowledged/resolved/reopened/escalated sont
journalisées dans la même transaction. Le journal refuse UPDATE/DELETE ; `down()`
refuse de supprimer un registre rempli. Une conservation/évolution de ces traces
techniques se traite par une procédure revue, sans purge de données personnelles.
Les rapports gardent toujours `supervision_validated=false` et
`alert_dispatched=false`, même avec `alerts_recorded=true`.

La [procédure OPS-003-FINAL](docs/procedures-incident-support.md#ops-003-final--recette-et-cycle-des-alertes)
définit les investigations, la matrice de panne et la fiche de recette à remplir.
Il reste à qualifier sur hébergement Redis/Horizon/cron, Reverb/canaux privés,
SMTP réel, scanner/signatures et stockage/ACL/capacité, puis à choisir et raccorder
un canal d’alerte avec destinataire/suppléant, réception, acquittement humain et
escalade reçue. Aucun adaptateur de notification externe n’est activé ; le
registre constitue la préparation locale de ce raccordement. **OPS-003 reste
ouvert pour cette recette et ce raccordement**, l’hébergement étant reporté.

## OPS-004 — PITR, coffre chiffré et preuves de sauvegarde

Les quatre lots sont préparés localement ; la recette hébergée reste en attente.
Avec PostgreSQL 17 et ses exécutables dans PATH, depuis `front` :

```sh
npm run test:recovery:pitr:harness
npm run test:recovery:pitr
```

Le second test crée exclusivement des clusters temporaires attestés sur loopback.
Il réalise une sauvegarde physique native avec un rôle LOGIN REPLICATION sans
lecture SQL des personnes, vérifie son manifeste SHA-256, archive des segments
WAL complets avec basic_archive, puis restaure à un instant UTC situé entre deux
transactions fictives. La source est arrêtée avant restauration. Les 143 tables
publiques, séquences, contraintes/déclencheurs/fonctions, trois fichiers privés,
leur déchiffrement et les gardes tenant/SQL sont contrôlés. Le cluster physique
emporte ses rôles ; seuls le nom de base et le mot de passe de la cible jetable
sont adaptés à son contrôle de propriété. Un arrêt PostgreSQL immédiat suivi
d’un redémarrage est testé ; cela ne simule pas une perte électrique de l’hôte.

Le coffre de laboratoire chiffre objets et manifeste avec AES-256-GCM et des
clés HKDF distinctes par génération. Il authentifie chemins, tailles et empreintes,
publie le manifeste en dernier et réutilise les objets complets vérifiés après
une interruption entre objets. Un fragment `.part` abandonné provoque un refus
plutôt qu’un écrasement automatique ; intervention sur la seule génération
jetable attestée avant reprise. Une seconde racine reçoit uniquement le chiffré,
revérifié avant restauration. Les clés de coffre et de signature sont dans une
troisième racine, distinctes de l’APP_KEY. Mauvaise clé/APP_KEY, chaîne WAL
incomplète, altération, liens et cible occupée sont refusés. Le format reste borné
(256 Mio, 4096 entrées, 32 Mio/fichier, huit segments WAL au maximum), en mémoire,
réservé aux fixtures ; ce n’est pas un agent de sauvegarde de production.

La capture des fichiers se fait sans écriture concurrente autour du point choisi.
Un PITR arbitraire en présence de modifications concurrentes de fichiers exige
un stockage versionné et un protocole de cohérence à qualifier. Deux répertoires
sur ce PC ne prouvent aucune indépendance hors site. Les ACL Windows, stockage
immuable, rotation/récupération des clés et protections fournisseurs restent à
vérifier. Garder aussi l’APP_KEY et les anciennes clés nécessaires au déchiffrement
des données restaurées, dans un stockage de secrets séparé et protégé.

`retentionPlan` produit uniquement des candidats à revue : conserve les deux
générations récentes, les copies non vérifiées, les gels et les générations avec
moins de deux copies vérifiées. Le seuil de laboratoire est sept jours. Il ne
supprime rien et ne modifie pas le soft delete des dossiers personnels.

Le rapport ignoré `front/reports/pitr-local.json` contient mesures, empreintes,
contrôles et nettoyage, jamais les clés ni données privées. La CI PostgreSQL
exécute ces deux commandes et conserve ce rapport 14 jours. Les durées locales
ne sont pas des engagements RPO/RTO.

La supervision lit uniquement un reçu privé signé HMAC-SHA256 lorsque
`OPS_BACKUP_MONITORING_ENABLED=true` et `ops:inspect-supervision --probe` est
explicitement exécuté. Configuration :

```dotenv
OPS_BACKUP_MONITORING_ENABLED=false
OPS_BACKUP_EVIDENCE_FILE=
OPS_BACKUP_EVIDENCE_KEY_FILE=
OPS_BACKUP_ARCHIVE_ROOT=
```

Les chemins doivent être absolus, canoniques et privés ; la clé binaire de 32
octets reste hors archive, hors `storage/app` et hors dossier du reçu. Sous POSIX,
les fichiers ne doivent donner aucun droit au groupe/aux autres. Le reçu est
borné à 64 Kio et son contenu JSON signé à 32 Kio. Les champs requis sont
`version=1`, `namespace`, `scope`, `backup_id`, `key_id`, `generated_at`,
`backup_completed_at`, `wal_archived_at`, `restore_tested_at`, `base_verified`,
`wal_chain_verified`, `copy_verified`, `manifest_sha256`. Dates UTC ISO avec trois
décimales ; `restore_tested_at` peut être nul. L’enveloppe comporte `payload`
(texte JSON exact) et `signature` (HMAC hexadécimal de ce texte).

Seuils proposés dans `config/operations.php` : sauvegarde/reçu 24 h, WAL 5 min,
exercice de restauration 7 jours, tolérance horloge 30 s. Absence/format/signature
incorrects échouent ; ancienneté ou vérification incomplète produit un avertissement.
Avec le registre OPS-003 activé, `--record-alerts` journalise les transitions
existantes ; aucun envoi externe n’est ajouté. Un reçu `local_simulation` reste
`unverified` et ne ferme pas une alerte. Un reçu `hosted` frais valide uniquement
la déclaration signée (`BACKUP_DECLARATION_CURRENT`) : il ne certifie ni la copie
fournisseur ni RPO/RTO. HMAC n’assure ni contrôle indépendant ni protection contre
le rejeu d’un reçu encore frais. La production automatisée de reçus et la collecte
continue doivent être raccordées au futur dispositif de sauvegarde hébergé.

La [recette OPS-004-FINAL](docs/procedures-incident-support.md#ops-004-final--recette-des-sauvegardes-hébergées)
définit les preuves encore attendues. Références techniques PostgreSQL 17 :
[archivage continu](https://www.postgresql.org/docs/17/continuous-archiving.html),
[basic_archive](https://www.postgresql.org/docs/17/basic-archive.html),
[sauvegarde physique](https://www.postgresql.org/docs/17/app-pgbasebackup.html),
[vérification](https://www.postgresql.org/docs/17/app-pgverifybackup.html).
