# Ultra Cyber Game

## Backlog technique modulaire de la plateforme esports multi-tenant

Version 1.0 — 3 octobre 2026

Ce document transforme le cahier des charges fonctionnel en tickets techniques suffisamment détaillés pour préparer les sprints, les estimations et l’affectation des travaux. Il ne remplace ni les maquettes, ni les règles métier détaillées, ni les décisions de cadrage encore ouvertes.

UCG est le tenant pilote. Le socle reste conçu pour accueillir plusieurs organisations sans partage implicite de leurs données.

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
- **Références CDC** : ORG-02 à ORG-05.

### UCG-TEN-004 — Imposer l’isolation dans PostgreSQL

- **Priorité / taille** : P0 / XL
- **Périmètre** : `organization_id` non nul, clés uniques propres au tenant, index composites, relations interobjets sûres et évaluation documentée de Row-Level Security.
- **Acceptation** : les associations inter-tenant invalides échouent en base ; les tables globales sont recensées dans une liste fermée.
- **Dépendances** : UCG-TEN-001, UCG-ARC-002.

### UCG-TEN-005 — Isoler fichiers, caches, verrous et quotas

- **Priorité / taille** : P0 / L
- **Périmètre** : préfixes S3 par tenant, clés Redis incluant le tenant, limitation de débit et limites techniques d’import, export et stockage.
- **Acceptation** : deux tenants utilisant les mêmes identifiants fonctionnels ne partagent ni fichier, ni cache, ni verrou ; les limites d’un tenant n’affectent pas silencieusement les autres.
- **Références CDC** : ORG-12, IAM-08, SEC-12.

### UCG-TEN-006 — Propager le tenant dans les jobs et le scheduler

- **Priorité / taille** : P0 / L
- **Périmètre** : sérialisation du tenant, restauration contrôlée du contexte, refus d’un tenant inactif, verrouillage et supervision des tâches planifiées.
- **Acceptation** : relance, nouvelle tentative et exécution différée utilisent toujours le tenant enregistré ; l’échec d’un tenant ne bloque pas les autres.
- **Références CDC** : ORG-14, IAM-08.

### UCG-TEN-007 — Gérer le cycle de vie et la portabilité d’un tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : suspension, réactivation, export complet, archivage, purge et récupération logique documentée.
- **Acceptation** : export traçable et borné ; suppression conforme à la conservation ; aucune donnée d’un autre tenant modifiée.
- **Références CDC** : ORG-09 à ORG-11.

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

### UCG-IAM-003 — Construire les rôles et permissions contextuels

- **Priorité / taille** : P0 / XL
- **Périmètre** : rôles configurables par tenant, périmètres équipe/jeu/cohorte/événement, périodes d’effet et suspension prioritaire.
- **Acceptation** : un même compte possède des droits différents selon tenant et affectation ; aucune auto-attribution de rôle supérieur.
- **Références CDC** : IAM-01 à IAM-04, IAM-09.

### UCG-IAM-004 — Appliquer les Policies et la visibilité par champ

- **Priorité / taille** : P0 / L
- **Périmètre** : Policies partagées, projections DTO/Resource et filtrage des données sportives, administratives et financières.
- **Acceptation** : un champ interdit n’est jamais envoyé au frontend, aux exports, aux recherches ou aux notifications.
- **Dépendances** : UCG-IAM-003, UCG-TEN-002.

### UCG-IAM-005 — Séparer administration tenant et plateforme

- **Priorité / taille** : P0 / L
- **Périmètre** : propriétaire, admin d’organisation, superadmin plateforme et accès exceptionnel limité dans le temps.
- **Acceptation** : motif, tenant, durée et actions du support sont audités ; expiration automatique ; aucune approbation métier par un compte technique.
- **Références CDC** : IAM-09, SEC-14.

### UCG-IAM-006 — Mettre en place le journal d’audit

- **Priorité / taille** : P0 / L
- **Périmètre** : acteur, tenant, action, cible, avant/après utile, motif, adresse technique et identifiant de corrélation.
- **Acceptation** : journal non modifiable par les utilisateurs ordinaires, sans secrets ni contenu excessif, avec rétention définie.
- **Références CDC** : SEC-07, SEC-08.

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

### UCG-HUM-002 — Gérer profils de jeu et comptes externes

