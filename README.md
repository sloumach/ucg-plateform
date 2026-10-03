# UCG Platform

Plateforme de gestion esports multi-tenant d'Ultra Cyber Game.

## Structure

- `back/` : API Laravel 13 sous PHP 8.5.
- `front/` : application React 19, TypeScript et Vite 8.
- `docs/` : cahier des charges et backlog technique.

Le dépôt Git est unique à la racine. `back/` et `front/` ne sont pas des dépôts indépendants.

## Prérequis

- PHP 8.3 ou supérieur, PHP 8.5 recommandé ;
- Composer 2 ;
- PostgreSQL 17 ;
- Node.js 22.12 ou supérieur, Node.js 24 LTS recommandé ;
- npm 10 ou supérieur.

## Démarrer l'API

```powershell
cd back
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan serve
```

La base locale attendue est `ucg_platform`. Renseigner `DB_USERNAME` et `DB_PASSWORD` dans `back/.env`, puis exécuter :

```powershell
php artisan migrate
```

## Démarrer le frontend

```powershell
cd front
npm install
npm run dev
```

## Vérifications

```powershell
cd back
php artisan test
vendor\bin\pint --test

cd ..\front
npm run lint
npm run build
```

## Documentation

- `docs/cahier-des-charges-ucg-multitenant.md`
- `docs/backlog-technique-ucg-multitenant.md`

