# État du projet UltraTools

> Document de transmission entre les discussions Codex. À lire au début d'une
> nouvelle tâche et à mettre à jour après chaque étape significative.

## Point de reprise

- Dernière mise à jour : 12 octobre 2026.
- Périmètre sportif : organisation de tournois retirée des documents ; suivi
  interne des compétitions externes conservé. Voir la décision de périmètre ci-dessous.
- Branche de travail : `staging`.
- Dernier ticket traité et validé localement : `UCG-HUM-004` — affectations
  datées, indisponibilité, départ formel et suivi des équipements/obligations.
- IAM-006 et son correctif `0b85252` poussés ; push, migration et CI validés
  par l’utilisateur dans cette discussion. Migration confirmée en lecture seule :
  `Ran`, batch 9. L’échec initial `38174777644` reste documenté ci-dessous.
- HUM-001 poussé (`d9a4066`) par l’utilisateur ; `staging` et `origin/staging`
  concordent. Migration appliquée par l’utilisateur, confirmée en lecture seule :
  `Ran`, batch 10. Aucune migration ni donnée métier réelle créée par l’agent.
- CI HUM-001 du commit `d9a4066` vérifiées vertes sur GitHub : backend
  `38180615309`, frontend `38180615233`. Documentation `35fe2bf` poussée ensuite ;
  push et CI de ces modifications confirmés par l’utilisateur avant HUM-002.
- HUM-002 (`3c799ff`) poussé et migré par l’utilisateur ; branche synchronisée
  avec `origin/staging` avant HUM-003. Migration confirmée en lecture seule :
  `Ran`, batch 11. CI validée par l’utilisateur le 12 octobre 2026.
- HUM-003 (`2382db8`) poussé ; CI validées par l’utilisateur avant HUM-004.
  `staging` et `origin/staging` concordaient au début de HUM-004.
- Prochaine action : push utilisateur HUM-004, validation des CI, sauvegarde
  puis migration depuis `back/` (`php artisan migrate`). Migration additive
  `2026_10_12_173809_create_person_lifecycle_tables` vérifiée `Pending` en lecture
  seule. Code, tests et docs sont dans le même commit. Aucun départ, personne,
  profil ou compte externe réel créé/modifié par l’agent ; aucune migration
  appliquée sur la base principale et aucun e-mail envoyé.
- Format de commit convenu : `UCG-ID: description`, sans préfixe `feat(...)`.
- Accord utilisateur : un seul commit de tâche regroupe code, tests et docs de
  reprise. Aucun commit documentaire séparé après la tâche ; les confirmations
  push/migration/CI ultérieures sont intégrées aux docs du prochain travail.
- IAM-005 poussé avec le renommage `70440a6` puis fusion `63b8626` ; CI vertes
  confirmées par l’utilisateur (backend `38171624409`, frontend `38171624442`).
  Migration IAM-005 `Ran`, batch 8, vérifiée en lecture seule.
- IAM-004 poussé (`7bfd765`) ; CI backend/frontend vertes vérifiées
  (runs `38163603035` et `38163602940`).
- IAM-003 (`f78e0cd`), ajustement du périmètre (`f9ea660`) et correctif mobile
  `72fb380` poussés. CI backend/frontend du dernier commit confirmées vertes
  (runs `38155883372` et `38155883213`), y compris Playwright.
- IAM-002 poussé (`d61c131`) ; CI backend/frontend vérifiées vertes via GitHub.
- Après push/CI/migration HUM-004, prochain ticket P0 : `UCG-HUM-005`
  (import CSV privé, prévisualisation, confirmation et reprise idempotente).
  `UCG-IAM-007` (tokens API) et `UCG-TEN-008` (branding/domaines) restent P1.
- CI de `TEN-001`, `TEN-002` et `TEN-003` confirmées réussies après push.
- `TEN-004` : push utilisateur effectué ; CI backend et frontend confirmées réussies
  pour `4f7854b` (runs `38065130499` et `38065130513`).
- TEN-005 poussé (`86569b1`) : CI backend et frontend confirmées réussies
  (runs `38077705674` et `38077705642`), dont le test Redis réel de TEN-005.
- TEN-006 poussé (`4269a6a`) : CI backend et frontend confirmées réussies
  (runs `38080998770` et `38080998784`), dont le nouveau test de queue Redis.
- TEN-007 : migration additive appliquée, table de suivi vide ; aucune opération
  métier de maintenance exécutée sur les données actuelles. Push/CI à confirmer.
- IAM-001 poussé (`98f2ed3`) : CI confirmées vertes par l’utilisateur.
- IAM-001/IAM-002/IAM-003 : migrations appliquées par l’utilisateur sur
  `ucg_platform`, statut `Ran`, batch 7 confirmé en lecture seule. IAM-004 n’ajoute
  aucune migration. IAM-005 est `Ran`, batch 8 ; IAM-006 est `Ran`, batch 9 ;
  HUM-001 est `Ran`, batch 10, après migration par l’utilisateur.
  Aucun e-mail réel envoyé.
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

## Travail terminé : UCG-TEN-005

Implémentation démarrée après push et validation CI de TEN-004 :

- préfixes de stockage local/S3 propres à chaque tenant ;
- clés de cache Redis et verrous incluant l’organisation ;
- limites de débit et quotas techniques d’import, export et stockage ;
- tests avec les mêmes identifiants fonctionnels dans deux organisations,
  sans fichier/cache/verrou partagé ni impact silencieux sur leurs limites.

Livré :

- ports publics `TenantArtifacts`, `TenantCache`, `TenantResourceLimits` et
  réautorisation du contexte courant pour fichiers/caches ;
- fichiers privés immuables préfixés tenant, noms/chemins sûrs, isolation des
  lectures/suppressions et disque enregistré par artefact ;
- compteur durable/réservations dans `tenant_storage_usage` et `tenant_artifacts`,
  migration additive `2026_10_10_160242_create_tenant_artifact_ledger_tables.php`,
  FK tenant, index, immutabilité de l’organisation et volumes non négatifs ;
- refus conservateur des dépassements/pannes : aucun écrasement d’un ancien
  fichier, aucune libération de quota avant suppression confirmée ;
- cache/verrous/débit à clés canoniques distinctes ; correction du conflit de
  verrou database qui invalidait une transaction PostgreSQL ;
- limites opérateur configurables par UUID et contrat `429` existant avec
  `Retry-After` pour le débit ; pas de plan commercial ;
- documentation d’exploitation, gardes d’import/export disponibles pour les
  futurs Services métier, sans endpoint artificiel.

Vérifications de clôture :

- suite backend SQLite : 265 tests, 263 réussis, 1 124 assertions, 2 skips
  explicites (contrainte spécifique PostgreSQL et Redis réel absent localement) ;
- PostgreSQL 17 sur base temporaire isolée : 226 tests, 225 réussis,
  936 assertions, 1 skip Redis ; suite Tenancy et architecture SQL complète,
  sans exclusion, dans un ordre aléatoire (seed `10010`) ;