- **Priorité / taille** : P0 / M
- **Périmètre** : jeu, identifiant externe stable, région, rang, rôle, comptes liés et fraîcheur.
- **Acceptation** : plusieurs profils par personne ; unicités et rapprochements limités au bon contexte.
- **Références CDC** : HUM-03, HUM-08.

### UCG-HUM-003 — Livrer fiches, listes et recherche paginées

- **Priorité / taille** : P0 / L
- **Périmètre** : CRUD autorisé, filtres, tri, pagination, vues générale/sportive/administrative et états vides.
- **Acceptation** : requêtes eager-loaded, champs filtrés côté serveur, aucun résultat d’un autre tenant.
- **Dépendances** : UCG-HUM-001, UCG-IAM-004.

### UCG-HUM-004 — Gérer affectations, statuts et départ

- **Priorité / taille** : P0 / L
- **Périmètre** : affectations datées, indisponibilité, ancien membre, révocation des accès, équipements et obligations restantes.
- **Acceptation** : le départ ferme les droits du tenant concerné sans supprimer l’historique ni les autres adhésions du compte.
- **Références CDC** : HUM-04, HUM-05, IAM-04.

### UCG-HUM-005 — Importer les personnes par CSV

- **Priorité / taille** : P0 / L
- **Périmètre** : dépôt privé, prévisualisation, mapping, validation, doublons, erreurs par ligne et reprise idempotente.
- **Acceptation** : aucune écriture avant confirmation ; relance sans doublon ; rapport téléchargeable dans le tenant.
- **Références CDC** : HUM-06, REC-06, REC-07.

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

### UCG-TEAM-002 — Gérer les équipes d’une organisation

- **Priorité / taille** : P0 / M
- **Périmètre** : équipe première, académie, réserve, événementielle ou catégorie spécifique ; jeu, saison, statut et identité publique.
- **Acceptation** : listes paginées, slug unique dans le tenant, archivage sans perte d’historique.
- **Dépendances** : UCG-TEAM-001, UCG-TEN-004.

### UCG-TEAM-003 — Gérer les appartenances datées

- **Priorité / taille** : P0 / L
- **Périmètre** : titulaire, remplaçant, capitaine, coach, analyste, dates et périodes sans chevauchement interdit.
- **Acceptation** : droits recalculés aux dates d’effet ; historique conservé ; départ et promotion tracés.
- **Références CDC** : TEAM-03, TEAM-08.

### UCG-TEAM-004 — Créer lineups et snapshots historiques

- **Priorité / taille** : P0 / L
- **Périmètre** : lineup de match ou d’inscription, remplaçants et instantané des participants.
- **Acceptation** : un changement de roster ultérieur ne modifie jamais un match historique.
- **Références CDC** : TEAM-04, TEAM-05, REC-05.

### UCG-TEAM-005 — Implémenter l’éligibilité sportive

- **Priorité / taille** : P0 / L
- **Périmètre** : taille min/max, verrouillage, jeu, région, âge, rang et règles configurées par compétition.
- **Acceptation** : résultat explicable ; contrat, rôle et éligibilité restent trois notions séparées.
- **Références CDC** : TEAM-06, CTR-09.

### UCG-TEAM-006 — Référencer adversaires et organisations externes

- **Priorité / taille** : P0 / M
- **Périmètre** : références externes, données publiques autorisées et snapshots de compétition.
- **Acceptation** : aucun droit interne attribué à un adversaire ; aucune donnée privée importée depuis un autre tenant.
- **Références CDC** : TEAM-07, TEAM-09.

## 9 Module Académie et coaching

### UCG-ACA-001 — Gérer cohortes, cycles et inscriptions

- **Priorité / taille** : P0 / M
- **Périmètre** : cohorte, cycle, mentors, inscrits, dates et emploi du temps.
- **Acceptation** : adhésions et personnes du même tenant uniquement ; listes paginées et historique conservé.
- **Références CDC** : ACA-01.

### UCG-ACA-002 — Gérer séances, présences et exercices

- **Priorité / taille** : P0 / M
- **Périmètre** : séance reliée au calendrier, présence, exercice, responsable et compte rendu.
- **Acceptation** : la présence est modifiable uniquement par les rôles autorisés et reste auditée.
- **Références CDC** : ACA-03.

### UCG-ACA-003 — Gérer objectifs et feedback

