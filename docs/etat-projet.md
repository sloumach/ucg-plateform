# État du projet UltraTools

> Document de transmission entre les discussions Codex. À lire au début d'une
> nouvelle tâche et à mettre à jour après chaque étape significative.

## Point de reprise

- Dernière mise à jour : 10 octobre 2026.
- Branche de travail : `staging`.
- Dernier ticket implémenté et validé localement : `UCG-TEN-004` — isolation dans PostgreSQL.
- Prochain ticket dans la séquence Tenancy : `UCG-TEN-005` — fichiers, caches, verrous et quotas.
- CI de `TEN-001`, `TEN-002` et `TEN-003` confirmées réussies après push.
- `TEN-004` : push utilisateur et validation CI distante restent à effectuer.
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
  supplémentaire ; exécution distante confirmée réussie après push.
- README et conventions API mis à jour avec les choix, limites et exemples.

### UCG-TEN-002 — Résolution et transport du contexte tenant

- Contrat public `TenantContext` immuable, avec UUID d’organisation, acteur
  authentifié, slug, fuseau, langue et UUID de corrélation.
- Middleware `tenant` placé après Sanctum et avant les bindings de ressources.
  Résolution depuis un UUID de route ou un hôte exact approuvé ; sources
  divergentes, inconnues ou non autorisées refusées.
- Liste opérateur `tenancy.approved_domains` vide par défaut, sans wildcard
  ni approbation automatique. Un domaine approuvé ne donne aucun droit.
- Aucun choix depuis un paramètre client, un en-tête, un cookie ou la session ;
  aucun tenant choisi implicitement, même pour un compte propriétaire unique.
- Accès propriétaire uniquement en attendant les adhésions de `TEN-003`.
  Le tenant doit être actif pour fournir un contexte métier.
- Endpoints Sanctum/Policy `GET /tenants/{uuid}/context` et
  `GET /tenant/context`, sous `/api/v1`, avec Resource et corrélation.
- Modification des paramètres existante placée sous `tenant:organization`.
  Le Service prend le contexte explicitement, puis revérifie le propriétaire
  et le statut sous verrou avant écriture.
- Binding non mémorisé, lié à la requête courante, avec vérification
  acteur/corrélation ; nettoyage du contexte et du champ de journalisation
  `tenant_id` dans un `finally`, y compris après erreur.
- Garde inter-contexte testée même lorsque l’acteur possède les deux tenants ;
  aucun identifiant de ressource ne peut déplacer le contexte déjà résolu.
- Transport explicite vers le `JobContext` existant, testé après sérialisation
  et une autre requête HTTP. Ce transport ne remplace pas l’autorisation
  différée ; la restauration contrôlée des jobs reste `TEN-006`.
- Les routes globales de gestion propriétaire, d’authentification et de statut
  restent accessibles sans contexte métier, selon leurs autorisations.
- Pas de nouvelle migration, dépendance ou interface React ; aucune
  modification du pilote ou des données locales existantes.
- Validation locale : 109 tests backend et 592 assertions, 82 tests Tenancy
  sur PostgreSQL et 417 assertions, Pint et PHPStan niveau 8 réussis,
  Composer valide, lint frontend, 12 tests Vitest, build et 1 test Playwright.
- Base PostgreSQL temporaire dédiée supprimée après les tests ; aucune
  suppression ni rollback dans la base applicative existante.
- Documentation de l’API, architecture, README et point de reprise mis à jour.
  Les workflows existants couvrent automatiquement les nouveaux tests.
- Commit local à pousser ; validation distante à vérifier après push.

### UCG-TEN-003 — Adhésions, invitations et changement d’organisation

- Tables Tenancy centralisées : adhésions datées, invitations et audits d’accès.
  Identités immuables, périodes valides, unicité organisation/compte et invitation
  pending par organisation/e-mail ; historique protégé contre réécriture/suppression.
- Multi-organisations par défaut ; politique globale d’exclusivité activable via
  `TENANCY_ALLOW_MULTIPLE_ORGANIZATIONS=false`. Contrôle des chevauchements à
  admission/réactivation/modification et création de propriété, compte verrouillé
  avant organisation/accès. Une suspension ne constitue pas un départ.
  Vérifier les conflits existants avant activation ; pas de révocation automatique.
- Propriété implicite inchangée, rôles minimaux `member`/`administrator`
  propres à chaque adhésion ; seul le propriétaire délègue l’administration.
  Aucun superadmin plateforme ou rôle métier détaillé ajouté implicitement.
- Invitations par e-mail vérifié : création, acceptation/refus, expiration et
  révocation ; départ volontaire et réadhésion sans compte/historique supprimé.
  Le parcours de création/vérification de nouveaux comptes reste Identity/IAM-001.
- Notifications e-mail après commit dans `notifications`, rendu échappé,
  contexte de corrélation explicite et retries. SMTP et worker requis pour une
  livraison externe ; le mailer local log ne livre pas d’e-mail réel.
  Une panne de mise en file après commit exige supervision/reprise explicite ;
  la boîte interne reste disponible et aucun accès n’est accordé par l’e-mail.
- Sélection serveur avec UUID de révision, confirmations et verrous de session.
  Droits réévalués côté serveur ; anciennes révisions refusées, accès perdu
  effacé. Le contexte HTTP reste issu d’une route/d’un domaine approuvé.
