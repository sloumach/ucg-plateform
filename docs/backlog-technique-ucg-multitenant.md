# Ultra Cyber Game

## Backlog technique modulaire de la plateforme esports multi-tenant

Version 1.1 — 11 octobre 2026 — périmètre sans organisation de tournois

Ce document transforme le cahier des charges fonctionnel en tickets techniques suffisamment détaillés pour préparer les sprints, les estimations et l’affectation des travaux. Il ne remplace ni les maquettes, ni les règles métier détaillées, ni les décisions de cadrage encore ouvertes.

UCG est le tenant pilote. Le socle reste conçu pour accueillir plusieurs organisations sans partage implicite de leurs données.

L’organisation de tournois est retirée de toutes les priorités, conformément à la section 2.3 du cahier des charges. Le suivi interne des compétitions externes, les équipes, scrims, matchs, scores et statistiques restent prévus. Les identifiants des tickets retirés restent réservés ; les tickets conservés ne sont pas renumérotés.

## 1 Conventions de lecture

- **P0** : nécessaire à la première version exploitable.
- **P1** : version professionnelle après validation du pilote.
- **P2** : extension ou industrialisation ultérieure.
- **S** : ticket limité et bien circonscrit.
- **M** : ticket moyen touchant plusieurs classes ou écrans.
- **L** : ticket transversal ou métier complexe, à découper pendant la planification.
- **XL** : epic technique à redécouper avant son entrée dans un sprint.

Les tailles sont relatives. Elles ne représentent pas des jours-personnes et devront être réévaluées après les ateliers fonctionnels et les prototypes d’intégration.

## 2 Définition de terminé commune

Un ticket applicatif n’est terminé que si les éléments pertinents suivants sont présents :

- migration réversible, contraintes, index et données de test ;
- modèle Eloquent, scopes explicites et relations sans chargement N+1 ;
- interface et implémentation de Repository injectées ;
- Service, Action ou classe de domaine portant la règle métier ;
- Form Request pour toute entrée HTTP et DTO typé pour les échanges internes utiles ;
- Policy ou Gate contrôlant l’organisation, l’action, le périmètre et les champs sensibles ;
- Controller mince et réponse Inertia, Resource ou vue correctement filtrée ;
- transaction autour des écritures cohérentes et événement émis après validation ;
- exceptions métier ciblées, message localisé et notification Flasher pour les actions interactives ;
- pagination ou bornes obligatoires sur les collections et exports ;
- journal d’audit lorsque l’action est sensible ;
- tests unitaires, tests d’intégration positifs et négatifs, dont isolation inter-tenant ;
- documentation technique ou API mise à jour ;
- validation Pint, PHPStan, tests PHP, tests frontend et construction Vite dans la CI.

Les requêtes ne prennent jamais `organization_id` depuis le corps de la requête comme preuve du tenant cible. Le tenant provient du contexte serveur autorisé.

## 3 Ordre de réalisation recommandé

1. Cadrage technique, architecture et environnements.
2. Tenancy, identité, sécurité et audit.
3. Personnes, jeux, équipes et affectations.
4. Documents privés et contrats.
5. Planning, matchs et compétitions.
6. Statistiques, tableaux de bord et exports.
7. Académie, sponsors et communication.
8. Modules P1, intégrations et espaces publics.
9. Reprise des données, recette, charge et mise en production.

Les modules métier peuvent avancer en parallèle seulement après stabilisation des contrats `TenantContext`, autorisation, audit, stockage et jobs.

## 4 Module Architecture et fondations

### UCG-ARC-001 — Initialiser la stack applicative

- **Priorité / taille** : P0 / M
- **Périmètre** : Laravel 13, PHP 8.5, API HTTP versionnée avec Sanctum, React, TypeScript, Tailwind CSS, Vite et PostgreSQL ; conventions de configuration par environnement. Le frontend SPA et le backend sont séparés, Inertia n’est donc pas utilisé.
- **Acceptation** : installation reproductible, page authentifiée minimale, connexion PostgreSQL fonctionnelle et aucun secret versionné.
- **Dépendances** : décisions de stack validées.

### UCG-ARC-002 — Mettre en place le monolithe modulaire

- **Priorité / taille** : P0 / L
- **Périmètre** : modules `Identity`, `Tenancy`, `People`, `Teams`, `Academy`, `Scheduling`, `Competition`, `Performance`, `Contracts`, `Partnerships`, `Finance`, `Media` et `Reporting` ; règles de dépendance entre modules.
- **Acceptation** : exemple vertical complet Route → Request/Policy → Controller → Service → Repository → modèle, conventions documentées et dépendances circulaires interdites.
- **Dépendances** : UCG-ARC-001.

### UCG-ARC-003 — Standardiser DTO, erreurs et réponses

- **Priorité / taille** : P0 / M
- **Périmètre** : DTO typés, exceptions métier, enveloppes JSON, pages d’erreur, identifiant de corrélation et messages localisés.
- **Acceptation** : statuts 401, 403, 404, 409, 422, 429 et 500 cohérents ; aucune stack trace ou donnée sensible envoyée au client.
- **Dépendances** : UCG-ARC-002.

### UCG-ARC-004 — Installer la qualité et la CI

- **Priorité / taille** : P0 / M
- **Périmètre** : Pint, PHPStan, Pest ou PHPUnit, tests frontend, Playwright et construction des assets.
- **Acceptation** : pipeline exécuté sur chaque changement, échec bloquant en cas de test ou analyse statique invalide, rapports conservés.
- **Dépendances** : UCG-ARC-001.

### UCG-ARC-005 — Préparer Redis, queues, temps réel et stockage

- **Priorité / taille** : P0 / L
- **Périmètre** : Redis, Horizon, Reverb/Echo, abstraction S3, files de jobs séparées et configuration locale de remplacement.
- **Acceptation** : Job, événement privé et fichier de test fonctionnent sans couplage à un fournisseur unique ; aucun artefact critique ne dépend uniquement de Redis.
- **Dépendances** : UCG-ARC-001.

### UCG-ARC-006 — Centraliser l’interface et les traductions

- **Priorité / taille** : P0 / M
- **Périmètre** : composants accessibles, formulaires, tableaux, pagination, états vide/chargement/erreur, Flasher et catalogue de traductions français.
- **Acceptation** : navigation clavier, contrastes vérifiés et aucun texte métier important dispersé dans les composants.
- **Dépendances** : UCG-ARC-001.

## 5 Module Tenancy et organisations

### UCG-TEN-001 — Modéliser les organisations et leurs paramètres

- **Priorité / taille** : P0 / M
- **Périmètre** : `organizations`, paramètres, statuts, fuseau IANA, langue, pays, slug immuable et propriétaire.
- **Acceptation** : création d’UCG comme tenant pilote ; transitions actif, suspendu, en clôture et archivé contrôlées et auditées.
- **Références CDC** : ORG-01, ORG-06, ORG-07, ORG-15.

### UCG-TEN-002 — Résoudre et transporter le contexte tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : middleware, `TenantContext` immuable, résolution par route ou domaine approuvé et refus par défaut.
- **Acceptation** : une requête métier sans tenant valide échoue ; aucun changement de paramètre ou d’identifiant ne permet de sortir du contexte autorisé.
- **Dépendances** : UCG-TEN-001.

### UCG-TEN-003 — Gérer adhésions, invitations et changement d’organisation

- **Priorité / taille** : P0 / L
- **Périmètre** : adhésion datée, invitation, expiration, révocation, sélection de l’organisation active et confirmation des actions sensibles.
- **Acceptation** : un compte peut rejoindre plusieurs tenants avec des rôles distincts ; le changement de contexte recharge permissions, menus et données.
- **Précision d’adhésion** : multi-organisations par défaut ; conserver une politique
  extensible d’exclusivité, activable explicitement, pour refuser les appartenances
  simultanées. Contrôle serveur des périodes à l’acceptation/réactivation, y compris
  les adhésions suspendues ; aucun départ ou effacement d’historique automatique.
  L’activation sur des comptes déjà multi-organisations nécessite un contrôle préalable
  des conflits. Une restriction spécifique aux joueurs ou à un autre rôle reste une
  règle métier distincte, à définir avec les permissions contextuelles.
- **Références CDC** : ORG-02 à ORG-05.

### UCG-TEN-004 — Imposer l’isolation dans PostgreSQL

- **Priorité / taille** : P0 / XL
- **Périmètre** : `organization_id` non nul, clés uniques propres au tenant, index composites, relations interobjets sûres et évaluation documentée de Row-Level Security.
- **Acceptation** : les associations inter-tenant invalides échouent en base ; les tables globales sont recensées dans une liste fermée.
- **Dépendances** : UCG-TEN-001, UCG-ARC-002.

- **Livraison TEN-004** : registre global fermé et contrôle de schéma exécutable,
  rattachements non nuls vérifiés sur les tables existantes, FK composites du
  journal vers adhésions/invitations, immutabilité tenant des paramètres,
  tests SQL négatifs et migration préservant les historiques. Évaluation RLS
  documentée dans `architecture-modulaire.md` ; aucune activation automatique.
  Les futures tables métier devront appliquer ce contrat dès leur migration.

### UCG-TEN-005 — Isoler fichiers, caches, verrous et quotas

- **Priorité / taille** : P0 / L
- **Périmètre** : préfixes S3 par tenant, clés Redis incluant le tenant, limitation de débit et limites techniques d’import, export et stockage.
- **Acceptation** : deux tenants utilisant les mêmes identifiants fonctionnels ne partagent ni fichier, ni cache, ni verrou ; les limites d’un tenant n’affectent pas silencieusement les autres.
- **Références CDC** : ORG-12, IAM-08, SEC-12.
- **Avancement (10 octobre 2026)** : ports publics de fichiers privés, cache/verrous,
  bornes techniques et débit HTTP tenant implémentés ; compteurs durables ajoutés
  sans déplacer les fichiers anciens. Validation locale terminée sur SQLite et
  PostgreSQL ; test historique de rollback adapté avec accord utilisateur et
  toutes ses vérifications conservées. Migration additive appliquée, empreintes
  des données existantes inchangées. Push `86569b1` et CI backend/frontend confirmés
  réussis, y compris Redis réel, le 10 octobre 2026. Les futurs Services
  d’import/export devront appeler les gardes livrées ; aucun parcours métier ni
  plan commercial n’est créé par ce lot. Détails d’exploitation dans README.

### UCG-TEN-006 — Propager le tenant dans les jobs et le scheduler

- **Priorité / taille** : P0 / L
- **Périmètre** : sérialisation du tenant, restauration contrôlée du contexte, refus d’un tenant inactif, verrouillage et supervision des tâches planifiées.
- **Acceptation** : relance, nouvelle tentative et exécution différée utilisent toujours le tenant enregistré ; l’échec d’un tenant ne bloque pas les autres.
- **Références CDC** : ORG-14, IAM-08.
- **Avancement (10 octobre 2026)** : transport tenant/acteur explicite, middleware
  de restauration et réautorisation à chaque tentative, refus permanent des
  tenants inactifs/accès révoqués, invitations e-mail raccordées. Tâches système
  via registre serveur fermé (vide par défaut), distribution bornée par pages,
  verrous tenant, reprises corrélées et supervision documentée. Validation locale
  SQLite/PostgreSQL effectuée ; queue Redis réelle validée en CI après push
  TEN-006 (`4269a6a`, backend `38080998770`, frontend `38080998784`). Aucune migration ; les handlers métier futurs doivent rester
  idempotents et appliquer leur périmètre système explicite.

### UCG-TEN-007 — Gérer le cycle de vie et la portabilité d’un tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : suspension, réactivation, export complet, archivage, purge et récupération logique documentée.
- **Acceptation** : export traçable et borné ; suppression conforme à la conservation ; aucune donnée d’un autre tenant modifiée.
- **Références CDC** : ORG-09 à ORG-11.
- **Avancement (10 octobre 2026)** : socle opérateur export asynchrone privé
  NDJSON complet pour le catalogue actuel, fichiers enregistrés inclus, limites,
  intégrité, expiration et suivi par tenant. Suspension/réactivation/archivage
  ordinaires conservés ; récupération distincte de données préservées vers
  `suspended`, auditée et sans reprise implicite des jobs. Purge ciblée bornée et
  reprenable, refusée par défaut sans politique revue ; racine, comptes et
  historiques protégés conservés. Données actuelles inchangées, validation locale
  SQLite/PostgreSQL effectuée ; push et CI TEN-007 à confirmer.
- **Limites / dépendances** : la purge ciblée n’est pas un effacement physique
  complet. Durées par catégorie/tenant et suppression des historiques,
  sauvegardes/réplicas restent CAD-004/OPS-007 ; restauration sélective après perte
  documentée, import/outillage et exercice réel restent OPS-004/OPS-005. Aucun
  transfert inter-tenant implicite ou endpoint public de maintenance.

### UCG-TEN-008 — Ajouter branding et domaines personnalisés

- **Priorité / taille** : P1 / L
- **Périmètre** : logo, couleurs autorisées, domaine, preuve de propriété, certificat et libération du domaine.
- **Acceptation** : domaine non activé avant vérification ; aucune prise de contrôle après suppression ou réattribution.
- **Références CDC** : ORG-08, ORG-13.

## 6 Module Identité, autorisations et audit

### UCG-IAM-001 — Implémenter l’authentification et les sessions

- **Priorité / taille** : P0 / M
- **Périmètre** : connexion, déconnexion, récupération encadrée, vérification d’adresse, limitation des tentatives et révocation des sessions.
- **Acceptation** : comptes individuels, cookies sécurisés, protection CSRF et journalisation des événements de sécurité.
- **Dépendances** : UCG-ARC-003.

### UCG-IAM-002 — Imposer le MFA aux comptes privilégiés

- **Priorité / taille** : P0 / M
- **Périmètre** : enrôlement, codes de récupération, challenge et politique selon les rôles plateforme ou organisation.
- **Acceptation** : aucun rôle privilégié utilisable sans MFA ; récupération auditée sans contourner la révocation.
- **Références CDC** : SEC-01, SEC-02.
- **État** : implémenté localement le 11 octobre 2026 ; enrôlement TOTP,
  challenge, récupération auditée, remplacement d’authentificateur, révocation
  des sessions et contrôles HTTP/jobs/Horizon. Commit `d61c131` poussé par
  l’utilisateur ; CI backend/frontend vérifiées vertes. Migrations IAM-001/IAM-002
  appliquées ensuite par l’utilisateur à la base principale, batch 7 confirmé.

### UCG-IAM-003 — Construire les rôles et permissions contextuels

- **Priorité / taille** : P0 / XL
- **Périmètre** : rôles configurables par tenant, périmètres équipe/jeu/cohorte/événement, périodes d’effet et suspension prioritaire.
- **Acceptation** : un même compte possède des droits différents selon tenant et affectation ; aucune auto-attribution de rôle supérieur.
- **Références CDC** : IAM-01 à IAM-04, IAM-09.
- **État** : implémenté localement le 11 octobre 2026 : catalogue fermé, rôles
  configurables, affectations versionnées, périodes, suspension, délégation
  limitée, anti-auto-escalade, MFA et audit transactionnel. Interface React,
  exports complets et vérification HTTP/services/jobs. Organisation disponible ;
  types équipe/jeu/cohorte/événement préparés et refusés jusqu’au raccordement des
  ressources réelles par leurs modules. IAM-004 fournit le socle de projection
  par champ ; IAM-005 fournit la séparation plateforme/support ci-dessous. Migration
  appliquée par l’utilisateur à `ucg_platform`, batch 7 confirmé. Commits
  `f78e0cd`, documentation `f9ea660` et correctif mobile `72fb380` poussés ;
  les deux CI du dernier commit sont vertes, y compris Playwright à 375/320 px.

### UCG-IAM-004 — Appliquer les Policies et la visibilité par champ

- **Priorité / taille** : P0 / L
- **Périmètre** : Policies partagées, projections DTO/Resource et filtrage des données sportives, administratives et financières.
- **Acceptation** : un champ interdit n’est jamais envoyé au frontend, aux exports, aux recherches ou aux notifications.
- **Dépendances** : UCG-IAM-003, UCG-TEN-002.

- **État** : socle implémenté et validé localement le 11 octobre 2026 pour les
  modules disponibles : contrat public `TenantDataVisibility`, schémas fermés,
  projection récursive, permissions actuelles, contrôle tenant/périmètre,
  raccordement aux Resources des rôles. Envoi des invitations déléguées corrigé
  avec contrôle de permission au traitement. Matrice sportif/admin/finance,
  révocation, MFA, isolation, formes imbriquées et requêtes bornées vérifiées.
  Aucune migration, dépendance ou modification de la base principale.
- **Raccordements à réaliser avec les modules métier** : les données sportives,
  administratives et financières, leurs recherches, exports et notifications
  doivent utiliser ce contrat avant diffusion. Ces parcours n’existent pas encore ;
  aucun endpoint métier fictif n’est ajouté. Le backup opérateur complet conserve
  son accès opérateur serveur avec acteur vérifié et son catalogue fermé, sans filtrage partiel
  qui rendrait la restauration incomplète. IAM-005 refuse ces sauvegardes aux comptes
  techniques actifs. IAM-004 poussé (`7bfd765`) ; backend et frontend verts
  (runs `38163603035` et `38163602940`).

### UCG-IAM-005 — Séparer administration tenant et plateforme

- **Priorité / taille** : P0 / L
- **Périmètre** : propriétaire, admin d’organisation, superadmin plateforme et accès exceptionnel limité dans le temps.
- **Acceptation** : motif, tenant, durée et actions du support sont audités ; expiration automatique ; aucune approbation métier par un compte technique.
- **Références CDC** : IAM-09, SEC-14.
- **État** : implémenté et validé localement le 11 octobre 2026. Rôles globaux
  explicites, bootstrap CLI unique, MFA, dernier superadmin protégé, audit immuable
  avant/après. Support limité à deux diagnostics sans données métier, tenant/motif/
  durée/actions explicites, TTL de 30 minutes maximum, révocation et liaison à la
  version du rôle. Les comptes techniques actifs ne peuvent ni utiliser des droits
  métier tenant ni accéder aux sauvegardes complètes. Horizon exige superadmin
  explicite, e-mail autorisé et MFA de session. API/CLI disponibles ; pas d’écran
  React support. Migration additive appliquée par l’utilisateur : `Ran`, batch 8.
  Push avec renommage `70440a6` puis fusion `63b8626` ; CI backend/frontend vertes
  confirmées. Le journal transverse/rétention est livré par IAM-006 ci-dessous.

### UCG-IAM-006 — Mettre en place le journal d’audit

- **Priorité / taille** : P0 / L
- **Périmètre** : acteur, tenant, action, cible, avant/après utile, motif, adresse technique et identifiant de corrélation.
- **Acceptation** : journal non modifiable par les utilisateurs ordinaires, sans secrets ni contenu excessif, avec rétention définie.
- **Références CDC** : SEC-07, SEC-08.
- **État** : implémenté et validé localement le 11 octobre 2026. Consultation
  séparée Identity/superadmin et Tenancy/`audit.view`, MFA et droits actuels requis,
  refus des comptes techniques dans le journal tenant. Sources existantes réunies
  sans copie, projections fermées, motif catégoriel, états typés pour les nouveaux
  événements. Consultations tracées avant diffusion et refus authentifiés 403/404
  sans contenu de requête. Pagination par curseur chiffré lié au compte/tenant/filtres,
  100 événements maximum, aucune lecture globale de données tenant.
  Conservation sans limite demandée par l’utilisateur, aucune purge ni scheduler.
  Deux nouvelles tables append-only, migration appliquée par l’utilisateur (`Ran`,
  batch 9) ; rollback refusé après audit. Commit `2c24782` poussé. Backend CI
  `38174777644` en échec sur l’initialisation du quota dans le test de comptage SQL ;
  correctif local vérifié avec caches mémoire/base sur SQLite et PostgreSQL.
  Suite complète SQLite avec cache CI, Pint et PHPStan 8 verts ; frontend/Chromium
  validés lors de la livraison. API/CLI disponibles ; pas d’écran React de journal.
  Correctif `0b85252` poussé ; push, migration et CI confirmés par l’utilisateur
  avant HUM-001. Futurs modules à raccorder aux ports typés.

### UCG-IAM-007 — Fournir les tokens API limités

- **Priorité / taille** : P1 / M
- **Périmètre** : Sanctum, scopes, tenant autorisé, expiration, rotation et révocation.
- **Acceptation** : un token ne peut pas changer de tenant via le corps de la requête ; quotas et audit appliqués.
- **Dépendances** : UCG-IAM-003, UCG-TEN-002.

## 7 Module Personnes, joueurs et staff

### UCG-HUM-001 — Modéliser personnes et lien au compte global

- **Priorité / taille** : P0 / L
- **Périmètre** : fiche personne tenant, compte facultatif, pseudonyme, identité restreinte, langue, fuseau, statuts et biographies publique/interne.
- **Acceptation** : une personne sans compte est possible ; un compte commun peut référencer des fiches distinctes sans fuite entre tenants.
- **Références CDC** : HUM-01, HUM-02, HUM-04, HUM-07.
- **État** : implémenté et validé localement le 11 octobre 2026.
  Table `people` tenant, compte nullable unique par organisation, identifiants
  immuables SQL/Eloquent, statuts descriptifs et biographies distinctes. Identité,
  coordonnées et biographie interne chiffrées et cachées par défaut. Pas de copie
  d’identité globale ni rapprochement par pseudonyme ; plusieurs fiches sans compte.
  Lien réservé aux comptes vérifiés déjà effectifs dans le tenant, sans admission
  ni droits ajoutés. Service/Repository, DTO/port public, Form Requests/Policy,
  projection IAM-004, API POST/GET/PATCH unitaire et client TypeScript raccordés.
  Droits explicites `sports.read/write` et `administrative.read/write`, MFA et
  confirmation tenant conservés ; PATCH avec version et champs omis préservés.
  Audit IAM-006 transactionnel avec états fermés sans valeurs privées ; catalogue
  de sauvegarde opérateur complété. Suppression physique/rollback non vide refusés.
  Commit `d9a4066` poussé par l’utilisateur ; migration additive appliquée,
  `Ran` batch 10 confirmé en lecture seule. Aucun backfill, personne réelle
  ou accès créé par l’agent. Photos/imports, profils de jeu, listes/recherche/écrans
  et départ restent dans les lots suivants. SQLite : 620 tests, 617 réussis ;
  PostgreSQL 17 : 620 tests, 618 réussis, bases temporaires supprimées. Pint,
  PHPStan niveau 8, lint/build et 38 tests frontend verts. Le workflow PostgreSQL
  couvre People. CI backend/frontend vérifiées vertes pour `d9a4066`
  (runs `38180615309` et `38180615233`) ; ticket clôturé.

### UCG-HUM-002 — Gérer profils de jeu et comptes externes