- **Priorité / taille** : P0 / L
- **Périmètre** : objectifs individuels/collectifs, échéances, responsable, feedback partagé et notes internes.
- **Acceptation** : le joueur voit seulement le feedback partagé ; les notes internes suivent une permission distincte.
- **Références CDC** : ACA-02, ACA-04.

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

## 10 Module Planning, disponibilités et logistique

### UCG-CAL-001 — Modéliser les activités du calendrier

- **Priorité / taille** : P0 / L
- **Périmètre** : entraînement, scrim, match, réunion, académie, bootcamp, sponsor, déplacement ; UTC, fuseau IANA, lieu, lien et confidentialité.
- **Acceptation** : affichage dans le fuseau utilisateur avec fuseau d’origine visible ; relations limitées au tenant.
- **Références CDC** : CAL-01, CAL-02.

### UCG-CAL-002 — Gérer disponibilités et absences

- **Priorité / taille** : P0 / M
- **Périmètre** : créneaux disponibles, indisponibles ou incertains, commentaire facultatif sans motif médical exigé.
- **Acceptation** : un joueur modifie uniquement ses données autorisées ; historique utile conservé.
- **Références CDC** : CAL-03, REC-02.

### UCG-CAL-003 — Détecter les conflits de réservation

- **Priorité / taille** : P0 / L
- **Périmètre** : chevauchements personnes, équipes et ressources ; service de détection transactionnel.
- **Acceptation** : conflit affiché avant confirmation ; exception motivée, autorisée et auditée ; tests de concurrence.
- **Références CDC** : CAL-04, REC-03.

### UCG-CAL-004 — Gérer convocations, réponses et rappels

- **Priorité / taille** : P0 / L
- **Périmètre** : présent, absent, en attente, échéance, rappels et déduplication.
- **Acceptation** : rappel asynchrone incluant le contexte tenant, traçable et non envoyé deux fois après une nouvelle tentative.
- **Références CDC** : CAL-05.

### UCG-CAL-005 — Exporter un calendrier ICS privé

- **Priorité / taille** : P0 / M
- **Périmètre** : jeton révocable, projection minimale, bornes temporelles et cache privé.
- **Acceptation** : révocation immédiate ; aucun contrat, note interne ou objet d’un autre tenant exposé.
- **Références CDC** : CAL-07, CAL-10.

### UCG-CAL-006 — Gérer reports, annulations et historique

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

- **Priorité / taille** : P0 / L
- **Périmètre** : jeu, format, participants multiples, horaire, responsable, visibilité et transitions proposées dans le CDC.
- **Acceptation** : transitions invalides refusées par une règle de domaine testée ; deux équipes non imposées au modèle.
- **Références CDC** : MATCH-01, MATCH-02.

### UCG-MATCH-002 — Affecter lineup et remplaçants

- **Priorité / taille** : P0 / L
- **Périmètre** : sélection, éligibilité, changements avant/pendant le match et snapshot.
- **Acceptation** : chaque changement indique auteur et moment ; historique indépendant du roster actuel.
- **Références CDC** : MATCH-03, TEAM-05.

### UCG-MATCH-003 — Saisir parties, cartes et score de série

- **Priorité / taille** : P0 / XL
- **Périmètre** : game instances, participants, carte/mode/version, BO1/BO3/BO5, nul, forfait, abandon et remake.
- **Acceptation** : calcul par stratégie de format ; BO3 incohérent refusé avec message exploitable.
- **Références CDC** : MATCH-04, MATCH-05, REC-08.

### UCG-MATCH-004 — Proposer et valider les résultats

- **Priorité / taille** : P0 / L
- **Périmètre** : soumission, preuve, approbation distincte, version optimiste, contestation et motif.
- **Acceptation** : validation autorisée uniquement ; conflit 409 sur version obsolète ; aucune perte silencieuse.
- **Références CDC** : MATCH-06, MATCH-07, REC-09.

### UCG-MATCH-005 — Gérer préparation, VOD et débrief

- **Priorité / taille** : P0 / M
- **Périmètre** : objectifs, notes, liens VOD, annotations et actions de suivi.
- **Acceptation** : stratégies protégées par permission dédiée ; contenus publics séparés des notes internes.
- **Références CDC** : MATCH-08.

### UCG-MATCH-006 — Recalculer après correction d’un score

