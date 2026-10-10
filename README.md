# UCG Platform

Plateforme de gestion esports multi-tenant d'Ultra Cyber Game.

## Structure

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
`AWS_*`. L’application passe par le contrat `ArtifactStorage`, de sorte que le
code métier ne dépend pas directement d’AWS.

Les identifiants de tenant sont propagés explicitement dans les jobs concernés
et les artefacts sont préfixés par `tenants/<tenant_id>/`. Redis transporte les
jobs et accélère le cache, mais ne constitue jamais la seule copie durable d’un
contrat, paiement, résultat ou fichier critique.

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
ne sont pas implémentés dans ce ticket.

Chaque création/changement de statut exige un motif et écrit un audit dans la
même transaction : organisation, acteur, ancien/nouveau statut, date et UUID
de corrélation. Le journal est protégé contre les mises à jour et suppressions
par Eloquent et par des triggers PostgreSQL/SQLite. Ces protections ne sont pas
une protection contre un administrateur de base ayant le droit de changer le schéma.
Les suppressions physiques d’organisations et de comptes référencés sont refusées.
Les futures procédures de transfert/conservation devront faire évoluer
explicitement ces protections, sans contournement par une mise à jour ordinaire.

Le contexte tenant (`TEN-002`), les adhésions/invitations (`TEN-003`), l’isolation
généralisée (`TEN-004`) et l’audit transverse (`IAM-006`) restent à implémenter.
Ce ticket ne crée pas encore d’écran React de gestion des organisations.

La CI teste ce module sur une base PostgreSQL 17 dédiée en plus de la suite SQLite.

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
