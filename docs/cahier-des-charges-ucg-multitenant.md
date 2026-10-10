# Ultra Cyber Game

## Cahier des charges de la plateforme de gestion esports

Version 1.1 — 3 octobre 2026 — orientation multi-tenant validée

Ce document définit une plateforme web multi-tenant destinée à centraliser la gestion sportive, humaine, administrative et commerciale d’organisations esports. Ultra Cyber Game, ci-après UCG, constitue la première organisation utilisatrice et le tenant pilote. Il sert de base aux ateliers de conception, aux devis, au backlog et à la recette du projet. Les fonctionnalités proposées, les objectifs de service et les estimations restent à valider avec la direction avant de devenir des engagements contractuels.

La recommandation est de construire une application Laravel organisée en modules métier, avec React et TypeScript pour les interfaces, PostgreSQL pour les données et Redis pour les traitements différés. Le socle isole les données, fichiers, autorisations, caches, traitements différés, exports et canaux temps réel de chaque organisation. Une première livraison doit rendre UCG autonome sur ses effectifs, ses contrats, son calendrier et ses résultats, sans empêcher l’accueil sécurisé d’autres organisations. Les statistiques automatisées, les tournois avancés et les intégrations sont ajoutés par étapes selon les accès réellement disponibles.

## 1 Objectifs et hypothèses de cadrage

### 1.1 Objectifs

- Disposer d’un référentiel fiable des joueurs, équipes, membres du staff, sponsors et partenaires.
- Organiser les entraînements, scrims, compétitions et événements sans conflits de disponibilité.
- Suivre les performances avec des indicateurs propres à chaque jeu et une provenance vérifiable.
- Gérer les contrats, leurs versions, leurs échéances et les obligations associées.
- Donner à chacun un espace adapté à ses responsabilités, avec des accès limités aux données utiles.
- Séparer les informations publiques, les opérations sportives et les documents confidentiels.
- Produire des tableaux de bord pour les décisions sportives et le suivi commercial.
- Conserver l’historique des affectations, résultats et décisions malgré les changements de roster.
- Permettre à plusieurs organisations indépendantes d’utiliser la même plateforme sans fuite ni mélange de données.
- Permettre à un même utilisateur d’appartenir à plusieurs organisations avec des droits distincts.

### 1.2 Hypothèses de travail

Le premier périmètre métier concerne UCG, avec plusieurs jeux, équipes et saisons, mais le socle technique est multi-tenant dès P0. Chaque organisation constitue un tenant indépendant. Toute requête métier s’exécute dans un contexte d’organisation explicite et aucune donnée privée ne peut être consultée, modifiée, recherchée, exportée ou diffusée depuis une autre organisation, sauf partage public ou processus interorganisation expressément autorisé.

Un utilisateur peut appartenir à plusieurs organisations et y exercer des rôles différents. Un joueur peut pratiquer plusieurs jeux. Une personne peut cumuler des missions, par exemple joueur dans une équipe et coach dans l’académie. Les droits dépendent donc de l’organisation active, du périmètre métier et des dates d’affectation. Changer d’organisation active ne fusionne ni les fiches administratives, ni les contrats, ni les permissions.

L’architecture initiale recommandée utilise une base PostgreSQL et un schéma partagés, avec un identifiant d’organisation obligatoire sur toutes les données appartenant à un tenant. Ce choix doit être renforcé par des contraintes relationnelles, des contrôles d’autorisation et des tests d’isolation ; l’ajout d’un simple champ `organization_id` ne constitue pas à lui seul une isolation multi-tenant.

Le pays d’établissement, les effectifs, les jeux prioritaires, le budget et la présence de mineurs ne sont pas encore précisés. Ils doivent être confirmés pendant le cadrage. Les exemples liés à VALORANT, League of Legends ou Counter-Strike illustrent le modèle et ne constituent pas une liste de jeux commandés.

### 1.3 Vocabulaire

- **Plateforme** : l’application et son exploitation communes à toutes les organisations.
- **Organisation ou tenant** : espace logique indépendant détenant ses paramètres et ses données métier ; UCG est le tenant pilote.
- **Compte utilisateur** : identité de connexion globale pouvant rejoindre plusieurs organisations.
- **Adhésion** : lien daté entre un compte et une organisation, avec statut et rôles propres à ce tenant.
- **Contexte de tenant** : organisation explicitement résolue et autorisée pour une requête, un Job, un export ou un événement.
- **Donnée globale** : donnée administrée par la plateforme et volontairement commune, par exemple un catalogue de jeux.
- **Donnée tenant** : donnée possédée par une organisation et inaccessible aux autres sauf projection publique ou partage métier explicite.
- **Participant externe** : personne ou structure autorisée sur un objet limité sans adhésion générale à l’organisation.

### 1.4 Mesure de réussite

Objectifs à mesurer sur un pilote puis à confirmer après un mois d’exploitation :

| Indicateur | Cible proposée | Mode de vérification |
| --- | --- | --- |
| Effectifs actifs renseignés | 100 % des personnes du périmètre pilote | Contrôle avec le manager |
| Activités sportives planifiées | Au moins 90 % dans le calendrier | Comparaison avec le planning réel |
| Contrats actifs suivis | 100 % avec propriétaire et échéance | Audit documentaire |
| Résultats des matchs officiels | Au moins 95 % validés sous 48 h | Rapport des matchs terminés |
| Adoption du staff pilote | Au moins 80 % d’utilisateurs actifs chaque semaine | Événements d’usage agrégés |
| Incidents de visibilité | Aucun accès non autorisé dans la recette | Tests de permissions et d’exports |
| Incidents d’isolation entre organisations | Aucun mélange ou accès inter-tenant non autorisé | Tests négatifs sur requêtes, fichiers, caches, jobs, exports et temps réel |
| Contexte d’organisation | 100 % des opérations métier rattachées à un tenant explicite | Audit des routes, données et événements techniques |

## 2 Périmètre et priorités

Les priorités expriment la livraison, et non l’importance générale du module. P0 est le socle de la première version exploitable. P1 complète la gestion professionnelle. P2 correspond aux extensions après validation des usages.

### 2.1 Socle multi-tenant

Le multi-tenant est une exigence P0. UCG est le premier tenant, mais aucun module métier ne doit coder son identité en dur. Le socle doit résoudre l’organisation active, vérifier l’adhésion de l’utilisateur, appliquer les droits dans cette organisation et transporter ce contexte jusqu’aux accès aux données, fichiers, caches, jobs, notifications, exports et canaux temps réel.

Les fonctionnalités commerciales d’un SaaS, telles que la facturation des abonnements, les plans, les quotas contractuels et les domaines personnalisés, ne font pas automatiquement partie du P0. Elles sont séparées de l’isolation technique obligatoire.

### 2.2 Gestion des organisations

- ORG-01 : créer une organisation avec identifiant immuable, nom, slug, statut, fuseau IANA, langue, pays et paramètres de base.
- ORG-02 : gérer invitation, acceptation, refus, expiration et révocation d’une adhésion sans créer de doublon de compte.
- ORG-03 : permettre à un compte d’appartenir à plusieurs organisations et d’y posséder des rôles, statuts et dates distincts.
- Précision ORG-02/ORG-03 : le mode multi-organisations reste le comportement par défaut.
  Préserver une politique d’adhésion extensible permettant d’interdire des appartenances
  simultanées lorsqu’une règle métier d’exclusivité est activée explicitement.
  L’interdiction doit être vérifiée côté serveur à l’acceptation ou à la réactivation,
  en tenant compte des périodes ; une suspension ne vaut pas départ. Ne pas supprimer
  les comptes ni les historiques, ni révoquer automatiquement des adhésions existantes.
  Une activation sur des données existantes exige une vérification préalable des conflits.
- ORG-04 : rendre l’organisation active visible et exiger une confirmation explicite avant toute action sensible après un changement de contexte.
- ORG-05 : résoudre le tenant depuis une source approuvée, puis vérifier l’adhésion ou l’accès externe avant de charger une donnée métier.
- ORG-06 : gérer actif, suspendu, en clôture et archivé ; une organisation suspendue ne peut plus produire de nouvelles opérations métier, sauf actions de régularisation autorisées.
- ORG-07 : séparer le propriétaire, les administrateurs d’organisation et les superadmins plateforme ; encadrer le transfert de propriété par une procédure renforcée.
- ORG-08 : appliquer paramètres, branding, fuseau, langue et préférences de notification sans modifier les données d’un autre tenant.
- ORG-09 : fournir un export tenant complet, traçable et borné aux données autorisées ; générer les archives en arrière-plan avec expiration.
- ORG-10 : définir l’archivage, la réactivation, la conservation et la purge d’une organisation sans casser les référentiels publics ou historiques légalement conservés.
- ORG-11 : empêcher le déplacement direct d’un objet métier vers une autre organisation ; tout transfert admis passe par une procédure explicite, auditée et testée.
- ORG-12 : prévoir des limites techniques configurables par tenant pour les imports, exports, stockage, requêtes et traitements différés, sans confondre ces limites avec une offre de facturation.
- ORG-13 : en P1, vérifier la propriété de tout domaine personnalisé avant activation, automatiser son certificat et empêcher sa réattribution tant que sa libération n’est pas confirmée.
- ORG-14 : exécuter les traitements planifiés par tenant actif avec verrouillage, reprise et observabilité, sans laisser un échec bloquer silencieusement les autres organisations.
- ORG-15 : auditer création, suspension, réactivation, changement de propriétaire, changement de domaine et suppression d’une organisation.

| Domaine | P0 première version | P1 version professionnelle | P2 extensions |
| --- | --- | --- | --- |
| Organisations | Tenants, paramètres, adhésions, invitations, changement de contexte et suspension | Branding, quotas et domaines personnalisés | Abonnements, facturation SaaS et options d’isolation renforcée |
| Identité et sécurité | Comptes globaux, MFA staff, rôles par tenant, périmètres et audit | Connexions externes, règles affinées | SSO entreprise par organisation |
| Joueurs et staff | Fiches, jeux, affectations et départs | Recrutement et essais détaillés | Scouting enrichi |
| Équipes | Rosters datés, titulaires, remplaçants | Historique sportif et mouvements avancés | Prêts et transferts complexes |
| Académie | Cohortes, séances et objectifs simples | Parcours pédagogiques et évaluations | Portail représentant légal si nécessaire |
| Planning | Disponibilités, convocations, conflits, rappels | Répétitions avancées, réservations, logistique | Synchronisation bidirectionnelle |
| Scrims et matchs | Saisie, lineups, parties, scores, validation | Veto, statistiques détaillées, débriefs | Données automatisées en direct |
| Tournois externes | Inscription suivie, échéances, résultats | Classements et obligations détaillées | Connecteurs organisateurs |
| Tournois organisés | Fiche et suivi manuel | Inscriptions externes et élimination simple | Double élimination, suisse, circuits |
| Contrats | Documents privés, versions, échéances | Circuit d’approbation et signature externe | Automatisation documentaire avancée |
| Sponsors | Contacts, contrats liés, engagements | Portail sponsor et rapports de campagne | Connecteurs d’audience |
| Statistiques | Saisie et import CSV contrôlé | Premier connecteur approuvé par jeu | Analyse approfondie et entrepôt dédié |
| Live et communication | Liens live et partage contrôlé | Lecteurs intégrés et pages publiques | Overlays de diffusion |
| Finances | Montants contractuels à accès restreint | Budgets, dépenses, primes et exports | Connexion comptable |
| Exploitation | Isolation multi-tenant, sauvegardes, alertes, exports et support | Haute disponibilité et récupération logique d’un tenant selon besoin | Déploiements ou bases dédiés selon contraintes contractuelles |