- **Priorité / taille** : P0 / M
- **Périmètre** : jeu, identifiant externe stable, région, rang, rôle, comptes liés et fraîcheur.
- **Acceptation** : plusieurs profils par personne ; unicités et rapprochements limités au bon contexte.
- **Références CDC** : HUM-03, HUM-08.
- **État** : implémenté et validé localement le 11 octobre 2026.
  Profils par personne/jeu/plateforme/région et comptes liés distincts, tenant-owned.
  Contexte et fournisseur immuables ; identifiant stable nullable enrichissable une
  fois, puis protégé. Unicités SQL par tenant/contexte, FK composites, archivage
  conservant les réservations et historique, aucune fusion par pseudonyme.
  API POST/GET/PATCH et listes paginées, Policy/Form Requests, Services/Repositories,
  projection IAM-004 et audit IAM-006 atomique sans valeurs d’alias/identifiant/rang.
  Fraîcheur basée sur observation manuelle UTC, jamais sur modification DB ; propriété
  externe non vérifiée. Aucun OAuth, token, connecteur ou droit d’accès ajouté.
  Catalogue initial configurable : League of Legends, VALORANT, Counter-Strike 2,
  Rocket League, plateformes compatibles PC/PlayStation/Xbox/Switch, zones applicatives.
  Ajout/retrait/activation possible sans changer de schema/enum ; le port pourra
  être implémenté par Teams sans cycle. Catalogue complet et UI restent TEAM-001.
  Client TypeScript et sauvegardes opérateur raccordés ; limites et anti-N+1 testés.
  Migration additive livrée Pending puis appliquée par l’utilisateur : Ran, batch 11,
  push `3c799ff` et CI confirmés avant HUM-003. Aucun backfill ou profil/compte réel
  par l’agent. SQLite : 718 tests, 715 réussis ; PostgreSQL 17 : 718 tests,
  716 réussis, bases temporaires supprimées. Pint/PHPStan 8, lint/build et 38 tests
  frontend verts. Tests PostgreSQL ajoutés à la CI. Un seul commit regroupe code,
  tests et docs. Push utilisateur/CI puis sauvegarde/migration avant usage.

### UCG-HUM-003 — Livrer fiches, listes et recherche paginées

- **Priorité / taille** : P0 / L
- **Périmètre** : CRUD autorisé, filtres, tri, pagination, vues générale/sportive/administrative et états vides.
- **Acceptation** : requêtes eager-loaded, champs filtrés côté serveur, aucun résultat d’un autre tenant.
- **Dépendances** : UCG-HUM-001, UCG-IAM-004.
- **État** : implémenté et validé localement le 12 octobre 2026.
  Liste tenant avec préfixe littéral de pseudonyme, filtres statut/langue/jeu/plateforme
  et compte lié, tris fermés stables, pagination bornée et vues autorisées côté serveur.
  Résumés limités aux colonnes utiles, projectMany IAM-004, withCount des profils,
  aucun N+1 ; filtres privés refusés même sur liste vide. Recherche d’identité
  administrative chiffrée exclue. Écrans de fiches/profils/comptes raccordés, formulaires
  partiels, versions, confirmations contextualisées, erreurs/états vides et fraîcheur.
  Données effacées lors d’un changement de tenant et réponses tardives ignorées.
  Références retirées conservées/archivables ; pas de suppression physique ni départ
  effectif (HUM-004). Aucun changement de schema ni migration principale.
  45 tests backend ajoutés ; SQLite complet : 763 tests/760 réussis, PostgreSQL 17 :
  763/761, exclusions attendues ; contrôle PG ciblé final 45/45. 57 tests frontend,
  parcours Playwright complets et deux scénarios HUM-003 verts, mobile 375/320.
  Pint/PHPStan 8, lint/build verts. Code/tests/docs dans un seul commit ; push/CI
  utilisateur avant HUM-004. Confirmation HUM-002 intégrée au même commit.
  Commit `2382db8` poussé et CI validées par l’utilisateur avant HUM-004.

### UCG-HUM-004 — Gérer affectations, statuts et départ

- **Priorité / taille** : P0 / L
- **Périmètre** : affectations datées, indisponibilité, ancien membre, révocation des accès, équipements et obligations restantes.
- **Acceptation** : le départ ferme les droits du tenant concerné sans supprimer l’historique ni les autres adhésions du compte.
- **Références CDC** : HUM-04, HUM-05, IAM-04.
- **État** : implémenté et validé localement le 12 octobre 2026.
  Missions organisationnelles datées et indisponibilités, intervalles semi-ouverts
  sans chevauchement de même type/rôle ; rosters Teams ultérieurs via port People.
  Checklist privée chiffrée équipements/obligations, régularisable après départ.
  Départ versionné confirmé et atomique : fermeture des périodes, registre immuable,
  statut former, révocation adhésion/rôles/invitations du seul tenant concerné.
  Propriétaire protégé, autres tenants et historique préservés, auto-départ supporté.
  Statut descriptif/indisponibilité seuls sans révocation. Compte/statut scellés après
  départ ; réadmission métier future. Aucun module Contracts ni calcul financier.
  API autorisée/typée, audit fermé, pagination bornée, limites 1000 périodes/200
  éléments par personne, gardes SQL PG/SQLite et portabilité tenant. Écrans raccordés.
  61 tests backend ajoutés ; SQLite complet 824/821 réussis, PG17 824/822,
  exclusions attendues ; 65 tests frontend et 7 Playwright verts, mobile 375/320.
  Pint/PHPStan 8, lint/build verts ; CI PG enrichie. Migration principale Pending,
  aucune opération métier réelle. Code/tests/docs dans un seul commit ; push/CI,
  sauvegarde puis migration par l’utilisateur avant HUM-005.
  Push `510d9c0` confirmé ; CI backend `38306904589` arrêtée au formatage de deux
  tests. Espacements corrigés, Pint global/PHPStan et 30 tests ciblés verts ;
  correctif et point de reprise dans un seul commit, nouveau push/CI à confirmer.
  Correctif `51fb9b1` poussé et CI confirmées vertes par l’utilisateur avant HUM-005 ;
  migration HUM-004 appliquée par l’utilisateur, vérifiée Ran, batch 12.

### UCG-HUM-005 — Importer les personnes par CSV

- **Priorité / taille** : P0 / L
- **Périmètre** : dépôt privé, prévisualisation, mapping, validation, doublons, erreurs par ligne et reprise idempotente.
- **Acceptation** : aucune écriture avant confirmation ; relance sans doublon ; rapport téléchargeable dans le tenant.
- **Références CDC** : HUM-06, REC-06, REC-07.
- **État** : implémenté et validé localement le 12 octobre 2026.
  Import privé de création uniquement, sans liaison de compte ni écrasement de
  personne existante. Dépôt/staging chiffrés, parser UTF-8/quotes/multilignes borné,
  mapping fermé, validation réutilisée, preview paginé et codes par ligne ; aucun
  write de fiche avant confirmation métier/tenant et reconnaissance des exclusions.
  Candidats en doublon par pseudonyme tenant, pas de rapprochement d’identité deviné.
  HMAC tenant/fichier/séparateur unique ; création par lots de 25 sur reports via
  port queue People, contexte frais et revalidation des quatre grants read/write.
  Résultat/audit par ligne atomiques, lots antérieurs préservés, reprise sans rejouer
  les créations. Rapport privé sans cellules source ni formules. Tables/FK/index
  tenant, gardes SQL et portabilité ; rollbacks de tests adaptés, schéma déployé intact.
  UI complète, transport multipart CSRF/révision, états, pagination et réponses tardives.
  57 tests backend ajoutés ; SQLite complet 881 tests/878 réussis, 3740 assertions,
  PostgreSQL 17 881/879, 3741 assertions, exclusions attendues ; bases temporaires
  supprimées. 77 tests frontend et 8 parcours Playwright verts, mobile 375/320.
  Pint global/dirty, PHPStan niveau 8, lint/build verts ; CI PG enrichie. Migration
  principale Pending, aucun CSV/personne réel importé. Code/tests/CI/docs dans un
  seul commit. Push `f960be8` et CI confirmés par l’utilisateur avant TEAM-001 ;
  migration appliquée par l’utilisateur, vérifiée Ran batch 13 le 13 octobre 2026.

### UCG-HUM-006 — Implémenter le recrutement et les essais

- **Priorité / taille** : P1 / L
- **Périmètre** : candidature, présélection, essai, évaluation, décision, proposition, intégration ou clôture.
- **Acceptation** : grilles versionnées, visibilité par auteur et rôle, décision humaine explicite et auditée.
- **Dépendances** : UCG-HUM-001, UCG-IAM-004.

## 8 Module Jeux, équipes et rosters

### UCG-TEAM-001 — Construire le catalogue de jeux

- **Priorité / taille** : P0 / M
- **Périmètre** : jeux, modes, plateformes, régions, rôles, cartes et versions globales ; activation et réglages par tenant.
- **Acceptation** : une organisation n’utilise que les éléments activés ; aucune modification tenant ne change le catalogue global.
- **Références CDC** : TEAM-01.
- **État** : implémenté et validé localement le 13 octobre 2026.
  Catalogue global persistant de jeux, plateformes, fournisseurs, régions, modes,
  rôles de jeu, cartes et versions. Clés et rattachements immuables, retrait par
  désactivation ; historique des profils conservé. API/UI de gestion réservées
  au superadmin vérifié avec MFA, confirmation globale, versions et audit atomique.
  Organisation : lecture sports.read ; écriture sports.read + games.manage
  explicitement attribués au niveau organisation. Aucun droit implicite du propriétaire,
  du support ou du superadmin sur le métier tenant. Restrictions locales parmi
  les références disponibles, aucune modification du catalogue commun.
  Pont Teams → port public People sans cycle ; les profils/comptes vérifient les
  activations effectives. Migration reprend les références/configurations initiales,
  sans inventer d’activation pour les nouveaux jeux. FK, index, gardes PG/SQLite,
  audit append-only, cache global versionné sans données tenant et portabilité locale.
  65 tests backend ajoutés ; SQLite complet 946/943, 4008 assertions, PostgreSQL 17
  946/944, 4009 assertions, exclusions attendues. 89 tests frontend et 10 parcours
  Playwright verts ; confirmations, erreurs et mobile 375/320 vérifiés.
  Pint complet/dirty, PHPStan 8, lint/build ; 4 fichiers ajoutés à la CI PostgreSQL.
  Migration principale Pending, aucune migration ou attribution réelle par l’agent.
  Code/tests/CI/docs dans un seul commit ; push/CI, sauvegarde puis migration
  utilisateur avant TEAM-002.
  Confirmation avant TEAM-002 : 427dae1 poussé, CI et migration validées par
  l’utilisateur ; contrôle en lecture seule Ran, batch 14.

### UCG-TEAM-002 — Gérer les équipes d’une organisation

- **Priorité / taille** : P0 / M
- **Périmètre** : équipe première, académie, réserve, événementielle ou catégorie spécifique ; jeu, saison, statut et identité publique.
- **Acceptation** : listes paginées, slug unique dans le tenant, archivage sans perte d’historique.
- **Dépendances** : UCG-TEAM-001, UCG-TEN-004.
- **État** : implémenté et validé localement le 13 octobre 2026.
  API/UI privées par organisation, équipes première/académie/réserve/événementielle/
  catégorie spécifique, jeu activé, saison descriptive, nom et identité facultative.
  Lecture sports.read ; écriture sports.read + teams.manage organisation avec
  MFA, confirmation tenant, CSRF et version optimiste. Aucun grant implicite.
  Jeu/slug/saison immuables pour le cycle, slug réservé même après archivage.
  Archive terminale conservée, archivage seul possible après retrait du jeu ;
  aucune suppression ou publication publique. Gardes modèle et SQL PG/SQLite,
  verrou tenant/catalogue, audit atomique minimal et portabilité tenant.
  Listes filtrées/paginées, eager loading vérifié, 1000 équipes archives comprises.
  Aucun roster ou scope IAM équipe ; appartenances réservées à TEAM-003.
  50 nouveaux tests backend / 204 assertions ; SQLite complet 996/993,
  4212 assertions ; PostgreSQL 17 996/994, 4213 assertions, exclusions attendues.
  98 tests frontend et 11 Playwright verts, erreurs et mobile 375/320 contrôlés ;
  Pint complet/dirty, format:check, PHPStan 8, architecture, lint/build verts.
  Migration 2026_10_13_143631_create_teams_table Pending sur la base principale,
  aucune migration/équipe/grant réel par l’agent. Code/tests/CI/docs dans un seul
  commit ; push/CI, sauvegarde puis migration utilisateur avant TEAM-003.
  Confirmation avant TEAM-003 : 11872a3 poussé, CI et migration validées par
  l’utilisateur ; contrôle en lecture seule Ran, batch 15.

### UCG-TEAM-003 — Gérer les appartenances datées

- **Priorité / taille** : P0 / L
- **Périmètre** : titulaire, remplaçant, capitaine, coach, analyste, dates et périodes sans chevauchement interdit.
- **Acceptation** : droits recalculés aux dates d’effet ; historique conservé ; départ et promotion tracés.
- **Références CDC** : TEAM-03, TEAM-08.
- **État** : implémenté et validé localement le 13 octobre 2026 ; validations dans le point
  de reprise. API/UI privées d’appartenances et de mouvements par organisation,
  fonctions titulaire/remplaçant/capitaine/coach/analyste, intervalles [début, fin).
  Même personne/équipe : titulaire et remplaçant incompatibles, doublon de même
  fonction interdit ; capitaine additif. Pas d’exclusivité entre équipes imposée.
  Identité de période immuable, annulation avant début ou clôture raccourcissant
  la fin ; aucune suppression/reprise terminale. Promotions académie → première
  et transferts du même jeu, atomiques, avec motif fermé et auteur conservés.
  Départ People clôture les fonctions en cours et annule les périodes futures
  dans la transaction existante, sans exiger teams.manage en plus des droits HUM-004.
  Grants IAM explicites à portée équipe, bornés par une appartenance de la personne
  liée au compte ; liaison durable empêchant toute réactivation lors d’un retour.
  Fonctions sportives sans permissions automatiques. Les commandes de roster
  restent sous sports.read + teams.manage organisation ; détail d’équipe accessible
  aussi sous sports.read équipe effectif. Audit, contraintes SQL PG/SQLite,
  portabilité locale, pagination et choix de personnes/destinations existantes.
  Migration additive réservée à l’utilisateur ; code/tests/CI/docs dans un seul
  commit, aucun roster ou grant réel créé par l’agent. Prochain P0 : TEAM-004.
  48 nouveaux tests backend / 228 assertions ; SQLite complet 1044/1041,
  4440 assertions ; PostgreSQL 17 1044/1042, 4441 assertions, exclusions attendues.
  107 tests frontend et 12 Playwright verts ; mobile 375/320, Pint complet/dirty,
  format:check, PHPStan 8, architecture, lint/build verts à la livraison locale.
  f0681e7 poussé et migré par l’utilisateur ; migration confirmée Ran, batch 16.
  CI frontend 38440198679 échouée sur le débordement mobile après promotion.
  Correctif des colonnes et retours à la ligne, test renforcé avec police de repli ;
  lint/build, 107 tests frontend et 12 Playwright verts localement sous Chrome
  Windows. CI Linux à revalider après push du correctif ; aucune nouvelle migration.

### UCG-TEAM-004 — Créer lineups et snapshots historiques

- **Priorité / taille** : P0 / L
- **Périmètre** : lineup de match ou d’inscription, remplaçants et instantané des participants.
- **Acceptation** : un changement de roster ultérieur ne modifie jamais un match historique.
- **Références CDC** : TEAM-04, TEAM-05, REC-05.
- **État** : implémenté le 13 octobre 2026 ; validations dans le point de reprise.
  API/UI privées par équipe : brouillon daté, titulaire/remplaçant, modification,
  annulation conservée et figement terminal. Appartenances de joueur couvrant la
  date, une place par personne et au moins un titulaire ; versions du brouillon,
  de l’équipe et des appartenances revérifiées sous verrou tenant.
  Pseudonymes et identité sportive d’équipe capturés à la confirmation du figement,
  jamais recalculés après renommage, transfert ou départ. Port HistoricalLineups
  pour les futurs modules Matchs/Compétitions : aucun faux rattachement à une
  fiche encore inexistante. Le libellé identifie le contexte préparé.
  Éligibilité complète réservée à TEAM-005. Limites techniques : 50 participants
  et 1000 compositions par équipe, historiques inclus ; liste paginée, relations
  eager-loaded, SQL SQLite/PostgreSQL, audit minimal atomique et export tenant.
  Migration additive réservée à l’utilisateur ; code/tests/CI/docs dans un seul
  commit, aucune composition réelle créée par l’agent. Prochain P0 : TEAM-005.

### UCG-TEAM-005 — Implémenter l’éligibilité sportive

- **Priorité / taille** : P0 / L
- **Périmètre** : taille min/max, verrouillage, jeu, région, âge, rang et règles configurées par compétition.
- **Acceptation** : résultat explicable ; contrat, rôle et éligibilité restent trois notions séparées.
- **Références CDC** : TEAM-06, CTR-09.
- **État** : implémenté le 13 octobre 2026 ; validation détaillée dans le point de
  reprise. Règlement immuable par contexte de compétition nommé et jeu, archivage
  conservé ; un changement exige un nouveau règlement. Liaison définitive au
  brouillon, tailles de titulaires/remplaçants, échéance exclusive, profil de jeu
  et plateforme, régions, rangs déclarés et âge facultatif à la date de composition.
  Rapports eligible/ineligible/incomplete avec motifs fermés ; aucune admissibilité
  déduite d’un contrat, d’une fonction ou d’un droit IAM. Les fiches Competition
  seront intégrées dans leur module, sans identifiant fictif aujourd’hui.
  Pré-évaluations conservées ; figement lié recontrôlé et refusé si incomplet ou
  non admissible. Rapport final et références de règlement/version conservés dans
  HistoricalLineups ; ancienne composition sans règlement reste unassessed.
  Date de naissance facultative chiffrée, réservée aux droits administratifs ;
  jamais copiée dans un rapport ou l’audit. Profils absents, ambigus, non datés,
  futurs ou périmés : incomplet. API/UI privées, pagination, confirmation humaine,
  gardes SQLite/PostgreSQL et export explicite des nouvelles tables. Migration
  additive réservée à l’utilisateur, aucun backfill. Code/tests/CI/docs dans un
  même commit ; prochain P0 : TEAM-006.

### UCG-TEAM-006 — Référencer adversaires et organisations externes

- **Priorité / taille** : P0 / M
- **Périmètre** : références externes, données publiques autorisées et snapshots de compétition, sans accès aux tenants des adversaires ni portail adversaire.
- **Acceptation** : aucun droit interne attribué à un adversaire ; aucune donnée privée importée depuis un autre tenant.
- **Références CDC** : TEAM-07, TEAM-09.
- **Avancement (14 octobre 2026)** : référentiel organisation/team/player privé
  au tenant, sources publiques déclarées et dates d’observation ; aucun compte,
  droit ou accès au tenant adverse. Clé/type/jeu immuables, modification partielle
  versionnée, archive définitive. Instantanés append-only capturés côté serveur,
  provenance/version/auteur conservés ; port public ExternalOpponents pour les
  futurs matchs/compétitions, sans identifiants métier fictifs. Form Requests,
  Policies, Repository, Services, Resources, audit atomique minimal, FK tenant
  composites et gardes SQL SQLite/PostgreSQL. API paginée et UI privée avec
  confirmations, annulation des lectures et contrôle mobile 375/320. Migration
  additive prête, application sur la base principale réservée à l’utilisateur.
  Push et CI du nouveau ticket à confirmer. Prochaine étape recommandée :
  documents privés et contrats (UCG-CTR-001).

## 9 Module Académie et coaching

### UCG-ACA-001 — Gérer cohortes, cycles et inscriptions

- **Priorité / taille** : P0 / M
- **Périmètre** : cohorte, cycle, mentors, inscrits, dates et emploi du temps.
- **Acceptation** : adhésions et personnes du même tenant uniquement ; listes paginées et historique conservé.
- **Références CDC** : ACA-01.
- **Livraison locale** : backend/frontend des cohortes et cycles, programme hebdomadaire borné, mentors et inscriptions datées, permissions academy.read/academy.manage explicites, isolation et versions concurrentes, archivage et révisions chiffrées conservées. Migration additive 2026_10_22_100213 préparée, non appliquée à la base courante. Listes et sélecteurs paginés ; compte membre vérifié obligatoire pour un mentor, aucun droit accordé automatiquement. Les occurrences calendrier et présences restent ACA-002, objectifs et feedback ACA-003. Vérifications et limites dans etat-projet.md ; push, CI, sauvegarde, migration et recette réelle avant usage.

### UCG-ACA-002 — Gérer séances, présences et exercices

- **Priorité / taille** : P0 / M
- **Périmètre** : séance reliée au calendrier, présence, exercice, responsable et compte rendu.
- **Acceptation** : la présence est modifiable uniquement par les rôles autorisés et reste auditée.
- **Références CDC** : ACA-03.
- **Livraison locale** : séances rattachées à une activité Académie partagée du calendrier, mentor responsable, exercices et compte rendu chiffrés ; présences paginées avec permission distincte `academy.attendance.manage`, corrections motivées, versionnées et auditées. Aucun droit implicite pour un mentor, aucune absence déduite automatiquement. Contrat public Scheduling, contrôle de fraîcheur du calendrier et isolation tenant/cycle. Migration additive `2026_10_22_172532` préparée, non appliquée à la base courante ; démontages/rejeux People, Tenancy, Calendar et Academy adaptés. Vérifications et limites dans etat-projet.md ; CI distante, sauvegarde, migration et recette réelle restent nécessaires avant usage.

### UCG-ACA-003 — Gérer objectifs et feedback

- **Priorité / taille** : P0 / L
- **Périmètre** : objectifs individuels/collectifs, échéances, responsable, feedback partagé et notes internes.
- **Acceptation** : le joueur voit seulement le feedback partagé ; les notes internes suivent une permission distincte.
- **Références CDC** : ACA-02, ACA-04.
- **Livraison locale** : objectifs individuels ou collectifs par cycle, échéance et mentor responsable ; feedback partagé et notes internes justifiées via parcours et permissions distincts. Accès joueur en lecture seule par `academy.self.read`, personne liée au compte et inscription étudiant courante, filtrage avant pagination ; aucune exposition des notes ni de leurs révisions/comptages. Destinataire et classification immuables, corrections motivées/versionnées, clôture/retrait définitifs, révisions chiffrées et audit minimal. Migration additive `2026_10_22_224821` préparée, non appliquée à la base courante. Tous les rejeux des parents et l’inventaire de portabilité adaptés. Résultats et limites dans etat-projet.md ; CI, sauvegarde, migration et recette réelle avant usage.

### UCG-ACA-004 — Ajouter ressources, évaluations et progression

- **Priorité / taille** : P1 / L
- **Périmètre** : ressources pédagogiques, évaluations périodiques, évolution et promotion vers un roster.
- **Acceptation** : métriques comparées uniquement dans un contexte de jeu cohérent ; historique et auteur affichés.
- **Références CDC** : ACA-05, ACA-06.

### UCG-ACA-005 — Préparer le parcours des mineurs

