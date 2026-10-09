# État du projet UltraTools

> Document de transmission entre les discussions Codex. À lire au début d'une
> nouvelle tâche et à mettre à jour après chaque étape significative.

## Point de reprise

- Dernière mise à jour : 9 octobre 2026.
- Branche de travail : `staging`.
- Dernier ticket terminé : `UCG-ARC-004` — qualité, tests automatisés et CI.
- Prochain ticket : `UCG-ARC-005` — Redis, queues, temps réel et stockage.
- Aucun blocage fonctionnel connu.
- Au démarrage, vérifier l'état réel avec `git status --short --branch` et
  `git log -8 --oneline --decorate`.
- Vérifier également que les derniers commits locaux ont été poussés.

## Structure et objectif

UltraTools est une application multitenant composée de :

- `back/` : API Laravel organisée en monolithe modulaire ;
- `front/` : application React avec TypeScript, Vite et Tailwind CSS ;
- `docs/` : cahier des charges, backlog, architecture et conventions.

Les règles permanentes de `AGENTS.md` s'appliquent à toute modification :
contrôleurs minces, couches Service et Repository, Form Requests, autorisation,
DTO/Resources typés, sécurité, pagination et prévention des requêtes N+1.

## Travail terminé

### UCG-ARC-001 — Socle applicatif

- Git initialisé ; branche de développement renommée en `staging`.
- Laravel initialisé dans `back/`.
- React et TypeScript initialisés dans `front/`.
- PostgreSQL configuré et connexion réelle validée.
- Authentification Sanctum et page de statut mises en place.
- Environnement validé avec PHP 8.5, Composer et Node.js 22.12 ou ultérieur.

Commits :

- `24d9452 UCG-ARC-001: initialize Laravel and React workspace`
- `2ed00f8 UCG-ARC-001: complete application foundation`

### UCG-ARC-002 — Architecture modulaire

- Monolithe modulaire Laravel établi.
- Module `Identity` implémenté.
- Frontières préparées pour `Tenancy`, `People`, `Teams`, `Academy`,
  `Scheduling`, `Competition`, `Performance`, `Contracts`,
  `Partnerships`, `Finance`, `Media` et `Reporting`.
- Carte des dépendances et tests d'architecture ajoutés.
- Les modèles Eloquent restent dans leurs modules métier.
- Les migrations sont centralisées dans `back/database/migrations/`.

Commits :

- `75d6462 UCG-ARC-002: establish modular monolith architecture`
- `96dd9cf UCG-ARC-002: centralize Laravel migrations`

### UCG-ARC-003 — Contrats et erreurs API

- Réponses API et gestion des erreurs standardisées.
- Exceptions métier et identifiants de requête ajoutés.
- Base commune pour les DTO ajoutée.
- Contrats correspondants ajoutés côté React.
- Parcours HTTP réel d'authentification vérifié avec PostgreSQL.
- Dernière validation connue : 19 tests backend, 149 assertions, Laravel Pint,
  lint frontend et build frontend réussis.

Commit :

- `bd176d7 UCG-ARC-003: standardize API contracts and errors`

### UCG-ARC-004 — Qualité, tests automatisés et CI

- Larastan 3 et PHPStan 2 configurés au niveau 8, sans baseline ni erreur ignorée.
- Scripts Composer ajoutés pour Pint, Larastan et PHPUnit avec rapport JUnit.
- Vitest, Testing Library et jsdom ajoutés pour les tests frontend React.
- Playwright configuré avec Chromium et un smoke E2E Laravel → React.
- Workflows GitHub Actions backend et frontend déclenchés sur chaque push et
  pull request, avec échecs bloquants.
- Rapports PHPUnit, Vitest et Playwright conservés 14 jours comme artefacts CI.
- Commandes locales documentées dans `README.md`.
- Validation locale : Composer valide, Pint réussi, Larastan sans erreur,
  20 tests backend et 151 assertions réussis, lint frontend réussi, 2 tests
  Vitest réussis, build Vite réussi et 1 test Playwright réussi.

L’implémentation est présente dans l’arbre de travail et n’a pas été commitée,
conformément à la demande.

## Décisions à préserver

- Conserver les migrations dans `back/database/migrations/`.
- Conserver les modèles et la logique métier dans les modules concernés.
- Respecter MVC avec Services et Repositories explicites.
- Ne pas placer de logique métier dans les contrôleurs.
- Valider avec des Form Requests et autoriser avec des Policies ou Gates.
- Respecter les conventions API existantes pour toutes les réponses JSON.
- Maintenir PHPStan au niveau 8 sans baseline et conserver les contrôles CI
  backend/frontend bloquants.
- Conserver les rapports PHPUnit, Vitest et Playwright comme artefacts CI.
- Prévenir les requêtes N+1 et prévoir pagination, index et files d'attente
  lorsque le volume le justifie.
- Ne pas déplacer les migrations dans `Modules/` sans nouvelle décision
  d'architecture documentée.

## Prochaine étape : UCG-ARC-005

Préparer Redis, les queues, le temps réel et le stockage conformément au backlog :

- Redis et Horizon ;
- Reverb et Echo ;
- abstraction de stockage compatible S3 ;
- files de jobs séparées et configuration locale de remplacement ;
- propagation explicite du contexte tenant dans les traitements concernés ;
- vérifications garantissant qu’aucun artefact critique ne dépend uniquement
  de Redis.

## Documents de référence

- `README.md` : installation et démarrage.
- `docs/cahier-des-charges-ucg-multitenant.md` : besoins fonctionnels.
- `docs/backlog-technique-ucg-multitenant.md` : ordre et contenu des tickets.
- `docs/architecture-modulaire.md` : modules et dépendances.
- `docs/conventions-api.md` : réponses et erreurs API.

## Reprise dans une nouvelle discussion

Message conseillé :

> Lis les instructions `AGENTS.md`, puis `README.md`,
> `docs/etat-projet.md`, `docs/backlog-technique-ucg-multitenant.md` et les
> derniers commits Git. Vérifie l'état réel du dépôt, puis reprends au prochain
> ticket non terminé.

Le code, Git et les tests priment en cas d'écart avec ce document. Corriger
ensuite ce fichier pour qu'il reflète la réalité.

## Clôture d'une étape

1. Exécuter les tests et contrôles pertinents.
2. Vérifier `git status` et les changements effectués.
3. Mettre à jour le point de reprise, le travail terminé, les décisions et la
   prochaine étape.
4. Mentionner les validations, les blocages et les éléments restant à faire.
5. Commiter ce fichier avec le code concerné, puis pousser selon le workflow
   convenu avec l'utilisateur.