- test historique de rollback adapté avec l’accord utilisateur : TEN-005 inversé
  avant les anciennes migrations et réappliqué après elles ; toutes les assertions
  existantes conservées, existence des deux nouvelles tables vérifiée ;
- Pint et Larastan niveau 8 réussis ; frontend Vitest : 21 tests réussis ;
- Redis inaccessible en local et moteur Docker arrêté : test réel ajouté à la
  CI avec service Redis dédié, puis validé après push (run `38077705674`) ;
- stockage S3 testé via son contrat/adaptateur simulé, pas d’accès AWS réel ;
- seule la migration additive TEN-005 appliquée à `ucg_platform` : empreintes
  SHA-256 et nombres de lignes des sept tables existantes identiques avant/après,
  deux nouvelles tables vides, aucun fichier privé local à migrer ;
- contrôle `tenancy:check-schema` réussi ; bases temporaires de vérification
  supprimées après les tests, sans rollback ni réinitialisation de la base principale.

Validation distante TEN-005 : push utilisateur et CI backend/frontend réussis.
Ne jamais exécuter `migrate:fresh`/rollback sur la base principale.

Le contexte HTTP de `TEN-002` et les contraintes SQL de `TEN-004` ne remplacent
pas l’isolation des fichiers/caches/verrous de `TEN-005`, ni la restauration
contrôlée des jobs de `TEN-006`. Le transfert renforcé de propriété,
les régularisations métier et l’audit transverse restent dans leurs lots dédiés.
TEN-007 ajoute désormais récupération conservatrice et purge ciblée décrites
ci-dessous, sans livrer l’effacement complet OPS-007 ; aucun écran React
organisations n’a été ajouté à `TEN-001` ou `TEN-002`.

## Travail terminé localement : UCG-TEN-006

- Contrat public `TenantJob` et middleware final : transport explicite des IDs
  tenant/acteur/corrélation ; statut, accès et rôles rechargés à chaque tentative.
  Aucun droit conservé dans le payload ou déduit de la sélection HTTP/logs.
- Contexte worker scoped et restauré dans un `finally`, y compris après erreur
  ou dispatch synchrone imbriqué ; refus permanents dans `failed_jobs`, pannes
  transitoires retentées. Délai et reprise manuelle conservent le tenant d’origine.
- Invitations e-mail raccordées : acteur expéditeur explicite et administration
  revérifiée avant envoi. Examiner les anciens payloads sans acteur avant
  déploiement : ils sont refusés, jamais attribués au propriétaire implicitement.
  Aucune ancienne invitation n’était en attente dans la queue database locale.
- Tâches système derrière `TenantScheduledTask` et registre serveur fermé,
  vide par défaut ; pas de tâche métier fictive, d’endpoint ni d’acteur propriétaire.
  Commande opérateur, scheduler multi-nœuds et distribution par pages de 200
  tenants actifs, curseur UUID, jobs indépendants et continuation en file.
- Verrous d’exécution par tenant/tâche, verrou de distribution par page/run,
  checkpoints de distribution et marqueurs de succès de 24 h : les retries
  évitent les redistributions connues, sans promettre exactly-once. Les futurs
  handlers doivent être idempotents durablement et revérifier leurs règles métier.
- Supervision corrélée, retries/backoffs et exploitation documentés dans README.
  Queues database/Redis durables seulement ; cache partagé sans failover pour
  les gardes. Les timeouts d’arrêt dur exigent Linux/PCNTL, non testés sous Windows.

Vérifications de clôture :

- Suite backend SQLite, ordre aléatoire (seed `10026`) : 305 tests, 302 réussis,
  1 264 assertions ; 3 skips explicites (test PostgreSQL et deux tests Redis réel).
- PostgreSQL 17.5, Tenancy et architecture SQL complètes, ordre aléatoire
  (seed `10026`) : 266 tests, 264 réussis, 1 076 assertions ; 2 skips Redis réel.
  Les anciens tests, dont le rollback, sont conservés et exécutés sans exclusion.
- Pint et PHPStan niveau 8 réussis ; frontend : 21 tests Vitest réussis,
  lint et build réussis. Aucune interface ou dépendance ajoutée.
- Test d’intégration queue Redis/retry ajouté au service Redis de la CI backend ;
  validation distante confirmée après push TEN-006 (backend `38080998770`).
- Contrôle `tenancy:check-schema` réussi ; scheduler sans handler métier enregistré.
  Aucune migration, reset ou consommation de queue dans la base principale.
  Empreintes SHA-256 et nombres de lignes des sept tables historiques identiques
  avant/après ; ledger stockage et queue database toujours vides.
- Seules les trois bases temporaires de tests `ucg_ten006_verify_a`,
  `ucg_ten006_verify_b` et `ucg_ten006_verify_c` ont été supprimées après vérification.
  Elles sont recréables ; aucune donnée applicative n’a été effacée.
- README, architecture, backlog et point de reprise mis à jour. Commit `4269a6a`
  poussé ; CI backend/frontend verte avant le démarrage de TEN-007.

## Socle terminé localement : UCG-TEN-007

- Commandes opérateur validées et couches Service/Repository/ports dédiées,
  sans endpoint de maintenance ni rôle plateforme inventé. L’acteur vérifié
  reste déclaré par un opérateur disposant d’un accès autorisé au serveur.
- Export asynchrone `reports`, suivi par UUID tenant/opération/corrélation,
  catalogue fermé des tables/colonnes et fichiers enregistrés TEN-005. Comptes,
  sessions, jetons globaux et queues exclus. Un ajout de schéma non enregistré,
  un fichier incomplet ou une limite dépassée empêche l’état `ready`.
- Archive privée NDJSON version 1, lecture vérifiée taille/SHA-256, snapshot
  PostgreSQL et pagination. Limites : 50 000 lignes, 64 MiB par archive, 128 MiB
  réservés par tenant ; expiration technique à 24 h depuis la demande, distincte
  de la conservation métier. Pas de dump de fichiers non enregistrés.
- Nettoyage ciblé des archives échues, borné et tolérant à l’échec d’un tenant.
  Scheduler désactivé par défaut ; activation explicite avec cache partagé.
  Panne de dispatch : opération `pending` conservée, UUID dans les logs,
  redistribution explicite possible ; aucun reset global de queue ou stockage.
- Purge : inventaire sans écriture par défaut. Politique serveur vide donc
  refus de toute purge métier. Exécution uniquement après archivage, confirmation
  exacte, export intègre généré après le dernier archivage et politique complète
  revue par UUID/date/catégories/référence de sauvegardes. Lots reprenables,
  politique figée par empreinte, comptabilité libérée après suppression confirmée.