La première version ne comprend pas un service de diffusion vidéo propriétaire, une paie complète, une comptabilité réglementaire, une application mobile native, une facturation SaaS complète ou une promesse de données universelles en direct. Ces capacités nécessitent des projets ou fournisseurs distincts.

## 3 Espaces et expérience utilisateur

### 3.1 Espace interne

Un point d’entrée commun donne accès à une navigation adaptée aux permissions. L’organisation active est toujours visible. L’utilisateur autorisé peut changer d’organisation, puis de contexte métier lorsqu’il possède plusieurs missions : équipe, jeu, saison ou académie. Son tableau de bord affiche uniquement les prochaines activités, tâches et alertes accessibles dans le tenant actif.

Les fiches doivent distinguer informations générales, données sportives et informations administratives. Les menus masqués ne remplacent jamais un contrôle serveur. Un utilisateur sans permission ne doit pas recevoir les champs confidentiels dans les données de la page. Une URL, un identifiant connu ou le changement manuel d’un paramètre ne doit jamais permettre de sortir du tenant actif.

### 3.2 Espace public

À partir de P1, chaque organisation peut disposer d’une vitrine présentant ses équipes, profils publiables, résultats officiels, événements, sponsors et streams. La publication nécessite une permission distincte dans le tenant propriétaire. Les scrims, stratégies, coordonnées privées, contrats et rémunérations restent privés par défaut. Les slugs, domaines, métadonnées SEO, caches CDN et médias publics doivent conserver l’identité de l’organisation propriétaire.

### 3.3 Espaces externes

Les participants externes d’un tournoi, sponsors et candidats possèdent des accès spécifiques. Ils ne deviennent pas automatiquement membres de l’organisation organisatrice. Un participant peut consulter son inscription, effectuer son check-in et soumettre une preuve de résultat ; un sponsor consulte seulement les campagnes et livrables de son propre accord. Une organisation inscrite au tournoi d’une autre organisation ne partage que les données prévues par l’inscription et ne reçoit aucun accès à son espace interne.

### 3.4 Exigences d’interface

- Interface responsive utilisable sur ordinateur, tablette et téléphone.
- Français au lancement ; textes centralisés pour permettre l’anglais ensuite.
- Calendriers affichés dans le fuseau de l’utilisateur avec indication du fuseau de l’événement.
- Recherche, filtres, tri et pagination sur les listes ; filtres partageables sans données sensibles.
- États de chargement, absence de résultats, échec et indisponibilité de connecteur explicitement visibles.
- Navigation clavier, libellés lisibles, contraste et états non fondés uniquement sur les couleurs.
- Formulaires avec erreurs près des champs, confirmation des actions sensibles et prévention des doubles soumissions.
- Notifications Flasher pour les opérations interactives, complétées par des messages persistants pour les erreurs importantes.
- Aucun cache hors ligne de contrat, pièce d’identité ou information financière dans une éventuelle PWA.
- L’organisation active doit rester explicite dans la navigation, les confirmations d’actions sensibles et les changements de contexte.
- Les URLs partageables ne doivent jamais contenir de secrets et doivent être réautorisées dans le tenant destinataire à chaque ouverture.

## 4 Rôles et autorisations

### 4.1 Modèle de droits

Une autorisation se compose d’une organisation, d’une action, d’un périmètre et de conditions. Exemple : un coach peut modifier un débrief de son équipe, dans son organisation active et pendant sa période d’affectation. Les permissions de lecture sportive, lecture administrative, lecture financière, modification, validation et publication sont distinctes.

Les rôles métier sont des ensembles de permissions configurables par organisation. Chaque affectation peut être limitée à un jeu, une équipe, une cohorte ou un événement et possède une date de début et de fin. Une personne peut avoir plusieurs rôles dans des périmètres ou des organisations différents. Une suspension d’adhésion retire en priorité les accès au tenant concerné ; une suspension globale du compte suit une procédure plateforme distincte.

Cette capacité multi-organisations ne rend pas les appartenances simultanées obligatoires :
la politique d’exclusivité ORG-03 peut les restreindre sans fusionner les rôles, les données
ou les identités. Une exclusivité ciblée sur un rôle métier demandera une règle explicite
lors de la mise en place des permissions contextuelles ; ne pas la déduire du seul rôle.

### 4.2 Matrice de responsabilité

| Rôle | Espace principal | Droits attendus | Limites par défaut |
| --- | --- | --- | --- |
| Joueur | Profil, planning, équipe, progression | Modifier ses disponibilités ; consulter ses objectifs et documents autorisés | Aucun contrat d’un tiers ; aucune note confidentielle de recrutement |
| Coach | Équipes affectées et séances | Planifier, préparer, analyser et évaluer | Aucun salaire ni contrat complet sans permission spécifique |
| Analyste | Analyse sportive | Consulter les parties, annoter les VOD, produire des analyses | Aucun engagement contractuel ou financier |
| Manager | Opérations des équipes affectées | Effectifs, inscriptions, convocations, logistique | Contrats et budgets uniquement si habilité |
| Responsable académie | Cohortes et parcours | Gérer objectifs, évaluations et promotions | Données sensibles limitées à sa mission |
| Responsable partenariats | Sponsors et campagnes | Gérer accords commerciaux et livrables | Aucun dossier RH privé |
| Responsable administratif | Contrats et dossiers | Préparer, suivre et archiver les documents | Aucun droit sportif de validation implicite |
| Finance | Budgets et engagements | Consulter les montants autorisés et effectuer les rapprochements | Pas de publication sportive ni modification de roster |
| Modérateur | Contenus et signalements | Traiter les signalements et modérer dans son périmètre | Pas de contrats, rémunérations ou stratégies |
| Arbitre | Tournois affectés | Valider scores, preuves et litiges | Pas d’accès général à l’organisation |
| Admin d’organisation | Administration du tenant | Gérer membres et configuration selon délégation | Aucun droit dans un autre tenant ; accès sensible explicitement accordé |
| Propriétaire d’organisation | Gouvernance du tenant | Gérer administrateurs, paramètres structurants et transfert de propriété | Aucun pouvoir plateforme ou accès à un autre tenant |
| Superadmin plateforme | Exploitation technique | Configuration globale et assistance contrôlée | Aucun accès métier permanent ; accès exceptionnel journalisé, motivé et limité dans le temps |
| Sponsor | Accord attribué | Consulter les livrables et rapports partagés | Aucun accès aux autres sponsors ou au staff |
| Participant externe | Tournoi inscrit | Inscription, check-in, résultats proposés | Aucune visibilité interne de l’organisation |

Un mode d’accès exceptionnel pour incident peut être prévu : tenant ciblé, motif obligatoire, durée courte, trace, revue et notification selon la politique convenue. Les comptes techniques n’ont pas vocation à approuver des contrats ou des dépenses.

### 4.3 Exigences de contrôle

- IAM-01 : contrôler chaque action côté serveur avec des Policies ou Gates.
- IAM-02 : appliquer les mêmes règles aux exports, fichiers, recherches, notifications et canaux temps réel.
- IAM-03 : journaliser les changements de rôles et empêcher l’auto-attribution d’un rôle supérieur sans délégation.
- IAM-04 : invalider les sessions et droits effectifs lors d’une suspension ou d’un départ.
- IAM-05 : tester explicitement les accès interdits entre équipes et entre accords sponsors.
- IAM-06 : résoudre un tenant explicite pour chaque route et action métier ; refuser tout contexte absent, ambigu, inactif ou non autorisé.
- IAM-07 : empêcher toute relation entre objets de tenants différents par validation applicative et contraintes de base de données lorsque cela est possible.
- IAM-08 : appliquer l’isolation aux recherches, caches, fichiers, exports, jobs, notifications, webhooks et canaux temps réel, sans se limiter aux contrôleurs HTTP.
- IAM-09 : séparer les rôles plateforme des rôles d’organisation et interdire à un administrateur de tenant de s’attribuer un privilège plateforme.
- IAM-10 : tester systématiquement les accès inter-tenant avec des identifiants connus, des URLs directes, des imports, des tâches différées et des données mises en cache.

## 5 Joueurs staff et recrutement

### 5.1 Référentiel des personnes

- HUM-01 : créer une fiche unique par personne au sein d’une organisation, distincte du compte global de connexion ; conserver les fiches de personnes sans compte et d’adversaires nécessaires aux matchs.
- HUM-02 : gérer pseudo, identité administrative restreinte, coordonnées utiles, langue, fuseau, statut, photo et biographies publique et interne distinctes.
- HUM-03 : rattacher plusieurs profils de jeu à une personne : jeu, identifiant externe stable lorsque disponible, région, rang, rôle dans le jeu, comptes liés et date de dernière mise à jour.
- HUM-04 : suivre candidat, en essai, actif, indisponible, remplaçant et ancien membre sans effacer l’historique.
- HUM-05 : conserver les affectations datées, contacts d’urgence si justifiés et documents strictement nécessaires.
- HUM-06 : importer des données avec prévisualisation, détection des doublons et rapport d’erreurs.
- HUM-07 : permettre à un compte d’être lié à des fiches distinctes dans plusieurs organisations sans partager automatiquement leurs données administratives.
- HUM-08 : limiter la détection des doublons, les recherches et les rapprochements au tenant actif, sauf référentiel public explicitement partagé.

Une date de naissance, une pièce d’identité ou des coordonnées bancaires ne doivent pas être collectées simplement parce qu’un champ est disponible. Leur nécessité, leur accès et leur conservation sont définis pendant le cadrage.

### 5.2 Recrutement et essais en P1

Candidature → présélection → essais → évaluation → décision → proposition → intégration ou clôture. Les critères varient par jeu et rôle : niveau, disponibilité, communication, discipline, résultats observés et adéquation à l’équipe.