- **Priorité / taille** : P2 conditionnelle / L
- **Périmètre** : représentant légal, autorisations, visibilité et conservation selon le pays.
- **Acceptation** : activation uniquement après validation juridique locale ; aucun dossier médical ou psychologique ajouté implicitement.
- **Références CDC** : ACA-07.
- **Cadrage pilote (25/10/2026)** : mineurs confirmés, participants du premier test
  vivant uniquement en Tunisie ; vérification des parents/tuteurs confiée à une
  personne de l'équipe UCG encore à désigner. Le besoin est confirmé, sans
  activation ni implémentation du parcours. Validation locale et procédure
  restent à compléter dans CAD-004 ; aucune durée ni preuve imposée par défaut.

## 10 Module Planning, disponibilités et logistique

### UCG-CAL-001 — Modéliser les activités du calendrier

- **État** : CAL-001 `3051aa8` poussé ; CI et migration validées par l’utilisateur avant CAL-002. Migration vérifiée Ran, batch 31.
- **Livré** : agenda privé paginé, huit types d’activité, UTC + fuseau IANA d’origine, affichage selon la personne liée puis le tenant, choix d’un autre fuseau pour la vue, résolution explicite des heures répétées et refus des heures inexistantes. Participants issus des personnes non parties du tenant (50 maximum), équipe active facultative, lieu/lien HTTPS, privé par défaut et partage interne confirmé. Trois permissions calendar.read/write/publish explicites, MFA, versions UUID, transactions/verrous, audit minimal, archivage définitif et export opérateur.
- **Isolation** : deux tables tenant, FKs composites activité/personne/équipe, périodes et partage contrôlés en SQLite/PostgreSQL ; audiences réévaluées avant comptage/pagination. Choix/labels groupés par contrats publics People/Teams. Les règles d’effectif Teams partagées avec COM-001 conservent son comportement.
- **Bornes** : durée 31 jours, consultation 1–93 dates locales avec fin exclue, 100 activités/page. Pas de cache durable des droits ; scrims privés par défaut.
- **Limites de ticket** : activités ponctuelles, sans disponibilité/conflit/convocation/ICS/report/notification/récurrence ; lots CAL-002 à CAL-007 dédiés. Pas de création ou rattachement fictif à un futur Match/Académie/accord, ni de raccordement automatique COM/MEDIA.

- **Priorité / taille** : P0 / L
- **Périmètre** : entraînement, scrim, match, réunion, académie, bootcamp, sponsor, déplacement ; UTC, fuseau IANA, lieu, lien et confidentialité.
- **Acceptation** : affichage dans le fuseau utilisateur avec fuseau d’origine visible ; relations limitées au tenant.
- **Références CDC** : CAL-01, CAL-02.

### UCG-CAL-002 — Gérer disponibilités et absences

- **État** : `2c786d2` poussé ; CI et migration validées par l’utilisateur avant CAL-003. Migration vérifiée Ran, batch 32.
- **Livré** : panneau privé paginé, créneaux available/unavailable/uncertain, commentaire facultatif chiffré (1000), sans motif médical. Saisie personnelle par personne actuelle liée, planning collectif distinct, gestion des personnes habilitée et historique immuable numéroté. Trois permissions availability.self/read/manage, MFA et refus des opérateurs techniques ; le lecteur collectif ne voit pas les commentaires/historiques des autres.
- **Fiabilité** : UTC/fuseau IANA et choix DST réutilisés de CAL-001 ; durée 31 jours, fenêtre 1–93 dates locales, 100 résultats/page. Refus des chevauchements actifs d’une même personne, adjacence permise. Verrou organisation, relecture des droits, versions UUID, instantané et audit atomiques, FKs tenant et gardes SQL SQLite/PostgreSQL, portabilité, rollback protecteur.
- **Conservation** : annulation terminale, aucun DELETE ; les anciennes valeurs restent consultables par la personne autorisée ou les gestionnaires. Après départ, plus d’accès personnel ni de réécriture ; annulation et historique restent permis au gestionnaire.
- **Limites** : absence de déclaration ≠ disponible ; aucune synchronisation implicite des états/périodes RH. Pas de conflit d’activité/équipe/ressource, convocation, notification ou récurrence. Prochain P0 après validation : CAL-003, non démarré.

- **Priorité / taille** : P0 / M
- **Périmètre** : créneaux disponibles, indisponibles ou incertains, commentaire facultatif sans motif médical exigé.
- **Acceptation** : un joueur modifie uniquement ses données autorisées ; historique utile conservé.
- **Références CDC** : CAL-03, REC-02.

### UCG-CAL-003 — Détecter les conflits de réservation

- **État** : `dea7936` poussé ; CI et migration confirmées par l’utilisateur avant CAL-004. Migration vérifiée Ran, batch 33.
- **Livré** : aperçu avant confirmation, recontrôle transactionnel des participants explicitement inscrits, de l’équipe associée et des ressources communes. Disponibilités indisponibles/incertaines incluses avec availability.read seulement, sans commentaire ; avertissement sinon. Intervalles UTC semi-ouverts, adjacence permise et archives exclues.
- **Exception** : permission calendar.conflicts.override, aperçu courant valable cinq minutes, motif opérationnel et confirmation explicite. Toute modification du périmètre ou des conflits impose un nouvel examen. Motif/instantané chiffrés et journal append-only avec activité/version/acteur, atomiques avec réservations et audit minimal. Aucun droit permanent hérité d’une ancienne exception.
- **Ressources** : référentiel minimal d’unités réservables, permission calendar.resources.manage distincte ; référence unique, nom chiffré, version, archivage terminal et réservations conservées. 20 ressources/activité, listes/historiques paginés et 100 conflits maximum ; dépassement bloquant, sans exception.
- **Isolation/concurrence** : trois tables additives avec FKs tenant et gardes SQLite/PostgreSQL, portabilité et rollback protecteur. Verrou organisation avant lecture/écriture, y compris créations sans ligne préalable ; tests de deux processus indépendants pour personne, équipe et ressource.
- **Limites** : aucune expansion automatique du roster ; inscrire les personnes réellement mobilisées. Les ressources sont des unités, sans stock, capacité multiple ni logistique CAL-008. Pas de migration corrective automatique des collisions anciennes. CAL-004 et CAL-005 ci-dessous couvrent les convocations/réponses/rappels internes et le flux ICS privé ; prochain P0 : CAL-006.

- **Priorité / taille** : P0 / L
- **Périmètre** : chevauchements personnes, équipes et ressources ; service de détection transactionnel.
- **Acceptation** : conflit affiché avant confirmation ; exception motivée, autorisée et auditée ; tests de concurrence.
- **Références CDC** : CAL-04, REC-03.

### UCG-CAL-004 — Gérer convocations, réponses et rappels

- **État** : `661b438` et correctif `defc140` poussés ; migration CAL-004 Ran batch 34 et CI confirmées par l’utilisateur avant CAL-005. Activation opérationnelle à recetter.
- **Livré** : émission explicite aux participants inscrits d’une version de l’activité, échéance UTC future au plus tard au début, zéro à trois rappels configurables (1–10080 minutes avant l’échéance), réponse personnelle pending/present/absent versionnée et historique paginé des remises. Permissions organisationnelles calendar.invitations.manage (avec read/write) et calendar.invitations.respond (avec read), MFA et refus des comptes techniques.
- **Remise** : notification interne persistante, produite en queue par la tâche tenant calendar-invitations ; activation serveur CALENDAR_INVITATIONS_ENABLED, désactivée par défaut. Droits, lien personne/compte, participants, version et échéance recontrôlés à chaque traitement. Compte sans accès = blocage explicite, aucun accès créé. Pas d’e-mail, webhook ou Discord dans ce lot.
- **Fiabilité** : verrou organisation puis activité puis convocation, unicité SQL par version/personne et par occurrence de remise. Remise et état commités ensemble ; reprise sans doublon, rattrapage du seul seuil le plus urgent, aucun rappel immédiat redondant après une première remise tardive. Réponse non pending ou échéance atteinte = aucun rappel. Toute modification de l’activité invalide les anciennes convocations ; nouvelle préparation explicite nécessaire, sans notification automatique de report (CAL-006).
- **Conservation** : paramètres et identité figés après émission ; pas de réaffectation automatique à un autre compte ni de suppression. Une configuration différente exige une nouvelle version de l’activité. Historique de remise append-only, audit minimal des émissions/réponses, inventaire de portabilité et rollback protecteur. CAL-005 ajoute le flux ICS privé ci-dessous ; prochain P0 après validation : CAL-006.
- **Priorité / taille** : P0 / L
- **Périmètre** : présent, absent, en attente, échéance, rappels et déduplication.
- **Acceptation** : rappel asynchrone incluant le contexte tenant, traçable et non envoyé deux fois après une nouvelle tentative.
- **Références CDC** : CAL-05.

### UCG-CAL-005 — Exporter un calendrier ICS privé

- **État** : commit `fea7094` présent sur staging et origin/staging au démarrage de CAL-006 ; migration Ran batch 35 vérifiée en lecture seule. CI distantes non confirmées dans cette reprise. Aucune donnée métier réelle ou permission créée par l’agent.
- **Validation locale** : SQLite complet 1870 réussis/10 exclusions ; sélection CI PostgreSQL intégrale 1690 réussis/2 exclusions Redis ; PHPStan 8, Pint, 278 tests frontend et 30 scénarios navigateur réussis.
- **Livraison** : lien personnel 256 bits, hash SHA-256 seulement, création/rotation/révocation optimistes et auditées ; secret affiché une seule fois, expiration 1–90 jours. Permission explicite calendar.export + calendar.read, compte vérifié/MFA, tenant et droits courants à chaque lecture sans session.
- **Confidentialité** : audience personnelle même pour un gestionnaire ; titres et horaires UTC seulement, UID opaque stable. Pas de descriptions, lieux, liens, participants, ressources, absences ou contrats. Fenêtre UTC 93 jours au plus, 500 activités/512 Kio et quota technique tenant ; refus entier si débordement. Aucun cache partagé/ETag/304, erreurs privées, limitation des téléchargements.
- **Opérations** : nouvelle migration calendar_feeds, rollback protecteur, inventaire de portabilité et round-trip OrganizationSchemaTest actualisés. Configurer APP_URL HTTPS et masquer token dans les logs d’entrée ; attribuer la permission via IAM-003 et recetter une révocation. Les copies externes déjà importées restent hors du contrôle de l’application. Prochain P0 : CAL-006.

- **Priorité / taille** : P0 / M
- **Périmètre** : jeton révocable, projection minimale, bornes temporelles et cache privé.
- **Acceptation** : révocation immédiate ; aucun contrat, note interne ou objet d’un autre tenant exposé.
- **Références CDC** : CAL-07, CAL-10.

### UCG-CAL-006 — Gérer reports, annulations et historique

- **État** : `064df63` poussé ; CI et migration confirmées par l’utilisateur avant MATCH-001. Migration vérifiée en lecture seule Ran batch 36. Activation opérationnelle à recetter ; aucun migrate/seed principal ni permission réelle par l’agent.
- **Validation locale** : SQLite complet 1901 réussis/13 exclusions, sélection CI PostgreSQL intégrale 1724 réussis/2 exclusions Redis, PHPStan 8/Pint/Composer validés, 284 tests frontend, build/lint/TypeScript strict et cinq scénarios navigateur calendrier réussis. Deux erreurs intermittentes de la fixture antivirus au premier passage SQLite ont disparu à la relance ciblée puis globale, sans modifier le scanner.
- **Livraison** : report d’horaires/fuseau avec motif 10–1000 caractères, annulation terminale via archive:true + change_reason, anciennes/nouvelles périodes et titre/motif chiffrés conservés. Historique paginé de gestion sous calendar.read/write et boîte personnelle sous calendar.read. Conflits CAL-003 recontrôlés ; ancienne version rejetée ; annulation libère le créneau, conserve les participants et invalide les convocations.
- **Notification** : participants explicitement inscrits avant/après, union bornée à 100, compte figé à l’émission sans création d’accès. Outbox et audit atomiques avec le changement ; tâche tenant calendar-changes, flag CALENDAR_CHANGES_ENABLED=false par défaut, queue default, 25 messages par passage. Reprise sans doublon sous verrous organisation/activité/remise, compte/MFA/droits/lien/audience relus. Message historique signalé si l’activité a encore changé. Aucun e-mail ou connecteur externe.
- **Conservation et accès** : historique et identités de livraison immuables en SQL, FK composites tenant, unicité par version/personne/compte, remise acquise figée. Filtrage personnel avant pagination, y compris annulation, sans fuite de cibles ni compteurs ; compteurs agrégés pour les gestionnaires seulement. Anciennes archives distinctes. Nouvelles tables inventoriées et round-trips des migrations parents actualisés ; down protecteur si historique/annulation conservés.
- **Recette** : deux comptes habilités et worker/scheduler supervisés avant activation ; le flag actif seul ne prouve pas la remise. Le flux ICS reflète le planning à sa prochaine synchronisation, dont le rythme dépend du lecteur externe. CAL-007 et CAL-008 restent P1 ; MATCH-001 est le prochain P0 sportif du backlog.
- **Priorité / taille** : P0 / M
- **Périmètre** : changement d’horaire, raison opérationnelle, notifications et audit.
- **Acceptation** : personnes affectées notifiées ; aucune double notification ; ancienne valeur consultable.
- **Références CDC** : CAL-08.

### UCG-CAL-007 — Ajouter récurrence et changements d’heure

- **Priorité / taille** : P1 / L
- **Périmètre** : série, occurrence, heure locale de référence, fuseau IANA et modification ciblée.
- **Acceptation** : traversée heure d’été/hiver correcte ; distinction claire entre occurrence et série.
- **Références CDC** : CAL-06, REC-04.

### UCG-CAL-008 — Ajouter ressources et logistique

- **Priorité / taille** : P1 / L
- **Périmètre** : salles, matériel, hébergement, transport et tâches de déplacement.
- **Acceptation** : disponibilité et conflits contrôlés ; accès aux justificatifs limité.
- **Références CDC** : CAL-09.

## 11 Module Scrims, matchs, parties et scores

### UCG-MATCH-001 — Construire l’agrégat Match et sa machine d’état

- **État** : poussé, CI et migration confirmées par l’utilisateur avant MATCH-002 ; migration Ran batch 37. Aucun match, permission ni donnée métier réelle créé par l’agent.
- **Validation locale** : SQLite complet 1940 réussis/15 exclusions, sélection CI PostgreSQL intégrale 1756 réussis/2 exclusions Redis, PHPStan 8/Pint/Composer validés ; 291 tests frontend, build/lint/TypeScript strict et quatre parcours navigateur Match/Calendrier/Équipes réussis. Deux scénarios de concurrence réelle à deux processus passent sur PostgreSQL.
- **Livré** : dossier scrim/officiel, jeu disponible, période UTC/fuseau IANA, responsable interne actif, formats BO1/BO3/BO5 (deux participants), lobby/chacun pour soi (2–100), participants internes personne/équipe ou instantané d’adversaire autorisé. Noms/version conservés, jeu immuable, titre/libellés/motifs chiffrés. Aucune consultation du roster d’un autre tenant ni accès accordé à l’adversaire.
- **Statuts** : machine des huit états testée ; commandes opérationnelles proposé → confirmé → en cours → terminé à valider, réouverture confirmé → proposé et annulation terminale depuis proposé/confirmé/en cours. Version optimiste et motif 10–1000 caractères ; confirmation/démarrage relisent l’admissibilité actuelle des références. Les états validé/contesté/forfait sont réservés au futur workflow de preuves et résultats MATCH-003/004, sans validation fictive dans MATCH-001.
- **Accès** : sports.read et sports.write explicites, MFA ; sports.publish + consentement pour élargir le partage. Privé par défaut, audience personnelle ou équipe/organisation partagée, filtrage SQL avant pagination. Mode gestion explicite pour l’éditeur. Historique paginé sans identité d’acteur ni contacts adverses ; aucun droit métier implicite propriétaire/compte technique.
- **Fiabilité** : transaction tenant → match → références, écriture/participants/événement/audit atomiques ; contraintes SQL tenant, forme, unicité, transitions, gel et rétention. Trois tables inventoriées pour la portabilité, rollback protecteur et tests des migrations parents actualisés. Résolutions de références groupées, insertion chiffrée groupée et relations anticipées ; concurrence à deux processus testée sur PostgreSQL.
- **Limites du lot** : période du dossier sans réservation calendrier ni contrôle de chevauchement automatique. Lineups/éligibilité MATCH-002, parties/calcul de score MATCH-003 et validation des résultats MATCH-004 restent distincts. Aucun score, convocation, notification ou partage public automatique. Recetter deux comptes habilités avant usage réel ; MATCH-002 livré ensuite ; prochain P0 courant : MATCH-004.
- **Priorité / taille** : P0 / L
- **Périmètre** : jeu, format, participants multiples, horaire, responsable, visibilité et transitions proposées dans le CDC.
- **Acceptation** : transitions invalides refusées par une règle de domaine testée ; deux équipes non imposées au modèle.
- **Références CDC** : MATCH-01, MATCH-02.

### UCG-MATCH-002 — Affecter lineup et remplaçants

- **Statut** : poussé, CI et migration confirmées par l’utilisateur avant MATCH-003 ; migration vérifiée en lecture seule Ran batch 38. Aucun migrate/seed principal par l’agent.
- **Vérifications** : suite SQLite complète 1954 réussis/17 exclusions attendues, sélection PostgreSQL complète 1772 réussis/deux exclusions Redis, quatre courses concurrentes, 296 tests frontend et quatre parcours Playwright validés ; PHPStan 8, Pint/format:check, Composer strict, build/lint et TypeScript strict réussis.
- **Livré** : affectation d’une composition de match finalisée par équipe interne, avant démarrage ; remplacements motivés entre titulaires et remplaçants retenus avant/pendant le match. Chaque version conserve auteur, date, phase, motif, noms et projection sportive chiffrée, indépendante des changements futurs du roster. Historique paginé sous l’audience du match, sans données administratives.
- **Éligibilité** : contrôle à l’affectation, au remplacement et au démarrage, au moment sportif du match. Roster et personnes encore admissibles, même jeu, équipe non archivée, règlement retenu actif et évaluation complète s’il existe. Sans règlement : statut explicite unassessed, contrôle du roster conservé. Un individu ne peut jouer dans plusieurs camps. Les individus et adversaires externes n’ont pas de lineup imposée ; chaque équipe interne en exige une pour démarrer.
- **Protection** : sports.read/write, MFA, tenant confirmé, version optimiste commune au dossier et journal/audit atomiques ; sports.publish et consentement supplémentaire à une affectation sur match partagé. Plus d’affectation après démarrage, plus de remplacement après fin/annulation. Participants du dossier conservés après première affectation. Correction d’un filtre d’audience préexistant pour les comptes sans fiche Personne.
- **Livraison** : migration additive `2026_10_18_101019_create_match_lineup_revisions`, FK tenant, historique SQL append-only et rollback refusant une table peuplée. Export inventorié et round-trips des migrations parents actualisés. Choix et historique bornés, 500 révisions au plus par match, lectures courantes groupées et faits d’éligibilité groupés par composition. API et panneau React complets, confirmations et toasts, conflits sans rejeu, recette mobile simulée.
- **Suite** : MATCH-003 pour parties/cartes/calcul du score ; MATCH-004 pour soumission/preuves/approbation. Aucune écriture réelle ni migration principale exécutée par l’agent.
- **Priorité / taille** : P0 / L
- **Périmètre** : sélection, éligibilité, changements avant/pendant le match et snapshot.
- **Acceptation** : chaque changement indique auteur et moment ; historique indépendant du roster actuel.
- **Références CDC** : MATCH-03, TEAM-05.

### UCG-MATCH-003 — Saisir parties, cartes et score de série

- **Statut** : poussé au commit 085e3cc, CI validée par l’utilisateur avant MATCH-004 ; migration constatée en lecture seule Ran batch 39. Aucun migrate/seed principal par l’agent.
- **Vérifications locales** : SQLite complet 1987 réussis/20 exclusions attendues, PostgreSQL complet 1805 réussis/deux exclusions Redis, 113 tests ciblés dont trois nouvelles courses à deux processus, 304 tests frontend et cinq parcours navigateur simulés ; PHPStan 8, Pint, Composer strict, build/lint et TypeScript strict réussis.
- **Livré** : règles explicites immuables, parties avec carte/mode/version du jeu, chaque participant du dossier et scores chiffrés ; BO1/BO3/BO5 en victoires, lobby/individuel en somme des points. Nuls et égalités finales soumis à allow_draw ; forfait/abandon de partie aux scores explicitement attribués et motivés ; remake sans score, conservé sans compter. Refus d’une série déjà terminée et des agrégats imposés par le client.
- **Sécurité** : audience du dossier, sports.read/write, MFA, publication et consentement sur match partagé, validation fermée dans Form Request/service, verrou tenant → match et version commune aux transitions/compositions/scores. Historique append-only avec auteur/date/motif et composition en vigueur à la saisie, sans reconstitution tardive prétendue ; audit et version atomiques.
- **Livraison** : deux tables additives, FK composites, contraintes SQL de phase/version/séquence/limite et immutabilité, rollback protecteur ; inventaire de portabilité et migrations parents en test actualisés. API et panneau React, confirmation humaine, pagination et conflit sans rejeu. Limite 100 tentatives et 100 participants, trois requêtes de parties par lecture de page/résumé ; aucun cache privé partagé.
- **Limites** : résultats non approuvés ; preuves/soumission/approbation et statut forfait du dossier réservés à MATCH-004. Correction et recalcul MATCH-006, diffusion MATCH-007. Aucune donnée réelle, permission, migration principale ou notification externe créée par l’agent. Prochain P0 : MATCH-004.
- **Priorité / taille** : P0 / XL
- **Périmètre** : game instances, participants, carte/mode/version, BO1/BO3/BO5, nul, forfait, abandon et remake.
- **Acceptation** : calcul par stratégie de format ; BO3 incohérent refusé avec message exploitable.
- **Références CDC** : MATCH-04, MATCH-05, REC-08.

### UCG-MATCH-004 — Proposer et valider les résultats