- Les seules catégories supprimables dans ce socle sont paramètres, artefacts
  et exports. Racine, comptes, adhésions/invitations, historiques protégés et
  suivi restent conservés ; aucun trigger ou FK désactivé. **Pas de preuve
  d’effacement physique complet** : matrice de durées et effacement des
  historiques/sauvegardes/réplicas restent CAD-004/OPS-007.
- Récupération distincte de données conservées : `archived` vers `suspended`,
  export intègre, paramètres présents, aucune purge engagée et propriétaire
  vérifié/admission compatible avec l’exclusivité TEN-003. Audit transactionnel,
  aucun remplacement de données ou reprise implicite de jobs. Matrice des
  transitions ordinaires inchangée. La procédure après perte est documentée,
  mais l’import/restauration de sauvegardes et l’exercice réel restent OPS-005.
- Échéances de travail souples et verrous par tenant ; arrêter les workers
  sous Linux/PCNTL en production, avec délais DB/I/O sous le bail. Aucun arrêt
  dur ni accès AWS réel validé sous Windows.

Vérifications de clôture :

- 37 nouveaux cas export/maintenance réussis (236 assertions), incluant queue
  database réelle, tenant inactif, isolation avec propriétaire partagé, intégrité,
  sources absentes, limites, transport indisponible, expiration, refus de purge,
  101 fichiers en lots reprenables, dérive de politique, panne de suppression,
  récupération et contraintes SQL. Disque S3 simulé seulement.
- Suite backend SQLite aléatoire (seed `10037`) : 342 tests, 339 réussis,
  1 501 assertions et 3 skips explicites (PostgreSQL et deux tests Redis réel).
- PostgreSQL 17.5, suite Tenancy/architecture SQL complète aléatoire (même seed) :
  303 tests, 301 réussis, 1 313 assertions, 2 skips Redis réel. Aucun ancien test
  exclu : test de rollback adapté avec l’accord explicite de l’utilisateur,
  inversion/réapplication TEN-007 et toutes les anciennes assertions conservées.
- Pint et PHPStan niveau 8 réussis ; frontend : 21 tests Vitest, lint et build
  réussis. Aucune interface ni dépendance ajoutée. CI après push à confirmer.
- Migration additive TEN-007 appliquée seule dans `ucg_platform` ; contrôle
  `tenancy:check-schema` réussi. Table d’opérations et queue vides. Comptages et
  empreintes SHA-256 des neuf tables préexistantes identiques avant/après ;
  stockage privé actuel sans fichier métier. Aucun export, purge, nettoyage,
  changement de statut, reset ou consommation de queue sur la base principale.
- Bases temporaires PostgreSQL de test `ucg_ten007_verify_a`,
  `ucg_ten007_verify_b` et `ucg_ten007_verify_c` supprimées après vérification ; recréables, sans perte
  de données applicatives. README, architecture, CDC, backlog et transmission
  mis à jour. Commit local à pousser par l’utilisateur.

## Travail terminé localement : UCG-IAM-001

- Connexion/déconnexion Sanctum conservées avec rotation et invalidation de
  session ; limitation à cinq tentatives par minute et restitution de
  `Retry-After` dans l’enveloppe JSON `429`.
- Récupération non énumérante avec jeton Laravel expirant, formulaire React,
  règle de mot de passe fort et révocation de toutes les anciennes sessions.
- Vérification d’e-mail par lien temporaire signé, renvoi borné et interface
  dédiée pour les comptes non vérifiés.
- Révocation des autres appareils après confirmation du mot de passe actuel.
- Journal global `identity_security_events`, indexé et append-only en SQLite et
  PostgreSQL. Les adresses demandées y sont pseudonymisées par HMAC ; aucun mot
  de passe, jeton ou contenu d’e-mail n’est journalisé.
- Notifications e-mail françaises et liens construits depuis `FRONTEND_URL` ;
  purge des jetons expirés planifiée toutes les quinze minutes.
- Validation : suite backend SQLite complète, 351 tests dont 348 réussis,
  1 564 assertions et 3 skips attendus ; PHPStan niveau 8 et Pint réussis.
  Vérification PostgreSQL ciblée : 26 tests et 153 assertions. Frontend :
  24 tests Vitest, lint et build réussis.
- Les bases PostgreSQL temporaires `ucg_iam001_verify_20261010` et
  `ucg_iam001_verify_final_20261010` ont été supprimées.
  La migration IAM-001 n’a pas été appliquée à la base principale.
  Commit `98f2ed3` poussé ; CI confirmées vertes par l’utilisateur.

### UCG-IAM-002 — MFA des comptes privilégiés

- TOTP compatible avec les applications d’authentification, clé chiffrée en base,
  confirmation obligatoire et préparation d’enrôlement expirant après dix minutes.
  Dix codes de récupération à usage unique, conservés uniquement sous forme de hash,
  sont affichés une seule fois par le parcours React.
- MFA imposé aux propriétaires, administrateurs effectifs d’organisation et
  comptes autorisés à Horizon. Le contrôle se réévalue à chaque requête ; une
  session avec seulement le mot de passe ne peut pas accéder au métier,
  au broadcasting ni à la révocation des autres sessions.
- Limitation des tentatives, rejet du rejeu TOTP, consommation transactionnelle
  des codes et journal de sécurité. Le frontend affiche les délais `Retry-After`.
- Sessions liées à l’utilisateur, à la version MFA et au mot de passe courant.
  Activation, récupération, remplacement et régénération révoquent les anciennes
  sessions ; la session courante est renouvelée après validation.
  Les tests couvrent aussi les anciennes sessions sans marqueur et un reset
  concurrent du mot de passe pendant le challenge.
- Remplacement d’authentificateur après validation MFA, avec l’ancien facteur
  actif jusqu’à confirmation. Un compte privilégié ne peut pas désactiver le MFA ;
  un reset de mot de passe ne supprime ni le facteur ni les codes.
- Contrôles applicatifs ajoutés aux services d’administration et aux jobs
  privilégiés ; Horizon exige également une session MFA, y compris en local.
  Les ports publics conservent la frontière Identity/Tenancy.
- Interface française d’activation, challenge, récupération et gestion,
  erreurs accessibles et masquage du workspace tant que le MFA requis est incomplet.
  Aucun secret ou code de récupération conservé dans le stockage navigateur.
- Validation SQLite complète : 388 tests, dont 385 réussis, 1 762 assertions
  et 3 exclusions attendues. PostgreSQL : suite Tenancy/schéma/Identity de
  333 tests, dont 331 réussis, 1 524 assertions et 2 exclusions Redis attendues ;
  contrôle final IAM-001/IAM-002 sur base neuve : 38 tests, 248 assertions.
  Pint et PHPStan niveau 8 réussis. Frontend : 31 tests Vitest, lint, build
  et 3 tests Playwright réussis. Le test navigateur MFA simule l’API ;
  les tests PHP couvrent les transactions et requêtes réelles.