- Interface React intégrée : contexte visible dans l’en-tête, organisations et
  invitations paginées, administration conditionnée par permissions, statuts,
  rôles, périodes, départ et confirmation explicite de la cible.
  Anciennes données effacées immédiatement, requêtes annulées/réponses obsolètes
  ignorées, rechargement au retour de focus ; aucune donnée tenant dans localStorage.
- Dates d’entrée normalisées en UTC, session PostgreSQL fixée à UTC :
  correction vérifiée avec des dates ISO 8601 à décalage non nul. Aucune
  réécriture des historiques existants pour corriger le fuseau serveur.
- Ancien test de rollback TEN-001 adapté avec accord explicite de l’utilisateur :
  inverser TEN-003 avant ses dépendances, puis le réappliquer après celles-ci ;
  toutes les vérifications historiques conservées.
- Validation locale : 157 tests backend / 836 assertions ; 130 tests Tenancy
  PostgreSQL / 661 assertions ; Pint, PHPStan niveau 8 et Composer réussis ;
  lint, 21 tests Vitest, build et 2 tests Chromium réussis.
  Le nouveau parcours navigateur utilise des réponses API contrôlées ; les
  autorisations/persistance sont vérifiées séparément sur PostgreSQL réel.
- Migration additive appliquée à la base locale. Empreinte des comptes,
  organisations, paramètres et audits de cycle de vie identique avant/après ;
  aucun reset, rollback ou effacement de ces données. Bases temporaires de tests
  supprimées après vérification.
- README, conventions API, architecture, cahier des charges, backlog et point
  de reprise mis à jour. Push utilisateur puis contrôle des workflows requis.

### UCG-TEN-004 — Imposer l’isolation dans PostgreSQL

- Registre global fermé avec justification de chaque exception dans
  `TableOwnership` et l’architecture. Toute nouvelle table reste tenant-owned
  tant qu’une décision documentée ne la déclare pas globale. `organizations`
  est la racine du tenant ; les transports globaux ne rendent pas leurs
  payloads publics et ne livrent pas implicitement TEN-005/TEN-006.
- Contrôle en lecture seule `tenancy:check-schema` : existence de la racine,
  rattachement tenant non nul, FK racine, index tenant, unicités locales et
  relations tenant composites ; références hors schéma partagé refusées.
  Ce contrôle ne remplace pas les Policies, les filtres de lecture ou les
  tests de chaque règle métier.
- Nouvelle migration uniquement : clés uniques `(organization_id, id)` des
  adhésions/invitations, colonnes calculées du sujet des audits et FK composites.
  Les associations étrangères/inexistantes/du mauvais type échouent en SQL,
  de même que les actions non déclarées et la suppression d’un sujet audité.
  Le tenant des paramètres est désormais immuable en base.
- Historique prévalidé avant DDL, transaction et verrouillage PostgreSQL avec
  attente de cinq secondes. Aucune correction silencieuse d’un audit incohérent.
  Payloads préservés à l’upgrade/rollback, colonnes calculées masquées par le
  modèle et triggers append-only restaurés lors des reconstructions SQLite.
  L’ancien test de rollback a été adapté avec accord explicite de l’utilisateur,
  en conservant toutes ses vérifications précédentes.
- RLS évaluée et non activée : décision, rôles SQL, contextes transactionnels,
  connexions réutilisées, workers et parcours globaux documentés dans
  `architecture-modulaire.md`. La protection des lectures reste applicative.
- Validation locale : 204 tests backend réussis / 975 assertions, avec un test
  propre à PostgreSQL ignoré sur SQLite ; 178 tests PostgreSQL / 801 assertions
  réussis, également en ordre aléatoire. Pint, PHPStan niveau 8 et Composer
  réussis ; lint, 21 tests Vitest, build et 2 tests Chromium réussis.
  La CI PostgreSQL inclut désormais aussi les tests du contrôle de schéma.
- Migration additive appliquée à `ucg_platform` ; empreintes et nombres des
  comptes, organisations, paramètres et journaux existants identiques avant/après.
  Aucun reset, rollback ou effacement de la base principale.
  Les seules bases supprimées sont les cinq bases temporaires créées pour ces tests.
- README, architecture, cahier des charges, backlog et point de reprise mis à
  jour. Aucun nouvel endpoint, écran ou paquet ajouté. Sur une base contenant
  des millions d’audits, prévoir mesure et migration progressive dédiée avant
  déploiement ; aucun benchmark de ce volume n’est revendiqué.

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

## Prochaine étape : UCG-TEN-005

Après push de TEN-004 et validation CI, poursuivre l’isolation des canaux
techniques conformément au backlog :

- préfixes de stockage local/S3 propres à chaque tenant ;
- clés de cache Redis et verrous incluant l’organisation ;
- limites de débit et quotas techniques d’import, export et stockage ;
- tests avec les mêmes identifiants fonctionnels dans deux organisations,
  sans fichier/cache/verrou partagé ni impact silencieux sur leurs limites.

Le contexte HTTP de `TEN-002` et les contraintes SQL de `TEN-004` ne remplacent
pas l’isolation des fichiers/caches/verrous de `TEN-005`, ni la restauration
contrôlée des jobs de `TEN-006`. Le transfert renforcé de propriété,
les régularisations métier, la réactivation après archivage, la conservation/purge
et l’audit transverse restent dans leurs lots dédiés ; aucun écran React
organisations n’a été ajouté à `TEN-001` ou `TEN-002`.

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
