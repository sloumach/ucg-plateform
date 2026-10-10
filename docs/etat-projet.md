# État du projet UltraTools

> Document de transmission entre les discussions Codex. À lire au début d'une
> nouvelle tâche et à mettre à jour après chaque étape significative.

## Point de reprise

- Dernière mise à jour : 10 octobre 2026.
- Branche de travail : `staging`.
- Dernier ticket implémenté et validé localement : `UCG-TEN-001` — organisations et paramètres.
- Prochain ticket : `UCG-TEN-002` — résolution et transport du contexte tenant.
- La validation distante du nouveau contrôle PostgreSQL reste à confirmer après push.
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

Commit :

- `8309cb9 feat(ARC-004): add automated quality and testing workflows`

Les workflows `Backend quality` et `Frontend quality` associés à ce commit ont
été validés sur GitHub Actions.

### UCG-ARC-005 — Redis, queues, temps réel et stockage

- Predis, Horizon 5.50, Reverb 1.12 et l’adaptateur Flysystem S3 installés.
- Mode local sans Redis conservé : queue/cache en base, broadcasting en log et
  fichiers privés sur disque local.
- Queues `critical`, `default`, `notifications`, `broadcasts` et `reports`
  séparées, avec supervisors Horizon et timeouts cohérents avec `retry_after`.
- Client Echo React paresseux configuré pour Reverb et l’autorisation Sanctum.
- Canal privé utilisateur protégé ; un utilisateur ne peut pas autoriser le
  canal d’un autre utilisateur.
- Tableau Horizon fermé par défaut hors environnement local et limité à une
  liste d’adresses e-mail configurée.
- Contrat `ArtifactStorage` indépendant du fournisseur, implémentation Laravel
  compatible disque local/S3 et préfixage obligatoire par tenant.
- `JobContext` ajouté pour transporter explicitement les identifiants de
  requête et de tenant dans les jobs concernés.
- Cache de repli Redis vers base configuré ; aucun artefact durable n’est
  stocké uniquement dans Redis.
- Validation locale : Composer valide et sans avis de sécurité, Pint réussi,
  Larastan sans erreur, 27 tests backend et 167 assertions réussis, npm sans
  vulnérabilité, lint frontend réussi, 3 tests Vitest réussis, build Vite
  réussi et 1 test Playwright réussi.

Commit :

- `0580629 UCG-ARC-005: prepare queues realtime and storage infrastructure`

### UCG-ARC-006 — Interface partagée et traductions

- Catalogue français typé ajouté côté React ; les textes importants de l’écran
  de fondation ne sont plus dispersés dans les composants.
- Messages API, validation de connexion et notification de déconnexion
  centralisés dans les catalogues Laravel français.
- Composants communs ajoutés pour boutons, champs et erreurs accessibles,
  alertes persistantes, chargement, état vide, erreur et nouvelle tentative.
- Système de notifications partagé ajouté avec les tons `success`, `error`,
  `info` et `warning`, déduplication, fermeture accessible et conservation des
  erreurs importantes jusqu’à leur fermeture.
- Tableau accessible et pagination bornée ajoutés pour les futurs écrans métier.
- Le client API conserve désormais tous les messages de champ, le code stable,
  le statut HTTP et `request_id` ; le premier champ invalide reçoit le focus.
- Navigation clavier du formulaire vérifiée par Playwright.
- Contrastes principaux vérifiés : le rapport minimal mesuré est de `7.87:1`,
  supérieur au niveau WCAG AA attendu pour le texte normal.
- Validation locale : Pint réussi, Larastan/PHPStan sans erreur, 27 tests
  backend et 175 assertions réussis, lint frontend sans avertissement, 12 tests
  Vitest réussis, build Vite réussi et 1 test Playwright réussi.

### UCG-TEN-001 — Organisations et paramètres

- Module Tenancy implémenté avec Repository, Service transactionnel, DTO,
  Form Requests, Policy, Resource et commandes opérateur.