- Base temporaire `ucg_iam002_verify_20261011` supprimée après vérification.
  À cette étape, migrations IAM-001/IAM-002 en attente sur la base principale ;
  elles ont ensuite été appliquées par l’utilisateur, voir le point de reprise.
  Aucun enrôlement automatique, nouvelle dépendance ou e-mail externe.
  Une nouvelle installation doit appliquer les migrations additives avant usage.
  Un rollback IAM-002 supprimerait les facteurs : privilégier une correction
  en avant après un enrôlement réel.
- README, conventions API, architecture et backlog mis à jour.
  Workflow PostgreSQL enrichi avec les tests MFA et récupération IAM-001 ;
  validation distante IAM-002 à confirmer après push.

### UCG-IAM-003 — Rôles et permissions contextuels

- Catalogue fermé séparant actions sportives, administratives, financières et
  gouvernance. Rôles configurables par organisation, permissions explicites,
  statut actif/inactif, version UUID et clé immuable. Aucun rôle plateforme.
- Affectations tenant/compte/rôle/périmètre versionnées, période UTC et statuts
  actif/suspendu/révoqué. Délégation limitée aux droits, périmètres et périodes
  du gestionnaire ; auto-attribution et auto-escalade refusées. Le propriétaire
  reste seul gestionnaire des définitions, sans droits métier implicites.
- Suspension d’adhésion prioritaire ; départ/révocation révoquent ses affectations
  dans la transaction et l’audit. Une réadmission ne restaure pas les anciennes.
- Contrat public `TenantAuthorization`, Gate/Policy, Form Requests, DTO/Resources,
  Service/Repository. Relecture des droits en HTTP/services/jobs ; contexte
  périmé sans autorité, MFA global relié aux permissions privilégiées effectives.
- Organisation entière disponible. Équipe/jeu/cohorte/événement sont préparés,
  testés avec annuaire factice et refusés jusqu’au raccordement des ressources
  réelles par leurs modules ; IAM-004/005 restent distincts.
- Interface française paginée, confirmations du tenant, versions conservées,
  gestion accessible des erreurs/focus, lecture des seules affectations du membre.
  Exports complets enrichis sans données d’un autre tenant ni profil Identity privé.
- Migration additive, contraintes composites, identité protégée et sujets d’audit
  immuables. Retour arrière sans historique testé ; rollback refusé après audit.
  Aucune conversion d’adhésion, dépendance ajoutée ou migration sur `ucg_platform`.
- Validation SQLite complète : 423 tests, 420 réussis, 1 948 assertions,
  3 exclusions attendues. PostgreSQL 17 : 376 tests, 374 réussis, 1 747 assertions,
  2 exclusions Redis attendues. Seed aléatoire 10043. Pint et PHPStan niveau 8
  réussis. Frontend : 37 tests Vitest, lint, build et 4 tests Chromium réussis.
  Le navigateur IAM-003 simule l’API ; les tests PHP utilisent les bases réelles.
- Les suites backend doivent être exécutées successivement : leurs tests
  `Storage::fake` partagent le répertoire temporaire malgré les bases isolées.
  La dernière vérification PostgreSQL isolée est verte. La première exécution
  a aussi permis de corriger le test de rollback pour ses nouvelles dépendances.
- À cette étape, migrations IAM-001/002/003 en attente sur la base principale,
  contrôlées en lecture seule ; elles ont ensuite été appliquées par l’utilisateur.
  Base PostgreSQL temporaire supprimée après vérification.
  Workflow PostgreSQL enrichi avec IAM-003 ; README, API, architecture et backlog
  mis à jour. Les commits ont ensuite été poussés ; voir le correctif CI ci-dessous.

### Correctif CI IAM-003 — débordement des formulaires mobiles

- Journal du job Playwright `114506974660`, run `38151927266`, consulté via la
  connexion Git existante, sans enregistrer les identifiants. Échec à la ligne 63
  de `roles.spec.ts` : largeur du document supérieure au viewport de 375 px.
  Le parcours de création/confirmation réussissait, les trois autres E2E aussi.
- Défaut reproduit sous Windows en remplissant les champs de date avec secondes.
  Les largeurs intrinsèques des contrôles et les colonnes implicites agrandissaient
  les formulaires. `FormField` et les grilles de rôles utilisent maintenant une
  colonne explicite qui peut rétrécir, avec `min-w-0` sur les champs/conteneurs.
  Le débordement n’est pas masqué et aucune assertion n’a été retirée.
- Test existant renforcé : dates saisies et conservées, absence de débordement
  à 375 et 320 px. Validation : 37 tests Vitest, build, lint et 4 tests Chromium
  avec `CI=true` (un worker, serveurs de test dédiés) réussis sous Windows.
  Le runner Linux GitHub est confirmé vert après push de `72fb380`.
- Le premier contrôle `migrate:status` indiquait Pending ; l’utilisateur a ensuite
  exécuté `migrate`. Le contrôle en lecture seule confirme désormais IAM-001/002/003
  `Ran`, batch 7, sur `ucg_platform`.

### UCG-IAM-004 — Policies et projection des champs

- Contrat public `TenantDataVisibility` et DTOs `TenantDataRecord`/`FieldVisibility`.
  Le module propriétaire déclare son schéma dans le code ; les champs inconnus ou
  interdits sont omis récursivement. Une structure sans schéma enfant est refusée.
- Autorisation d’action conservée dans les Policies/Gate existants, projection
  fondée sur `TenantAuthorization` actuel, tenant et périmètre vérifiés. Les droits
  propriétaire/admin et d’écriture ne donnent pas implicitement la lecture métier.
  MFA, révocation, suspension et périodes sont ceux d’IAM-003.
- API rôles raccordée avant Resource, même contrat JSON et statuts 201/200,
  notifications, pagination et request ID. Le mail d’invitation utilise désormais
  `invitations.manage` au traitement : délégué autorisé accepté, grant révoqué refusé.
- Pages/chunks limités à 200 records, contrôles partagés pendant un seul appel,
  aucun cache durable de décisions. Requêtes comparées pour 1 et 100 records.
- Validation SQLite complète : 455 tests, 452 réussis, 1 998 assertions,
  3 exclusions attendues. PostgreSQL 17 : 408 tests, 406 réussis, 1 797 assertions,
  2 exclusions Redis attendues. Base `ucg_iam004_verify_20261011` créée pour ces
  tests puis supprimée. Pint et PHPStan niveau 8 réussis. Workflow PostgreSQL
  enrichi avec les tests de projection ; frontend et dépendances inchangés.
- Limite explicite : modules métier, recherches, exports et notifications métier
  futurs doivent raccorder ce port avant livraison, après sélection des records
  autorisés et contrôle des champs recherchables/triables. Aucun parcours métier
  fictif n’est créé. La sauvegarde opérateur complète TEN-007 conserve ses contrôles
  opérateur serveur avec acteur vérifié et son catalogue fermé, sans projection
  partielle. IAM-005 refuse désormais les acteurs techniques actifs.