- **Priorité / taille** : P0 / L
- **Périmètre** : audit avant/après, recalcul asynchrone, invalidation de cache et republication contrôlée.
- **Acceptation** : correction motivée, idempotente et propagée aux agrégats sans faux doublon.
- **Références CDC** : MATCH-07, REC-10.

### UCG-MATCH-007 — Diffuser les scores autorisés en temps réel

- **Priorité / taille** : P0 / L
- **Périmètre** : événements après transaction, canaux publics ou privés incluant le tenant et fraîcheur de la donnée.
- **Acceptation** : aucune stratégie ou donnée privée sur un canal public ; délai mesurable depuis la validation.
- **Références CDC** : MATCH-10, LIVE-03, LIVE-06.

### UCG-MATCH-008 — Ajouter veto et sélection des cartes

- **Priorité / taille** : P1 / L
- **Périmètre** : règles par jeu/compétition, ordre des actions et audit.
- **Acceptation** : séquence invalide refusée ; résultat reproductible à partir de l’historique.
- **Références CDC** : MATCH-09.

## 12 Module Compétitions et tournois

### UCG-COMP-001 — Suivre les compétitions externes

- **Priorité / taille** : P0 / M
- **Périmètre** : organisateur, jeu, région, saison, règlement versionné, contacts et URL.
- **Acceptation** : données limitées au tenant, documents privés et historique des changements importants.
- **Références CDC** : COMP-01.

### UCG-COMP-002 — Gérer inscriptions et échéances

- **Priorité / taille** : P0 / L
- **Périmètre** : paiement renseigné, pièces, roster, validation, check-in, règles d’âge/résidence/rang et tâches manager.
- **Acceptation** : éléments manquants visibles et rappelés ; aucune éligibilité déduite automatiquement d’un contrat.
- **Références CDC** : COMP-02, COMP-04, COMP-05.

### UCG-COMP-003 — Relier résultats, classement et dotation

- **Priorité / taille** : P0 / M
- **Périmètre** : matchs, classement, dotation annoncée et réellement reçue.
- **Acceptation** : montants et résultats soumis aux droits dédiés ; source et date conservées.
- **Références CDC** : COMP-03.

### UCG-TOUR-001 — Créer un tournoi organisé et ses inscriptions

- **Priorité / taille** : P1 / L
- **Périmètre** : tenant organisateur, page d’inscription, règlement, capacité, critères, liste d’attente et verrouillage.
- **Acceptation** : version du règlement acceptée conservée ; participant externe limité à sa propre inscription.
- **Références CDC** : TOUR-01 à TOUR-03.

### UCG-TOUR-002 — Gérer participants interorganisations

- **Priorité / taille** : P1 / L
- **Périmètre** : organisation participante facultative, compte externe, roster soumis et snapshots autorisés.
- **Acceptation** : aucune adhésion implicite au tenant organisateur ; seul le périmètre partagé est lisible.
- **Références CDC** : TOUR-02, TOUR-09, TOUR-10, REC-31.

### UCG-TOUR-003 — Générer un bracket à élimination simple

- **Priorité / taille** : P1 / XL
- **Périmètre** : seeding, byes, formats de séries, forfaits et progression sur résultat validé.
- **Acceptation** : génération déterministe, format verrouillé après démarrage et couverture exhaustive des cas limites.
- **Références CDC** : TOUR-04, TOUR-05, TOUR-07, REC-18.

### UCG-TOUR-004 — Gérer preuves, litiges et corrections dépendantes

- **Priorité / taille** : P1 / XL
- **Périmètre** : propositions contradictoires, arbitrage, décision, correction tardive et rencontres dépendantes.
- **Acceptation** : aucune propagation avant validation ; impact sur les tours suivants présenté et traité explicitement.
- **Références CDC** : TOUR-06, REC-19.

### UCG-TOUR-005 — Ajouter les formats avancés

- **Priorité / taille** : P2 / XL
- **Périmètre** : poules, round robin, double élimination et suisse avec départages propres.
- **Acceptation** : chaque moteur possède invariants, simulations et jeux de tests séparés.
- **Références CDC** : TOUR-08.

## 13 Module Statistiques et performance

### UCG-STAT-001 — Créer le dictionnaire versionné des métriques