Les évaluations indiquent auteur, date, grille et visibilité. Un coach ne doit pas accéder à une note administrative sans lien avec sa mission. La décision de recrutement reste humaine ; aucun score automatique ne vaut acceptation ou refus.

### 5.3 Départ

Le départ ferme les affectations, retire l’adhésion et les accès aux fichiers de l’organisation concernée, traite les équipements et obligations restants et applique les règles d’archivage. Il ne supprime pas automatiquement les adhésions du même compte à d’autres organisations. Les matchs historiques continuent à afficher le roster effectivement présent à leur date.

## 6 Jeux équipes et rosters

- TEAM-01 : maintenir un catalogue de jeux, modes, plateformes, régions, rôles, cartes et versions, puis permettre à chaque organisation d’activer et de paramétrer les éléments utiles à ses saisons.
- TEAM-02 : créer plusieurs équipes par jeu : première équipe, académie, réserve, équipe événementielle ou catégorie spécifique.
- TEAM-03 : gérer titulaire, remplaçant, capitaine, coach et analyste avec des périodes d’appartenance.
- TEAM-04 : distinguer appartenance à l’équipe et lineup retenu pour un match ou une inscription.
- TEAM-05 : enregistrer un instantané du lineup au match ; les changements ultérieurs de roster ne modifient pas cet instantané.
- TEAM-06 : définir taille minimale et maximale, critères d’éligibilité et date de verrouillage selon la compétition.
- TEAM-07 : conserver les opposants dans un référentiel externe sans leur attribuer des droits internes.
- TEAM-08 : tracer les promotions académie, changements d’équipe et mouvements sportifs avec motif et auteur.
- TEAM-09 : conserver les équipes et opposants d’autres organisations sous forme de références publiques ou d’instantanés de compétition, sans exposer leurs données internes.

La même personne peut jouer dans plusieurs contextes si le règlement le permet. L’éligibilité est une règle de compétition configurée ; elle ne peut pas être déduite uniquement du contrat ou du rôle joueur.

## 7 Académie et coaching

- ACA-01 : créer cohortes, cycles, mentors et emplois du temps.
- ACA-02 : associer des objectifs individuels et collectifs avec échéance et responsable.
- ACA-03 : enregistrer présence, exercices, séances et feedback accessible au joueur.
- ACA-04 : distinguer feedback partagé et notes internes justifiées.
- ACA-05 : en P1, gérer ressources pédagogiques, évaluations périodiques et progression vers un roster compétitif.
- ACA-06 : afficher l’évolution sur une période cohérente sans comparer directement des métriques de jeux différents.
- ACA-07 : si des mineurs participent, prévoir le lien avec le représentant légal et les autorisations requises selon le pays et les usages.

Le suivi sportif initial n’inclut pas de dossier médical ou psychologique. Un tel périmètre demanderait une conception juridique et technique distincte.

## 8 Calendrier disponibilités et logistique

- CAL-01 : gérer entraînement, scrim, match officiel, réunion, séance académie, bootcamp, événement sponsor et déplacement.
- CAL-02 : enregistrer début, fin, fuseau d’origine, participants, équipe, lieu, lien et niveau de confidentialité.
- CAL-03 : gérer disponibilités et absences sans exiger un motif médical.
- CAL-04 : détecter les chevauchements des joueurs, du staff et des ressources réservées ; afficher les conflits avant confirmation.
- CAL-05 : envoyer des convocations avec réponse présent, absent ou en attente, et rappels configurables.
- CAL-06 : distinguer modification d’une occurrence et modification d’une série récurrente en P1.
- CAL-07 : exporter un calendrier ICS privé dont le lien est révocable ; ne pas y exposer les contrats ou notes internes.
- CAL-08 : notifier les personnes affectées lors d’un report ou d’une annulation ; conserver la raison opérationnelle et l’historique.
- CAL-09 : en P1, suivre salles, matériel, hébergement, transport et tâches de déplacement.
- CAL-10 : rattacher activités, disponibilités, ressources, convocations et flux ICS au tenant propriétaire ; un jeton ICS ne donne aucun accès à une autre organisation.

Règle de date : les instants sont stockés en UTC. Les événements récurrents conservent aussi le fuseau IANA et l’heure locale de référence pour traiter correctement les changements d’heure. Un flux ICS est une première étape ; une synchronisation bidirectionnelle Google ou Microsoft est un lot distinct avec règles de conflit.

## 9 Scrims matchs parties et scores

### 9.1 Distinctions du modèle

Un match est une rencontre entre participants. Une série peut comporter plusieurs parties ou cartes, par exemple BO3. Une partie possède ses participants, sa carte ou son mode, sa version du jeu et ses statistiques. Certains jeux utilisent des lobbies à plusieurs équipes ou des compétitions individuelles : le modèle ne doit pas imposer systématiquement deux équipes.

Un scrim est une activité d’entraînement privée. Un match officiel peut être rattaché à un tournoi externe ou organisé par une organisation cliente, dont UCG. Le résultat de la série, le score d’une partie et les statistiques individuelles sont des objets distincts.

### 9.2 Exigences

- MATCH-01 : créer une rencontre avec jeu, format, participants, horaire, visibilité et responsable.
- MATCH-02 : suivre proposé, confirmé, en cours, terminé à valider, validé, contesté, annulé et forfait ; contrôler les transitions permises.
- MATCH-03 : sélectionner le lineup et les remplaçants autorisés ; tracer les changements avant ou pendant la rencontre.
- MATCH-04 : saisir les résultats par partie et calculer le résultat de la série selon le format.
- MATCH-05 : gérer forfait, abandon, remake, match nul lorsqu’autorisé et preuve du résultat.
- MATCH-06 : valider le score par un responsable autorisé ; en tournoi organisé, permettre propositions contradictoires et arbitrage.
- MATCH-07 : conserver tout changement de score avec ancienne valeur, nouvelle valeur, motif et auteur.
- MATCH-08 : ajouter VOD, notes, objectifs et débrief ; appliquer des droits spécifiques aux stratégies.
- MATCH-09 : en P1, gérer le veto ou la sélection des cartes lorsque le jeu et le règlement l’exigent.
- MATCH-10 : diffuser le score autorisé aux abonnés temps réel ; ne jamais diffuser les données privées dans un canal public.
- MATCH-11 : identifier l’organisation propriétaire du match et représenter les adversaires interorganisations par une inscription autorisée ou un instantané, sans relier leurs données internes.

### 9.3 Circuit opérationnel

Le manager confirme la rencontre et les participants. Le coach prépare les objectifs. Les joueurs répondent à la convocation. Un score est saisi ou importé avec sa source. Une personne habilitée le valide. Les agrégats sont recalculés, puis la publication éventuelle intervient séparément. Le coach clôture le débrief et attribue les actions suivantes.

Un lecteur Twitch ou YouTube ne constitue pas une source de score. Le temps réel d’affichage commence à la réception d’une donnée fiable dans la plateforme ; il ne garantit pas l’accès immédiat à la télémétrie du jeu.

## 10 Statistiques et profils par jeu

### 10.1 Dictionnaire des métriques

Chaque métrique possède un code, une définition, une unité, un niveau d’agrégation, une formule versionnée et un périmètre de disponibilité. Les observations précisent jeu, mode, région, patch, période, rôle, source et date de synchronisation.

| Famille de jeu | Exemples de métriques | Conditions de lecture |
| --- | --- | --- |
| FPS tactique | Kills, deaths, assists, K/D, ADR, taux de headshots, impact selon rôle | Définition et événements disponibles ; certaines mesures exigent un fournisseur ou une analyse de démo |
| MOBA | KDA, CS/min, gold/min, vision, dégâts, participation aux kills | Durée, rôle, champion, patch et source |
| Battle royale | Placement, points, éliminations, dégâts, survie | Barème du tournoi et résultat du lobby |
| Sport ou course | Score, tirs, passes, possession, classement, temps | Jeu, mode et granularité accessible |
| Évaluation coach | Communication, exécution, décisions, progression | Grille, auteur et date ; valeur subjective signalée |

Ces métriques sont des exemples fonctionnels. Aucune disponibilité automatique n’est promise avant une étude de faisabilité pour le jeu concerné.

### 10.2 Exigences

- STAT-01 : afficher profil par jeu, historique de rang lorsqu’il est disponible, résultats et tendances.
- STAT-02 : séparer statistiques officielles, scrims, entraînements et évaluations humaines.
- STAT-03 : filtrer par saison, patch, rôle, carte, adversaire et période selon les données disponibles.
- STAT-04 : afficher taille d’échantillon, source, dernière mise à jour et indicateur de données incomplètes.
- STAT-05 : représenter une valeur absente comme indisponible, et non comme zéro.
- STAT-06 : calculer les ratios à partir de leurs composantes ; ne pas faire une moyenne naïve de ratios.
- STAT-07 : définir explicitement le comportement d’un ratio lorsque le dénominateur vaut zéro.
- STAT-08 : permettre corrections manuelles autorisées sans écraser silencieusement la donnée importée.
- STAT-09 : recalculer les agrégats après correction ; conserver la version de calcul.
- STAT-10 : comparer des joueurs dans un contexte comparable et distinguer périodes et échantillons.
- STAT-11 : rattacher chaque observation privée, import et agrégat à son organisation ; une métrique globalement définie ne rend pas ses valeurs visibles entre tenants.

### 10.3 Modes d’acquisition

P0 : saisie manuelle et import CSV validé. P1 : premier connecteur pour chaque jeu prioritaire après confirmation d’accès et de licence. P2 : autres connecteurs, démos ou télémétrie autorisée.

Chaque import comporte une prévisualisation, une validation de schéma, la correspondance des joueurs dans le tenant actif, une clé de déduplication incluant l’organisation, un journal des erreurs et une possibilité de reprise. Les lignes refusées restent identifiables. Une relance ne doit pas créer un second match et un fichier importé par un tenant ne doit jamais être accessible à un autre.

### 10.4 Faisabilité des connecteurs

Pour chaque jeu, établir une fiche : données nécessaires, endpoints officiels ou fournisseur, compte propriétaire, organisation utilisatrice, accord d’accès, consentements, quotas, latence, historique accessible, coût et restrictions de publication. Les credentials, quotas et autorisations d’un connecteur sont isolés par tenant, sauf contrat plateforme explicitement documenté.