- Aucune migration IAM-004 ni écriture sur la base principale. Migrations précédentes
  appliquées par l’utilisateur et vérifiées en lecture seule. README, API,
  architecture et backlog actualisés ; commit `7bfd765` poussé et CI backend/frontend
  vertes (runs `38163603035` et `38163602940`).

### UCG-IAM-005 — Administration plateforme et support

- Identity possède les rôles globaux `superadmin`/`support` et leur audit avant/après.
  CLI `identity:platform-role`, bootstrap unique sans création automatique de compte,
  comptes vérifiés/MFA requis et dernier superadmin actif protégé. Version UUID
  renouvelée à chaque changement ; un ancien accès ne revit pas après réattribution.
- Tenancy possède les accès support et leurs événements. API authentifiée/MFA,
  Policy, Form Requests, DTO/Resources, Service et Repository. Tenant et opérateur
  figés, raison 10..500 caractères, durée 1..30 minutes, actions fermées et
  confirmation explicite. Maximum cinq accès actifs par tenant/opérateur.
- Deux diagnostics : statut/fuseau/langue et compteurs adhésions/rôles/affectations.
  Aucune donnée métier, aucun contexte tenant/impersonation. Expiration contrôlée
  à chaque requête sans scheduler ; ouverture/lecture/refus propre accès/révocation
  transactionnels. Audits append-only en Eloquent et SQL, FK composites tenant.
- Permissions métier refusées aux comptes techniques actifs même avec une
  affectation tenant. Sauvegardes complètes refusées aussi, y compris aux propriétaires
  techniques, avant mise en queue, traitement et lecture. Horizon demande superadmin
  actif, e-mail autorisé/vérifié et preuve MFA de session actuelle.
- Validation backend complète : 506 tests, 503 réussis, 3 exclusions attendues.
  PostgreSQL 17 : 459 tests, 457 réussis, 2 exclusions Redis attendues ;
  base temporaire `ucg_iam005_verify_20261011` supprimée après vérification.
  Retour arrière/réapplication de migrations testé avec le bon ordre des FK.
  Pint et PHPStan niveau 8 réussis. Frontend : lint, 37 tests Vitest, build et
  4 tests Chromium avec `CI=true` réussis ; ces E2E existants simulent l’API.
- Migration additive de quatre tables, index et gardes SQL, aucun backfill.
  `migrate:status` en lecture seule : nouvelle migration `Pending`, anciennes
  IAM-001/002/003 `Ran` batch 7. Aucune écriture sur `ucg_platform`, aucun rôle
  réel attribué et aucun e-mail réel. Après sauvegarde, l’utilisateur lance `migrate`.
  Le rollback refuse les accès/audits existants : préférer une correction en avant.
- Rôles globaux exclus des backups tenant ; seul l’historique support du tenant
  est inclus. Aucun écran React support, restauration, journal transverse ou
  politique de rétention ajouté. IAM-006 est le prochain ticket P0 après CI/migration.
  Workflow PostgreSQL et les documents existants actualisés ; push/CI à confirmer.

### UCG-IAM-006 — Journal transverse et conservation

- API globale réservée au superadmin, sources Identity uniquement ; API tenant
  avec permission privilégiée `audit.view` à l’échelle de l’organisation.
  Propriétaire/admin autorisés, comptes techniques refusés même propriétaires.
  Auth/session, e-mail vérifié, preuve MFA actuelle et Policies ; droits relus.
- Sources existantes réunies sans copie ni backfill. Resource fermée, motifs
  catégoriels, données privées et clés inconnues omises ; les anciens historiques
  ne sont pas réécrits. Nouveaux événements typés et états strictement validés.
- Lecture réussie tracée transactionnellement avant diffusion ; panne d’audit : 500
  sans données. Refus authentifiés 403/404 des routes tenant et du journal global
  tracés avec acteur/route serveur/code/request ID/IP, sans contenu de requête
  ni tenant demandé. Les invités/sessions invalidées ne passent pas par ce middleware.
- Pages 1..100, filtres fermés, curseur chiffré lié au compte/tenant/filtres ;
  ordre date/source/UUID, sans COUNT/OFFSET ni requêtes par événement.
  Nombre de requêtes constant testé avec 1 puis 101 événements.
- Décision utilisateur : conservation sans limite pour le moment. Pas de purge,
  scheduler ou exclusion automatique des anciens événements. CLI `audit:retention`
  en lecture seule ; configuration contradictoire refusée.
- Migration additive de deux tables avec FK restrictives, index et gardes SQL
  append-only/forme JSON. Rollback vide testé, rollback non vide refusé.
  Portabilité inclut uniquement les nouveaux événements de son tenant, jamais Identity.
- Validation SQLite complète : 546 tests, 543 réussis, 2 364 assertions,
  3 exclusions attendues. PostgreSQL 17 : 499 tests, 497 réussis, 2 163 assertions,
  2 exclusions Redis attendues ; base `ucg_iam006_verify_20261011` supprimée.
  Pint, PHPStan niveau 8, lint/build frontend, 38 tests Vitest et 4 tests Chromium
  avec `CI=true` réussis. Les E2E existants simulent l’API ; tests PHP sur bases réelles.
- Le premier passage PostgreSQL a corrigé le délimiteur SQL du test de panne d’audit ;
  la dernière exécution complète PostgreSQL est verte. Les suites backend sont
  exécutées successivement pour préserver leurs fichiers temporaires partagés.
- À la livraison initiale, base principale contrôlée en lecture seule : IAM-005
  `Ran` batch 8, IAM-006 `Pending`. Aucun rôle réel attribué, aucune migration/purge
  ni e-mail réel par l’agent. Push et migration ensuite effectués par l’utilisateur ;
  IAM-006 confirmée `Ran`, batch 9. Voir le correctif CI ci-dessous.
- API/CLI et permission dans l’interface de rôles ; aucun écran React de journal.
  Les modules métier futurs doivent raccorder le port typé dans la transaction de
  leur action, avec des états fermés. Surveiller capacité/index/backups et profiler
  avant optimisation ; aucun benchmark de millions de lignes.
  README, API, architecture, backlog et workflow PostgreSQL mis à jour.

### Correctif CI IAM-006 — isolation du comptage SQL

- Journal GitHub du job `114574756525`, run `38174777644` lu avec la connexion Git
  existante, sans affichage/enregistrement des identifiants. Pint/PHPStan verts ;
  échec SQLite : 46 requêtes sur petite page contre 44 sur grande page.
  Les étapes PostgreSQL/Redis n’ont pas été exécutées dans ce run.
- Cause reproduite localement avec `TENANCY_CACHE_STORE=database`, valeur de
  `.env.example` utilisée en CI. Le premier appel initialise le quota et exécute
  deux requêtes supplémentaires ; la vérification précédente utilisait le cache mémoire.