- **Priorité / taille** : P0 / L
- **Périmètre** : code, définition, unité, agrégation, formule, jeu, mode, rôle, patch et disponibilité.
- **Acceptation** : version de formule conservée ; métriques globales séparées des valeurs privées des tenants.
- **Références CDC** : STAT-01, STAT-09.

### UCG-STAT-002 — Saisir et corriger les observations

- **Priorité / taille** : P0 / L
- **Périmètre** : source, période, partie, joueur, contexte, valeur absente, correction autorisée et historique.
- **Acceptation** : indisponible différent de zéro ; donnée importée jamais écrasée silencieusement.
- **Références CDC** : STAT-02, STAT-04, STAT-05, STAT-08.

### UCG-STAT-003 — Construire le pipeline d’import statistique

- **Priorité / taille** : P0 / XL
- **Périmètre** : fichier privé, validation de schéma, prévisualisation, correspondance des joueurs, déduplication incluant le tenant, erreurs et reprise.
- **Acceptation** : import idempotent, lignes refusées identifiables et aucune observation créée avant confirmation.
- **Références CDC** : section 10.3, REC-06, REC-07.

### UCG-STAT-004 — Calculer agrégats et ratios

- **Priorité / taille** : P0 / L
- **Périmètre** : jobs de calcul, composants des ratios, dénominateur zéro, taille d’échantillon et version de calcul.
- **Acceptation** : aucun calcul par moyenne naïve de ratios ; recalcul ciblé après correction.
- **Références CDC** : STAT-06, STAT-07, STAT-09.

### UCG-STAT-005 — Livrer profils et comparaisons contextualisés

- **Priorité / taille** : P0 / L
- **Périmètre** : filtres saison, patch, rôle, carte, adversaire, période, source et fraîcheur.
- **Acceptation** : comparaisons uniquement sur contextes cohérents ; données incomplètes clairement signalées.
- **Références CDC** : STAT-01, STAT-03, STAT-04, STAT-10.

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

### UCG-CTR-002 — Versionner documents et avenants

- **Priorité / taille** : P0 / L
- **Périmètre** : document, versions immuables, version signée et avenants liés.
- **Acceptation** : aucun remplacement silencieux ; historique et hash du fichier conservés.
- **Références CDC** : CTR-02, CTR-03.

### UCG-CTR-003 — Sécuriser dépôt et téléchargement des fichiers

- **Priorité / taille** : P0 / L
- **Périmètre** : stockage privé par tenant, type/taille, analyse de contenu, URL temporaire et nouvelle autorisation au téléchargement.
- **Acceptation** : exécutable refusé, lien expiré inutilisable et aucun chemin d’un autre tenant devinable ou accessible.
- **Références CDC** : CTR-07, SEC-06, REC-14.

### UCG-CTR-004 — Gérer approbations et champs financiers

- **Priorité / taille** : P0 / L
- **Périmètre** : séparation préparation, approbation, signature ; permissions au niveau des montants et modalités sensibles.
- **Acceptation** : un coach non habilité ne reçoit aucun champ financier ; décisions tracées.
- **Références CDC** : CTR-05, REC-01.

### UCG-CTR-005 — Planifier échéances et rappels

- **Priorité / taille** : P0 / M
- **Périmètre** : échéance, préavis, renouvellement, règles 90/30/7 configurables et jobs idempotents.
- **Acceptation** : propriétaire notifié, nouvelle tentative sans doublon et statut du traitement consultable.
- **Références CDC** : CTR-04, REC-16.

### UCG-CTR-006 — Auditer consultations et exports sensibles

- **Priorité / taille** : P0 / M
- **Périmètre** : lecture, téléchargement, export, acteur, motif éventuel et tenant.
- **Acceptation** : audit consultable uniquement par les rôles autorisés et dépourvu du contenu du contrat.
- **Références CDC** : CTR-06, CTR-10.

### UCG-CTR-007 — Intégrer la signature électronique

- **Priorité / taille** : P1 / XL
- **Périmètre** : fournisseur abstrait, envoi, callbacks signés, événements dupliqués, preuve et récupération du document final.
- **Acceptation** : webhook idempotent, preuve archivée, version signée clairement identifiée et échec récupérable.
- **Références CDC** : CTR-08.

## 15 Module Sponsors et partenariats

### UCG-SPON-001 — Gérer prospects et contacts

