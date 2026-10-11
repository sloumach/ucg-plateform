# État du projet UltraTools

> Document de transmission entre les discussions Codex. À lire au début d'une
> nouvelle tâche et à mettre à jour après chaque étape significative.

## Point de reprise

- Dernière mise à jour : 11 octobre 2026.
- Périmètre sportif : organisation de tournois retirée des documents ; suivi
  interne des compétitions externes conservé. Voir la décision de périmètre ci-dessous.
- Branche de travail : `staging`.
- Dernier ticket traité et validé localement : `UCG-IAM-004` — socle partagé
  de projection par champ, raccordement de l’API rôles et permissions du mail.
  Les futurs modules métier devront raccorder leurs canaux lors de leur livraison.
- Prochaine action : push utilisateur d’IAM-004 puis confirmation des deux CI.
- IAM-003 (`f78e0cd`), ajustement du périmètre (`f9ea660`) et correctif mobile
  `72fb380` poussés. CI backend/frontend du dernier commit confirmées vertes
  (runs `38155883372` et `38155883213`), y compris Playwright.
- IAM-002 poussé (`d61c131`) ; CI backend/frontend vérifiées vertes via GitHub.
- Ensuite, priorité P0 : `UCG-IAM-005` (administration tenant/plateforme) ;
  `UCG-TEN-008` (branding/domaines) reste P1.
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
  aucune migration et ne modifie pas cette base. Aucun e-mail réel envoyé.
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
  propriétaire/MFA et son catalogue fermé, sans projection partielle.
- Aucune migration IAM-004 ni écriture sur la base principale. Migrations précédentes
  appliquées par l’utilisateur et vérifiées en lecture seule. README, API,
  architecture et backlog actualisés ; push utilisateur et CI IAM-004 à confirmer.

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
   convenu avec l'utilisateur.