- Test conservé et renforcé : cas mémoire/base via DataProvider, temps figé,
  initialisation du quota avant les deux mesures, journal SQL vidé avant mesure.
  L’égalité stricte, les quatre sources bornées à 21 lignes, l’absence d’OFFSET,
  le curseur et la corrélation request ID restent vérifiés. Aucun quota désactivé,
  aucun changement applicatif, workflow, dépendance ou migration.
- Validation : suite SQLite complète avec cache tenant en base, 547 tests,
  544 réussis, 2 382 assertions, 3 exclusions attendues. Tests du journal :
  41/41, 168 assertions sur PostgreSQL 17 avec cache tenant en base ; base isolée
  `ucg_iam006_ci_fix_20261011` créée puis supprimée. Pint/PHPStan niveau 8 verts.
- Push/migration initiale confirmés par l’utilisateur ; contrôle en lecture seule
  IAM-006 `Ran` batch 9 sur `ucg_platform`. Aucun besoin de relancer migrate pour
  ce correctif. Correctif `0b85252` présent sur `staging`/`origin/staging` ;
  push et CI confirmés ensuite par l’utilisateur avant le lancement de HUM-001.

### UCG-HUM-001 — Personnes et lien facultatif au compte global

- Module `People` câblé : modèle tenant, factory de tests, statuts, repository,
  Service, DTO et port public `PeopleDirectory`, projection, Form Requests, Policy
  et API unitaire POST/GET/PATCH. Client TypeScript utilisant CSRF/révision tenant.
- Compte facultatif, une fiche liée par compte/tenant, fiches distinctes possibles
  dans plusieurs tenants. Aucun rapprochement global ni unicité de pseudonyme.
  Lien réservé à un compte vérifié déjà propriétaire/membre effectif du tenant ;
  aucun compte, accès ou rôle créé. Un déliage ne révoque pas l’adhésion.
- Pseudo/langue/fuseau/statut/biographie publique sous `sports.read/write` ;
  identité/coordonnées/biographie interne/lien compte sous `administrative.read/write`.
  Chaque catégorie soumise exige son droit d’écriture, y compris un null explicite.
  Lecture distincte de l’écriture, droits métier non implicites, comptes techniques
  refusés. Projection serveur IAM-004, champs privés absents sans autorisation.
- Identité, coordonnées et biographie interne chiffrées/masquées. Aucun champ
  de naissance, pièce d’identité ou coordonnées bancaires. Les biographies sont
  du texte à échapper ; aucune publication publique ou upload/photo dans ce lot.
- PATCH partiel avec version UUID ; champs omis conservés, conflit 409 si obsolète.
  Tenancy expose `TenantBusinessGuard` : transaction obligatoire, compte éventuel
  puis organisation verrouillés ; People revérifie les droits puis verrouille
  la fiche. Ressources filtrées par UUID + tenant, sans import de modèles voisins.
- Audit via port IAM-006 dans la transaction : action `domain.changed`, cible
  organisation et UUID de personne dans l’état fermé, statut/lien/version/noms
  des champs soumis ; aucune valeur privée. Une panne d’audit annule la mutation.
  Le schéma d’audit existant n’est pas réécrit.
- Migration additive `2026_10_11_215159_create_people_table` : table `people`,
  FK restrictives, unicités/index tenant, gardes SQL/Eloquent contre déplacement
  et suppression physique. Aucun backfill. Rollback vide testé ; rollback avec
  personnes refusé. Les statuts descriptifs ne révoquent aucun accès ; HUM-004
  devra implémenter le départ et ses obligations.
- Catalogue fermé de portabilité opérateur complété ; seuls les fiches/colonnes
  physiques du tenant sont exportées, ciphertext conservé. Garder les clés avec
  sauvegardes/rotation. Aucun import, restauration, export métier ni purge ajouté.
- Validation SQLite complète avec `TENANCY_CACHE_STORE=database` : 620 tests,
  617 réussis, 2 704 assertions, 3 exclusions attendues. PostgreSQL 17 : suite
  complète de 620 tests, 618 réussis, 2 705 assertions, 2 exclusions Redis attendues.
  Base `ucg_hum001_verify_20261011_220904` supprimée après succès.
  Le premier lancement PostgreSQL héritait du broadcasting local ; relancé avec
  les variables complètes de phpunit.xml et cache tenant database, sans correction
  des tests applicatifs ni modification de la configuration principale.
- Pint et PHPStan niveau 8 verts ; lint/build frontend et 38 tests Vitest verts.
  Aucun écran créé/modifié ; Playwright non relancé pour ce lot. Le workflow
  PostgreSQL inclut les quatre nouveaux fichiers de tests.
- À la livraison, HUM-001 était `Pending`. Push `d9a4066` et migration ensuite
  effectués par l’utilisateur ; contrôle en lecture seule : HUM-001 `Ran`, batch 10.
  CI backend/frontend vérifiées vertes pour `d9a4066` (runs `38180615309` et
  `38180615233`). Aucune personne réelle, migration,
  purge ou e-mail réel par l’agent. Profiler la contention du
  verrou organisation avant forte charge ; aucun benchmark de millions d’utilisateurs.
- README, contrats API, architecture, backlog et point de reprise mis à jour.
  Prochain ticket : HUM-002 ; listes/recherche/écrans restent HUM-003.

### UCG-HUM-002 — Profils de jeu et comptes externes

- `People` possède deux tables tenant, profils et comptes externes, modèles/factories,
  repositories/services distincts, DTO, validation partagée, Policy, Form Requests
  et Resources. GET unitaire/paginé, POST et PATCH avec version ; client TypeScript
  complet utilisant cookies/CSRF/révision. Aucun écran créé dans ce lot.
- Un profil par personne/jeu/plateforme/région, plusieurs profils/personne et comptes
  liés/profil. Contexte immuable, FK composites tenant/contexte, unicités SQL,
  archivage conservant les réservations et historique. Identifiant stable nullable,
  complétable une fois puis immuable ; aucun rapprochement par alias/pseudonyme.
  Unicité de l’identifiant sur organisation/jeu/plateforme/région/fournisseur,
  casse conservée, ASCII sans espaces. Aucun global account/admission/droit créé.
- Catalogue demandé par l’utilisateur : League of Legends, VALORANT, Counter-Strike 2,
  Rocket League et plateformes compatibles PC/PlayStation/Xbox/Switch selon le jeu.
  Zones initiales applicatives global/eu/na/apac, pas des shards officiels.
  Configuration serveur extensible, activations par tenant, ajouts/retraits et
  désactivation sans migration/enum. Historique lisible et archivable après retrait ;
  clés à conserver stables et non réutilisables pour un autre objet.
  Le port public `GameProfileCatalogue` pourra être implémenté par Teams ;
  aucun cycle People → Teams. Catalogue complet et UI restent TEAM-001.
- Droits explicites `sports.read/write`, champs projetés via IAM-004 ; écrire ne
  donne pas lecture, propriétaire/administrateur sans droits métier implicites.
  Comptes techniques refusés, MFA et confirmation tenant conservés. Services
  revérifiant état/accès/version/catalogue/limites dans la transaction verrouillée.