- **Statut** : commit f25f966 poussé ; CI et migration confirmées par l’utilisateur avant MATCH-005. Migration vérifiée en lecture seule Ran, batch 40. Départ propre et synchronisé sur staging/origin/staging.
- **Livré** : soumission de résultat joué calculé complet, ou forfait/abandon de rencontre avec bénéficiaire explicite sans scores fictifs. Snapshot chiffré des règles/participants/série/parties et preuve référencée textuelle confirmée ; la pièce matérielle reste dans l’espace privé de l’organisation, sans dépôt/téléchargement automatique ni garantie d’intégrité externe.
- **Approbation** : sports.read/validate et vérification personnelle déclarée, compte distinct du soumissionnaire et de tous les auteurs des règles/parties. Pas de write exigé pour valider, ni de contournement de l’audience privée ; une mutation partagée exige publish et consentement. Résultat joué/abandon validé, forfait forfeited ; contestation motivée, conservation de toutes les décisions puis nouvelle soumission avant réapprobation.
- **Fiabilité** : MatchResultWorkflow de domaine, Service/Repository/Form Request/Policy/Resources ; version commune au dossier, soumission exacte, garde tenant → Match, journal → dossier → statuts → audit atomiques. Journal append-only et gel SQL des scores ; migration additive adapte les gardes Match sans modifier les anciens fichiers. Down protecteur et restauration des anciennes gardes seulement à vide, portabilité exhaustive et migrations parents actualisées.
- **Vérifications locales** : SQLite complet 2017 réussis/24 exclusions attendues, PostgreSQL complet 1829 réussis/deux exclusions Redis ; ciblage PostgreSQL 139 réussis avec quatre nouvelles courses à deux processus, gel SQL isolé vérifié sur SQLite/PostgreSQL. 312 tests frontend, six parcours navigateur simulés, build/lint/TypeScript strict, PHPStan 8, Pint et Composer strict réussis.
- **Limites** : 200 événements, historiques paginés, 100 participants/tentatives par snapshot ; aucune résolution de litige de tournoi ni portail adverse. Pas de correction de partie (MATCH-006), ni diffusion (MATCH-007). Aucun compte/droit/match réel, migration principale ou notification externe par l’agent. Prochain P0 : MATCH-005.
- **Priorité / taille** : P0 / L
- **Périmètre** : soumission interne, preuve, approbation distincte, version optimiste, contestation interne et motif ; aucun portail adversaire ni arbitrage de tournoi.
- **Acceptation** : validation autorisée uniquement ; conflit 409 sur version obsolète ; aucune perte silencieuse.
- **Références CDC** : MATCH-06, MATCH-07, REC-09.

### UCG-MATCH-005 — Gérer préparation, VOD et débrief

- **Vérifications** : SQLite complet 2058 réussis/25 exclusions attendues ; PostgreSQL complet 1869 réussis/deux exclusions Redis, puis 42 tests finaux MATCH-005 dont concurrence réelle. Frontend 319 tests et cinq parcours navigateur sportifs simulés ; PHPStan 8, Pint, Composer strict, build/lint/TypeScript strict réussis. Migration principale non appliquée par l’agent.
- **Statut** : commit f40d2a6 poussé ; CI et migration validées par l’utilisateur avant MATCH-006. Migration vérifiée en lecture seule Ran, batch 41 ; départ propre et synchronisé sur staging/origin/staging.
- **Livré** : objectifs, notes, liens VOD HTTPS avec horodatage/annotation, débriefs et actions de suivi à échéance UTC et état à faire/terminée. Panneau React accessible depuis chaque match, filtres et historique paginés.
- **Confidentialité** : espaces fixes internal/strategy/shared ; droits sports.notes.read/write et sports.strategies.read/write explicites, cumulés avec sports.read/write. Aucun droit implicite pour le propriétaire, administrateur, auteur ou sports.validate. L’espace shared exige sports.publish et consentement à chaque création/révision ; lecture limitée à l’audience authentifiée du match. La vitrine anonyme reste dans son lot P1, sans endpoint public ajouté ici.
- **Intégrité** : versions append-only chiffrées, audience/type immuables, FK tenant/Match/racine et gardes SQLite/PostgreSQL ; aucune réécriture du score, statut ou version sportive, même après approbation. Verrou tenant → match, version du contenu et conflit 409 sans rejeu. Audit minimal atomique et portabilité chiffrée intégrée ; down refuse un historique peuplé.
- **Bornes** : 500 contenus par match, 100 versions par contenu, 10000 caractères de notes, horodatage 0–604800 secondes, pages 1–100. Aucune VOD chargée, intégrée, contrôlée ou téléchargée par le serveur ; lien ouvert explicitement sans référent. Pas d’attribution automatique de droits, notification externe ou migration principale par l’agent.
- **Suite** : après validation, MATCH-006 pour la correction motivée et le recalcul des scores, puis MATCH-007.
- **Priorité / taille** : P0 / M
- **Périmètre** : objectifs, notes, liens VOD, annotations et actions de suivi.
- **Acceptation** : stratégies protégées par permission dédiée ; contenus publics séparés des notes internes.
- **Références CDC** : MATCH-08.

### UCG-MATCH-006 — Recalculer après correction d’un score

- **Vérifications finales** : SQLite complet 2101 réussis/28 exclusions attendues ; PostgreSQL complet 1917 réussis/deux exclusions Redis, 88 ciblés dont trois courses réelles ; frontend 327 tests et six parcours navigateur sportifs simulés. PHPStan 8, Pint, Composer strict, build/lint/TypeScript strict réussis. Nouvelle migration principale Pending.
- **Statut** : livré localement sur staging ; push, CI distantes, migration additive et recette supervisée à effectuer après livraison. Aucun migrate/seed principal ni attribution de droit réel par l’agent.
- **Livré** : correction motivée de scores/résultats de parties existantes, journal chiffré avant/après avec auteur/date et clé d’idempotence ; une même clé et un même contenu renvoient la même correction, une clé réutilisée autrement est refusée. Traitement asynchrone durable, reprise explicite si nécessaire et agrégat unique par correction.
- **Publication contrôlée** : un résultat soumis/approuvé est d’abord contesté atomiquement. Pendant le recalcul, aucun total exploitable ni nouvelle mutation sportive. Après traitement : nouvelle soumission avec preuve et score_revision fraîche, puis approbation indépendante excluant aussi les auteurs des corrections. Les anciennes preuves/décisions restent conservées ; aucune revalidation ou diffusion externe automatique.
- **Droits** : sports.read/write/correct, MFA et confirmation du tenant ; sports.publish et consentement sur match partagé. Les droits sont revérifiés dans la tâche. Aucune permission implicite pour un rédacteur, validateur ou administrateur. Lectures selon audience du match ; retry autorisé sans changer le contenu déjà confirmé.
- **Fiabilité** : version du match + score_revision ; verrou tenant → match, journal/audit atomiques, gardes SQL supplémentaires et projections de parties corrigées sans réécriture des sources. Cache de série privée isolé tenant/match/version/correction, invalidé avant correction et application ; état SQL pending prioritaire. Interface de file en Application, implémentation et TenantJob en Infrastructure.
- **Bornes et limites** : 100 corrections/match, 100 parties et participants par correction ; pages de journal 1–10. Partie, carte, format, composition et exclusion remake conservés. Série cohérente exigée ; un résultat complet ne peut devenir incomplet. Pas d’ajout/suppression de partie ni modification des règles via cette API. Les vues publiques et événements temps réel restent MATCH-007 ; aucun classement de tournoi externe recalculé.
- **Exploitation** : file persistante database/redis/sqs/beanstalkd et worker default nécessaires ; sync/null/deferred/background refusés avant invalidation d’une approbation. Une panne d’envoi conserve la demande pending pour reprise ; aucune activation ni consommation de file métier principale par l’agent.
- **Priorité / taille** : P0 / L
- **Périmètre** : audit avant/après, recalcul asynchrone, invalidation de cache et republication contrôlée.
- **Acceptation** : correction motivée, idempotente et propagée aux agrégats sans faux doublon.
- **Références CDC** : MATCH-07, REC-10.

### UCG-MATCH-007 — Diffuser les scores autorisés en temps réel

- **Statut** : commit `2c5d622` poussé, CI validées par l’utilisateur avant COMP-001. MATCH-006 (`22f46fc`) poussé, CI/migration validées ; Ran batch 42 vérifié en lecture seule. Aucune migration MATCH-007.
- **Vérifications** : SQLite complet 2121 réussis/28 exclus ; PostgreSQL CI complet 1935 réussis/2 exclus Redis puis 20 cas finaux dédiés ; frontend 338 tests, 7 parcours navigateur, captures 320/375 px. PHPStan 8, Pint, composer validate, tsc/build et oxlint verts. Signature réelle testée au backend ; WebSocket simulé au navigateur, recette du service Reverb et worker réel encore requise.
- **Livraison** : canal privé tenant/match, signature avec MFA/contexte confirmé/audience sportive, invalidations après commit, projection HTTP cohérente sans preuve/stratégie, vue dédiée provisoire/validée, correction en attente masquée, forfait approuvé affiché.
- **Fiabilité** : broadcasts 3 tentatives/30 s/1–5–15 s, déduplication et regroupement client, relecture au réabonnement/retour en ligne et secours visible 30 s ; panne après commit journalisée sans échec trompeur de l’action sportive. Aucun outbox : rattrapage par relecture.
- **Confidentialité** : WebSocket limité aux identifiants d’invalidation et horodatage ; chaque lecture contrôle les droits actuels. Aucun canal public ou accès adverse ; publication publique distincte en P1.
- **Suite** : recette Reverb + file persistante broadcasts en environnement isolé ; aucune activation principale automatique. COMP-001 livré dans le lot suivant ; MATCH-008 reste P1.

- **Priorité / taille** : P0 / L
- **Périmètre** : événements après transaction, canaux publics ou privés incluant le tenant et fraîcheur de la donnée.
- **Acceptation** : aucune stratégie ou donnée privée sur un canal public ; délai mesurable depuis la validation.
- **Références CDC** : MATCH-10, LIVE-03, LIVE-06.

### UCG-MATCH-008 — Ajouter veto et sélection des cartes

- **Priorité / taille** : P1 / L
- **Périmètre** : règles par jeu/compétition, ordre des actions et audit.
- **Acceptation** : séquence invalide refusée ; résultat reproductible à partir de l’historique.
- **Références CDC** : MATCH-09.

## 12 Module Suivi des compétitions externes

Ce module suit les participations de l’organisation à des compétitions gérées ailleurs. Il ne crée ni événement compétitif organisé sur la plateforme, ni inscription publique, ni moteur de tournoi. Le module technique `Competition` reste propriétaire des matchs et de ce suivi interne.

### UCG-COMP-001 — Suivre les compétitions externes

- **État** : commit `abdb569` poussé ; CI et migration validées par l’utilisateur avant COMP-002. Migration constatée Ran, batch 43. Anciennes migrations intactes.
- **Validation** : SQLite complet 2160 réussis/31 exclusions attendues ; PostgreSQL 67 cas finaux réussis (42 COMP-001 et démontages de schémas corrigés après trois erreurs dans la sélection globale). Frontend 349 tests, 8 parcours Chrome, captures 320/375 px, PHPStan 8/Pint/Composer et lint/tsc/build verts.
- **Livré** : dossier par tenant, référence et jeu immuables, organisateur, région/saison, URL HTTPS, dix contacts structurés, modifications motivées, historique chiffré et archivage définitif en lecture seule. API et panneau React paginés.
- **Documents** : un règlement logique et des notes privées, versions texte chiffrées et immuables, téléchargement authentifié `.txt` des versions anciennes avec contrôle actuel des droits. Aucun upload binaire dans ce lot ; les pièces d’inscription restent COMP-002.
- **Accès** : sports.read + sports.competitions.read pour consulter, sports.competitions.write en supplément pour modifier ; MFA et confirmation du tenant aux écritures. Aucune permission accordée automatiquement aux rôles existants.
- **Fiabilité** : verrous organisation/dossier, contrôle optimiste des versions et contraintes SQL PostgreSQL/SQLite ; conflits 409 sans rejeu client, archivage concurrent protégé. Audit transverse limité aux identifiants, export tenant conserve le chiffrement.
- **Bornes** : 10 000 dossiers/tenant, 1000 révisions/dossier, 100 documents/dossier et 100 versions/document ; pages dossiers/jeux 1–25, historiques/documents 1–10, contenu texte 20 000 caractères. Index tenant et requêtes sans N+1 ; pas de cache partagé de contenu privé.
- **Suite** : après push/CI, sauvegarde et migration utilisateur, délégation ciblée puis recette de deux comptes/révocations. COMP-002 est le prochain P0. Aucune donnée métier réelle, activation de worker ou inscription auprès d’un organisateur par l’agent.

- **Priorité / taille** : P0 / M
- **Périmètre** : organisateur, jeu, région, saison, règlement versionné, contacts et URL.
- **Acceptation** : données limitées au tenant, documents privés et historique des changements importants.
- **Références CDC** : COMP-01.

### UCG-COMP-002 — Suivre les dossiers d’inscription externes et échéances

- **Livraison** : commit `67ddc29` poussé ; CI et migration validées par l’utilisateur avant COMP-003. Un suivi par compétition, révisions chiffrées et motivées, lecture seule après archivage.
- **Validation** : SQLite complet 2195 réussis/34 exclusions attendues ; PostgreSQL CI complet 2017 réussis/2 exclusions Redis puis 72 cas finaux ciblés. Frontend 356 tests, deux parcours Chrome, captures 320/375 px ; PHPStan 8/Pint/Composer strict/lint/tsc/build verts.
- **Fonctionnel** : inscription/paiement/roster/check-in déclaratifs, exigences externes sans déduction contractuelle, roster de 20 personnes, 50 pièces/tâches avec état, échéance UTC et responsable. Statut approuvé du roster à réévaluer si sa composition change.
- **Pièces** : suivi des pièces demandées/remises et lien facultatif à une version texte privée COMP-001 ; aucun upload binaire ni transmission automatique à l’organisateur dans ce lot.
- **Accès** : sports.read + sports.competitions.read + sports.registrations.read ; sports.registrations.write en supplément aux écritures et choix des personnes. MFA/CSRF/contexte confirmé ; aucun grant implicite ni modification des rôles existants.
- **Rappels** : journal interne personnel durable et dédupliqué (7 jours, 1 jour, dépassé), contrôle actuel des destinataires ; cache de complétion indépendant. Aucun e-mail. Intégration TEN-006, traitement tournant limité à 25 dossiers avec budget de 45 secondes vérifié entre dossiers, flag désactivé par défaut.
- **Schéma** : deux nouvelles tables tenant, gardes d’immutabilité et de séquence PostgreSQL/SQLite, inventaire de portabilité chiffré complet, down refusé sur données existantes. Migration `2026_10_18_231153` constatée Ran, batch 44.
- **Suite** : délégation ciblée et recette des révocations. Scheduler/worker et activation des rappels à valider séparément. Prochain P0 : COMP-003.

- **Priorité / taille** : P0 / L
- **Périmètre** : suivi interne du paiement renseigné, des pièces, du roster soumis, de sa validation par l’organisateur, du statut de check-in externe, des règles d’âge/résidence/rang et des tâches manager.
- **Acceptation** : éléments manquants visibles et rappelés ; aucune éligibilité déduite automatiquement d’un contrat ; inscription et check-in effectués chez l’organisateur externe, sans accès participant ni partage automatique entre tenants.
- **Références CDC** : COMP-02, COMP-04, COMP-05.

### UCG-COMP-003 — Relier résultats, classement et dotation

- **Livraison** : implémenté localement le 19 octobre 2026 ; push/CI et migration principale restent à valider.
- **Validation** : 34 nouveaux cas backend et 16 frontend. SQLite complet 2224 réussis/37 exclusions, puis 143 cas finaux ciblés ; sélection CI PostgreSQL intégrale en deux bases isolées, 2051 réussis/2 exclusions Redis, plus 146 cas finaux ciblés. Frontend 372 tests, trois parcours Chrome dont COMP-003 rejoué après finition, captures 320/375 px ; PHPStan 8/Pint/Composer strict/lint/tsc/build verts. Ordre des anciens tests de rollback adapté ; aucune ancienne migration modifiée.
- **Sportif** : 20 liens de matchs maximum, même tenant/jeu, audience actuelle conservée dans le dossier et l’historique. Choix paginés, lecture groupée des références. Classement communiqué manuellement par l’organisateur avec source et date ; résultats affichés uniquement s’ils sont encore approuvés, hors recalcul en attente.
- **Financier** : journal séparé sous financial.read/write en plus de sports.read + sports.competitions.read ; le droit sportif d’écriture ne suffit pas. Montants entiers en unités mineures, devise explicite, absence distincte de zéro, réception datée. Aucune conversion ni exécution de paiement.
- **Fiabilité** : deux tables tenant, contenu chiffré, révisions append-only et audit atomique ; versions du dossier, du suivi et des nouveaux matchs vérifiées. Archives figées, historique paginé, gardes SQL PostgreSQL/SQLite et export chiffré complet. Un match rattaché conserve son jeu.
- **Mise en service** : migration additive `2026_10_19_005858`, sans reprise de données ni seed principal. Recette des deux profils et révocations après push/CI et migration utilisateur. STAT-001 est le prochain P0 proposé ; Reverb/broadcasts et rappels COMP-002 restent à recetter séparément.

- **Priorité / taille** : P0 / M
- **Périmètre** : matchs, classement, dotation annoncée et réellement reçue.
- **Acceptation** : montants et résultats soumis aux droits dédiés ; source et date conservées.
- **Références CDC** : COMP-03.

## 13 Module Statistiques et performance

### UCG-STAT-001 — Créer le dictionnaire versionné des métriques

- **Livraison** : implémenté localement le 19 octobre 2026 ; push/CI et migration principale à valider.
- **Définitions** : code stable par jeu, unité, niveau de mesure distinct de l’opérateur d’agrégation, formule documentaire, comportement explicite des ratios à dénominateur nul, mesure subjective/objective, disponibilité et limites ; contexte mode/rôle/patch issu du catalogue commun.
- **Versions** : identité immuable, corrections et retrait/réactivation append-only, versions anciennes consultables par UUID ; auteur, motif, date et request_id conservés. Compteurs et immutabilité protégés SQL PostgreSQL/SQLite ; publication de la version globale atomique.
- **Accès** : lecture des seules métadonnées par compte vérifié ; écriture superadmin plateforme + MFA et confirmation globale. Aucun grant tenant implicite. Trois tables globales exclues des exports privés ; observations et valeurs restent STAT-002.
- **Mise en service** : migration additive `2026_10_19_094551`, dictionnaire initialement vide, aucune métrique réelle ni reprise automatique. Recette lecteur/superadmin, historique, révocation et concurrence après migration utilisateur. Prochain P0 proposé : STAT-002.
- **Validation locale** : 28 cas backend du dictionnaire, deux courses PostgreSQL entre opérateurs distincts, sept tests frontend et un parcours Chrome mobile ; suites complètes du projet et contrôles statiques verts. Vérification finale ciblée : 53/53 SQLite et 55/55 PostgreSQL. CI distante après push utilisateur.

- **Priorité / taille** : P0 / L
- **Périmètre** : code, définition, unité, agrégation, formule, jeu, mode, rôle, patch et disponibilité.
- **Acceptation** : version de formule conservée ; métriques globales séparées des valeurs privées des tenants.
- **Références CDC** : STAT-01, STAT-09.

### UCG-STAT-002 — Saisir et corriger les observations

- **Livraison** : push/CI et migration 2026_10_19_113618 validés par l’utilisateur avant STAT-003A ; migration constatée Ran batch 47.
- **Saisie** : valeurs décimales exactes, indisponibilité explicite, source et contexte privés immuables, joueur, période, échantillon, partie/manche ou séance selon le niveau ; version de métrique et formule conservées.
- **Corrections** : ajout de révisions motivées, invalidation/réactivation, contrôle de version concurrente et audit atomique ; provenance importée jamais remplacée. Importeur STAT-003 et calculs STAT-004 séparés.
- **Accès** : permissions statistiques distinctes sous MFA, confirmation tenant ; aucun accès implicite propriétaire/admin. Audience match ordinaire réappliquée aux listes et historiques ; tables et exports privés isolés par organisation.
- **Validation locale** : 34 cas backend du registre et une course PostgreSQL entre deux correcteurs, six tests frontend et un parcours Chrome mobile. Suite SQLite complète : 2288 réussis, 40 ignorés ; frontend complet : 385 réussis. Derniers contrôles ciblés : 34/34 SQLite et 35/35 PostgreSQL. Sélection générale PostgreSQL validée en lots : 2080 tests ordinaires et 36 courses réussis, deux ignorés Redis ; CI distante à exécuter après push utilisateur.

- **Priorité / taille** : P0 / L
- **Périmètre** : source, période, partie, joueur, contexte, valeur absente, correction autorisée et historique.
- **Acceptation** : indisponible différent de zéro ; donnée importée jamais écrasée silencieusement.
- **Références CDC** : STAT-02, STAT-04, STAT-05, STAT-08.

### UCG-STAT-003 — Construire le pipeline d’import statistique

- **Découpage validé** : STAT-003A = dépôt, validation et prévisualisation sans observation ; STAT-003B = confirmation, traitement, idempotence incluant le tenant, erreurs et reprise.
- **STAT-003A livré localement** : CSV UTF-8 1 Mio/100 lignes, schéma fermé, UUID joueur du tenant et version de métrique explicites ; règles de saisie partagées, erreurs par ligne et doublons du fichier signalés. Fichier et aperçu chiffrés privés au déposant dans son tenant ; nouvelle permission explicite sous MFA, confirmation tenant et audience match réappliquée.
- **Mise en service A** : migration additive `2026_10_19_143154` après push/CI/sauvegarde utilisateur, puis recette avec habilitation explicite. Aucune route de confirmation/traitement, aucune observation créée, aucun worker nécessaire.
- **Validation A** : 21 nouveaux cas backend, six frontend et un parcours Chrome mobile ; 137/137 PostgreSQL ciblés, suite SQLite complète 2309 réussis/40 ignorés, frontend complet 391 réussis ; typage, lint, build, Pint et PHPStan verts. Ces totaux de suites couvrent tout le projet.
- **Correctif CI A** : ordre de démontage des dépendances de l’index artefact corrigé dans PersonSchemaTest et TenantStorageSchemaTest, deux erreurs reproduites puis 54/54 PostgreSQL et SQLite réussis. Sélection PostgreSQL complète CI rejouée : 2137 réussis, deux ignorés, aucun échec. Anciennes migrations et code applicatif inchangés ; aucune nouvelle migration pour ce correctif.
- **STAT-003B livré localement** : confirmation et revalidation, déduplication par organisation entre fichiers/déposants, conflits sans écrasement, jobs reports de dix lignes avec transaction par ligne, résultats privés chiffrés et reprise explicite par génération. Migration additive `2026_10_19_175141`, traitement désactivé par défaut ; validation de livraison et recette utilisateur avant clôture du parent. Voir etat-projet.md et conventions-api.md.

- **Priorité / taille** : P0 / XL
- **Périmètre** : fichier privé, validation de schéma, prévisualisation, correspondance des joueurs, déduplication incluant le tenant, erreurs et reprise.
- **Acceptation** : import idempotent, lignes refusées identifiables et aucune observation créée avant confirmation.
- **Références CDC** : section 10.3, REC-06, REC-07.

### UCG-STAT-004 — Calculer agrégats et ratios

- **Priorité / taille** : P0 / L
- **Périmètre** : jobs de calcul, composants des ratios, dénominateur zéro, taille d’échantillon et version de calcul.
- **Acceptation** : aucun calcul par moyenne naïve de ratios ; recalcul ciblé après correction.
- **Références CDC** : STAT-06, STAT-07, STAT-09.
- **Livraison locale** : backend et interface de sélection d’un échantillon fixe ; opérations décimales, ratios par composants additifs versionnés, absence distincte de zéro, historique immuable et invalidation/recalcul ciblé après correction. Résultats privés au demandeur avec droits et audience actuels. Migration additive `2026_10_19_183908`, calculs désactivés par défaut. Push, CI distante, sauvegarde, migration et recette restent nécessaires avant activation ; détails et validations dans etat-projet.md. Les profils/comparaisons et l’extension automatique des échantillons relèvent de STAT-005.