- **Priorité / taille** : P0 / M
- **Périmètre** : organisation sponsor, contacts, opportunité, statut et historique saisi dans la plateforme.
- **Acceptation** : recherche paginée et limitée au tenant ; données RH UCG inaccessibles aux rôles partenariat.
- **Références CDC** : SPON-01.

### UCG-SPON-002 — Gérer accords et périmètres d’exposition

- **Priorité / taille** : P0 / L
- **Périmètre** : période, montant ou apport, responsable, équipe/événement/joueur, exclusivités et pièces.
- **Acceptation** : autorisation financière distincte et aucune exposition non approuvée.
- **Références CDC** : SPON-02 à SPON-04.

### UCG-SPON-003 — Suivre campagnes et livrables

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

### UCG-REP-002 — Calculer les indicateurs en arrière-plan

- **Priorité / taille** : P0 / L
- **Périmètre** : agrégats versionnés, fraîcheur, invalidation et recalcul ciblé.
- **Acceptation** : dashboards courants sans calcul lourd synchrone ; tenant inclus dans chaque clé de cache.

### UCG-REP-003 — Construire la recherche limitée au tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : personnes, équipes, matchs, compétitions et documents autorisés ; filtres et autocomplétion.
- **Acceptation** : aucune existence d’un autre tenant révélée ; champs confidentiels exclus de l’index non autorisé.
- **Références CDC** : REC-24.

### UCG-REP-004 — Générer les exports asynchrones

- **Priorité / taille** : P0 / L
- **Périmètre** : demande, permission figée ou revérifiée selon politique, job, fichier privé, expiration et audit.
- **Acceptation** : période et volume bornés ; téléchargement réautorisé ; cache et stockage isolés.
- **Références CDC** : REC-27.

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

### UCG-OPS-002 — Automatiser les déploiements

- **Priorité / taille** : P0 / L
- **Périmètre** : build, tests, migrations compatibles, déploiement web/workers/realtime et plan de retour arrière.
- **Acceptation** : release reproductible, scheduler unique et migration destructive interdite sans stratégie validée.

### UCG-OPS-003 — Superviser application et infrastructure

- **Priorité / taille** : P0 / L
- **Périmètre** : logs structurés, erreurs, latence, queues, connexions temps réel, imports, rappels, stockage et corrélation.
- **Acceptation** : alertes actionnables avec responsable et procédure ; aucune donnée sensible dans les logs.

### UCG-OPS-004 — Mettre en place sauvegarde et restauration

- **Priorité / taille** : P0 / XL
- **Périmètre** : PostgreSQL, fichiers, chiffrement, copie isolée, rétention, PITR, RPO/RTO et tests.
- **Acceptation** : restauration complète mesurée ; cohérence base/fichiers ; rapport conservé.

### UCG-OPS-005 — Tester la récupération logique d’un tenant

- **Priorité / taille** : P0 / L
- **Périmètre** : export, sélection depuis sauvegarde, réconciliation et procédure de réimport contrôlée.
- **Acceptation** : récupération sans écraser les mises à jour récentes des autres tenants.
- **Références CDC** : REC-36.

### UCG-OPS-006 — Appliquer la sécurité des fichiers et dépendances

- **Priorité / taille** : P0 / L
- **Périmètre** : analyse antivirus ou équivalente, MIME réel, tailles, dépendances, scans et correctifs.
- **Acceptation** : fichiers dangereux mis en quarantaine ; vulnérabilités suivies avec délai de traitement.

### UCG-OPS-007 — Implémenter conservation et purge

- **Priorité / taille** : P0 / L
- **Périmètre** : matrice par catégorie/tenant, archivage restreint, purge, exports temporaires, réplicas et cycle des sauvegardes.
- **Acceptation** : soft delete non confondu avec effacement ; opération auditée et testée.

### UCG-OPS-008 — Rédiger les procédures d’incident et support

- **Priorité / taille** : P0 / M
- **Périmètre** : indisponibilité, fuite suspectée, intégration en panne, file bloquée, restauration et accès exceptionnel.
- **Acceptation** : responsables, seuils, escalade, communication et preuves de clôture définis.

## 22 Module Tests, données et livraison

### UCG-QA-001 — Créer les fixtures multi-tenants

- **Priorité / taille** : P0 / M
- **Périmètre** : UCG, deux tenants de test, comptes multiorganisations, rôles, données privées similaires et identifiants volontairement proches.
- **Acceptation** : scénarios positifs et attaques inter-tenant reproductibles en local et CI.