- Rangs/rôles de jeu : libellés manuels, pas des permissions/éligibilités ou métriques
  comparables entre jeux. Comptes : source manuelle, propriété non vérifiée ;
  aucun OAuth, connecteur, appel éditeur, mot de passe ou token.
- Observation UTC non future ; fraîcheur unknown/recent/stale calculée à la lecture,
  seuil serveur 30 jours. Changer une donnée observée sans date remet l’observation
  à null, sans effacer les autres champs omis. Configuration du seuil vérifiée
  avant écriture pour éviter un échec de sérialisation après commit.
- Audit IAM-006 dans la transaction, cible organisation et UUID personne/profil/compte
  dans états fermés, statut, présence d’identifiant, version/date/champs soumis ;
  aucun identifiant externe, alias ou rang copié. Une panne d’audit annule l’écriture.
  Catalogue de sauvegarde opérateur complété, seulement les lignes du tenant.
- Listes bornées à 100, comptes comptés avec withCount et projection de page en bloc.
  Limites techniques, archives incluses : 50 profils/personne, 20 comptes/profil.
  Nombre de requêtes constant comparé avec 1 et 21 profils, caches array/database,
  après initialisation du quota et avec temps figé. Aucune optimisation de cache
  d’autorisation durable ni prétention de benchmark de millions d’utilisateurs.
- Migration additive `2026_10_11_223914_create_person_game_profiles_and_external_accounts`
  sans backfill, attente de verrou PostgreSQL de 5 s, gardes SQL/Eloquent d’identité
  et suppression. Identité minimale des comptes protégée en SQL. Rollback vide
  testé ; rollback non vide refusé. Migrations déployées antérieures inchangées ;
  tests de rollback existants complétés pour les nouvelles dépendances FK.
- Validations finales SQLite complètes avec cache tenant database : 718 tests,
  715 réussis, 3 130 assertions, 3 exclusions attendues. PostgreSQL 17 : 718 tests,
  716 réussis, 3 131 assertions, 2 exclusions Redis attendues ; base temporaire
  `ucg_hum002_verify_20261011_231014` supprimée. Premier passage : données des tests
  d’identité raccourcies pour exercer les triggers plutôt que les limites varchar ;
  assertions SQLSTATE maintenues, dernière suite PostgreSQL entièrement verte.
- Pint/PHPStan niveau 8 sans erreur ; lint/build et 38 tests Vitest verts.
  Trois nouveaux fichiers de tests ajoutés au workflow PostgreSQL.
  Playwright non relancé : aucun écran/flux visuel modifié.
- Contrôle principal en lecture seule : HUM-001 Ran batch 10, HUM-002 Pending.
  Aucun profil/compte réel, migration, purge ou e-mail réel par l’agent.
  Push utilisateur, CI puis sauvegarde/migration avant utilisation.
- README, API, architecture, backlog et point de reprise inclus dans le même
  commit de tâche. Accord utilisateur enregistré : pas de commit séparé pour
  les confirmations documentaires ultérieures ; reprise dans le prochain travail.
  Prochaine tâche P0 : HUM-003, fiches/listes/recherche paginée et écrans.

### UCG-HUM-003 — Fiches, listes et recherche paginées

- Livré localement le 12 octobre 2026. Reprise de HUM-002 : push `3c799ff`,
  migration batch 11 et CI confirmés par l’utilisateur ; confirmation intégrée
  dans ce commit de tâche, conformément à l’accord d’un commit unique.
- GET `tenants/{tenant}/people` : Form Request, Policy existante, DTO de requête,
  validation/autorisation répétées dans le Service, Repository tenant et Resource.
  Pagination 1..100 éléments, page 1..10000 ; tris fermés id/pseudonyme/statut,
  sens asc/desc et UUID comme départage stable. Liens conservant les filtres validés.
- Recherche littérale par début de pseudonyme, 2..80 caractères, wildcards SQL
  échappés et paramètres liés. Statut/langue/jeu/plateforme exigent sports.read,
  même pour un résultat vide ; jeux/plateformes appartiennent au même profil actif.
  Filtre compte lié réservé à administrative.read. Aucune recherche sur les
  identités/coordonnées chiffrées ni sur les alias externes.
- Vues générale/sportive/administrative : projection IAM-004 en lot, SELECT des
  seules colonnes de résumé autorisées, sans biographies ni téléphone en liste.
  Nombre de profils (archives incluses) par withCount, aucun tableau relationnel
  massif ; profils et comptes chargés séparément à l’ouverture de la fiche.
- Frontend raccordé à l’organisation active avec composants partagés de tables,
  pagination, formulaires, alertes et notifications. Liste/recherche/filtres/tri,
  états vides, vues de détail, création et PATCH de fiches, profils et comptes.
  Lecture et écriture distinctes : aucun privilège implicite propriétaire/admin.
  Un auteur sportif sans lecture peut créer, sans charger de liste privée.
- Confirmation nommant l’organisation avant mutation, cookies/CSRF/révision,
  version UUID ; PATCH contenant les seuls champs modifiés. L’ancienne observation
  manuelle n’est pas renvoyée lorsque seul le rang ou l’alias change ; le serveur
  invalide alors la fraîcheur. Identifiant stable établi en lecture seule.
  Références retirées encore consultables et archivables, contexte immuable.
- Changement d’organisation/révision/permissions : démontage des panneaux,
  annulation des lectures et rejet des réponses tardives. Si la confirmation est
  encore en vol, aucun PATCH n’est envoyé après démontage. Erreurs 422 : brouillon
  conservé, message près du champ et focus ciblé. 401/403/404/409 : rechargement du
  contexte et notification ; aucun rejeu automatique d’écriture obsolète.
- Tests ajoutés : 45 backend (159 assertions) et 19 frontend. SQLite complet avec
  cache tenant database : 763 tests, 760 réussis, 3289 assertions, 3 exclusions
  attendues. PostgreSQL 17 complet : 763 tests, 761 réussis, 3289 assertions,
  2 exclusions Redis attendues ; nouveau fichier revérifié seul après le dernier
  ajout d’assertion, 45 tests/159 assertions. Bases temporaires
  `ucg_hum003_verify_20261012_170423` et `ucg_hum003_verify_20261012_171100` supprimées.
- Query count identique pour 1 et 21 personnes, droits sportifs/administratifs,
  caches array et database. Pint et PHPStan niveau 8 sans erreur ; lint/build et
  57 tests Vitest verts. Suite Playwright complète finale : 6 parcours verts,
  dont les deux scénarios HUM-003 et le changement de tenant pendant une
  confirmation. Mobile 375/320 sans débordement
  global, capture inspectée dans reports/hum003-people-mobile.png (ignorée par Git).