La documentation VALORANT prévoit un accès de production et un parcours d’opt-in via Riot Sign On pour le partage des données personnelles ; les clés personnelles ne sont pas proposées pour ce jeu. Cela crée une dépendance de planning à anticiper. [Source Riot](https://developer.riotgames.com/docs/valorant).

FACEIT propose une Data API pour ses informations publiques. Cela ne garantit pas l’accès aux scrims privés ni à toutes les données de Counter-Strike. Le périmètre de chaque endpoint et le droit d’usage doivent être vérifiés. [Source FACEIT](https://docs.faceit.com/docs/data-api/).

## 11 Tournois et événements

### 11.1 Compétitions auxquelles une organisation participe

- COMP-01 : enregistrer organisateur, jeu, région, saison, règlement, URL et contacts.
- COMP-02 : suivre inscription, paiement éventuel, pièces requises, validation du roster, check-in et échéances.
- COMP-03 : rattacher les matchs, résultats, classement et dotation annoncée puis réellement reçue.
- COMP-04 : attribuer les tâches au manager et alerter sur les pièces ou disponibilités manquantes.
- COMP-05 : conserver les exigences d’âge, résidence, compte ou rang telles que définies par le règlement.

### 11.2 Tournois organisés par une organisation cliente

P0 suit les événements et rencontres manuellement. P1 fournit un format d’élimination simple pour le premier jeu retenu, avec paramètres validés avant génération.

- TOUR-01 : créer page d’inscription, période, règlement versionné, capacité et critères d’éligibilité.
- TOUR-02 : enregistrer équipes ou joueurs externes, responsables et roster de compétition.
- TOUR-03 : gérer liste d’attente, validation, check-in et verrouillage des inscriptions.
- TOUR-04 : définir seeding, byes, format des séries et règles de forfait avant publication du bracket.
- TOUR-05 : générer les rencontres et propager uniquement les résultats validés.
- TOUR-06 : gérer preuves, contestations, arbitrage et décisions traçables.
- TOUR-07 : empêcher un changement de format après démarrage sans procédure de migration contrôlée.
- TOUR-08 : en P2, ajouter poules, round robin, double élimination et suisse, chacun avec ses règles propres de classement et départage.
- TOUR-09 : identifier le tenant organisateur et limiter les organisations participantes aux données d’inscription, de check-in, de résultat et de règlement qui leur sont explicitement partagées.
- TOUR-10 : conserver un instantané du nom, du logo et du roster autorisé d’un participant interorganisation afin que les changements dans son tenant d’origine n’altèrent pas l’historique du tournoi.

Corriger un score alors que le tour suivant a commencé exige de traiter les rencontres dépendantes et la décision d’arbitrage. Une simple modification de ligne ne suffit pas. Le moteur de progression et ses tests constituent un lot métier à part entière.

### 11.3 Événements hors compétition

Bootcamp, rencontre communautaire, activation sponsor, réunion et lancement peuvent partager le calendrier, les participants, tâches, documents, budget et visibilité. Les règles sportives restent dans les modules de compétition.

## 12 Contrats et documents administratifs

### 12.1 Types et données

Contrats joueurs, coachs, managers, analystes, autres intervenants et accords sponsors. Chaque dossier appartient à une seule organisation et comporte parties, propriétaire, type, statut, dates, échéances, obligations et documents. Les rémunérations, primes et modalités de partage de gains sont des champs à accès financier restreint dans ce tenant.

- CTR-01 : gérer brouillon, en revue, approuvé, envoyé, signé, actif, terminé, résilié et archivé ; les transitions dépendent des dates et règles applicables.
- CTR-02 : conserver chaque version documentaire et identifier clairement la version signée.
- CTR-03 : enregistrer les avenants comme objets distincts liés au contrat d’origine.
- CTR-04 : configurer alertes d’échéance, préavis et renouvellement, par exemple 90, 30 et 7 jours selon le dossier.
- CTR-05 : séparer préparation, approbation et signature lorsque l’organisation l’exige.
- CTR-06 : journaliser consultation et téléchargement des documents sensibles selon la politique validée.
- CTR-07 : stocker les fichiers dans un espace privé ; produire des liens temporaires seulement après contrôle d’accès.
- CTR-08 : en P1, intégrer un prestataire de signature ; vérifier les callbacks, télécharger la preuve et gérer les événements reçus en double.
- CTR-09 : distinguer contrat signé, contrat en vigueur et éligibilité sportive ; les trois ne sont pas interchangeables.
- CTR-10 : interdire tout rattachement, recherche, téléchargement ou rappel mêlant des contrats, parties ou documents de tenants différents.

Une image de signature ou un bouton « signé » ne remplace pas un processus de signature adapté. Le droit applicable, les modèles, les obligations envers les mineurs et la valeur probante attendue sont validés avec un professionnel compétent dans le pays concerné. Le logiciel applique ces décisions ; il ne détermine pas seul la qualification juridique des contrats.

### 12.2 Classement documentaire

Classer par organisation, personne, équipe, compétition, sponsor ou dossier. Vérifier type de fichier, taille et contenu ; analyser les fichiers entrants ; refuser les exécutables et contenus non autorisés. Distinguer document public, sportif interne, administratif confidentiel et financier restreint. Les clés de stockage commencent par un identifiant de tenant non ambigu. Un document confidentiel ne doit pas apparaître dans une recherche publique, un aperçu non autorisé, un e-mail de rappel ou un export d’une autre organisation.

## 13 Sponsors partenariats et engagements

- SPON-01 : gérer prospects, contacts, opportunités, statut de négociation et historique des échanges renseignés dans l’application.
- SPON-02 : relier accord, période, montant ou apport en nature, responsable et pièces.
- SPON-03 : préciser les périmètres d’exposition : organisation, équipe, événement, contenu ou joueur autorisé.
- SPON-04 : suivre obligations, exclusivités définies, échéances et preuves de réalisation.
- SPON-05 : gérer une campagne comme un ensemble de livrables assignés : publications, vidéos, présence événementielle ou placement de marque.
- SPON-06 : produire un rapport distinguant engagement promis, livré, validé et mesure réellement disponible.
- SPON-07 : en P1, ouvrir un portail limité à l’accord du sponsor avec validation préalable des informations partagées.
- SPON-08 : si un même compte sponsor intervient auprès de plusieurs organisations, présenter des espaces et accords séparés sans agrégation ni navigation croisée implicite.

Les mesures d’audience précisent leur fournisseur, leur période et leur méthode. La plateforme ne doit pas annoncer une audience unique en additionnant des chiffres de canaux qui peuvent compter les mêmes personnes.

## 14 Live communication et modération

- LIVE-01 : associer un stream, une VOD ou un clip à un match, une équipe ou un événement.
- LIVE-02 : en P1, intégrer les lecteurs officiels Twitch et YouTube selon leurs paramètres et politiques.
- LIVE-03 : afficher séparément statut de diffusion, score sportif et fraîcheur de la donnée.
- LIVE-04 : définir une publication différée pour les scrims si elle est autorisée ; conserver les scrims privés par défaut.
- LIVE-05 : en P2, proposer un overlay de score avec accès révocable et champs strictement autorisés.
- COM-01 : publier annonces internes ciblées par équipe, jeu, rôle ou événement.
- COM-02 : proposer commentaires contextualisés sur tâches, matchs et séances, avec notifications configurables.
- COM-03 : intégrer Discord par connecteur en P1 pour les rappels autorisés ; garder l’application comme référentiel du planning et des résultats.
- MOD-01 : traiter signalements, décisions de modération, motifs et recours éventuels dans le périmètre prévu.
- LIVE-06 : inclure le tenant dans l’autorisation et le nommage des canaux privés, flux publics, overlays et jetons de consultation.
- COM-04 : isoler annonces, commentaires, notifications et intégrations Discord par organisation et vérifier le tenant au moment de l’envoi.

Twitch permet d’intégrer streams, VOD et clips et demande notamment la déclaration des domaines d’intégration via le paramètre parent. Le lecteur YouTube repose sur son API d’intégration officielle. Les vidéos restent diffusées par ces fournisseurs. [Twitch](https://dev.twitch.tv/docs/embed/video-and-clips/) ; [YouTube](https://developers.google.com/youtube/iframe_api_reference).

## 15 Budgets équipements et pilotage

### 15.1 Finances opérationnelles en P1

Suivre, dans chaque organisation, les budgets par équipe, saison ou événement ; engagements, dépenses, primes et dotations ; pièces justificatives ; circuit d’approbation ; rapprochement avec les paiements renseignés ou importés. Les montants utilisent une valeur décimale ou des unités monétaires entières, jamais un flottant approximatif. La devise est explicite ; toute conversion conserve taux, source et date.

Ce module produit des exports vers le système comptable choisi. Il ne remplace pas une solution de paie ou de comptabilité et ne déduit pas automatiquement les règles fiscales d’un pays non précisé.

### 15.2 Équipements en P1

Inventaire, numéros de série, affectations, état, prêt, remise et restitution. Accès limité aux justificatifs utiles ; traitement des équipements lors du départ d’un membre.

### 15.3 Tableaux de bord

- Direction : effectifs, contrats à échéance, engagements sponsors, budgets et résultats agrégés.
- Manager : activités proches, conflits, inscriptions incomplètes et tâches urgentes.
- Coach : présence, objectifs, progression et analyses des équipes affectées.
- Joueur : convocations, disponibilités, feedback et actions personnelles.
- Administration : qualité des données, tâches d’import, anomalies et alertes d’accès.

Chaque indicateur possède une définition, une période, une organisation propriétaire et un périmètre d’accès. Les rapports lourds et exports sont générés en arrière-plan, avec contexte de tenant persistant, téléchargement contrôlé et expiration.

## 16 Architecture et stack recommandée

### 16.1 Choix principal

| Composant | Proposition | Raison et condition |
| --- | --- | --- |
| Backend | Laravel 13 avec PHP 8.5 | Alignement avec la structure MVC, Services et Repositories demandée ; compatibilité des dépendances vérifiée au démarrage |
| Interface | React, TypeScript et Inertia | Interface riche avec routage et sécurité côté Laravel ; Vue reste une alternative si mieux maîtrisé par l’équipe |
| Design | Tailwind CSS et composants accessibles | Cohérence des formulaires, tableaux, calendriers et états |
| Données | PostgreSQL dans une version maintenue | Transactions, contraintes, relations et extensions JSONB pour les données variables |
| Multi-tenant | Schéma PostgreSQL partagé et contexte de tenant explicite | Isolation applicative et relationnelle dès P0 ; option dédiée seulement si une contrainte validée l’impose |
| Cache et jobs | Redis et Laravel Horizon | Traitements différés observables ; politiques distinctes pour cache et données de queue |
| Temps réel | Laravel Reverb et Echo | Scores reçus, alertes et mises à jour ; canaux privés autorisés |
| Authentification | Comptes globaux, adhésions par organisation, sessions Laravel et MFA ; Sanctum pour les API utiles | Une session web pour Inertia ; jetons limités par tenant et par portée pour les clients ou intégrations qui en ont besoin |
| Permissions | Policies, Gates et éventuellement Spatie Permission | Rôles configurables par tenant ; les règles d’organisation, de périmètre et de confidentialité restent dans les Policies |
| Fichiers | Stockage objet compatible S3 | Préfixes par tenant pour contrats, pièces et imports ; séparation des médias publics |
| Construction frontend | Vite et Node LTS compatible | Compilation ; service Node en production seulement si SSR retenu |
| Tests et qualité | Pest ou PHPUnit, Playwright, Pint et PHPStan | Règles métier, parcours, permissions et qualité statique |
| Exploitation | Linux, conteneurs ou déploiement Laravel standard, CI/CD | Environnements reproductibles, workers supervisés et déploiements contrôlés |
| Observabilité | Logs structurés, métriques et suivi d’erreurs | Alertes opérationnelles sans exposer les données privées |

Au 3 octobre 2026, la documentation Laravel indique que Laravel 13 accepte PHP 8.3 à 8.5 et reçoit des corrections de sécurité jusqu’au 17 mars 2028. Les versions exactes sont verrouillées et revérifiées au lancement du développement. [Politique officielle](https://laravel.com/docs/13.x/releases).

Inertia prend en charge React, Vue et Svelte avec un routage serveur. Son SSR est une option utile aux pages publiques et introduit un processus Node à exploiter. Pour UCG, l’application interne peut fonctionner sans SSR ; les pages publiques peuvent être rendues avec Blade ou avec Inertia SSR selon les besoins. [Inertia](https://inertiajs.com/) ; [SSR](https://inertiajs.com/server-side-rendering).

PostgreSQL permet d’indexer JSONB. Cela convient aux champs spécifiques aux jeux, en complément de tables relationnelles typées pour les identités, participants, dates, résultats et métriques critiques. [Documentation PostgreSQL](https://www.postgresql.org/docs/current/datatype-json.html).

### 16.2 Structure du code

Flux HTTP : route → résolution du tenant → vérification de l’adhésion → Form Request et Policy → Controller → Service ou Action métier → Repository limité au tenant → modèles Eloquent et PostgreSQL. La réponse utilise une vue Blade, une page Inertia avec données filtrées, ou des Resources et DTOs typés pour les endpoints JSON.

Les contrôleurs délèguent l’orchestration. Un `TenantContext` immuable fournit l’organisation active aux Services et Repositories autorisés. Les Services appliquent les règles et délimitent les transactions. Les Repositories encapsulent les accès aux données, les recherches paginées et les agrégats en exigeant le tenant ; leurs interfaces sont injectées. Les règles complexes, telles que l’éligibilité ou la progression d’un bracket, vivent dans des classes métier testables. Un scope Eloquent automatique peut renforcer la protection, mais ne remplace ni les Policies, ni les contraintes de base de données, ni les tests négatifs.

Les événements métier sont émis après validation de la transaction et transportent l’identifiant du tenant. Les notifications, imports, synchronisations, PDF, signatures et recalculs utilisent des Jobs dont le contexte d’organisation est sérialisé puis revérifié à l’exécution. Les intégrations possèdent une interface par fournisseur et traduisent leurs erreurs en exceptions métier identifiables.

Exemples de modules : Identity, Tenancy, People, Teams, Academy, Scheduling, Competition, Performance, Contracts, Partnerships, Finance, Media et Reporting. Chaque module contient ses propres Requests, Policies, Controllers, Services, interfaces de Repository, implémentations, DTOs et Jobs nécessaires. Les règles partagées restent limitées et explicites.

### 16.3 Arbitrage des alternatives

| Option | Adaptation au besoin | Arbitrage proposé |
| --- | --- | --- |
| Laravel avec React et Inertia | Gestion interne riche ; une application principale à déployer | Choix initial recommandé |
| Laravel API avec Next.js séparé | Plusieurs clients indépendants et forte équipe frontend | À choisir si ce besoin est établi dès le cadrage ; plus de contrats API et d’exploitation |
| Laravel avec Blade et Livewire | Équipe surtout PHP et budget frontend limité | Alternative crédible ; prototyper les écrans de planning et analyse |
| Microservices dès le départ | Plusieurs équipes autonomes ou contraintes d’isolation déjà démontrées | Reporter ; découper ensuite les traitements qui le justifient |

Le choix recommandé est un monolithe modulaire. Les processus web, workers et temps réel peuvent être dimensionnés séparément tout en partageant les modules métier. Cette organisation permet une évolution progressive sans imposer immédiatement la complexité des communications entre services.

### 16.4 Stratégie d’isolation des tenants

Le choix initial est un schéma partagé : les comptes et catalogues explicitement globaux sont séparés des données détenues par une organisation. Toute table détenue par un tenant porte un `organization_id` non nul. Les unicités et index métier incluent ce champ, et les associations sensibles emploient des contraintes composites lorsque PostgreSQL peut les garantir.

Une base ou un schéma dédié par organisation n’est pas retenu pour P0. Cette option reste possible pour une offre future soumise à une exigence d’isolation, de résidence ou de restauration particulière. Elle nécessiterait un lot distinct couvrant provisionnement, migrations, connexions, supervision, sauvegarde, restauration et consolidation des métriques plateforme.

## 17 Modèle de données conceptuel

### 17.1 Entités principales

| Groupe | Entités | Relation essentielle |
| --- | --- | --- |
| Tenancy | organizations, organization_settings, organization_memberships, organization_invitations, organization_domains | Un compte peut appartenir à plusieurs organisations ; une organisation possède ses paramètres et son cycle de vie |
| Identité | users, persons, role_assignments | Le compte de connexion est global ; la fiche personne et les rôles sont rattachés à une organisation, un périmètre et une période |
| Jeux | games, organization_games, game_profiles, external_accounts | Le catalogue peut être global ; les activations et profils appartiennent à un tenant ; l’identifiant externe ne dépend pas seulement du pseudo |
| Effectifs | teams, team_memberships, roster_snapshots | Une appartenance est datée ; un lineup historique est conservé |
| Académie | cohorts, enrollments, learning_goals, assessments | Une cohorte contient des inscrits et des objectifs suivis |
| Planning | activities, activity_participants, availability_slots, resource_bookings | L’activité commune porte le planning ; match ou séance l’enrichit sans dupliquer l’horaire |
| Compétition | tournaments, stages, registrations, matches, match_participants, game_instances | Le match appartient éventuellement à une phase ; une partie appartient à un match |
| Performance | metric_definitions, observations, aggregate_versions | Chaque observation précise joueur, partie, jeu, source et version de schéma |
| Documents | documents, document_versions, contracts, amendments, contract_parties, approvals | Les contrats référencent des versions et plusieurs parties |
| Partenariats | sponsors, deals, campaigns, deliverables | L’accord possède obligations et livrables |
| Finances | budgets, expenses, payment_records, prize_allocations | Les écritures ont une devise et des droits dédiés |
| Médias et suivi | streams, vod_annotations, tasks, notifications, audit_events | Les objets référencent leur contexte avec contrôles d’accès |
| Intégration | integrations, import_runs, source_records, webhook_receipts | Les identifiants externes et événements permettent la déduplication |

### 17.2 Règles de conception

- Ne pas fusionner compte de connexion, personne, profil de jeu et appartenance à une équipe.
- Rendre `organization_id` obligatoire sur chaque donnée détenue par un tenant et interdire sa modification directe après création, sauf procédure métier explicite de transfert.
- Attribuer `organization_id` côté serveur depuis le `TenantContext` lors de la création ; ne jamais accepter sa valeur depuis un payload métier comme preuve du tenant cible.
- Inclure l’organisation dans les clés d’unicité métier, les index de lecture et, lorsque cela est possible, les clés étrangères composites afin d’empêcher une relation inter-tenant invalide.
- Ne jamais déduire l’organisation d’un identifiant fourni sans vérifier l’adhésion de l’acteur et l’appartenance de l’objet ; un UUID difficile à deviner ne remplace pas l’autorisation.
- Isoler requêtes, fichiers, caches, jobs, exports, notifications, recherches, intégrations et canaux temps réel ; tester les tentatives d’accès entre organisations sur chaque mécanisme.
- Maintenir une liste fermée des tables globales. Toute nouvelle table est considérée comme tenant-owned tant qu’une décision d’architecture documentée ne la déclare pas globale.
- Évaluer PostgreSQL Row-Level Security pendant le cadrage comme défense supplémentaire pour les tables les plus sensibles ; ne pas l’activer sans stratégie compatible avec les connexions persistantes, les workers, les migrations et l’administration plateforme, et ne pas en faire l’unique contrôle.
- Conserver les participants et rosters au moment de la compétition ; ne pas reconstruire l’historique à partir des seules affectations actuelles.
- Utiliser contraintes d’unicité, clés étrangères et validations de transition ; les identifiants difficiles à deviner ne remplacent pas l’autorisation.
- Indexer les filtres fréquents après analyse : organisation et statut, équipe et dates, match et partie, joueur et jeu et période.
- Documenter migrations avec down, contraintes et index ; traiter les reprises de données par lots et vérifier leur cohérence.
- Ne pas considérer soft delete comme un effacement de données personnelles ; appliquer la politique réelle de purge et d’archivage.

### 17.3 Propriété des données

| Catégorie | Portée initiale | Règle |
| --- | --- | --- |
| Comptes de connexion | Globale | Un compte s’authentifie une fois et rejoint des organisations par adhésion |
| Catalogue des jeux et référentiels techniques | Globale, administrée par la plateforme | Une organisation active uniquement les éléments dont elle a besoin |
| Paramètres, personnes, équipes et rôles | Tenant | Aucune fiche administrative n’est partagée automatiquement |
| Contrats, finances, documents et équipements | Tenant strict | Aucun accès inter-tenant ; export et conservation propres à l’organisation |
| Planning, matchs, statistiques et analyses | Tenant | Le partage public ou interorganisation porte sur une projection autorisée, jamais sur l’objet privé complet |
| Tournois | Tenant organisateur | Les inscrits externes ou autres tenants voient uniquement leur inscription et les données publiées |
| Fournisseurs et intégrations | Tenant par défaut | Un contrat ou credential plateforme partagé doit être explicitement documenté et compartimenté |
| Audit et observabilité | Plateforme avec dimension tenant | Accès restreint ; aucune donnée sensible dans les logs techniques |

## 18 API et intégrations fiables

### 18.1 Contrats d’API à prévoir

L’application Inertia n’exige pas une API séparée pour chaque écran. Les API utiles aux intégrations, overlays ou futurs clients sont documentées et versionnées, par exemple `/api/v1`. Le tenant est résolu par le domaine ou le contexte de route pour les utilisateurs, et par la portée du token pour les clients techniques. Un identifiant d’organisation reçu dans le corps de la requête n’est jamais considéré comme une preuve d’accès.

Exemples de ressources futures, à implémenter selon les clients retenus :

| Action | Endpoint indicatif | Exigence |
| --- | --- | --- |
| Liste des joueurs | GET /api/v1/players | Recherche et pagination, champs selon droits |
| Activités d’une période | GET /api/v1/activities | Bornes de dates obligatoires et périmètre autorisé |
| Création d’un match | POST /api/v1/matches | Validation du jeu, participants et éligibilité |
| Proposition d’un résultat | POST /api/v1/matches/{id}/result-submissions | Preuve, auteur et clé d’idempotence |
| Validation du résultat | POST /api/v1/matches/{id}/result-approval | Permission distincte et contrôle de version |
| Résumé sportif | GET /api/v1/players/{id}/statistics | Jeu et période ; source et fraîcheur |
| Export administratif | POST /api/v1/exports | Traitement différé, permission et expiration |

Exemple illustratif de lecture par client autorisé, et non endpoint déjà livré :

```bash
curl --request GET \
  --url 'https://ucg.app.exemple.tld/api/v1/players?page=1&per_page=25' \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer <JETON_A_PORTEE_LIMITEE>'
```

Les réponses JSON exposent `data`, `meta` et `links` lorsqu’il s’agit d’une collection. Les erreurs de validation sont localisées ; les erreurs techniques comportent un identifiant de suivi sans stack trace. Les statuts 401, 403, 409, 422 et 429 correspondent aux cas d’authentification, autorisation, conflit, validation et quota. Une tentative inter-tenant ne doit pas révéler inutilement l’existence de l’objet ciblé.

### 18.2 Fiabilité des échanges

- Clés et tokens chiffrés ou stockés dans un gestionnaire de secrets, avec propriétaire tenant ou plateforme explicite ; aucune clé fournisseur dans le navigateur.
- Quotas connus, timeouts, reprises avec attente croissante et respect des indications du fournisseur.
- Imports et callbacks idempotents ; stockage durable de leur réception et traitement traçable dans le bon tenant.
- Vérification des signatures de webhook lorsqu’elles sont disponibles et prévention du rejeu.
- Mise en quarantaine des événements invalides ; reprise manuelle contrôlée des échecs.
- Affichage de la dernière synchronisation et maintien d’un mode manuel pendant une panne externe.
- Priorités de queues séparant rappels urgents, signatures, synchronisations et rapports lourds.
- Version des données externes conservée afin de diagnostiquer les changements de schéma.
- Résolution du tenant avant traitement d’un webhook ; signature, intégration et clé d’idempotence vérifiées dans le même contexte.

Redis ne doit pas devenir la seule preuve durable d’un contrat, d’un paiement ou d’un résultat. Les Jobs critiques ont un état persistant permettant leur reprise. La configuration de queue et les mécanismes après transaction s’appuient sur les capacités Laravel. [Queues](https://laravel.com/docs/13.x/queues) ; [Horizon](https://laravel.com/docs/13.x/horizon).

## 19 Sécurité confidentialité et conformité

### 19.1 Classification proposée

| Niveau | Exemples | Accès |
| --- | --- | --- |
| Public | Profil approuvé, résultat publié, logo autorisé | Publication explicite |
| Sportif interne | Planning équipe, objectifs, scrims | Membres et staff concernés |
| Administratif confidentiel | Identité, contrats, pièces justificatives | Personne concernée selon le document et responsables habilités |
| Financier restreint | Rémunérations, primes, coordonnées de paiement | Finance et délégataires explicites |

### 19.2 Exigences techniques

- SEC-01 : HTTPS, cookies sécurisés, protections CSRF, contrôle des tentatives de connexion et MFA obligatoire pour les comptes privilégiés.
- SEC-02 : comptes individuels, récupération de compte encadrée et révocation des sessions.
- SEC-03 : autorisation de chaque objet et champ sensible ; contrôles sur téléchargements et exports.
- SEC-04 : validation par Form Requests, requêtes paramétrées, escaping des vues et nettoyage des contenus enrichis.
- SEC-05 : chiffrement des sauvegardes et protection des clés ; chiffrement de champs sensibles lorsqu’il est justifié.
- SEC-06 : fichiers privés, antivirus ou analyse adaptée, limites de taille et URLs temporaires.
- SEC-07 : journal d’audit à accès restreint, protégé contre les modifications ordinaires ; rétention définie.
- SEC-08 : logs sans secrets, rémunérations, contenu de contrats ni données excessives ; identifiant de corrélation pour le support.
- SEC-09 : dépendances suivies, scans, correctifs et procédure de traitement des vulnérabilités.
- SEC-10 : contrôler les connexions externes et éviter les téléchargements arbitraires de liens fournis par des utilisateurs.
- SEC-11 : appliquer un refus par défaut lorsqu’un tenant ne peut pas être résolu ou que l’adhésion est absente, expirée ou suspendue.
- SEC-12 : inclure le tenant dans les clés de cache, verrous, quotas, noms de canaux, chemins de fichiers, exports, audits et identifiants de corrélation internes.
- SEC-13 : vérifier les relations interobjets au sein du même tenant et compléter les Policies par des contraintes PostgreSQL lorsque cela est possible.
- SEC-14 : interdire aux administrateurs d’organisation les fonctions plateforme et encadrer toute assistance inter-tenant par un accès exceptionnel temporaire et audité.
- SEC-15 : exécuter des tests automatisés de non-régression inter-tenant pour les lectures, écritures, recherches, fichiers, API, jobs et événements temps réel.
- SEC-16 : éviter d’inclure des informations d’un autre tenant dans les messages d’erreur, suggestions, compteurs, journaux ou réponses de type autocomplétion.

### 19.3 Cadre juridique à préciser

Identifier, pour la plateforme et pour chaque organisation cliente, le pays d’établissement, les territoires des utilisateurs, les rôles de responsable de traitement et de sous-traitant, les prestataires et les transferts de données. Si le RGPD s’applique, documenter finalités, bases légales, durées, information des personnes, demandes d’exercice de droits et accords de traitement nécessaires. Le consentement n’est pas une base universelle pour toutes les opérations administratives.

Définir une matrice de conservation par catégorie et, si nécessaire, par organisation pour candidats, anciens membres, contrats, justificatifs, statistiques, logs et sauvegardes. Les besoins contractuels ou légaux peuvent justifier un archivage restreint distinct du dossier actif. La purge d’un tenant doit inclure ses données actives, exports et fichiers, puis un cycle de disparition documenté dans les réplicas et sauvegardes sans compromettre la continuité des autres organisations.

Pour les mineurs, valider représentation légale, participation, publication d’image et accès aux données selon le droit applicable. Aucun seuil d’âge français ne doit être appliqué automatiquement à une organisation dont le pays est inconnu.

Ces exigences s’inspirent des recommandations officielles sur les habilitations, la conservation et les sauvegardes. La validation locale reste nécessaire. [CNIL conformité](https://www.cnil.fr/fr/assurer-votre-conformite-en-4-etapes) ; [CNIL conservation](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees).

## 20 Performance exploitation et évolutivité

### 20.1 Enveloppe initiale à valider

Hypothèse de dimensionnement pour la recette : UCG et au moins deux tenants de test isolés, 500 comptes internes ou associés au total, 100 utilisateurs actifs simultanément, 100 000 parties historisées et 2 000 visiteurs simultanés sur les pages publiques lors d’un événement. Les scénarios mesurent la charge agrégée et l’effet d’un tenant fortement actif sur les autres. Ce sont des hypothèses de test, non les effectifs déclarés d’UCG et non une capacité déjà prouvée.

| Exigence | Cible proposée | Condition de mesure |
| --- | --- | --- |
| Lecture interactive backend | p95 inférieur à 500 ms | Hors appel externe et génération de rapport ; jeu de données défini |
| Écran courant | Utilisable sous 2,5 s | Réseau, appareil et scénario fixés au protocole |
| Affichage d’un score reçu | p95 inférieur à 3 s | De la validation dans le tenant à l’affichage ; hors latence fournisseur |
| Disponibilité mensuelle | 99,5 % | Mesure synthétique et fenêtre de maintenance convenues |
| Sauvegarde et reprise | RPO de 1 h ; RTO de 4 h | Perte maximale visée et délai de restauration testés |
| Rappel planifié | Exécuté sous 5 min de l’heure prévue | Services internes disponibles ; remise fournisseur suivie séparément |

Un objectif plus exigeant, par exemple 99,9 %, RPO de 15 minutes et RTO d’une heure, implique un budget et une architecture de reprise renforcés. Les cibles retenues doivent correspondre au calendrier compétitif et aux moyens d’exploitation.

### 20.2 Organisation de la production

Séparer développement, recette et production. Les données de test sont fictives ou anonymisées. Déployer les processus web, workers, scheduler et temps réel avec supervision et redémarrage automatique. La base, le stockage et les sauvegardes disposent de leurs protections propres. Le scheduler possède un mécanisme évitant l’exécution multiple lors d’un déploiement horizontal.

La CI vérifie qualité, tests, dépendances et construction des assets. Les migrations sont compatibles avec le déploiement et les volumes réels. Chaque release possède un plan de retour arrière ; les migrations destructrices requièrent une stratégie de conservation et de restauration.

Surveiller erreurs HTTP, temps de réponse, connexions temps réel, longueur et âge des queues, échecs d’import, rappels, stockage et intégrité des sauvegardes. Les métriques opérationnelles portent une dimension tenant uniquement lorsque cela est nécessaire et autorisé. Les alertes ont un responsable et une procédure d’intervention.

### 20.3 Performance des données

Paginer les collections ; borner les périodes et exports. Utiliser with ou loadMissing pour les relations nécessaires et profiler les requêtes via Telescope ou un outil équivalent dans un environnement protégé. Les tests de performance vérifient aussi le nombre de requêtes ; assertDatabaseCount contrôle des données, pas le nombre de requêtes exécutées.

Les tableaux de bord lisent des agrégats calculés en arrière-plan. Les caches incluent obligatoirement l’organisation, la portée et les facteurs de visibilité pertinents ; une modification d’adhésion ou d’autorisation invalide les résultats concernés. Le cache public ne peut pas contenir une réponse personnalisée privée.

Prévoir des limites et priorités empêchant un tenant bruyant de saturer les imports, exports, jobs, stockage ou canaux temps réel au détriment des autres. Les quotas techniques initiaux servent à protéger la plateforme et ne constituent pas automatiquement une offre commerciale.

À grande audience, les pages publiques sont servies via CDN, les nœuds web et workers sont augmentés séparément et le canal public de scores est dimensionné. Les réplicas de lecture et le partitionnement sont ajoutés lorsque les mesures le justifient. Les traitements analytiques très volumineux peuvent ensuite être extraits vers un entrepôt dédié.

La capacité à accueillir des millions de visiteurs ne se déduit pas du nom du framework. Elle exige tests de charge, budgets CDN et temps réel, protections contre les abus et stratégie de données adaptée. Pour UCG, il faut distinguer une petite population interne de pics publics beaucoup plus élevés.

### 20.4 Sauvegardes et continuité

Configurer sauvegardes de base et fichiers, isolation de copies, rétention et essais de restauration. Pour un RPO d’une heure, les mécanismes doivent produire une copie récupérable au moins à cette fréquence, ou permettre la restauration à un point dans le temps. Un simple backup quotidien ne satisfait pas cette cible.

Tester au minimum une restauration complète avant livraison puis périodiquement. Documenter aussi l’export, la récupération logique et la suppression contrôlée des données d’un tenant dans une base partagée. Une restauration sélective ne doit pas écraser les données plus récentes des autres organisations. Garder une copie protégée de la compromission des comptes de production. Les recommandations de la CNIL demandent notamment de protéger les sauvegardes et de vérifier leur restauration. [Source CNIL](https://cnil.fr/fr/securite-sauvegarder).

## 21 Backlog et lots de réalisation

### 21.1 Lot A cadrage et conception

- UCG-001 : confirmer jeux, effectifs, pays, mineurs, utilisateurs externes, modèle multi-tenant et priorités ; responsable produit et direction.
- UCG-002 : auditer fichiers actuels, méthodes de planning et circuits d’approbation ; managers et administration.
- UCG-003 : établir le dictionnaire des données, la propriété globale ou tenant de chaque entité et la matrice permissions × organisations × périmètres × champs ; produit et technique.
- UCG-004 : vérifier les APIs prioritaires avec un prototype et documenter les dépendances d’accès ; backend.
- UCG-005 : réaliser les parcours et maquettes de fiches, planning, match, contrat et tableau de bord ; design.
- UCG-006 : arrêter la liste P0, les cibles de service, le plan de reprise des données et les critères de recette ; produit.
- UCG-007 : produire le modèle de menace multi-tenant, les règles de résolution du tenant et la stratégie de test des fuites interorganisations ; sécurité et technique.

Livrables : périmètre validé, maquettes, modèle conceptuel multi-tenant, matrice d’accès, matrice de propriété des données, modèle de menace et backlog estimé. Aucun lot dépendant d’une API n’est engagé comme automatisé avant confirmation de faisabilité.

### 21.2 Lot B socle technique

- UCG-010 : initialiser les modules Laravel, dont Tenancy, l’injection des interfaces, les styles et les conventions PSR ; technique.
- UCG-011 : préparer CI, environnements, migrations, contraintes tenant, seeds multi-organisations et données de démonstration ; backend et exploitation.
- UCG-012 : implémenter comptes globaux, organisations, adhésions, invitations, changement de contexte, MFA, droits contextuels et révocation ; backend et frontend.
- UCG-013 : implémenter stockage privé préfixé par tenant, audit, messages Flasher et gestion d’erreurs localisées ; backend.
- UCG-014 : mettre en place queues et scheduler transportant explicitement le contexte tenant, observabilité, secrets et sauvegardes ; exploitation.
- UCG-015 : automatiser les tests d’isolation des requêtes, relations, fichiers, caches, exports, jobs, notifications et canaux temps réel ; backend et QA.

### 21.3 Lot C référentiel humain et administratif

- UCG-020 : fiches personnes, profils de jeu, staff, recherche et import isolés par tenant ; backend et frontend.
- UCG-021 : équipes, affectations datées et lineups historiques ; backend.
- UCG-022 : contrats, versions, droits financiers et rappels ; backend et frontend.
- UCG-023 : cohorte académie, objectifs et suivi simple ; frontend et backend.
- UCG-024 : sponsors, accords liés et obligations minimales ; frontend et backend.

### 21.4 Lot D opérations sportives

- UCG-030 : calendrier, disponibilités, conflits et convocations isolés par tenant ; backend et frontend.
- UCG-031 : scrims, matchs, parties, saisie et validation de résultats, y compris participants interorganisations autorisés ; backend.
- UCG-032 : compétitions externes, inscriptions, échanges interorganisations limités et échéances ; backend et frontend.
- UCG-033 : statistiques saisies ou importées, définition des métriques et tableaux de bord ; backend.
- UCG-034 : liens VOD et live, débriefs et visibilité ; frontend.
- UCG-035 : tests de parcours de la semaine sportive complète ; QA et staff pilote.

### 21.5 Lot E livraison de la première version

- UCG-040 : exécuter les tests de permissions, d’isolation inter-tenant, d’imports et de conflits concurrents ; QA.
- UCG-041 : tester charge, tenant bruyant, reprise de jobs, restauration complète et récupération logique d’une organisation ; QA et exploitation.
- UCG-042 : reprendre les données, rapprocher les totaux et former les utilisateurs ; produit et équipe technique.
- UCG-043 : piloter avec une équipe, corriger les anomalies bloquantes et valider la recette ; direction et staff.
- UCG-044 : documenter exploitation, support et déployer ; exploitation.

### 21.6 Lot F version professionnelle

- UCG-050 : premier connecteur statistique autorisé, isolé par tenant, et réconciliation avec les matchs du tenant pilote.
- UCG-051 : recrutement, évaluations académie et ressources pédagogiques.
- UCG-052 : signature électronique et preuves archivées.
- UCG-053 : tournoi organisé en élimination simple avec comptes externes et arbitrage.
- UCG-054 : portail sponsor, campagnes et rapports.
- UCG-055 : budgets opérationnels, dépenses, matériel et exports comptables.
- UCG-056 : pages publiques, lecteurs live, SEO et notifications Discord.
- UCG-057 : branding par organisation, domaines personnalisés et quotas configurables.

### 21.7 Lot G extensions

Autres jeux et connecteurs, formats de tournoi avancés, overlays, scouting, synchronisation calendrier bidirectionnelle, application mobile éventuelle, abonnements SaaS, facturation et options de déploiement ou de base dédiés. Chaque extension est précédée d’un cadrage propre et d’une estimation.

### 21.8 Dépendances structurantes

Résolution du tenant, organisations, adhésions et isolation précèdent tous les modules métier. Identité et permissions précèdent les documents. Personnes, jeux et équipes précèdent lineups et calendrier. Matchs et définitions de métriques précèdent agrégats et connecteurs. Validation de résultats précède progression de tournoi. Classification des données précède publication et portail sponsor. Sauvegardes et restauration précèdent mise en production.

## 22 Critères de recette

| ID | Scénario | Résultat attendu |
| --- | --- | --- |
| REC-01 | Un coach tente de lire un contrat hors habilitation | Refus côté serveur, y compris URL directe et export |
| REC-02 | Un joueur modifie sa disponibilité | Modification visible au planning ; autres profils protégés |
| REC-03 | Deux activités mobilisent le même joueur | Conflit signalé avant confirmation ; politique d’exception tracée |
| REC-04 | Une séance récurrente traverse un changement d’heure | Heure locale conforme au fuseau de référence |
| REC-05 | Un joueur quitte son équipe après un match | Ancien lineup et statistiques historiques conservés |
| REC-06 | Un fichier CSV contient doublons et joueurs inconnus | Prévisualisation, erreurs par ligne et aucun doublon silencieux |
| REC-07 | Le même import est relancé | Pas de second match ou observation identique |
| REC-08 | Un score BO3 incohérent est proposé | Validation refusée avec message exploitable |
| REC-09 | Deux responsables valident des versions différentes | Une modification obsolète reçoit un conflit ; aucune perte silencieuse |
| REC-10 | Un score déjà publié est corrigé | Motif, audit, recalcul et mise à jour des vues concernées |
| REC-11 | Une métrique est absente de la source | Affichage indisponible et agrégat sans faux zéro |
| REC-12 | Un fournisseur de statistiques est indisponible | État visible, données précédentes datées et reprise contrôlée |
| REC-13 | Une affectation de coach expire | Accès équipe retiré, y compris canal temps réel et fichiers |
| REC-14 | Un lien temporaire de document expire | Téléchargement refusé ; nouvelle demande réautorisée |
| REC-15 | Un sponsor ouvre le rapport d’un autre accord | Aucun accès ni fuite dans la recherche |
| REC-16 | Un rappel de contrat est relancé après incident | Envoi dédupliqué et exécution traçable |
| REC-17 | Un participant externe ouvre un module interne | Accès refusé ; inscription propre accessible |
| REC-18 | Un bracket reçoit un résultat validé en P1 | Progression conforme aux byes et au règlement |
| REC-19 | Un résultat dépendant doit être corrigé en P1 | Arbitrage et impact sur les tours suivants traités |
| REC-20 | Une restauration est exécutée | Base et fichiers cohérents ; RPO et RTO mesurés |
| REC-21 | Charge conforme à l’enveloppe validée | Cibles p95 vérifiées et rapport reproductible |
| REC-22 | Suppression ou archivage d’une personne | Politique de conservation appliquée sans casser les résultats autorisés |
| REC-23 | Un membre d’UCG ouvre l’URL connue d’une équipe d’un autre tenant | Aucun accès et aucune révélation inutile de l’existence de l’objet |
| REC-24 | Une recherche ou autocomplétion est effectuée dans UCG | Aucun résultat privé provenant d’un autre tenant |
| REC-25 | Un compte membre de deux organisations change de contexte | Données, rôles, menus et permissions sont recalculés sans mélange |
| REC-26 | Deux tenants utilisent la même clé fonctionnelle ou le même slug local | Les contraintes autorisent les valeurs propres à chaque tenant et empêchent les collisions réellement globales |
| REC-27 | Deux tenants génèrent le même rapport avec des filtres identiques | Caches et téléchargements restent strictement séparés |
| REC-28 | Un Job est relancé après changement d’organisation dans la session de l’auteur | Le Job utilise le tenant persisté et revérifié, jamais le contexte courant d’un autre utilisateur |
| REC-29 | Un événement temps réel est publié pour un match privé | Seuls les abonnés autorisés du tenant et du périmètre reçoivent les données |
| REC-30 | Un webhook porte un identifiant externe existant dans deux tenants | L’intégration, la signature et la clé d’idempotence déterminent sans ambiguïté le bon tenant |
| REC-31 | Une organisation participe au tournoi d’une autre | Seules l’inscription, le roster soumis et les informations publiées sont partagés |
| REC-32 | Un admin d’organisation tente d’obtenir un rôle plateforme | Refus, audit et absence de modification de privilège |
| REC-33 | Un superadmin ouvre un accès exceptionnel | Tenant, motif, durée et actions sont journalisés ; l’accès expire automatiquement |
| REC-34 | Un tenant fortement actif lance de nombreux imports et exports | Les limites empêchent la dégradation incontrôlée des autres organisations |
| REC-35 | Un tenant demande export puis suppression | Données exportées de manière complète, purge conforme et autres tenants inchangés |
| REC-36 | Une récupération logique d’un tenant est simulée | Données et fichiers sont réconciliés sans écraser les mises à jour des autres organisations |

La recette vérifie les autorisations positives et négatives, l’isolation inter-tenant, les transactions, l’idempotence, les règles sportives et les scénarios d’échec. Les tests unitaires portent sur les règles ; les tests d’intégration sur données, contraintes relationnelles, autorisations, caches, jobs et fournisseurs simulés ; les tests navigateur sur les parcours essentiels et les changements d’organisation.

Une fonction P1 ou P2 n’est pas exigée pour valider P0. Les scénarios correspondants sont activés lors du lot concerné. Les scénarios multi-tenant applicables aux fonctions du socle, notamment les accès directs, recherches, changements de contexte, caches, jobs, webhooks, rôles plateforme, limites de charge et récupérations logiques, sont toutefois des exigences P0. La livraison exige l’absence d’anomalie critique ou bloquante, la validation des parcours P0 et le transfert des procédures d’exploitation.

## 23 Planning équipe et estimation

### 23.1 Organisation proposée

Un responsable produit côté UCG arbitre les priorités du tenant pilote avec un manager, un coach et un responsable administratif. Un responsable produit plateforme tranche les règles communes, le cycle de vie des organisations et les évolutions SaaS ; ces deux responsabilités peuvent être portées par la même personne pendant le pilote si les décisions sont explicitement distinguées. L’équipe de réalisation type comprend un lead backend Laravel, un développeur backend, un développeur frontend React, un designer à temps partiel, un QA à temps partiel et un responsable exploitation à temps partiel. Certaines personnes peuvent cumuler ces missions selon leur expérience.

### 23.2 Enveloppe de première version

Estimation de cadrage révisée, non devis : 210 à 325 jours-personnes de développement pour le P0 multi-tenant décrit, hors prestations juridiques, licences de données et nouveaux connecteurs complexes. Cette enveloppe ajoute au chiffrage initial la gestion des organisations, les contraintes de données, l’isolation de chaque canal technique, les jeux de données multi-tenants et les tests négatifs. Elle inclut le développement des vérifications automatisées et les corrections ; le design, la recette métier et l’exploitation doivent être chiffrés séparément ou explicitement inclus dans l’offre.

Avec trois développeurs mobilisés à environ 85 % sur le projet, 17 à 26 semaines représentent environ 217 à 332 jours-personnes. Ce calcul donne un ordre de grandeur cohérent avec l’enveloppe ; il n’annule pas les dépendances ni les temps d’attente externes.

| Phase | Durée indicative | Sortie attendue |
| --- | --- | --- |
| Cadrage et conception | 3 à 4 semaines | Périmètre, propriété des données, permissions, modèle de menace, maquettes et faisabilité |
| Socle multi-tenant et premier parcours | 3 à 5 semaines | Organisations, adhésions, contexte, connexion, fiches et sécurité |
| Modules P0 par itérations | 8 à 12 semaines | Gestion administrative et sportive utilisable et isolée |
| Pilote et préparation production | 3 à 5 semaines | Recette multi-tenant, données, formation, sauvegarde et récupération |

Ces phases constituent une projection de 17 à 26 semaines avec travaux parallèles de design et recette. L’équipe les recalcule après découpage des stories. Une première version métier plus étroite, limitée aux effectifs, au planning et aux résultats, peut être isolée si le budget impose une livraison plus rapide ; le socle d’isolation multi-tenant ne doit pas être retiré.

La version professionnelle ajoute des lots distincts. Un périmètre large comprenant plusieurs APIs, signature, tournois avancés et portails peut porter le programme à 9 à 15 mois avec cette équipe. Ce scénario n’est pas un engagement et doit être réestimé après le pilote et les réponses des fournisseurs.

### 23.3 Méthode budgétaire

Budget de réalisation = jours-personnes par profil × tarif convenu + licences et prestations + réserve de risque. Prévoir une réserve de cadrage de 15 à 25 % selon la part d’intégrations, de données à reprendre et d’exigences d’isolation par tenant ; ce pourcentage est une hypothèse de planification, pas un tarif de marché.

Les postes récurrents incluent hébergement, base, stockage, sauvegardes, e-mails, observabilité, fournisseurs de statistiques, signature et maintenance. Les chiffrer à partir de devis correspondant aux pays, au nombre de tenants, au trafic, aux volumes, aux quotas et aux cibles de service retenues. Éviter un budget d’hébergement fixe avant d’avoir précisé les pics publics, la croissance du nombre d’organisations et les données vidéo éventuellement stockées.

## 24 Risques livrables et décisions de lancement

### 24.1 Principaux risques

| Risque | Effet | Réponse proposée |
| --- | --- | --- |
| Accès API refusé ou incomplet | Statistiques automatiques retardées | Prototype tôt ; modes manuel et CSV ; périmètre contractuel conditionnel |
| Périmètre trop large | Budget et délai instables | P0 fermé, extensions estimées et arbitrage régulier |
| Permissions trop générales | Fuite de contrats ou stratégies | Matrice par champ et contexte ; tests négatifs |
| Fuite inter-tenant | Exposition de données privées d’une autre organisation | Refus par défaut, contexte explicite, contraintes SQL, isolation de tous les canaux et tests systématiques |
| Tenant bruyant | Saturation des jobs, exports, stockage ou temps réel | Quotas techniques, priorités, supervision et limitation par organisation |
| Cycle de vie incomplet d’un tenant | Données orphelines ou suppression dangereuse | Procédures d’onboarding, suspension, export, archivage, purge et récupération |
| Partage interorganisation trop large | Données internes révélées pendant un tournoi | Projections autorisées, instantanés et permissions temporaires minimales |
| Données historiques incohérentes | Rapports erronés | Nettoyage, imports contrôlés et rapprochement avec responsables |
| Modèle de jeu trop uniforme | Impossibilité de gérer certains formats | Participants multiples et métriques spécifiques au jeu |
| Correction tardive d’un score | Bracket incohérent | Validation, versionnement et traitement des dépendances |
| Faible adoption | Retour aux outils dispersés | Pilote, parcours simples, formation et suivi d’usage |
| Incident de production | Activités ou documents indisponibles | Supervision, copie isolée, restauration testée et procédure d’urgence |
| Mineurs ou droit applicable mal cadrés | Processus administratifs inadaptés | Validation locale des documents et accès nécessaires |
| Dépendance à un prestataire | Difficulté de reprise | Propriété des comptes, exports documentés et transfert d’exploitation |

### 24.2 Livrables à demander au prestataire

- Cahier des charges validé, maquettes et backlog avec identifiants d’exigences.
- Code source complet et historique dans un dépôt appartenant au propriétaire désigné de la plateforme, avec droits garantis à UCG selon le contrat.
- Migrations, données de démonstration, dictionnaire des données et documentation des règles métier.
- Matrice de permissions, matrice de propriété globale ou tenant, modèle de menace et tests de sécurité fonctionnelle inter-tenant.
- Documentation d’API pour les interfaces réellement livrées.
- Configuration reproductible des environnements, queues, scheduler, secrets et stockage, avec règles de propagation du contexte tenant.
- Rapports de recette, d’isolation, de charge, de tenant bruyant, de restauration complète et de récupération logique d’un tenant.
- Guide utilisateur, formation des référents et procédures d’exploitation et d’incident.
- Inventaire des dépendances, licences, comptes fournisseurs et coûts récurrents.
- Procédure d’export par organisation et de transfert permettant de changer de prestataire.
- Modalités de garantie, support, maintenance, mise à jour et traitement des anomalies.

### 24.3 Décisions nécessaires avant devis définitif

1. Jeux exacts, plateformes, régions, modes et métriques prioritaires.
2. Effectifs actuels et attendus, équipes, staff et nombre de comptes externes.
3. Pays d’établissement, territoires d’activité et présence de mineurs.
4. Priorité entre gestion interne, site public et tournois ouverts.
5. Formats de tournoi à gérer réellement au lancement.
6. Sources actuelles de données et qualité des fichiers à importer.
7. Budget, date souhaitée et compétences de l’équipe de réalisation.
8. Droits contractuels, signataires et personnes habilitées aux montants.
9. Fournisseurs déjà utilisés : Discord, calendrier, statistiques, signature et comptabilité.
10. Région d’hébergement, exigences de disponibilité et responsabilités de support.
11. Nombre d’organisations attendu à un, trois et cinq ans, ainsi que le modèle privé, fédéré ou SaaS visé.
12. Méthode de résolution du tenant : sous-domaine, domaine personnalisé, chemin ou combinaison contrôlée.
13. Données globales autorisées et données qui doivent rester propres à chaque organisation.
14. Possibilité pour un utilisateur, un sponsor ou un prestataire d’appartenir à plusieurs organisations.
15. Propriétaire de la plateforme, règles de création d’un tenant et procédure de transfert de propriété.
16. Besoin de plans, quotas commerciaux, essais, abonnements et facturation, hors P0 par défaut.
17. Exigences éventuelles de base, clé de chiffrement, région ou déploiement dédiés pour certains tenants.
18. Procédure attendue d’export, de suppression et de récupération logique d’une organisation.

## 25 Références techniques et réglementaires

Références consultées le 3 octobre 2026. Les modalités des fournisseurs peuvent évoluer et doivent être revérifiées pendant le cadrage.

- Laravel versions et support : https://laravel.com/docs/13.x/releases
- Laravel jobs et transactions : https://laravel.com/docs/13.x/queues
- Laravel Horizon : https://laravel.com/docs/13.x/horizon
- Laravel Reverb : https://laravel.com/docs/13.x/reverb
- Laravel Sanctum : https://laravel.com/docs/13.x/sanctum
- Inertia et interfaces : https://inertiajs.com/
- Inertia SSR : https://inertiajs.com/server-side-rendering
- PostgreSQL JSONB : https://www.postgresql.org/docs/current/datatype-json.html
- PostgreSQL sécurité des lignes : https://www.postgresql.org/docs/current/ddl-rowsecurity.html
- Riot VALORANT : https://developer.riotgames.com/docs/valorant
- Riot League of Legends : https://developer.riotgames.com/docs/lol
- FACEIT Data API : https://docs.faceit.com/docs/data-api/
- Twitch lecteurs vidéo : https://dev.twitch.tv/docs/embed/video-and-clips/
- YouTube lecteur intégré : https://developers.google.com/youtube/iframe_api_reference
- CNIL démarche de conformité : https://www.cnil.fr/fr/assurer-votre-conformite-en-4-etapes
- CNIL durées de conservation : https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees
- CNIL sauvegardes : https://cnil.fr/fr/securite-sauvegarder

Les priorités, la stack, l’architecture multi-tenant, les cibles de performance et les estimations de charge sont des recommandations de conception pour la plateforme et son tenant pilote UCG. Elles ne sont pas présentées comme des prescriptions des sources citées.