- Tables `organizations`, `organization_settings` et
  `organization_lifecycle_events` ajoutées par des migrations réversibles.
- UUID/slug immuables et propriétaire protégé contre une modification ordinaire,
  y compris par SQL direct ; propriétaire référencé dans les comptes globaux.
- Paramètres validés : fuseau IANA, langue française installée, pays ISO alpha-2,
  premier jour de semaine et format de date bornés.
- Cycle de vie explicite : actif vers suspendu/en clôture, suspendu vers
  actif/en clôture, en clôture vers archivé ; aucun retour depuis archivé.
- Créations et changements de statut audités dans la même transaction,
  avec acteur, motif, ancien/nouveau statut, horodatage et corrélation.
  Le journal refuse les mises à jour/suppressions par Eloquent et en base.
- API propriétaire uniquement : liste paginée, détail et remplacement complet
  des paramètres ; refus `404` pour une autre organisation et `409`
  pour les modifications ordinaires d’une organisation non active.
- Création et transitions réservées aux commandes opérateur du serveur,
  sans endpoint plateforme prématuré ni attribution de superadmin au propriétaire.
- Seed local UCG idempotent, sans réinitialisation d’un compte ou de paramètres
  existants ; aucun compte de démonstration créé en production.
- Migrations appliquées sur la base PostgreSQL locale ; pilote UCG actif créé
  avec un audit de création. `UTC/fr/FR` sont des valeurs de développement.
- Validation locale : 82 tests backend et 422 assertions, 55 tests Tenancy sur
  PostgreSQL et 247 assertions, Pint et PHPStan niveau 8 réussis, Composer valide,
  lint frontend réussi, 12 tests Vitest, build Vite et 1 test Playwright réussis.
- Le premier essai Playwright sous le compte Windows isolé ne pouvait pas
  accéder au navigateur installé ; la relance avec accès autorisé a réussi.
- Les tests PostgreSQL ont utilisé une base temporaire dédiée, supprimée ensuite,
  sans rollback ni suppression dans la base locale existante.
- CI backend enrichie d’une base PostgreSQL 17 dédiée et d’un rapport JUnit
  supplémentaire ; exécution distante à vérifier après push.
- README et conventions API mis à jour avec les choix, limites et exemples.

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
- Exécuter Horizon sur Linux/WSL ou en production ; sous Windows, conserver le
  worker `database` et ignorer uniquement `ext-pcntl`/`ext-posix` à
  l’installation Composer.
- Garder Redis comme transport/cache remplaçable et la base ou le stockage
  local/S3 comme source durable.
- Conserver l’autorisation des canaux privés sous `auth:sanctum`. Les canaux
  tenant-aware complets seront ajoutés après les modèles et policies Tenancy.
- Conserver les textes d’interface React dans `front/src/i18n/fr.ts` et les
  messages backend dans les catalogues `back/lang/fr/`.
- Afficher les erreurs de validation près des champs, les incidents importants
  dans une alerte persistante et les retours d’actions dans le système de
  notifications partagé.
- Piloter le comportement frontend avec le code d’erreur API stable, jamais en
  analysant le texte localisé ; conserver `request_id` pour le support.

## Prochaine étape : UCG-TEN-002

Résoudre et transporter le contexte tenant conformément au backlog :

- middleware et `TenantContext` immuable ;
- résolution depuis une route ou un domaine approuvé, refus par défaut ;
- requête métier sans tenant valide refusée ;
- empêcher toute sortie du contexte autorisé par modification d’identifiants ;
- appliquer le statut de l’organisation aux opérations métier futures.

Ne pas confondre le filtrage propriétaire de TEN-001 avec le contexte tenant
complet. Les adhésions/invitations arrivent dans TEN-003 et l’isolation PostgreSQL
généralisée dans TEN-004. Le transfert renforcé de propriété, les régularisations
métier, la réactivation après archivage, la conservation/purge et l’audit transverse
restent à réaliser dans leurs lots dédiés ; aucun écran React organisations
n’a été ajouté à TEN-001.

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