- Chrome local en mode headless utilisé car Chromium/FFmpeg Playwright absents
  du runtime local ; vidéos locales désactivées, aucun navigateur téléchargé.
  Configuration optionnelle PLAYWRIGHT_BROWSER_CHANNEL/PLAYWRIGHT_VIDEO ; CI
  garde Chromium et les vidéos par défaut. Nouveau fichier backend dans la CI PG.
- Une erreur GET de configuration du catalogue est affichée sans boucle de
  rechargement automatique. En lecture, seule une révision tenant invalide ou un
  refus d’accès relance le contexte ; les conflits de mutation restent signalés.
- Aucun changement de schéma ni migration principale, données réelles, e-mail,
  OAuth ou appel éditeur. API de suppression toujours absente : historique conservé.
  Le statut ancien membre reste descriptif ; le départ effectif reste HUM-004.
  Catalogue administrable complet/modes/cartes reste TEAM-001 ; import reste HUM-005.
  Les recherches natives LOWER ne normalisent pas les accents ; profiler les plans
  à volume réel avant index fonctionnel, moteur de recherche ou pagination cursor.
  Pas de cache de fiches privées ni de benchmark de millions d’utilisateurs.
- README, contrats API, architecture, backlog, frontend README et point de reprise
  actualisés dans le même commit de tâche. Prochain P0 : HUM-004 après push/CI.

### UCG-HUM-004 — Affectations, indisponibilité et départ formel

- Mission organisationnelle datée : joueur, remplaçant, capitaine, coach,
  analyste ou staff. Indisponibilités distinctes, sans motif médical. Intervalles
  semi-ouverts, chevauchement interdit pour un même type/rôle, dates adjacentes
  autorisées. Aucun roster d’équipe créé : Teams n’est pas encore implémenté.
- Départ immédiat et explicite, version de personne requise, permissions
  sports.write + administrative.write + memberships.manage + invitations.manage,
  session MFA et confirmation tenant. Transaction unique : clôture/annulation des
  périodes, statut former, registre immuable, révocation de l’adhésion et de tous
  ses rôles, invitations en attente du compte lié révoquées dans ce tenant.
  Autres organisations, compte global et historique conservés. Le propriétaire
  doit transférer la propriété avant départ ; délégation administrative respectée.
- Équipements/obligations génériques privés : libellé et notes chiffrés, échéance,
  états open/resolved/waived et date de résolution serveur. Reconnaissance explicite
  des éléments ouverts avant départ ; les accès sont révoqués immédiatement et
  la régularisation reste possible ensuite. Aucune décharge financière/contractuelle
  automatique. Statut descriptif seul et indisponibilité ne révoquent aucun droit.
- Personne ayant un départ enregistré : statut former et compte lié scellés,
  pas de nouvelles affectations/profils/comptes externes ; archivage des profils
  existants permis par l’API. Biographie et checklist restent modifiables selon
  droits. Une seule clôture par personne ; réadmission métier contrôlée future.
  Le départ TEN générique ne crée pas automatiquement un registre People.
- Port public People `PersonDepartureParticipant`, participants database-only
  dans la même transaction, destiné à Teams/Contracts sans dépendance inverse.
  Port Tenancy `TenantDepartureAccess`, verrous compte → organisation → personne,
  3 tentatives en cas de deadlock ; échec participant/audit annule toute l’opération.
- Migration additive PostgreSQL/SQLite : 3 tables, FK composites tenant/personne,
  index, gardes SQL d’immuabilité/cohérence, interdiction de DELETE et rollback
  refusé si données présentes. Aucun backfill ; export de portabilité tenant inclut
  ces tables avec les champs privés conservés chiffrés.
- API imbriquée périodes/checklist/départ, Form Requests strictes, policies,
  DTO/Resource, projections IAM-004, audit sans notes/libellés. Pagination 1..100,
  page 1..10000, limites par personne : 1000 périodes et 200 éléments au total,
  y compris clos/archivés. Projection en bloc ; absence de N+1 vérifiée pour 1/21
  périodes avec cache database. Profilage des verrous à volume réel reste nécessaire.
- UI raccordée aux fiches : pages de 10, versions, dates locales normalisées,
  confirmation contextualisée, départ confirmé séparément et rappel des éléments
  ouverts, consultation du départ et régularisation. Les droits sont rechargés
  après révocation, y compris départ de soi-même ; réponses tardives ignorées.
- 61 tests backend ajoutés, 272 assertions. Suites complètes avec cache database :
  SQLite 824 tests / 821 réussis / 3561 assertions / 3 exclusions attendues ;
  PostgreSQL 17 824 / 822 / 3562 / 2 exclusions Redis attendues. Base temporaire
  `ucg_hum004_verify_20261012_184820` supprimée ; base principale non migrée.
  Pint et PHPStan niveau 8 verts. 65 tests frontend, lint/build et 7 parcours
  Playwright verts ; mobile 375/320 sans débordement global. API métier simulées
  pour les parcours navigateur ; aucune mutation métier réelle.
- CI PostgreSQL enrichie des 4 fichiers de tests HUM-004, SQLite intégral existant.
  README, contrats API, architecture, backlog et point de reprise dans le même
  commit. Confirmation push/CI HUM-003 intégrée à ce lot. Prochain P0 : HUM-005
  après confirmation utilisateur du push, des CI et de la migration HUM-004.

## Décision de périmètre — retrait de l’organisation de tournois

- Décision utilisateur du 11 octobre 2026 : retirer des documents le parcours
  d’organisation de tournois, en P0, P1 et P2 ; aucune réintroduction planifiée.
- Retirés : fiche de tournoi organisé et suivi manuel, inscriptions publiques,
  comptes participants, liste d’attente, check-in sur la plateforme, brackets,
  progression automatique, formats avancés et arbitrage de tournoi ; exigences,
  tickets, rôles dédiés et critères de recette correspondants supprimés.
- Conservés : suivi interne des compétitions externes et de leurs dossiers,
  équipes/rosters, scrims, matchs, scores, validations internes et statistiques.
  L’inscription et le check-in externes sont seulement renseignés en interne.
- `Competition` et ses dépendances restent utiles aux matchs et à ce suivi.
  Les sponsors, candidats et événements hors compétition gardent leur périmètre.
- Cahier des charges, backlog et architecture alignés ; identifiants conservés
  sans réutiliser ceux retirés. L’estimation professionnelle reste à réévaluer.
- Changement documentaire uniquement : aucun code, migration ou donnée modifié.
- Vérifications : seuls les cinq tickets de tournois et leurs exigences/lots/recettes
  associés sont retirés ; 122 tickets conservés sans doublons, 127 références au CDC
  résolues, recherche des mentions restantes revue et `git diff --check` réussi.

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
   convenu avec l'utilisateur : un seul commit de tâche couvre code, tests et docs.
6. Intégrer les confirmations ultérieures du push, des CI et migrations dans les
   docs du prochain travail ; ne pas créer de commit séparé de clôture documentaire.