### UCG-QA-002 — Automatiser la matrice d’isolation

- **Priorité / taille** : P0 / XL
- **Périmètre** : CRUD, URL directe, recherche, export, fichier, cache, job, notification, webhook et temps réel.
- **Acceptation** : tous les scénarios REC-23 à REC-36 applicables au P0 sont couverts ; tout nouveau module tenant ajoute ses cas négatifs.

### UCG-QA-003 — Tester les parcours navigateur critiques

- **Priorité / taille** : P0 / L
- **Périmètre** : invitation, MFA, changement de tenant, personne, roster, calendrier, match, contrat et export.
- **Acceptation** : parcours stables sur tailles d’écran convenues et erreurs exploitables.

### UCG-QA-004 — Tester concurrence et idempotence

- **Priorité / taille** : P0 / L
- **Périmètre** : validation de score, réservation, imports, rappels, webhooks et jobs relancés.
- **Acceptation** : conflits explicites, aucune perte silencieuse et aucun double effet métier.

### UCG-QA-005 — Tester performances et tenant bruyant

- **Priorité / taille** : P0 / L
- **Périmètre** : 500 comptes, 100 actifs simultanés, 100 000 parties, 2 000 visiteurs publics et charges asymétriques.
- **Acceptation** : p95 mesuré, nombre de requêtes contrôlé et autres tenants protégés pendant les traitements lourds.

### UCG-DATA-001 — Auditer et préparer les données UCG

- **Priorité / taille** : P0 / L
- **Périmètre** : inventaire des fichiers, qualité, doublons, mapping, propriétaires, données inutiles et règles de conservation.
- **Acceptation** : rapport d’écarts validé par les responsables métier avant import.

### UCG-DATA-002 — Réaliser la reprise à blanc

- **Priorité / taille** : P0 / L
- **Périmètre** : imports en recette, contrôles de totaux, échantillonnage, erreurs et rollback.
- **Acceptation** : rapprochement signé, aucune donnée hors tenant et procédure reproductible.

### UCG-REL-001 — Exécuter le pilote UCG

- **Priorité / taille** : P0 / L
- **Périmètre** : une équipe pilote, semaine sportive complète, contrats autorisés, support et collecte d’usage agrégée.
- **Acceptation** : parcours P0 validés, anomalies critiques ou bloquantes fermées et décision de généralisation formalisée.

### UCG-REL-002 — Préparer la mise en production

- **Priorité / taille** : P0 / L
- **Périmètre** : formation, guides, comptes propriétaires, support, sauvegarde, restauration, monitoring et checklist de release.
- **Acceptation** : responsables nommés, procédures transférées, restauration testée et accord de mise en production enregistré.

## 23 Tickets de cadrage à traiter avant estimation ferme

### UCG-CAD-001 — Valider le modèle commercial multi-tenant

- **Priorité / taille** : P0 / S
- **Décision attendue** : plateforme privée, produit SaaS ou modèle hybride ; nombre de tenants attendu à un, trois et cinq ans.

### UCG-CAD-002 — Valider la propriété des données

- **Priorité / taille** : P0 / M
- **Décision attendue** : tables globales, données tenant, données publiables et informations partageables pendant un tournoi.

### UCG-CAD-003 — Valider la résolution du tenant

- **Priorité / taille** : P0 / S
- **Décision attendue** : sous-domaine, chemin, domaine personnalisé et comportement des liens profonds.

### UCG-CAD-004 — Valider pays, mineurs et conservation

- **Priorité / taille** : P0 / M
- **Décision attendue** : pays d’établissement, territoires, rôles RGPD, durées, représentants légaux et hébergement.

### UCG-CAD-005 — Valider jeux et connecteurs prioritaires

- **Priorité / taille** : P0 / M
- **Décision attendue** : jeux, modes, métriques, fournisseurs, licences, quotas et disponibilité des identifiants externes.

### UCG-CAD-006 — Valider le périmètre exact du pilote

- **Priorité / taille** : P0 / S
- **Décision attendue** : équipe UCG pilote, utilisateurs, modules activés, données reprises, calendrier et critères de succès.

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
| Compétitions et tournois | UCG-COMP-001 à UCG-COMP-003, UCG-TOUR-001 à UCG-TOUR-005 |
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