### UCG-STAT-005 — Livrer profils et comparaisons contextualisés

- **Priorité / taille** : P0 / L
- **Périmètre** : filtres saison, patch, rôle, carte, adversaire, période, source et fraîcheur.
- **Acceptation** : comparaisons uniquement sur contextes cohérents ; données incomplètes clairement signalées.
- **Références CDC** : STAT-01, STAT-03, STAT-04, STAT-10.
- **Livré localement** : profils en lecture seule, choix du jeu/métrique et de sa version historique, filtres exacts par saison/patch/rôle/mode/carte/adversaire/région/source/période, groupes cohérents et évolution paginée des observations. Taille d’échantillon, dates de source et de correction, absence/invalidation et motifs de refus de comparaison explicites. Comparaison de deux joueurs seulement sur même définition, contexte et couverture de périodes ; écart décimal et alerte sur tailles différentes, aucun classement automatique. Lecture bornée à 1 000 candidats par joueur et 366 jours inclusifs ; dépassement refusé, jamais tronqué. Aucune migration ni activation des workers. Validation et limites dans etat-projet.md ; CI distante et recette avant clôture de livraison.

### UCG-STAT-006 — Créer le framework de connecteurs

- **Priorité / taille** : P1 / XL
- **Périmètre** : interface fournisseur, identifiants techniques du tenant, quotas, nouvelles tentatives, correspondance, idempotence et état de synchronisation.
- **Acceptation** : panne visible, données précédentes datées, reprise contrôlée et mode manuel maintenu.
- **Références CDC** : sections 10.4 et 18.2, REC-12.

## 14 Module Contrats et documents

### UCG-CTR-001 — Modéliser contrats, parties et statuts

- **Priorité / taille** : P0 / L
- **Périmètre** : types, parties, propriétaire, dates, obligations et machine d’état du brouillon à l’archive.
- **Acceptation** : transitions contrôlées, tenant unique et distinction signé/en vigueur/éligible.
- **Références CDC** : CTR-01, CTR-09.
- **État au 14 octobre 2026** : implémenté localement avec API, interface,
  Policy/Form Requests, Service/Repository, migration additive et tests.
  Référence/type fixes, parties People du tenant ou externes déclarées,
  responsable administratif, dates, obligations et historique append-only.
  Termes figés après approbation ; approbateur distinct du préparateur ;
  signature reçue hors plateforme déclarée, sans fichier rattaché à ce stade.
  En vigueur exige active et période UTC courante (fin incluse) ; éligibilité
  sportive non évaluée. Archive terminale après régularisation des obligations.
  Champs privés chiffrés, audit atomique, FK tenant composites, versions UUID,
  pagination et contrôles SQLite/PostgreSQL. Aucun montant avant CTR-004.
  CTR-001 87953e0 poussé, CI vertes et migration validés par l’utilisateur ;
  Ran, batch 20, vérifié en lecture seule avant CTR-002.

### UCG-CTR-002 — Versionner documents et avenants

- **Priorité / taille** : P0 / L
- **Périmètre** : document, versions immuables, version signée et avenants liés.
- **Acceptation** : aucun remplacement silencieux ; historique et hash du fichier conservés.
- **Références CDC** : CTR-02, CTR-03.
- **État au 14 octobre 2026** : implémenté localement avec API et interface.
  Un original par contrat, avenants distincts liés à cet original signé,
  versions numérotées append-only, empreinte SHA-256 calculée sur les octets
  reçus, fichier et nom chiffrés, acteur et date conservés. Copie déclarée
  signée identifiée par UUID/numéro/hash, liaison définitive et document figé.
  Avenant sans modification implicite des termes ou de la période du contrat.
  PDF ≤5 Mo conservés en stockage privé TEN-005 sous quotas, en attente d’analyse,
  aucune route de téléchargement ou URL exposée avant CTR-003. Pas de certification
  de signature ni d’approbation financière. Migrations déployées inchangées,
  gardes SQLite/PostgreSQL, export fermé, audit atomique et nettoyage du nouveau
  fichier après rollback. Commit `ce268f6` poussé et CI validées par l’utilisateur ;
  migration confirmée Ran, batch 21, avant CTR-003.

### UCG-CTR-003 — Sécuriser dépôt et téléchargement des fichiers

- **Priorité / taille** : P0 / L
- **Périmètre** : stockage privé par tenant, type/taille, analyse de contenu, URL temporaire et nouvelle autorisation au téléchargement.
- **Acceptation** : exécutable refusé, lien expiré inutilisable et aucun chemin d’un autre tenant devinable ou accessible.
- **Références CDC** : CTR-07, SEC-06, REC-14.

- **État au 14 octobre 2026** : implémenté localement, API et interface.
  ClamAV INSTREAM choisi par l’utilisateur, analyse asynchrone après dépôt,
  relance idempotente/optimiste, résultats retenus et audit minimal. Vérifications
  taille/hash/PDF, signatures antivirus ≤7 jours, aucune autorisation sans clean.
  Liens cinq minutes liés au compte et dernier scan, session MFA et droits
  recontrôlés après I/O, verdict valable 24 h, corruption invalidant le clean.
  Historique paginé, états/motifs, relance confirmée, actualisation et téléchargement
  Blob privé ; archive documentaire toujours immuable. Migration additive,
  gardes SQL SQLite/PostgreSQL, export fermé, aucun backfill automatique.
  Protocole testé avec pair TCP local ; test réel ClamAV opt-in à exécuter après
  configuration du démon/freshclam. Dépendance opérationnelle désactivée par défaut,
  téléchargement bloqué jusque-là. Après push/CI/migration et mise en service
  ClamAV/worker, prochain P0 : CTR-004. Configuration dans README section CTR-003.

### UCG-CTR-004 — Gérer approbations et champs financiers

- **Priorité / taille** : P0 / L
- **Périmètre** : séparation préparation, approbation, signature ; permissions au niveau des montants et modalités sensibles.
- **Acceptation** : un coach non habilité ne reçoit aucun champ financier ; décisions tracées.
- **Références CDC** : CTR-05, REC-01.
- **État au 15 octobre 2026** : implémenté localement. Conditions financières
  structurées chiffrées, accès dédiés financial.read/write/validate, nombres
  décimaux en chaînes, aucune valeur financière dans les réponses administratives.
  Approbation distincte du préparateur, option de troisième intervenant pour la
  déclaration de signature, figée après approbation. Avenants : approbation de
  la dernière préparation et version financière, invalidation après modification,
  copie signée liée à sa décision. Historique paginé et audit sans montants.
  Tous les documents du dossier exigent financial.read dès que des conditions
  financières structurées existent ; recontrôle des anciens liens temporaires.
  Migration additive SQLite/PostgreSQL, sans backfill ni attribution de droits.
  CI CTR-003 confirmée verte avant ce travail ; mise en service ClamAV à confirmer.
  Push, CI verte et migration CTR-004 confirmés par l’utilisateur avant CTR-005 ;
  migration Ran batch 23 vérifiée en lecture seule. Prochain P0 traité : CTR-005.

### UCG-CTR-005 — Planifier échéances et rappels

- **Priorité / taille** : P0 / M
- **Périmètre** : échéance, préavis, renouvellement, règles 90/30/7 configurables et jobs idempotents.
- **Acceptation** : propriétaire notifié, nouvelle tentative sans doublon et statut du traitement consultable.
- **Références CDC** : CTR-04, REC-16.
- **État au 15 octobre 2026** : implémenté localement. Réglages versionnés par
  dossier, dates calendaires tenant, seuils 90/30/7 configurables, rattrapage du
  seuil atteint le plus urgent, sans envoi après la date. Application et e-mail
  retenus par l’utilisateur ; SMTP Laravel préparé, Brevo API ultérieur via port.
  Responsable lié à un compte vérifié, adhésion/MFA/droits actuels recontrôlés ;
  lecture financière exigée si nécessaire. Boîte personnelle et historique paginés.
  Tâche asynchrone TEN-006, journal durable et déduplication des reprises. Les
  résultats SMTP ambigus ne sont jamais relancés automatiquement ; vérification
  humaine auditée et acceptation du risque de doublon avant retry. Aucune promesse
  exactly-once SMTP ni de livraison finale. Activation technique explicite après
  migration et configuration, pas d’envoi ni de migration principale par l’agent.
  Push, CI verte et migration CTR-005 confirmés par l’utilisateur avant CTR-006 ;
  migration Ran batch 24 vérifiée en lecture seule. Prochain P0 traité : CTR-006.

### UCG-CTR-006 — Auditer consultations et exports sensibles

- **Priorité / taille** : P0 / M
- **Périmètre** : lecture, téléchargement, export, acteur, motif éventuel et tenant.
- **Acceptation** : audit consultable uniquement par les rôles autorisés et dépourvu du contenu du contrat.
- **Références CDC** : CTR-06, CTR-10.
- **État au 15 octobre 2026** : implémenté localement. Consultations HTTP réussies
  des contrats et données sensibles journalisées ; lien temporaire et préparation
  du téléchargement distincts. Exports opérateur TEN-007 existants : demande,
  relance, génération et ouverture tracées ; opérateur recontrôlé après stockage.
  Métadonnées fermées sans contenu contractuel, montant, nom, fichier, URL signée
  ni motif libre. Motif facultatif catégorisé, journal append-only, lecture réservée
  à audit.view/MFA et sans accès plateforme implicite. Écran mobile, filtres et
  pagination par curseurs IAM-006. Réponse sensible bloquée si audit indisponible.
  Migration additive et inventaire d’export mis à jour ; aucun accès passé inventé.
  Pas de nouvel export CSV métier ni de signature électronique dans ce ticket.
  Push/CI/migration CTR-006 à effectuer par l’utilisateur avant mise en service.
  Dernier P0 Contrats implémenté ; prochain P0 du backlog : UCG-SPON-001.

### UCG-CTR-007 — Intégrer la signature électronique

- **Priorité / taille** : P1 / XL
- **Périmètre** : fournisseur abstrait, envoi, callbacks signés, événements dupliqués, preuve et récupération du document final.
- **Acceptation** : webhook idempotent, preuve archivée, version signée clairement identifiée et échec récupérable.
- **Références CDC** : CTR-08.

## 15 Module Sponsors et partenariats

### UCG-SPON-001 — Gérer prospects et contacts

- **État** : livré (`68ec32e`), CI et migration validées par l’utilisateur avant SPON-002 ; migration vérifiée Ran, batch 26.
- **Livraison** : sociétés privées, contacts distincts de People/Identity, opportunités déclaratives et échanges manuels append-only. Permissions partnerships.read/write avec MFA, recherche paginée, API/React, versions optimistes, archives immuables, audit minimal, contraintes composites SQLite/PostgreSQL et export opérateur. Aucun envoi, accord, montant ou portail créé.

- **Priorité / taille** : P0 / M
- **Périmètre** : organisation sponsor, contacts, opportunité, statut et historique saisi dans la plateforme.
- **Acceptation** : recherche paginée et limitée au tenant ; données RH UCG inaccessibles aux rôles partenariat.
- **Références CDC** : SPON-01.

### UCG-SPON-002 — Gérer accords et périmètres d’exposition

- **État** : livré (`a899bf2`, correctif `8fa6376` poussé), CI et migration validées par l’utilisateur avant SPON-003. Migration vérifiée Ran, batch 27 ; branche synchronisée au démarrage.
- **Livraison** : accords privés, période/responsable, conditions numéraire/nature/mixte chiffrées, lecture financière auditée et droits distincts, approbation indépendante et invalidation après modification. Périmètres organisation/équipe/joueur avec référence d’autorisation ; exclusivités par catégorie sur les périodes approuvées qui se chevauchent. Pièces via contrat sponsor associé et protections Contrats existantes. API/React, pagination, versions optimistes, contraintes SQLite/PostgreSQL et export opérateur.
- **Limites explicites** : référentiels événement/contenu indisponibles, donc refusés ; aucune publication automatique ni preuve de réalisation inventée. Campagnes et livrables restent SPON-003. Aucune attribution réelle de rôle ni migration principale par l’agent.

- **Priorité / taille** : P0 / L
- **Périmètre** : période, montant ou apport, responsable, équipe/événement/joueur, exclusivités et pièces.
- **Acceptation** : autorisation financière distincte et aucune exposition non approuvée.
- **Références CDC** : SPON-02 à SPON-04.

### UCG-SPON-003 — Suivre campagnes et livrables

- **État** : `aba9205` poussé ; CI et migration validées par l’utilisateur avant MEDIA-001. Migration vérifiée Ran, batch 28.
- **Livraison** : campagnes rattachées aux accords, périodes et responsables habilités, promesses liées aux périmètres, déclarations datées avec preuves textuelles chiffrées, validation indépendante et réouverture avec conservation de l’ancienne preuve. États promis/livré/validé distincts, annulation et archivage protégés, historique en ajout seul, tableau tenant paginé avec retards et compteurs séparés. API/React, versions optimistes, contraintes SQLite/PostgreSQL et portabilité opérateur.
- **Limites** : aucune publication, ouverture automatique de référence, mesure d’audience, pièce téléversée ou notification externe. Une preuve est une référence manuelle à vérifier ; le statut livré ne prouve pas une exposition autorisée. Les types événementiel/contenu ne créent pas de référentiels indisponibles. Rapports SPON-004 et portail SPON-005 restent P1.

- **Priorité / taille** : P0 / L
- **Périmètre** : publications, vidéos, événementiel, placement de marque, responsable, échéance et preuve.
- **Acceptation** : états promis, livré et validé distincts ; retard visible dans le tableau de bord.
- **Références CDC** : SPON-05, SPON-06.

### UCG-SPON-004 — Produire les rapports sponsor

- **Priorité / taille** : P1 / L
- **Périmètre** : période, livrables, mesures disponibles, méthode et fournisseur.
- **Acceptation** : aucune addition trompeuse d’audiences possiblement dupliquées ; export asynchrone et autorisé.
- **Références CDC** : SPON-06.

### UCG-SPON-005 — Ouvrir le portail sponsor

- **Priorité / taille** : P1 / L
- **Périmètre** : accès externe limité aux accords attribués et informations préalablement partagées.
- **Acceptation** : un compte lié à plusieurs tenants voit des espaces séparés ; test négatif sur l’accord d’un tiers.
- **Références CDC** : SPON-07, SPON-08, REC-15.

## 16 Module Live, communication et modération

### UCG-MEDIA-001 — Gérer streams, VOD et clips

- **Validation** : `1f7cc8c` poussé ; CI et migration confirmées par l’utilisateur avant COM-001. Migration vérifiée Ran, batch 29.

- **État** : socle livré, push/CI/migration validés ; rattachements match/événement réservés à leurs référentiels.
- **Livraison** : catalogue tenant de streams/VOD/clips Twitch et YouTube, rattachés à une équipe active existante. Liens HTTPS normalisés et contrôlés sans accès réseau, statut de diffusion manuel et observation datée séparés du score. Médias privés par défaut, droits media.read/write/publish explicites et MFA, partage différé de scrim autorisé et filtré côté serveur, retrait et archive définitive. API/React, validation fermée, contrôle tenant, versions, audit atomique minimal, portabilité et contraintes SQLite/PostgreSQL.
- **Limites** : aucune fiche match/événement n’existe encore ; ces UUID sont refusés, le contexte scrim reste déclaratif. Visibilité public = autorisation externe enregistrée, aucun endpoint anonyme ou changement chez le fournisseur. Pas de lecteur intégré, de collecte de disponibilité, d’audience ou de score. MEDIA-002 reste P1 ; socle COM-001 livré dans la présente étape.

- **Priorité / taille** : P0 / M
- **Périmètre** : média lié à un match, une équipe ou un événement ; statut, fournisseur et visibilité.
- **Acceptation** : lien validé, source du média distincte de la source du score et scrim privé par défaut.
- **Références CDC** : LIVE-01, LIVE-03, LIVE-04.

### UCG-MEDIA-002 — Intégrer les lecteurs officiels

- **Priorité / taille** : P1 / M
- **Périmètre** : Twitch et YouTube, paramètres de domaine et consentement/cookies selon la politique retenue.
- **Acceptation** : intégration conforme aux lecteurs officiels, état d’indisponibilité visible et aucune vidéo réhébergée.
- **Références CDC** : LIVE-02.

### UCG-COM-001 — Publier annonces et commentaires ciblés

- **État** : socle livré (`8d48651` poussé), CI et migration validées par l’utilisateur avant CAL-001. Migration vérifiée Ran, batch 30. Le ticket demeure partiel pour ses rattachements contextuels.
- **Livré** : annonces tenant/équipe/jeu/rôle, brouillons versionnés, publication confirmée, contenu publié figé, retrait définitif, discussions sous les annonces, ouverture des commentaires, boîte interne et préférences par compte/tenant. Permissions communication.read/write/publish/comment explicites, MFA, audience courante filtrée avant comptage/pagination ; notifications remises seulement si les droits sont toujours effectifs. Pas d’envoi externe ni de liste figée de destinataires.
- **Sécurité** : cinq tables tenant, FKs composites, textes chiffrés, historique append-only, audit atomique minimal, rollback sans perte de données, portabilité exhaustive ; références et listes paginées. API fermée, contrôles sous verrou organisation et absence de participation hors audience même en mode gestion.
- **Reliquat explicite** : ciblage événement et commentaires sur tâches/matchs/séances, dépendants de leurs futurs référentiels. Aucun UUID libre accepté pour ces contextes. COM-002 Discord et MOD-001 restent P1.

- **Priorité / taille** : P0 / L
- **Périmètre** : annonces par tenant/équipe/jeu/rôle/événement et commentaires sur tâches, matchs et séances.
- **Acceptation** : destinataires recalculés au moment de l’envoi ; contenu inaccessible après expiration des droits.
- **Références CDC** : COM-01, COM-02, COM-04.

### UCG-COM-002 — Connecter Discord

- **Priorité / taille** : P1 / L
- **Périmètre** : identifiants techniques par tenant, correspondance des canaux, rappels autorisés et suivi des échecs.
- **Acceptation** : l’application reste le référentiel ; aucun message envoyé au canal d’un autre tenant.
- **Références CDC** : COM-03.

### UCG-MOD-001 — Gérer les signalements

- **Priorité / taille** : P1 / M
- **Périmètre** : signalement, modérateur affecté, décision, motif, preuve et recours éventuel.
- **Acceptation** : périmètre tenant appliqué, historique audité et accès sensible limité.
- **Références CDC** : MOD-01.

### UCG-MEDIA-003 — Fournir un overlay révocable

- **Priorité / taille** : P2 / L
- **Périmètre** : jeton limité, champs publiables, score validé et révocation.
- **Acceptation** : aucune donnée privée, token expiré refusé et charge publique mesurée.
- **Références CDC** : LIVE-05, LIVE-06.

## 17 Module Finances et équipements

### UCG-FIN-001 — Gérer budgets et engagements

- **Priorité / taille** : P1 / L
- **Périmètre** : budget par équipe/saison/événement, devise, période, responsable et engagements.
- **Acceptation** : valeurs décimales ou unités monétaires entières uniquement ; permissions financières dédiées.

### UCG-FIN-002 — Gérer dépenses et justificatifs

- **Priorité / taille** : P1 / L
- **Périmètre** : dépense, pièce privée, catégorie, circuit d’approbation et rapprochement.
- **Acceptation** : séparation demande/approbation, audit complet et aucun rôle sportif implicite.

### UCG-FIN-003 — Suivre primes, paiements et dotations

- **Priorité / taille** : P1 / L
- **Périmètre** : montant, devise, bénéficiaire, statut, source et conversion avec taux/date.
- **Acceptation** : aucune règle fiscale déduite automatiquement ; contrat, dotation annoncée et paiement réel séparés.

### UCG-FIN-004 — Produire les exports comptables

- **Priorité / taille** : P1 / M
- **Périmètre** : format validé, bornes, génération asynchrone, expiration et journal d’accès.
- **Acceptation** : export limité au tenant et aux champs autorisés ; totaux rapprochables.

### UCG-EQP-001 — Gérer l’inventaire et les affectations

- **Priorité / taille** : P1 / L
- **Périmètre** : équipement, numéro de série, état, prêt, remise, restitution et justificatifs.
- **Acceptation** : conflit d’affectation détecté ; départ d’un membre crée les actions de restitution nécessaires.

## 18 Module Tableaux de bord, recherche et exports

### UCG-REP-001 — Créer les tableaux de bord par rôle

- **Priorité / taille** : P0 / L
- **Périmètre** : direction, manager, coach, joueur et administration ; définitions, périodes et périmètres visibles.
- **Acceptation** : indicateurs expliqués, limités au tenant, paginés si nécessaire et sans requêtes N+1.
- **Livraison locale** : cinq vues backend/frontend avec permissions explicites, onze cartes sur sources existantes, définitions/périodes/périmètres, pagination et reprise par carte. Budgets et progression agrégée indisponibles explicitement ; autres indicateurs cibles du CDC tributaires de leurs sources. Aucun calcul lourd, job, migration ou attribution réelle de permission. Agrégats/cache/fraîcheur restent REP-002. Vérifications dans etat-projet.md ; livraison incluse dans le commit UCG-REP-001, push, CI et recette restent à faire.

### UCG-REP-002 — Calculer les indicateurs en arrière-plan

- **Priorité / taille** : P0 / L
- **Périmètre** : agrégats versionnés, fraîcheur, invalidation et recalcul ciblé.
- **Acceptation** : dashboards courants sans calcul lourd synchrone ; tenant inclus dans chaque clé de cache.
- **Livraison locale** : six comptages organisationnels par statut, jobs asynchrones explicites, matérialisation privée/versionnée, fraîcheur maximale cinq minutes, invalidation transactionnelle et recalcul ciblé à la demande. Lecture sans calcul, détails actuels à la demande, historique paginé. Migration additive `2026_10_20_184653`, traitement désactivé par défaut. Audiences privées, budgets et progression sportive sans définition métier non agrégés ; pas de recalcul global automatique. Vérifications dans etat-projet.md ; commit/push, CI, sauvegarde, migration et recette avant activation.

### UCG-REP-003 — Construire la recherche limitée au tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : personnes, équipes, matchs, compétitions et documents autorisés ; filtres et autocomplétion.
- **Acceptation** : aucune existence d’un autre tenant révélée ; champs confidentiels exclus de l’index non autorisé.
- **Références CDC** : REC-24.
- **Livraison locale** : recherche backend/frontend par catégorie et préfixe de libellé/référence ; personnes, équipes, matchs visibles, compétitions et métadonnées documentaires autorisées. Suggestions bornées, pagination par curseur chiffré lié au contexte, filtres fermés et droits revérifiés. Aucun index en clair, aucun corps documentaire ni total exhaustif ; parcours limité à 100 candidats examinés par appel, continuation explicite. Pas de nouvelle migration. Vérifications et limites de volumétrie dans etat-projet.md ; commit 355f42b constaté au démarrage de REP-004, CI/recette restent à confirmer.


### UCG-REP-004 — Générer les exports asynchrones

- **Priorité / taille** : P0 / L
- **Périmètre** : demande, permission figée ou revérifiée selon politique, job, fichier privé, expiration et audit.
- **Acceptation** : période et volume bornés ; téléchargement réautorisé ; cache et stockage isolés.
- **Références CDC** : REC-27.
- **Livraison locale** : demande idempotente, file persistante reports, CSV chiffré au repos réservé au demandeur, permission explicite reporting.export plus droits métier et vue courants, journal transverse et sensible, expiration une heure et nettoyage des artefacts expirés avec libération du quota. Catalogue fermé : active_people, unlinked_people, people_imports, expiring_contracts, overdue_deliverables, registration_due. Périodes bornées à 31 jours pour les rapports datés ; instantanés signalés comme tels. Limites 500 lignes, 8 Mio et dix demandes non expirées par tenant ; aucun résultat tronqué. Source modifiée pendant/après génération : export refusé, pas de recalcul HTTP. Audiences individuelles, contenus documentaires, finances détaillées et exports exhaustifs ne sont pas couverts. Migration additive 2026_10_21_224633 préparée ; traitements désactivés par défaut et aucune migration courante appliquée. Validation dans etat-projet.md ; revue/push, CI et recette restent à faire.

## 19 Module API, webhooks et intégrations

### UCG-API-001 — Définir le contrat API v1

- **Priorité / taille** : P1 / L
- **Périmètre** : versionnement, pagination, filtres, Resources, erreurs, idempotency keys et contrôle de version optimiste.
- **Acceptation** : conventions publiées, exemples cURL valides et contrats testés automatiquement.

### UCG-API-002 — Sécuriser les webhooks entrants

- **Priorité / taille** : P1 / L
- **Périmètre** : endpoint, signature, rejeu, tenant, stockage durable, idempotence et quarantaine.
- **Acceptation** : doublon sans double effet ; événement invalide consultable et rejouable par une action autorisée.
- **Références CDC** : REC-30.
- **Décision de périmètre (25/10/2026)** : l'utilisateur confirme qu'aucun webhook
  entrant n'est prévu au P0. REC-30 est non applicable à ce périmètre ; ce ticket
  P1 reste ouvert pour une future intégration et ne bloque pas la clôture P0 de QA-002.

### UCG-API-003 — Fiabiliser les appels fournisseurs

- **Priorité / taille** : P1 / L
- **Périmètre** : délai maximal, nouvelles tentatives avec attente croissante, circuit de reprise, quotas et erreurs métier normalisées.
- **Acceptation** : panne externe n’immobilise pas une requête web ; état et dernière synchronisation visibles.

### UCG-API-004 — Gérer identifiants techniques et consentements

- **Priorité / taille** : P1 / M
- **Périmètre** : secrets chiffrés, propriétaire tenant/plateforme, rotation, révocation, opt-in et restrictions de publication.
- **Acceptation** : aucun secret dans le navigateur ou les logs ; accès limité au connecteur concerné.

### UCG-API-005 — Documenter les APIs livrées

- **Priorité / taille** : P1 / M
- **Périmètre** : OpenAPI ou documentation équivalente, scopes, exemples, quotas et erreurs.
- **Acceptation** : documentation construite dans la CI et alignée sur les endpoints réellement disponibles.

## 20 Module Pages publiques

### UCG-PUB-001 — Construire le workflow de publication

- **Priorité / taille** : P1 / L
- **Périmètre** : brouillon, validation, publication, retrait, auteur et projection publique.
- **Acceptation** : permission distincte ; aucun objet privé publié par simple changement de visibilité côté client.

### UCG-PUB-002 — Livrer la vitrine d’une organisation

- **Priorité / taille** : P1 / L
- **Périmètre** : organisation, équipes, profils, résultats, événements, sponsors et streams.
- **Acceptation** : contenu limité au tenant, responsive, accessible et états vides propres.

### UCG-PUB-003 — Optimiser SEO, SSR et cache public

- **Priorité / taille** : P1 / L
- **Périmètre** : arbitrage Blade/Inertia SSR, métadonnées, sitemap, cache/CDN et invalidation par tenant.
- **Acceptation** : aucune réponse personnalisée privée dans le cache public ; mesure des performances reproductible.

### UCG-PUB-004 — Diffuser les scores publics à grande audience

- **Priorité / taille** : P1 / L
- **Périmètre** : projection minimale, canal public, fraîcheur, CDN éventuel et protection contre les abus.
- **Acceptation** : seules les données validées et publiées sont diffusées ; charge testée selon l’enveloppe.

## 21 Module Exploitation, sécurité et conformité

### UCG-OPS-001 — Préparer les environnements et secrets

- **Priorité / taille** : P0 / M
- **Périmètre** : développement, recette, production, variables, gestionnaire de secrets et données fictives.
- **Acceptation** : séparation vérifiée, rotation documentée et aucun secret ou fichier de production dans les environnements inférieurs.
- **État au 23/10/2026** : socle de préparation indépendant du fournisseur en cours
  de livraison : gabarits sans secrets, diagnostic de configuration en lecture
  seule, contrôle des variables publiques Vite et procédure de rotation dans le
  README. Acceptation ouverte jusqu'au provisionnement, peuplement fictif et
  contrôle effectif de séparation ; hébergeur/gestionnaire non encore choisis.

### UCG-OPS-002 — Automatiser les déploiements

- **Priorité / taille** : P0 / L
- **Périmètre** : build, tests, migrations compatibles, déploiement web/workers/realtime et plan de retour arrière.
- **Acceptation** : release reproductible, scheduler unique et migration destructive interdite sans stratégie validée.
- **Préparation locale (30/10/2026)** : constructeur/vérificateur Node sans nouvelle
  dépendance, candidat sources serveur + frontend TypeScript/Vite + manifeste SHA-256
  et plan non exécutable. Entrées runtime commitées et propres, dérive pendant build,
  fichiers sensibles/altérés, chemins sortants et liens refusés. Workflow manuel
  Release candidate conditionné aux dernières CIs backend/frontend push réussies
  sur même SHA/branche/dépôt, puis audits frais et upload du seul dossier vérifié.
  Garde-fous ajoutés à Frontend quality. 16 tests Node et lint verts, deux YAML
  parsés ; vrai candidat local de 1 325 fichiers construit et revérifié.
  **OPS-002 ouvert pour l'hébergement** : pas de vendor PHP, installation/migrations,
  bascule web/workers/Reverb, scheduler unique et récupération réelle non exécutés.
  Commit et push demandés ensuite ; aucun workflow distant de release ni déploiement exécuté.

### UCG-OPS-003 — Superviser application et infrastructure

- **Priorité / taille** : P0 / L
- **Périmètre** : logs structurés, erreurs, latence, queues, connexions temps réel, imports, rappels, stockage et corrélation.
- **Acceptation** : alertes actionnables avec responsable et procédure ; aucune donnée sensible dans les logs.
- **Premier lot local (30/10/2026)** : commande serveur `ops:inspect-runtime`,
  sans lecture de dépendance par défaut ; `--probe` autorise des contrôles en lecture
  de base/cache, métadonnées bornées des queues/échecs et racine du stockage privé
  local. Rapport expurgé, codes 0/1/2, seuil proposé de 300 s, troncature et limites
  explicites ; totaux Redis seulement, sans preuve de worker actif. Aucun endpoint,
  transport d’alerte, scheduler ou action corrective ajouté. Procédures et limites
  documentées dans le README et le guide incident. **OPS-003 reste ouvert** pour
  métriques/latence, parcours imports/rappels, temps réel, sondes réelles, responsables,
  réception/acquittement d’alertes et recette sur l’hébergement toujours reporté.
- **Deuxième lot local (30/10/2026)** : marqueurs d’activité workers daemon et
  scheduler, opt-in désactivé par défaut, cache partagé et namespace isolé ;
  diagnostic serveur `ops:inspect-background` avec lectures explicites et
  métadonnées bornées des imports personnes/statistiques et livraisons e-mail de
  rappels contractuels. Seuils proposés 180 s/900 s, erreurs expurgées et contenues,
  propositions d’alertes avec rôle à désigner/procédure/clé stable, sans transport.
  **OPS-003 reste ouvert** pour la collecte continue et les alertes réelles,
  la recette Redis/Horizon/hébergement, métriques HTTP/temps réel et couverture
  métier des rappels non matérialisés/échéances de compétition.
- **Préparation locale des lots restants (30/10/2026)** : télémétrie API expurgée
  et buckets/seuils bornés, sondes externes sur option, compteur agrégé Reverb,
  balayages de rappels contrats/compétitions et tests de création réelle fictive.
  Registre technique SQL par namespace/composant, journal immuable, acquittement
  versionné, résolution sur contrôle concluant, réouverture et signal d’escalade.
  Collectes et registre désactivés par défaut ; aucune notification externe.
  Matrice S3-01–10 et fiche de recette préparées. **OPS-003 reste ouvert** pour
  raccordement du canal choisi et validation sur hébergement, responsables,
  réception/acquittement/escalade humains, confidentialité/charge et services réels.

### UCG-OPS-004 — Mettre en place sauvegarde et restauration

- **Priorité / taille** : P0 / XL
- **Périmètre** : PostgreSQL, fichiers, chiffrement, copie isolée, rétention, PITR, RPO/RTO et tests.
- **Acceptation** : restauration complète mesurée ; cohérence base/fichiers ; rapport conservé.
- **Premier lot local (25/10/2026)** : exercice reproductible sur deux clusters
  PostgreSQL 17 temporaires attestés, source sans écritures concurrentes et données
  fictives Alpha/Beta. Dump complet et deux artefacts privés dans une enveloppe
  AES-256-GCM, clé distincte de l'APP_KEY ; source modifiée après sauvegarde puis
  arrêtée, restauration vers une cible vide. Comparaison de toutes les tables
  publiques, séquences, inventaire de contraintes, déclencheurs/fonctions, fichiers
  et déchiffrement ; isolation et refus CHECK/FK/historique exercés. Cibles
  étrangères/remplies et stockage occupé refusés ; corruption, clés incorrectes
  et fichier manquant détectés. Commandes npm test:recovery:harness/test:recovery,
  rapport ignoré recovery-local.json et raccordement au job CI PostgreSQL.
  **Ticket ouvert** : PITR, copie isolée distante, rôles/ACL, clés durables,
  rétention et qualification RPO/RTO sur infrastructure restent à traiter.
  Format borné de fixture, aucune restauration vers une base utilisée ; OPS-005
  et REC-36 non clôturés. Nouvelle CI distante à confirmer.

- **Complément des quatre lots (30/10/2026)** : PITR physique PostgreSQL 17/WAL
  natif vers cible isolée, coffre AES-GCM et manifeste authentifié, copie locale
  séparée vérifiée, clés distinctes, reprise entre objets et plan de conservation
  sans suppression. Contrôle des preuves privées signées intégré à OPS-003,
  désactivé par défaut ; un reçu local ne qualifie jamais l’hébergement.
  Tests de corruption/clé/WAL manquant/cible occupée, redémarrage après arrêt
  brutal, cohérence des 143 tables et trois fichiers privés, rapport pitr-local.json
  et CI PostgreSQL. Procédure OPS-004-FINAL et matrice de recette préparées.
  **Reste ouvert** : recette réelle sur hébergement reporté, copies hors site et
  immutabilité/ACL, exploitation des clés et rétention réelle, collecte continue,
  cohérence fichiers concurrents et mesures RPO/RTO représentatives.

### UCG-OPS-005 — Tester la récupération logique d’un tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : export, sélection depuis sauvegarde, réconciliation et procédure de réimport contrôlée.
- **Acceptation** : récupération sans écraser les mises à jour récentes des autres tenants.
- **Références CDC** : REC-36.
- **Livraison de la simulation complète (30/10/2026)** : reconstruction effective
  d’un contrat approuvé, obligation terminée après approbation, parties/document/
  version/historique et d’un match validé avec participants, règles, score et preuves.
  Rejeu sous les gardes SQL normales, dépendances externes ordonnées, empreintes
  finales de chaque ligne ; refus des historiques/preuves incomplets et auto-approbation,
  rollback intégral d’un score invalide. Les scénarios existants couvrent les autres
  données liées, fichiers privés, conflits, droits, journal/clé, gel/purge, crash et
  reprise. Concurrence réelle de processus SQL/fichiers et préservation Beta/globales.
  Procédure, commandes et rapports privés en CI disponibles. **Périmètre d’acceptation
  OPS-005/REC-36 : simulation des données/fichiers sans écrasement des autres tenants.**
  L’exploitation hébergée, la capacité et l’outil opérateur réel sont des validations
  de livraison distinctes ; ils ne sont pas revendiqués par ce laboratoire borné.
  Les entrées ci-dessous retracent les lots antérieurs, désormais complétés.
- **Validation finale** : trois scénarios PostgreSQL réussis, 41 lignes pour la
  récupération avancée, 120 tables Beta et 23 globales préservées ; douze contrôles
  du rapport complet et nettoyage confirmés. Onze tests protocole/isolation, Pint,
  PHPStan niveau 8 et lint valides. **Implémentation et recette simulée OPS-005/REC-36
  terminées** ; nouvelle CI à confirmer après livraison. Pas d’import sur base utilisée.
- **Trois lots regroupés (30/10/2026)** : coordination des applications/reprises
  par verrou de session PostgreSQL tenant, verrou métier repris durant les fichiers ;
  scénario à vrais processus concurrents avec attente Alpha observée dans PostgreSQL,
  progression Beta et reprise après arrêt SQL. Archives restaurées de contrat
  réellement approuvé et de match réellement validé par un tiers : refus sans
  mutation des états avancés absents, sans prétendre les réimporter. Rejeu des
  transitions avancées, signatures/approbations/copies et résultats manquants refusé.
  Commande test:recovery:qualification, rapport privé et CI PostgreSQL raccordés.
  Recette OPS-005-FINAL préparée, matrice S5-01–12, fiche restreinte, décisions sur
  états exclus, clés, barrière d’écriture, conservation et retour de service.
  **Reste ouvert** : récupération effective des états avancés et autres modules,
  qualification multihôte/charge, panne machine et exécution hébergée avec données
  représentatives. Les trois lots ne sont pas déclarés achevés en production.
- **Complément de reprise prudente (30/10/2026)** : scénario PostgreSQL enrichi
  après commit SQL interrompu : mauvaise clé applicative ou source, journal
  absent/tronqué/altéré, gel nouvellement posé. Chaque refus doit conserver toutes
  les tables, marqueurs/audits, fichiers privés et le journal tel que présenté.
  Rétablissement des éléments originaux puis reprise normale sans doublon.
  Données fictives et clusters temporaires uniquement ; ce complément ne qualifie
  ni panne machine, perte définitive de clés ni exploitation concurrente réelle.
- **Consolidation locale lots 1–4 (25/10/2026)** : CI de `da068af` validée par
  l'utilisateur ; demande d'un seul nouveau commit pour le travail restant.
  Exercice complémentaire de graphe absent People/profils/compte/période,
  Teams/affectation/composition, match proposé/participant et contrat brouillon
  avec partie/obligation/historique/document/version. Catalogue fermé, ordre FK,
  conflits conservés explicitement, compteurs dérivés laissés aux triggers et
  absence de réattribution automatique d'accès. Journal chiffré et marqueur SQL
  pending/completed ; rollback, deux arrêts de sous-processus, reprises sans
  doublon, dérive post-commit, gel/purge et objet inattendu éprouvés par le scénario.
  Procédure et limites dans le README, test:recovery:graph et rapport dédié en CI.
  **Qualification réelle toujours ouverte** : états protégés avancés et modules
  non peuplés, panne machine/perte de clés, exploitation concurrente, données
  réelles et infrastructure ne sont pas déclarés validés. Aucun moteur opérateur
  de production ou désactivation de garde SQL ; les paragraphes suivants retracent
  les premiers lots locaux.
- **Premier exercice local (25/10/2026)** : sélection Alpha depuis un cluster
  restauré à partir d'une sauvegarde chiffrée, puis réconciliation ciblée dans
  le cluster fictif courant. Trois champs d'une fiche existante et un artefact
  manquant/ledger intact uniquement ; nouvelle version et audit via PersonService.
  Modifications, nouvelle fiche et nouveau fichier Beta postérieurs à la sauvegarde
  conservés, contrôles sur les 116 tables tenant et 20 globales et fichiers Beta.
  Plan sans écriture signé au laboratoire, refus inter-tenant/altération/obsolescence,
  compensation base/fichier sur erreur synchrone et réapplication refusée.
  npm test:recovery:tenant, rapport tenant-recovery-local.json et raccordement CI
  PostgreSQL. **Ticket/REC-36 ouverts** : import complet, références/intermodules,
  décisions d'effacement/gel et reprise après crash restent à traiter. Aucun
  moteur de réimport de production ou changement d'OrganizationRecoveryService.

### UCG-OPS-006 — Appliquer la sécurité des fichiers et dépendances

- **Priorité / taille** : P0 / L
- **Finalisation applicative (31/10/2026)** : critères fichiers dangereux en
  quarantaine et vulnérabilités suivies avec délai couverts. Deux parcours HTTP
  dépôt → job sérialisé → téléchargement propre/refus dangereux ajoutés à la
  qualification ClamAV réelle en CI (pair TCP explicite localement). Audits
  quotidiens/manuels/push/PR des deux verrous, registre versionné des premières
  détections, responsables, évaluations et clôtures ; échéances par sévérité et
  retards calculés sans remise à zéro. Audit rouge toujours bloquant, même suivi.
  Troisième workflow requis pour un candidat de release. Qualification du futur
  hébergement séparée, aucune activation réelle ; nouvelle CI à confirmer après
  livraison. Les paragraphes ci-dessous conservent l’historique du ticket.
- **Périmètre** : analyse antivirus ou équivalente, MIME réel, tailles, dépendances, scans et correctifs.
- **Acceptation** : fichiers dangereux mis en quarantaine ; vulnérabilités suivies avec délai de traitement.
- **Trois lots consolidés (25/10/2026)** : contrôles PDF/MIME/extension/taille et
  absence d'effets sur refus complétés ; limite 5 Mio partagée. Protocole ClamAV
  renforcé contre verdicts multiples répartis entre paquets et absence de clôture,
  tests de quarantaine après rescan négatif. Politique clamav-pdf-v2 : les anciens
  clean v1 requièrent une nouvelle analyse. Job réel clamd/freshclam/EICAR activé
  en CI, à valider après push ; pas de moteur réel disponible localement.
  Audits Composer/npm bloquants avec rapports ; Vite 8.3.2→8.3.5 et Undici
  8.11.2→8.12.3 corrigent les deux dépendances signalées, réaudit npm vierge.
  Suivi et délais proposés dans le README, référent à désigner ; ticket ouvert
  pour recette antivirus réelle et exploitation sur le futur hébergement.
- **Validation CI (26/10/2026)** : l’utilisateur valide `50e4b37`, y compris les
  deux sondes ClamAV réelles (EICAR brut et pièce jointe PDF). Cette attente CI
  est levée ; la qualification du futur hébergement reste ouverte.

### UCG-OPS-007 — Implémenter conservation et purge

- **Priorité / taille** : P0 / L
- **Correctif CI d’initialisation (28/10/2026)** : la fonction SQLite de refus
  d’effacement est enregistrée par le connecteur à l’ouverture physique du PDO,
  sans forcer de connexion pendant package:discover. Reproduction de l’échec avec
  base absente/cache database ; hook Composer corrigé sans créer une base factice.
  PDO séparés, reconnexion et maintien des permissions temporaires vérifiés.
  Régressions : 471 cas/462 réussites/9 exclusions, 2 145 assertions ; Pint/PHPStan
  verts. Correctif séparé avant push, nouvelle CI distante à confirmer.
- **Extension du 28/10/2026, nouveau commit unique demandé** : gel SQL/service des
  écritures métier ; effacement manuel de personne et d’organisation archivée,
  prévisualisation/empreinte/confirmation, quarantaine, lots de 100, preuves et
  permissions SQL précises. Les liens partagés bloquent l’effacement individuel,
  conformément à la réponse utilisateur ; un import partagé bloque aussi.
  Journal signé dans une base indépendante avec clé distincte, réimport conservateur
  des gels absents et rejeu des effacements après restauration, y compris les fichiers
  orphelins décrits par le manifeste. Catalogue 120 tables, protection des comptes
  globaux et racine d’organisation anonymisée conservée en quarantaine permanente.
  Les 11 revues de copies sont des attestations opérateur, sans preuve de suppression
  fournisseur. Configuration désactivée ; aucune modification d’une base utilisée.
  **Reste ouvert** : qualification hébergée explicitement reportée, copies externes,
  cycle réel de sauvegardes/PITR et validations CAD-004 ; aucun effacement automatique
  métier ni écran SPA. Détails et procédure dans le README.
  Validation locale finale : 2 937 cas backend, 2 876 réussites, 61 exclusions,
  18 430 assertions ; vérifications PostgreSQL ciblées, PHPStan et Pint verts.
- **Validation CI (28/10/2026)** : l'utilisateur valide la CI de la livraison
  9151df0, après regroupement des suites PostgreSQL. Cette attente est levée ;
  l'extension ci-dessus complète le code mais ne qualifie pas l'hébergement.
- **Périmètre** : matrice par catégorie/tenant, archivage restreint, purge, exports temporaires, réplicas et cycle des sauvegardes.
- **Acceptation** : soft delete non confondu avec effacement ; opération auditée et testée.
- **Décision utilisateur intégrée (27/10/2026)** : les informations des personnes
  sont conservées sans limite, soft delete réversible jusqu'à effacement manuel
  ultérieur. API People DELETE/restore et liste paginée archive=only ajoutées,
  droits sportifs+administratifs, départ enregistré/obligations clôturées,
  validation, versions et audit atomique. Données chiffrées, liens et historiques
  conservés, aucun compteur d'effacement. Registre de revue compatible avec une
  conservation illimitée explicite. L'effacement physique exhaustif et sa
  réconciliation sur copies restent à construire/qualifier ; pas de corbeille SPA.
- **Demande des six blocs en un commit (27/10/2026)** : contrôles opérateur natifs
  ajoutés : gels indépendants d'organisation sur les suppressions techniques,
  restriction d'archive d'organisation, quarantaine manuelle et levée liée à une
  revue versionnée des 18 familles/11 catégories de copies. Journal append-only
  avec reçu de revue figé, versions attendues et rejeu idempotent. Garde commune
  reliée aux expirations/fichiers/exports/purge existants et aux reprises, catalogue
  étendu à 117 tables, commande désactivée et accès vide par défaut. Bilan des six
  blocs explicite, `business_deletion_supported=false`, `production_qualified=false`.
  **Ticket toujours ouvert** : hors décision People ci-dessus, règles métier à
  compléter ; pas d'archive par
  dossier, de gel général des réécritures, d'effacement métier exhaustif, de
  réconciliation automatique des copies ou de qualification de l'hébergement.
- **Lots natifs de préparation (27/10/2026)** : catalogue fermé 18 familles/116
  tables, registre de revue versionné et empreinte, commande serveur protégée par
  compte/tenant, Service/Repository/DTO, inventaire et dossiers People paginés.
  Dépendances SQL groupées, copies/âge/besoin non établis signalés, aucune donnée
  privée textuelle exposée, droits/règles revérifiés avant rapport. Registres vides
  par défaut, aucune durée UCG choisie, aucun --execute ni pont vers la purge.
  Tests natifs et garde-fous de maintenance/reprise existants réexercés, CI dédiée.
  **Lots non clôturés** : règles/validations CAD-004, archivage restreint et gel
  métier, effacement exhaustif des modules/copies, cycle des sauvegardes et recette
  d'hébergement. Le tableau de lots du README distingue explicitement ces limites.
- **Premier laboratoire fictif (27/10/2026)** : module pur Node et lanceur fixe
  dans front/tests/real-api, 11 exemples et 9 tests réussis. Politique de 30 jours
  exclusivement fictive pour tester le calcul UTC, aucune durée UCG choisie.
  Absence de règle/date, obligations, mineurs/âge inconnu, copies et compte partagé
  demandent une revue ; même une échéance atteinte ne permet aucune suppression.
  Schéma/organisations fermés, arguments externes refusés, aucune lecture de l'app,
  de sa configuration ou de ses bases. Rapport JSON et tests raccordés à la CI,
  exécution distante non confirmée. **Ticket/CAD-004/REC-35 ouverts** : ce laboratoire
  n'est ni une politique validée, ni un moteur métier, ni un test d'effacement réel.
- **Préparation documentaire (25/10/2026)** : établissement UCG en Tunisie confirmé
  par l'utilisateur. Inventaire des 116 entrées du catalogue en 18 familles,
  événements de départ proposés et fiche de décision dans le README ; aucune
  durée métier chiffrée validée. Conservation d'audit illimitée existante préservée,
  à réexaminer selon le cadrage tunisien. Purge opérateur actuelle limitée aux
  catégories techniques ; expirations, effacement métier, comptes globaux et
  copies/sauvegardes distingués. Aucune configuration ou donnée réelle modifiée,
  aucune commande de purge exécutée. **Ticket/CAD-004/REC-35 ouverts** ; suite :
  validation des finalités/durées/formalités, puis simulation sur fixtures avant
  tout moteur ou opération réelle. Préparation sans commit/push.

### UCG-OPS-008 — Rédiger les procédures d’incident et support

- **Priorité / taille** : P0 / M
- **Périmètre** : indisponibilité, fuite suspectée, intégration en panne, file bloquée, restauration et accès exceptionnel.
- **Acceptation** : responsables, seuils, escalade, communication et preuves de clôture définis.
- **Trois lots locaux (26/10/2026)** : dossier dédié
  [procédures d’incident et de support](procedures-incident-support.md), sept
  fiches rattachées aux mécanismes existants, rôles fonctionnels, seuils et délais
  proposés, escalade, fiche privée, messages à préparer et critères de clôture.
  Huit scénarios fictifs revus sur table, sans panne injectée ni envoi réel.
  Contrôles techniques ciblés dans etat-projet.md. CI de `50e4b37` validée par
  l’utilisateur, y compris ClamAV réel ; hébergement toujours différé.
  **Ticket ouvert avant exploitation** : désigner titulaires/suppléants/contacts,
  adopter les seuils, éprouver canal de secours, preuves et procédures sur
  l’hébergement. Dossier local livré en un commit, pas d’astreinte déclarée active.

## 22 Module Tests, données et livraison

### UCG-QA-001 — Créer les fixtures multi-tenants

- **Priorité / taille** : P0 / M
- **Périmètre** : UCG, deux tenants de test, comptes multiorganisations, rôles, données privées similaires et identifiants volontairement proches.
- **Acceptation** : scénarios positifs et attaques inter-tenant reproductibles en local et CI.

### UCG-QA-002 — Automatiser la matrice d’isolation

- **Priorité / taille** : P0 / XL
- **Périmètre** : CRUD, URL directe, recherche, export, fichier, cache, job, notification, webhook et temps réel.
- **Acceptation** : tous les scénarios REC-23 à REC-36 applicables au P0 sont couverts ; tout nouveau module tenant ajoute ses cas négatifs.
- **Premier lot local (24/10/2026)** : groupe PHPUnit `tenant-isolation`, neuf
  cas critiques dont huit nouveaux : job d'export sérialisé/rejoué après changement
  de tenant, adhésion/permission révoquée avant traitement, recherche/suggestions
  après changement et révocation, cache/fichiers après révocation et refus d'URL
  étrangère indiscernable d'une URL inconnue. 312 tests SQLite (1 650 assertions)
  sur quinze suites auditées ; 120 tests PostgreSQL temporaire (767 assertions)
  sur les cinq suites modifiées. Pint/PHPStan verts ; aucune modification métier.
  Matrice et limites REC-23–36 dans etat-projet.md ; ticket ouvert pour l'inventaire
  complet CRUD/jobs, Reverb réel, webhooks, charge et restauration/réconciliation.
  Livraison locale, nouvelle CI à confirmer après revue/commit/push.
- **Deuxième lot local (24/10/2026)** : fiches Personnes/Équipes et effectifs.
  Neuf cas ajoutés, deux scénarios étrangers renforcés : départ/archivage étrangers,
  révocation d'adhésion/rôle après accès réussi, retrait granulaire de permissions,
  rattachements/périodes/transferts inter-tenant refusés sans effet métier.
  Lectures/modifications Beta autorisées conservées. Groupe critique : 19 tests,
  270 assertions ; neuf suites sélectionnées : 180 tests, 1 094 assertions sur
  SQLite et sur PostgreSQL 17 temporaire, aucun échec ni exclusion. Pint/PHPStan
  verts, cluster supprimé ; code métier et migrations inchangés. Suite à étendre
  aux profils de jeu, périodes et compositions ; QA-002 reste ouvert.
  CI de ce nouveau lot à confirmer après revue/commit/push.

- **Troisième lot local (24/10/2026)** : profils/comptes de jeu, périodes/checklist
  et compositions d'équipe. Douze cas ajoutés, scénarios étrangers renforcés et
  trois scénarios existants intégrés au groupe critique : 34 tests, 489 assertions.
  Refus sous le mauvais tenant/parent, révocation d'adhésion/rôle après lecture,
  retrait des seules permissions d'écriture ; données/versions/audits conservés.
  Onze suites : 220 tests, 1 160 assertions sur SQLite et PostgreSQL 17 temporaire,
  zéro échec, erreur ou exclusion. Pint/PHPStan verts, cluster supprimé ; aucun
  changement métier ou de migration. CI du deuxième lot `c3bbe85` validée par
  l'utilisateur ; troisième lot livré dans un commit dédié, sans push,
  CI à confirmer après livraison distante.
  QA-002 reste ouvert pour les autres CRUD/jobs et les limites REC-23–36.

- **Quatrième lot local (24/10/2026)** : imports de personnes. Douze cas ajoutés,
  scénario HTTP étranger renforcé et intégré au groupe critique : 47 tests,
  804 assertions. Job sérialisé après bascule HTTP, rejeu, révocations avant
  traitement/entre blocs, rapports et artefacts privés ; droits Beta conservés
  sans permettre les créations Alpha révoquées. Cinq suites : 69 tests,
  488 assertions sur SQLite et PostgreSQL 17 temporaire, aucun échec ou test ignoré.
  Pint/PHPStan verts, cluster supprimé, aucun changement métier ou de migration.
  CI de `2c9d3bc` validée par l'utilisateur ; quatrième lot livré dans un commit
  dédié, sans push, CI à confirmer après livraison distante. Limites dans etat-projet.md ;
  autres CRUD/jobs et REC-29/30/34/35/36 restent ouverts.

- **Cinquième lot local (24/10/2026)** : documents contractuels. Neuf cas ajoutés,
  un scénario étranger existant intégré au groupe critique : 57 tests,
  1 024 assertions. Analyse sérialisée après bascule HTTP, rejeu, révocation avant
  exécution et anciens liens signés après révocation ; lectures conservées après
  retrait de l'écriture. Six suites : 89 tests, 618 assertions par moteur SQLite
  et PostgreSQL 17 temporaire, aucun échec ou test ignoré. Pint/PHPStan verts,
  cluster supprimé ; scanner/stockage simulés, aucun changement métier ou migration.
  Commandes SQLite avec stockage simulé commun validées successivement.
  CI de `14117bd` validée par l'utilisateur ; cinquième lot livré dans un commit
  dédié, sans push, CI à confirmer après livraison distante.
  Autres CRUD/jobs et REC-29/30/34/35/36 ouverts.

- **Sixième lot local (24/10/2026)** : rappels contractuels. Sept cas ajoutés,
  un scénario étranger renforcé/intégré au groupe critique : 65 tests,
  1 173 assertions. Tâches sérialisées/rejouées dans deux tenants au destinataire
  commun, révocations avant envoi, historiques privés et résolutions tenant/contrat
  incorrects ; droits Beta maintenus sans autoriser Alpha. Quatre suites :
  81 tests, 538 assertions par moteur SQLite/PostgreSQL 17 temporaire, aucun échec
  ou test ignoré. Pint/PHPStan verts, cluster supprimé, emails simulés ; aucun
  changement métier ou migration. CI de `11d6117` validée par l'utilisateur ;
  sixième lot livré dans un commit dédié, sans push, CI à confirmer après livraison distante.
  Autres CRUD/jobs et REC-29/30/34/35/36 restent ouverts.

- **Septième lot local (24/10/2026)** : inscriptions aux compétitions. Dix cas
  ajoutés, un scénario étranger existant intégré au groupe critique : 76 tests,
  1 395 assertions. Tâches sérialisées/rejouées, révocations avant génération et
  après consultation, retrait de l'écriture, références documentaires sous le
  mauvais tenant/parent. Six suites : 66 tests, 531 assertions par moteur
  SQLite/PostgreSQL 17 temporaire, aucun échec ou test ignoré. Pint/PHPStan verts,
  cluster supprimé ; aucune modification métier ou migration, documents texte
  existants seulement. CI de `9b55cbf` validée par l'utilisateur ; septième lot
  livré dans un commit dédié, sans push, CI à confirmer après livraison distante.
  Autres CRUD/jobs et limites
  REC-29/30/34/35/36 restent ouverts.

- **Huitième lot local (24/10/2026)** : imports statistiques. Neuf cas ajoutés,
  un scénario étranger existant intégré au groupe critique : 86 tests,
  1 695 assertions. Jobs confirmés par HTTP puis sérialisés/rejoués après bascule,
  révocations avant traitement/entre blocs, anciennes générations et résultats
  privés ; observations/révisions/reçus validés conservés. Quatre suites :
  104 tests, 1 082 assertions par moteur SQLite/PostgreSQL 17 temporaire, aucun
  échec ou test ignoré. Pint/PHPStan verts, cluster supprimé ; aucun changement
  métier ou migration. CI de `53d3c4e` validée par l'utilisateur ; huitième lot
  livré dans un commit dédié demandé explicitement, sans push, CI à confirmer
  après livraison distante. Autres CRUD/jobs et REC-29/30/34/35/36 ouverts.

- **Neuvième lot local (24/10/2026)** : observations statistiques. Huit cas
  ajoutés, un scénario étranger existant intégré au groupe critique : 95 tests,
  1 921 assertions. Observations/versions entre tenants, révocations après lecture,
  création/correction distinctes et retrait d'audience d'un match ; valeurs et
  historiques conservés après refus. Quatre suites : 112 tests, 1 459 assertions
  par moteur SQLite/PostgreSQL 17 temporaire, aucun échec ou test ignoré.
  Pint/PHPStan verts, cluster supprimé ; aucun changement métier ou migration.
  CI de `a581415` annoncée en lancement, sans résultat confirmé ; neuvième lot
  livré dans un commit dédié, sans push, CI à obtenir après livraison distante.
  Limites dans etat-projet.md ; autres
  CRUD/jobs et REC-29/30/34/35/36 restent ouverts.

- **Dixième lot local (24/10/2026)** : séances Académie et présences. Neuf cas
  ajoutés, un scénario étranger existant intégré au groupe critique : 105 tests,
  2 131 assertions sur SQLite. Identifiants liés au tenant/cycle/cohorte/séance,
  révocations après lecture et droits de gestion des séances/présences distincts.
  Calendrier devenu privé ou droit calendrier retiré : créneau masqué et écritures
  refusées, lectures Académie encore autorisées conservées. Quatre suites :
  77 tests, 607 assertions par moteur SQLite/PostgreSQL 17 temporaire, sans
  échec, erreur ou exclusion. Pint/PHPStan verts, cluster arrêté et supprimé ;
  aucun changement métier ou migration. CI de `5172f83`
  validée par l'utilisateur ; dixième lot réuni dans un commit dédié, sans push,
  CI à obtenir après livraison distante.
  Autres CRUD/jobs et REC-29/30/34/35/36 restent ouverts.

- **Onzième lot local (24/10/2026)** : suivi pédagogique Académie. Onze cas
  ajoutés, un scénario étranger existant intégré au groupe critique : 117 tests,
  2 421 assertions. Objectifs/retours/notes liés au tenant/cycle/parent,
  révocations après lecture, droits partagés/internes distincts et filtrage de
  l'historique après retrait du droit interne. Accès personnel revérifié après
  retrait d'inscription, départ, retrait du droit ou d'adhésion, sans affecter
  Beta. Quatre suites : 69 tests, 777 assertions par moteur SQLite/PostgreSQL 17
  temporaire, sans échec, erreur ou exclusion. Pint/PHPStan verts, cluster arrêté
  et supprimé ; aucun changement métier ou migration. CI de `22ac7d5` validée par
  l'utilisateur ; onzième lot réuni dans un commit dédié, sans push, CI à obtenir après
  livraison distante. Autres CRUD/jobs et REC-29/30/34/35/36 restent ouverts.

- **Consolidation en tâche unique (24–25/10/2026)** : 45 cas HTTP ajoutés (40 sur
  dix familles de ressources, deux révocations financières et trois après
  signature temps réel), un test Reverb réel et 69 méthodes existantes intégrées
  au groupe critique, soit 84 cas avec paramètres. Groupe critique : 246 tests,
  3 831 assertions sur SQLite. La CI inclut la nouvelle matrice, le test de cache
  isolé et le transport Reverb local avec Node 24. Aucun changement métier ou
  migration. Suites complètes : 2 748 réussites/50 exclusions SQLite et 2 794
  réussites/4 exclusions PostgreSQL 17, sans échec ni erreur. Reverb réel passé
  trois fois (1 test/26 assertions par exécution), Pint/PHPStan verts, ressources
  temporaires nettoyées. Livraison en trois commits dédiés, sans push. QA-002 reste ouvert
  pour QA-005/REC-34, OPS-007/REC-35 et OPS-004/005/REC-36. L'utilisateur confirme
  le 25/10/2026 qu'aucun webhook entrant n'est prévu au P0 : REC-30 non applicable
  à ce périmètre, API-002 conservé pour une future intégration.
  REC-29 dispose d'une preuve de transport local, pas d'une certification de
  l'hébergement ni d'une éviction des sockets déjà connectées. Détails et matrice
  de preuves dans etat-projet.md ; aucune CI distante validée pour la consolidation.

### UCG-QA-003 — Tester les parcours navigateur critiques

- **Priorité / taille** : P0 / L
- **Périmètre** : invitation, MFA, changement de tenant, personne, roster, calendrier, match, contrat et export.
- **Acceptation** : parcours stables sur tailles d’écran convenues et erreurs exploitables.
- **Qualifié sur le périmètre convenu (25/10/2026, `b4dd21a`)** : les trois
  artefacts frontend du run `40142869767` ont été téléchargés après autorisation
  explicite, puis JUnit et données HTML relus. 493 tests unitaires, 53 cas
  Playwright généraux, 8 parcours avec API réelle SQLite et 8 PostgreSQL : aucun
  échec, erreur ou exclusion. Les 69 cas Playwright ont chacun une seule tentative,
  zéro flaky et aucune erreur globale. Les huit parcours représentent les neuf
  thèmes, avec contrôles à 375/320 px. Revue de qualification terminée ; attente
  de lecture des artefacts levée. Les cas avancés déjà recensés restent à prioriser,
  sans nouvel échec constaté. Portée Chromium et parcours ciblés ; QA-002/004/005
  et l'exploitation restent distincts. Prochain chantier proposé : QA-005/REC-34,
  non démarré ici. Preuves et limites dans la dernière section de `etat-projet.md`.
- **Premier lot local (23/10/2026)** : connexion/MFA TOTP, sélection et changement
  de tenant, confirmation et création persistée d'une personne, déconnexion,
  contrôles CSRF/isolation/droits et mobile 375/320 avec API Laravel réelle.
  Lanceur SQLite et stockage temporaires, fixtures de trois tenants, rapports CI
  distincts et suite simulée conservée. Correction du cache persistant du catalogue
  détectée par le rechargement. Ticket ouvert pour les autres parcours et moteurs
  de recette ; détails et commande d'exécution dans le README.
- **Deuxième lot local (23/10/2026)** : personne active, création de deux équipes,
  appartenance, transfert avec changement de rôle et clôture avec API réelle.
  Persistance/historique, refus de chevauchement/identifiants étrangers/version
  périmée/clôture répétée et écritures sans droit ; formulaires à 320 px.
  Compte et organisations distincts du premier scénario, cinq tenants fictifs
  au total. Aucun changement métier ni migration ; les autres parcours restent ouverts.
- **Troisième lot local (23/10/2026)** : calendrier avec API réelle, détection
  d'indisponibilité et refus d'exception non autorisée, création avec équipe et
  participants, déplacement du créneau/ajout d'un participant, annulation et
  historique après rechargement. UTC/Asia-Tokyo, mobile 375/320, versions et accès
  étrangers/en lecture seule contrôlés. Trois comptes de scénario et sept tenants
  fictifs. Pas de changement métier, migration, notification réelle ou worker activé.
- **Quatrième lot local (23/10/2026)** : match privé BO1 créé par l'interface,
  deux compositions affectées, règles et scores saisis, preuve soumise et résultat
  approuvé par un second compte. Auto-approbation refusée, correction motivée
  recalculée par un worker sur la SQLite temporaire, résultat contesté puis
  nouvelle preuve et nouvelle approbation indépendante. Persistance/historique,
  mobile 375/320, version périmée et refus inter-tenant/en lecture seule vérifiés.
  Neuf tenants fictifs ; quatre comptes de scénario et un approbateur. Aucun
  changement métier ou migration. Reverb et workers hébergés restent à valider.
- **Cinquième lot local (24/10/2026)** : création/modification d'un contrat avec
  partie et obligation, conditions financières, revue et approbation indépendante.
  Refus d'auto-approbation et d'accès financier pour l'administratif non habilité,
  versions périmées et gel après approbation. Signature externe déclarée, activation,
  résiliation, archivage bloqué par l'obligation ouverte puis autorisé après son
  accomplissement. Rechargements, historique, isolation et mobile contrôlés.
  Onze tenants fictifs et huit comptes de scénario/intervenants ; aucun changement
  métier ou migration. Documents/avenants/rappels et exports réels restent ouverts.
- **Sixième lot local (24/10/2026)** : export réel active_people, génération par
  worker database/reports, téléchargement CSV, confidentialité et formule neutralisée.
  Confirmation de tenant rendue accessible depuis le panneau, demande idempotente,
  refus pour autre demandeur ou sans permission, export déjà échu et source modifiée.
  Second CSV Beta distinct, rechargement et mobile 375/320. Treize tenants et onze
  comptes de scénario/intervenants. Les cinq autres rapports exportables et les cas
  avancés de révocation/nettoyage en environnement hébergé restent à approfondir.
- **Septième lot local (24/10/2026)** : invitations d'organisation avec API réelle,
  rôle Membre et période d'adhésion, expiration et refus sans accès, révocation,
  réémission puis acceptation et une seule adhésion. Doublon, délégation administrateur,
  rôle injecté, autre destinataire et accès inter-tenant refusés. Rechargements et
  mobile ; quinze tenants et treize comptes de scénario/intervenants. SQLite du
  lanceur sérialisée pour éviter les collisions HTTP/worker ; SMTP reste exclu.
  Enrôlement/récupération MFA et invitations avancées restent ouverts.
- **Huitième lot local (24/10/2026)** : propriétaire sans MFA, activation réelle
  liée à sa session, client TOTP indépendant et codes de secours à usage unique.
  Refus des codes incorrects/rejoués, accès métier bloqué avant vérification,
  récupération et renouvellement révoquant les autres sessions, anciens codes
  invalidés et désactivation interdite au propriétaire. Rechargements, connexion
  TOTP sur un nouveau pas réel et mobile. Seize tenants et quatorze comptes fictifs.
  Limitation 429 et délai Retry-After respectés sans désactiver la protection.
  Remplacement d'authentificateur, désactivation d'un compte ordinaire, limitation
  multi-comptes par IP et expiration d'enrôlement restent des cas à approfondir.
- **Bilan local (24/10/2026)** : les neuf thèmes du périmètre sont couverts par
  huit parcours réels, exécutés sous Chrome à 375/320 px avec SQLite. QA-003 reste
  en qualification, sans extension implicite à toute la matrice QA-002/REC-23–36.
  Preuves et écarts détaillés dans etat-projet.md ; critères et procédure PostgreSQL
  dans le README. CI PostgreSQL existante complétée par les quatre fichiers Contrats
  d'audit/gouvernance financière/rappels/e-mails. Exécution locale de ces ajouts sous
  SQLite : 82 tests, 489 assertions. CI du SHA `c1d5f34` vérifiée le 24/10/2026 :
  backend `40046960453` et frontend `40046960495` réussies, dont les étapes
  PostgreSQL, Redis et parcours navigateur avec API réelle. Artefacts présents
  et non expirés ; contenu des JUnit non relu lors de cette vérification API,
  donc aucun nouveau décompte ni absence d'exclusions revendiqués. QA-003 reste
  en qualification pour la revue des rapports et des écarts documentés.
- **Extension PostgreSQL (24/10/2026)** : mode explicite `--postgresql`, cluster
  PostgreSQL 17 neuf dans le répertoire temporaire, port libre en loopback,
  identifiants propres et refus des connexions héritées. Propriété vérifiée avant
  boot Laravel via manifeste et répertoire réel du serveur ; schéma public vide
  exigé avant migration. Arrêt contrôlé après HTTP/worker puis suppression, ou
  conservation en cas de doute. Six contrôles du lanceur et test d'intégration
  PostgreSQL des refus/propriété/base déjà remplie/annulation validés. Rapports
  séparés ; huit parcours réels réussis sur chaque moteur, sans échec ni exclusion,
  avec arrêt/suppression confirmés. Frontend 493/493, typage/lint/Pint/PHPStan verts.
  Matrice SQLite/PostgreSQL préparée ; nouvelle CI distante à exécuter après livraison.

### UCG-QA-004 — Tester concurrence et idempotence

- **Priorité / taille** : P0 / L
- **Périmètre** : validation de score, réservation, imports, rappels, webhooks et jobs relancés.
- **Acceptation** : conflits explicites, aucune perte silencieuse et aucun double effet métier.
- **Trois lots locaux (26/10/2026)** : inventaire des preuves existantes dans le
  README, sans recréer les courses de scores/résultats déjà couvertes. Calendrier :
  ressources propres à deux tenants au même horaire, deux créations sans conflit
  global. Import statistique : deux workers sur la même génération, compteur et
  résultat uniques, exécution inchangée après rejeu terminal. Rappels contractuels :
  trois courses (même tenant, Alpha/Beta, accusé SMTP incertain) avec un appel par
  occurrence au transport simulé et aucun renvoi automatique après relance.
  Étape CI PostgreSQL dédiée aux rappels ; suites existantes de reprise conservées.
  Données fictives, aucun SMTP réel ni changement applicatif. Résultats et limites
  dans etat-projet.md ; les recettes de transports réels/hébergement et futures
  intégrations restent distinctes, webhooks entrants hors du P0 confirmé.
- **Résultat local** : 178 tests PostgreSQL 17 réussis sur 25 suites, 1 654
  assertions, zéro erreur/échec/exclusion ; 50 cas de concurrence et 128 de
  traitement/reprise. Cinq cas supplémentaires, code métier/migrations inchangés.
  Qualification locale ciblée des trois lots livrée ; nouvelle CI à confirmer,
  sans déduire une garantie exactly-once de tous les effets externes.

### UCG-QA-005 — Tester performances et tenant bruyant

- **Priorité / taille** : P0 / L
- **Périmètre** : 500 comptes, 100 actifs simultanés, 100 000 parties, 2 000 visiteurs publics et charges asymétriques.
- **Acceptation** : p95 mesuré, nombre de requêtes contrôlé et autres tenants protégés pendant les traitements lourds.
- **Reprise prudente (29/10/2026)** : diagnostic isolé de résolution PDO et de la
  première requête, répartition des lectures simultanées côté client par serveur.
  En-têtes absents/invalides refusés et empreintes suivies revérifiées en fin de
  phases. 20 tests Node, lint, Pint et PHPStan verts. Profil stabilisé : 300
  lectures réussies, aucun refus, sources suivies inchangées et nettoyage confirmé ;
  cible encore refusée sur la page de 100 personnes (p95 524,60 ms pendant charge).
  Connexion incluse dans la première requête, sans préchauffage ni modification
  applicative ; aucun gain de performance attribué à cette instrumentation.
  Pressure final : 597 lectures réussies, 3 000 créations et trois exports terminés,
  intégrité ciblée, sources suivies et nettoyage conformes. P95 Beta pendant charge
  15,79 s, dont résidu hors bootstrap/traitement au p95 15,24 s ; jusqu'à 26 lectures
  simultanées côté client par serveur séquentiel. Aucun gain global établi.
  Résultats détaillés et limites dans etat-projet.md. **QA-005/REC-34 ouverts**.
- **Trois lots restants demandés en un commit (29/10/2026)** : (1) topologie HTTP
  concurrente, générateur séparé et Redis/Horizon ; (2) corrections justifiées par
  les mesures de cette topologie puis comparaison à charge identique ; (3) recette
  prolongée, 100 sessions et 2 000 visiteurs publics applicables. Non exécutés :
  hébergement reporté, moteur Docker indisponible, environnement WSL incomplet et
  PHP Windows sans pcntl/posix. Le commit du diagnostic local ne clôt aucun de ces
  lots ; aucune accélération ou recette représentative n'est revendiquée.
- **Lectures et diagnostic (27/10/2026)** : snapshot local pour les filtres/colonnes
  People, projection fraîche après lecture, accès de base sans doublon dans la
  projection. Lectures courantes 47 → 41 requêtes SQL, budgets de services et
  révocations testés. Suite PHP : 2 772 réussites/59 exclusions conditionnelles,
  zéro erreur/échec ; dix suites PostgreSQL : 252 tests verts. Pressure final :
  597 lectures réussies sans refus, 3 000 créations et trois exports, intégrité
  ciblée et nettoyage conformes. P95 Beta pendant charge 18,06 → 10,20 s sur le
  poste partagé ; résidu hors bootstrap/traitement instrumentés mesuré séparément.
  **QA-005/REC-34 ouverts** : cible 500 ms non tenue, hébergement représentatif
  toujours différé. Commit et push demandés ; nouvelle CI à confirmer.
- **Trois lots de corrections (26/10/2026)** : références profile/pressure reprises
  avant modification ; lectures d'affichage multi-classifications regroupées dans
  un snapshot frais par projection, sans cache de droits entre appels et sans
  surcoût du snapshot pour les seuls champs de base. Verrou HTTP réessayé au plus
  onze fois avec dix pauses de 5 ms, quota/fenêtre/verrou inchangés, refus persistant
  et panne toujours bloquants. Diagnostic quota/contention/unclassified uniquement
  dans le serveur de charge isolé, chaque refus conservé dans les mesures.
  Tests des plafonds de requêtes, révocations et scopes, contention et budgets
  concurrents PostgreSQL ; rapports comparatifs et contrôles dans etat-projet.md.
  **QA-005/REC-34 restent ouverts** : le pressure final termine sans refus
  (53 avant), mais son p95 Beta pendant charge atteint 16,56 s contre 14,51 s
  avant ; aucun gain global de latence n'est établi. La recette
  représentative demeure différée. Aucun seuil ou volume diminué.
- **Consolidation des lots restants (25/10/2026)** : profilage SQL sans texte ni
  bindings, 500 comptes/100 sessions, 1 000 matchs/100 000 parties avec contraintes
  actives, lectures réelles personnes/matchs/parties et pression de trois imports
  de 1 000 lignes avec trois exports de 500 lignes. Dernier pressure : protocole
  terminé, 3 000 créations Alpha, intégrité ciblée Beta/Gamma et nettoyage
  conformes, zéro job échoué/en attente. **Performance non validée** : p95 Beta
  3,47 / 8,69 / 3,58 s et 16/29/11 refus 429 par phase ; aucun refus masqué ou
  limiteur désactivé. Régression faible concurrence réussie, 15 tests de garde-fous,
  lint/Pint/PHPStan verts. Protocole de recette représentative préparé ; hébergement
  toujours différé sur confirmation utilisateur. **QA-005/REC-34 ouverts** sur les
  écarts mesurés et la qualification représentative/public/Redis/Horizon/longue
  durée. Commit autorisé, sans push. Détails et rapports dans etat-projet.md.
- **Premier lot local (25/10/2026)** : profil `smoke` sur PostgreSQL 17 jetable,
  trois tenants, trois comptes, 630 fiches et deux sessions ; 100 lectures Beta
  avant/pendant/après Alpha, pages 25/100, trois exports réels de 500 lignes et
  worker database. Dernier essai : p95 332,82 / 378,47 / 381,10 ms, zéro erreur,
  100 lectures Alpha, intégrité ciblée Beta/Gamma et nettoyage confirmés. Premier
  essai plus lent conservé (539,87 ms sous charge, 655,73 ms après) : aucune
  qualification de capacité déduite de la relance. Comptage SQL, diagnostics
  bootstrap/traitement et rapports reproductibles ajoutés ; cinq tests de
  garde-fous et six tests du lanceur réutilisé réussis, lint/Pint/PHPStan/YAML verts.
  **Ticket et REC-34 ouverts** : enveloppe complète, imports simultanés,
  charge publique applicable et infrastructure représentative restent à prouver.
  Aucun changement métier ou migration ; sans commit/push. Détails et limites
  dans la dernière section de `etat-projet.md` et protocole dans le README frontend.

### UCG-DATA-001 — Auditer et préparer les données UCG

- **Priorité / taille** : P0 / L
- **Compléments préparatoires (28/10/2026), un seul commit demandé** : utilisateur
  confirme aucun fichier réel pour le moment. Audits JSON Équipes/Matchs/Contrats
  avec règles des modules, contrôles ponctuels de références/clés en base sous
  administration + MFA + droits métier, revue déclarée liée aux empreintes du lot
  et couverture explicite des 18 familles. Modèles et inventaire de disponibilité
  fictifs ; aucun import. 265 cas ciblés distincts, 264 réussites, 1 exclusion Windows,
  1 555 assertions ; Pint/PHPStan verts. **Ticket en attente des sources réelles** :
  formats supplémentaires seulement s’ils sont nécessaires à ces sources, contrôles
  métier complémentaires et validation vérifiable avant DATA-002. Une approbation
  déclarée ou une liste partielle de vérifications ne vaut pas clôture.
- **Deuxième lot prudent (28/10/2026)** : audit local des CSV Statistiques au format
  canonique existant, date de référence explicite, décimales exactes, disponibilité,
  périodes et identifiants ; doublons et contradictions entre fichiers sans fusion.
  Rapport sans valeurs sources, compteurs Personnes/Statistiques séparés et aucune
  consultation de la base. Existence/droits des références et règles propres aux
  métriques restent non vérifiés. Démonstrations fictives uniquement ; aucun import.
  Validation : 185 cas ciblés distincts, 184 réussites, 1 exclusion conditionnelle
  Windows, 1 623 assertions ; Pint/PHPStan verts. Commit local demandé ensuite, sans push.
- **Premier lot prudent (28/10/2026)** : commande locale `data:audit`, inventaire
  explicite lié au tenant déclaré, références de responsable/finalité/conservation,
  empreintes et rapport JSON sans valeurs personnelles. Analyse du CSV Personnes
  réutilisant parseur et règles existants ; mapping exclusif, colonnes non affectées,
  contrôles de qualité, doublons entre sources et rapprochements à revoir sans fusion.
  Autres formats/familles signalés comme audit dédié nécessaire. Bornes de volume,
  lecture locale confinée et rapport plafonné ; aucune consultation de la base ni
  lancement d’import. Démonstrations exclusivement fictives : lot propre en revue
  métier, lot d’écarts bloqué avec 8 anomalies et 5 points de revue.
  **Ticket ouvert** : inventaire réel et autres formats, propriétaires/finalités/
  conservation à confirmer et rapport à valider par les responsables avant DATA-002.
  Procédure et exemples dans le README. Commit demandé ensuite, sans push.
  Validation ciblée : 156 cas/155 réussites/1 exclusion conditionnelle Windows,
  984 assertions ; imports existants et architecture réexercés, Pint/PHPStan verts.
- **Périmètre** : inventaire des fichiers, qualité, doublons, mapping, propriétaires, données inutiles et règles de conservation.
- **Acceptation** : rapport d’écarts validé par les responsables métier avant import.

### UCG-DATA-002 — Réaliser la reprise à blanc

- **Priorité / taille** : P0 / L
- **Préparation technique (28/10/2026), un seul commit demandé** : commande
  `data:rehearse`, suite dédiée exécutée dans un processus enfant SQLite en mémoire,
  caches/sessions isolés et livraisons externes neutralisées. Services et imports
  réels exercés sur fixtures ; totaux, échantillons, liens, reprise de chunk,
  idempotence, rejets, périmètre tenant et interruption avec retour arrière contrôlés.
  Empreintes de toutes les tables et liste des fichiers comparées à la préparation
  initiale après rollback. Rapport JSON sans cellules ni identifiants métier,
  scénario versionné, diagnostic privé, exécution et éléments de preuve prévus en CI.
  Validation locale : 148 cas distincts, 147 réussites, 1 exclusion Windows,
  717 assertions ; Pint/PHPStan verts. Aucun import réel ni qualification hébergée.
  **Ticket en attente** : sources réelles et validation DATA-001, recette/stockage/
  workers qualifiés, rapprochement signé et procédure de restauration exécutée.
  Le rollback local n’annule pas des commits effectués par des workers distincts.
- **Périmètre** : imports en recette, contrôles de totaux, échantillonnage, erreurs et rollback.
- **Acceptation** : rapprochement signé, aucune donnée hors tenant et procédure reproductible.

### UCG-REL-001 — Exécuter le pilote UCG

- **Priorité / taille** : P0 / L
- **Périmètre** : une équipe pilote, semaine sportive complète, contrats autorisés, support et collecte d’usage agrégée.
- **Acceptation** : parcours P0 validés, anomalies critiques ou bloquantes fermées et décision de généralisation formalisée.
- **Préparation et répétition (29/10/2026)** : dossier complet dans le README :
  périmètre proposé, conditions de départ, programme J1–J7, rôles à nommer,
  matrice de preuves, registre d'anomalies/retests, support, indicateurs agrégés et
  procès-verbal de décision. Scénario HTTP fictif sur sept jours simulés, une
  équipe, planning, composition finalisée, contrat avec approbateur distinct et
  résultat validé indépendamment ; refus d'accès et retrait de droit vérifiés.
  Suite dédiée forcée en SQLite mémoire et test d'environnement parent hostile,
  régression P0/support/reporting/exports : 233 tests réussis, 1 917 assertions,
  zéro exclusion ; Pint/PHPStan verts. CI enrichie, sans prétention de CI distante
  exécutée. Aucun déploiement, collecte d'usage réelle ou accord métier automatique.
  **REL-001 reste ouvert** : équipe/dates/modules à confirmer, hébergement reporté,
  semaine réelle, anomalies réelles et décision formelle non réalisés. L'utilisateur
  confirme : « Pas encore : préparer et tester localement ». La préparation locale
  est réalisée ; aucun pilote réel à lancer dans le périmètre actuel.
- **Correctif CI MFA (29/10/2026)** : échec 403 reproduit lorsque Sanctum n'inclut
  pas localhost:5173, avant création du .env. Origines et domaines de session
  explicités dans le bootstrap isolé ; deux régressions couvrent les défauts CI et
  une configuration héritée étrangère. Suite pilote : 234 tests réussis,
  1 921 assertions, zéro exclusion ; Pint/PHPStan verts. Aucun assouplissement MFA.

### UCG-REL-002 — Préparer la mise en production

- **Priorité / taille** : P0 / L
- **Périmètre** : formation, guides, comptes propriétaires, support, sauvegarde, restauration, monitoring et checklist de release.
- **Acceptation** : responsables nommés, procédures transférées, restauration testée et accord de mise en production enregistré.
- **Préparation locale (29/10/2026), un seul commit demandé** : dossier du README
  avec guides par rôle, formation et registre de transfert, responsabilités,
  fiche d'exploitation, checklist R2-01–15, livraison/retour arrière à autoriser et
  formulaire d'accord. Réemploi des procédures OPS-001/004/005/008 ; revue sur table
  des cas de refus d'ouverture, sans nouvel outil ni changement applicatif.
  L'utilisateur confirme assurer les accès utilisateurs et le support pour le
  moment ; exploitant, suppléants et intervenants spécialisés restent à désigner.
  Huit liens internes vérifiés et 32 tests existants de diagnostic/statut réussis,
  173 assertions ; cela ne prouve pas les ressources d'hébergement.
  **Préparation livrée, REL-002 non clôturé** : hébergement reporté, formation et
  transfert non attestés, sauvegarde/restauration/alertes sur cible non éprouvées,
  accord de production non donné. Aucun déploiement ni push demandé.

## 23 Tickets de cadrage à traiter avant estimation ferme

### UCG-CAD-001 — Valider le modèle commercial multi-tenant

- **Priorité / taille** : P0 / S
- **Décision attendue** : plateforme privée, produit SaaS ou modèle hybride ; nombre de tenants attendu à un, trois et cinq ans.

### UCG-CAD-002 — Valider la propriété des données

- **Priorité / taille** : P0 / M
- **Décision attendue** : tables globales, données tenant, données publiables et projections autorisées des résultats de matchs, sans dossier interne de compétition externe.

### UCG-CAD-003 — Valider la résolution du tenant

- **Priorité / taille** : P0 / S
- **Décision attendue** : sous-domaine, chemin, domaine personnalisé et comportement des liens profonds.

### UCG-CAD-004 — Valider pays, mineurs et conservation

- **Priorité / taille** : P0 / M
- **Décision attendue** : pays d’établissement, territoires, rôles RGPD, durées, représentants légaux et hébergement.
- **Décideur confirmé (27/10/2026)** : l'utilisateur choisit les informations
  demandées aux membres et qui peut les voir. CAD4-02 actualisé ; qualité dans
  laquelle il agit, identité personne/structure et contact restent à préciser,
  sans attribution automatique d'une qualification juridique. Fonctions du
  premier essai réel, durées et procédure mineurs encore à confirmer.
- **Reprise opérationnelle (27/10/2026)** : deux questions transmises à l'utilisateur
  sur le décideur des usages/données et le périmètre du premier essai réel.
  Six décisions PERS-D01–06 préparées dans le dossier README, avec sources du code,
  événements manquants, accès d'archive à décider et huit résultats attendus d'une
  future simulation OPS-007. Aucun événement, durée ou responsable inventé ;
  aucune simulation nouvelle exécutée, aucun moteur/politique de purge activé.
  Deux sources officielles déjà citées revérifiées ; validation locale, réponses
  UCG, durées et hébergement restent ouverts.
- **État consolidé (25/10/2026)** : préparation documentaire consolidée, **ticket
  ouvert pour validation avant données réelles**. L'utilisateur confirme UCG comme
  sa véritable structure et des essais uniquement fictifs pour le moment ; la
  première population réelle visée est tunisienne, MENA/Europe ultérieur.
  Dossier unique dans le README, avec six décisions CAD4-01–06, responsabilités
  non attribuées par défaut, procédure mineurs proposée en huit étapes, repérage
  des sources officielles et critères de passage au réel. Les 18 familles de
  conservation n'ont pas encore de durées métier validées ; l'hébergement reste
  différé. Le rôle du vérificateur UCG ne suffit pas à valider le parcours légal.
  Un seul commit de consolidation est demandé. Aucun code, collecte réelle,
  purge ou activation ACA-005 ; les sections ci-dessous retracent la préparation.
- **Préparation dossiers de personnes (25/10/2026)** : après le cadrage OPS-007
  commité en `83fa8e4`, l'utilisateur confirme l'accueil de mineurs et un périmètre
  MENA/Europe ; les pays exacts restent à préciser. Fiche dans le README : huit
  sous-catégories PERS, six tables de noyau, candidats/essais/membres/anciens,
  événements fiables à définir, accès existants, dépendances et copies. Le statut
  `former` est distingué du départ enregistré et de l'effacement. Date de naissance
  facultative et absence de parcours de représentant identifié dans People ne
  valent pas validation de prise en charge des mineurs. Aucune durée ou seuil
  d'âge fixé, aucun code ni donnée modifié ; simulations seulement décrites.
  Ticket ouvert pour les décisions métier, leur validation applicable et les
  autres familles de données ; OPS-007/REC-35 ouverts également.
- **Décisions pilote complémentaires (25/10/2026)** : premier test réservé à des
  participants vivant en Tunisie ; MENA/Europe concerne la suite. Une personne
  de l'équipe UCG à désigner vérifiera les informations/autorisations des
  parents/tuteurs. Tableau de décisions et proposition de parcours dans le
  README ; procédure, usages, durées et validation locale restent ouverts.
  Aucun code, donnée, filtre géographique ou mécanisme de purge modifié.

### UCG-CAD-005 — Valider jeux et connecteurs prioritaires

- **Priorité / taille** : P0 / M
- **Décision attendue** : jeux, modes, métriques, fournisseurs, licences, quotas et disponibilité des identifiants externes.

### UCG-CAD-006 — Valider le périmètre exact du pilote

- **Priorité / taille** : P0 / S
- **Décision attendue** : équipe UCG pilote, utilisateurs, modules activés, données reprises, calendrier et critères de succès.
- **Décision partielle (25/10/2026)** : l'utilisateur confirme que les participants
  du premier test vivront uniquement en Tunisie. Le périmètre MENA/Europe reste
  ultérieur ; équipe, liste des participants, modules, calendrier et critères de
  succès restent à décider. CAD-006 n'est pas clôturé par ce seul choix de pays.
- **Précision ultérieure (25/10/2026)** : l'essai actuel utilise uniquement des
  données fictives. La résidence tunisienne définit la première population réelle
  cible, sans affirmer qu'une liste de personnes réelles est déjà collectée.
  Le dossier CAD-004 ne remplace pas le choix de l'équipe/modules du premier essai.

## 24 Découpage suggéré des premières itérations

### Itération 0 — Cadrage et socle

Tickets de cadrage, UCG-ARC-001 à UCG-ARC-004, conventions, environnements et premier modèle de données.

### Itération 1 — Tenant et identité

UCG-TEN-001 à UCG-TEN-004, UCG-IAM-001 à UCG-IAM-004 et premières fixtures multi-tenants.

### Itération 2 — Premier parcours vertical

Personne → équipe → affectation, avec recherche, Policy, audit, tests inter-tenant et interface accessible.

### Itération 3 — Documents et planning

Stockage privé, contrats simples, activités, disponibilités, conflits et convocations.

### Itération 4 — Semaine sportive

Lineup, scrim, match, parties, score, validation, débrief et notifications.

### Itération 5 — Statistiques et pilotage

Import CSV, métriques, agrégats, tableaux de bord et exports.

### Itération 6 — Stabilisation du pilote

Reprise à blanc, tests navigateur, charge, restauration, formation et correction des anomalies bloquantes.

Les tickets P1 et P2 sont replanifiés après la recette UCG. Les epics de taille XL doivent être découpés en stories indépendamment testables avant engagement dans un sprint.

## 25 Matrice de couverture du CDC

| Domaine du CDC | Tickets principaux |
| --- | --- |
| Architecture, qualité et interface | UCG-ARC-001 à UCG-ARC-006 |
| Organisations et isolation multi-tenant | UCG-TEN-001 à UCG-TEN-008, UCG-QA-001, UCG-QA-002 |
| Identité, rôles et sécurité | UCG-IAM-001 à UCG-IAM-007, UCG-OPS-006 à UCG-OPS-008 |
| Personnes, joueurs, staff et recrutement | UCG-HUM-001 à UCG-HUM-006 |
| Jeux, équipes, rosters et éligibilité | UCG-TEAM-001 à UCG-TEAM-006 |
| Académie et coaching | UCG-ACA-001 à UCG-ACA-005 |
| Calendrier, disponibilités et logistique | UCG-CAL-001 à UCG-CAL-008 |
| Scrims, matchs, scores et débriefs | UCG-MATCH-001 à UCG-MATCH-008 |
| Suivi des compétitions externes | UCG-COMP-001 à UCG-COMP-003 |
| Statistiques, imports et connecteurs | UCG-STAT-001 à UCG-STAT-006 |
| Contrats et documents | UCG-CTR-001 à UCG-CTR-007 |
| Sponsors et partenariats | UCG-SPON-001 à UCG-SPON-005 |
| Live, communication et modération | UCG-MEDIA-001 à UCG-MEDIA-003, UCG-COM-001, UCG-COM-002, UCG-MOD-001 |
| Finances et équipements | UCG-FIN-001 à UCG-FIN-004, UCG-EQP-001 |
| Tableaux de bord, recherche et exports | UCG-REP-001 à UCG-REP-004 |
| API, webhooks et intégrations | UCG-API-001 à UCG-API-005 |
| Pages publiques | UCG-PUB-001 à UCG-PUB-004 |
| Exploitation, sauvegardes et conformité | UCG-OPS-001 à UCG-OPS-008 |
| Données, recette et livraison | UCG-QA-001 à UCG-QA-005, UCG-DATA-001, UCG-DATA-002, UCG-REL-001, UCG-REL-002 |

Cette matrice garantit la couverture fonctionnelle du CDC, mais ne remplace pas la traçabilité fine entre chaque critère d’acceptation et les tests automatisés. Cette traçabilité sera complétée lors du découpage des tickets XL et de la préparation de la recette.
