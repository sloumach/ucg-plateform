# Guide des permissions UCG

Dans **Membres et accès → Rôles**, coche les permissions qui correspondent au travail du membre. Dans **Affectations**, choisis ensuite le membre, le rôle, le périmètre et la période. Un rôle définit les droits. Une affectation indique qui les reçoit, où et pendant combien de temps.

Ce guide couvre les **66 permissions du formulaire**. Les libellés en gras sont ceux à rechercher. Les codes entre parenthèses servent à retrouver la même permission dans les échanges techniques.

- **Double authentification (MFA)**. Toutes les permissions de ce guide l’exigent, sauf **Consulter l’organisation** et **Consulter les données sportives**.
- **Périmètre**. Un rôle peut être attribué au niveau de l’organisation. Pour une équipe, un jeu, une cohorte ou un événement précis, toutes les permissions du rôle doivent être compatibles avec le périmètre choisi. Par exemple, le tableau de bord coach s’attribue au niveau de l’organisation.
- **Lecture et gestion**. Certains écrans demandent les deux droits. Pour modifier des activités dans le calendrier, coche par exemple la consultation des activités et la gestion des activités.
- **Gestion des rôles**. Le droit interne de créer et modifier les rôles relève du propriétaire et ne figure pas parmi les cases à cocher.

## Organisation et accès

| Permission | À quoi elle sert |
|---|---|
| **Consulter l’organisation** (`organization.view`) | Accéder aux informations générales de l’organisation, comme son nom et ses paramètres de base. |
| **Modifier les paramètres de l’organisation** (`organization.update`) | Mettre à jour les informations de l’organisation et ses préférences, comme le fuseau horaire. |
| **Gérer les adhésions ordinaires** (`memberships.manage`) | Consulter les adhésions et gérer leur statut, leur période ou leur rôle de base, selon l’autorité du compte. |
| **Gérer les invitations ordinaires** (`invitations.manage`) | Envoyer et révoquer des invitations pour rejoindre l’organisation. Il s’agit des invitations de membres. |
| **Déléguer des rôles dans les limites autorisées** (`roles.assign`) | Attribuer un rôle existant à un membre et gérer cette affectation, dans le périmètre et la période autorisés. |
| **Consulter le journal d’audit de l’organisation** (`audit.view`) | Consulter les événements d’audit de l’organisation, notamment les accès sensibles enregistrés. |

## Équipes, jeux et suivi sportif

| Permission | À quoi elle sert |
|---|---|
| **Consulter les données sportives** (`sports.read`) | Voir les équipes, jeux, adversaires et matchs accessibles, ainsi que la partie sportive des fiches. |
| **Gérer les jeux de l’organisation** (`games.manage`) | Activer ou désactiver localement un jeu du catalogue commun et régler les choix disponibles pour l’organisation. |
| **Gérer les équipes de l’organisation** (`teams.manage`) | Créer, modifier ou archiver les équipes et gérer leurs effectifs, compositions et adversaires externes dans le périmètre autorisé. |
| **Modifier les données sportives** (`sports.write`) | Créer ou modifier des données sportives, par exemple une fiche de joueur ou un dossier de match, selon les règles de l’écran. |
| **Valider les données sportives** (`sports.validate`) | Examiner et valider des résultats de match, sous réserve des règles de validation du dossier. |
| **Publier les données sportives** (`sports.publish`) | Autoriser la diffusion interne de données sportives, par exemple lorsque la visibilité d’un dossier de match est étendue à l’organisation. |
| **Corriger les scores avec audit et recalcul** (`sports.correct`) | Déclencher une correction de score avec historique et recalcul des éléments concernés. |
| **Lire les notes sportives internes** (`sports.notes.read`) | Consulter les notes sportives internes des dossiers auxquels le membre a accès. |
| **Modifier les notes sportives internes** (`sports.notes.write`) | Rédiger ou mettre à jour les notes sportives internes de ces dossiers. |
| **Lire les stratégies sportives** (`sports.strategies.read`) | Consulter les éléments de stratégie des dossiers accessibles. |
| **Modifier les stratégies sportives** (`sports.strategies.write`) | Créer ou modifier ces éléments de stratégie. |
| **Consulter les observations statistiques privées** (`sports.statistics.read`) | Voir les mesures enregistrées et les vues statistiques privées accessibles. |
| **Saisir les observations statistiques privées** (`sports.statistics.write`) | Enregistrer une mesure, comme une valeur observée pour un joueur ou une équipe. |
| **Déposer et prévisualiser les CSV statistiques privés** (`sports.statistics.import`) | Déposer un fichier CSV et vérifier son contenu avant import. La saisie effective reste soumise aux droits de l’opération. |
| **Corriger et invalider les observations statistiques privées** (`sports.statistics.correct`) | Rectifier une mesure erronée ou l’invalider en conservant son historique. |
| **Consulter les suivis de participation privés** (`sports.registrations.read`) | Voir le suivi d’une inscription à une compétition, comme l’effectif déclaré, les échéances et les pièces accessibles. |
| **Modifier les suivis de participation privés** (`sports.registrations.write`) | Renseigner ou mettre à jour les éléments de ce suivi d’inscription. |
| **Consulter les compétitions externes et leurs documents privés** (`sports.competitions.read`) | Ouvrir les dossiers de compétitions autorisés et consulter leurs documents lorsque l’accès le permet. |
| **Gérer le suivi des compétitions externes** (`sports.competitions.write`) | Créer et mettre à jour les dossiers de compétition et leur suivi sportif, comme les matchs liés et le classement. |

## Calendrier et disponibilités

| Permission | À quoi elle sert |
|---|---|
| **Consulter les activités accessibles du calendrier** (`calendar.read`) | Voir les entraînements, réunions et autres activités accessibles, avec leurs horaires. |
| **Gérer les activités et leurs participants** (`calendar.write`) | Créer, modifier, reporter ou annuler des activités et renseigner leurs participants. |
| **Autoriser le partage interne des activités** (`calendar.publish`) | Partager une activité avec les lecteurs autorisés de l’organisation selon la visibilité choisie. |
| **Créer son flux de calendrier privé** (`calendar.export`) | Générer un lien ICS privé pour afficher son calendrier dans une autre application. |
| **Gérer les ressources réservables du calendrier** (`calendar.resources.manage`) | Ajouter et gérer les ressources utilisées pour les réservations d’activités. |
| **Autoriser les exceptions de conflits de réservation** (`calendar.conflicts.override`) | Confirmer une exception autorisée à un conflit de réservation, avec une justification. |
| **Gérer les convocations du calendrier** (`calendar.invitations.manage`) | Envoyer les convocations à une activité et suivre les réponses. |
| **Répondre à ses convocations** (`calendar.invitations.respond`) | Répondre aux convocations reçues sur son propre compte. |
| **Gérer mes disponibilités et absences** (`availability.self`) | Déclarer ou mettre à jour ses propres créneaux et absences. |
| **Consulter le planning collectif des disponibilités** (`availability.read`) | Voir les disponibilités déclarées des personnes accessibles. |
| **Gérer les disponibilités et leur historique** (`availability.manage`) | Gérer les déclarations de disponibilité dans le périmètre autorisé et consulter leur historique. |

## Académie

| Permission | À quoi elle sert |
|---|---|
| **Consulter l’académie** (`academy.read`) | Voir les cohortes, cycles et informations pédagogiques accessibles. |
| **Gérer l’académie** (`academy.manage`) | Organiser les cohortes, cycles et séances, ainsi que les inscriptions et les mentors selon le périmètre. |
| **Saisir et corriger les présences Académie** (`academy.attendance.manage`) | Marquer la présence aux séances et rectifier les erreurs de saisie. |
| **Publier et corriger le feedback partagé de l’académie** (`academy.feedback.manage`) | Suivre les objectifs pédagogiques et publier ou corriger les retours partagés avec les apprenants, selon le rôle et le périmètre. |
| **Consulter et gérer les notes internes de l’académie** (`academy.notes.manage`) | Lire et gérer les notes pédagogiques internes réservées au personnel habilité. |
| **Consulter ses propres objectifs et retours partagés** (`academy.self.read`) | Accéder à son parcours personnel, à ses objectifs et aux retours qui lui ont été partagés. |

## Tableaux de bord et exports

| Permission | À quoi elle sert |
|---|---|
| **Consulter le tableau de bord direction** (`reporting.direction`) | Ouvrir la vue de pilotage destinée à la direction. |
| **Consulter le tableau de bord manager** (`reporting.manager`) | Ouvrir la vue de suivi destinée au manager. |
| **Consulter le tableau de bord coach** (`reporting.coach`) | Ouvrir la vue de suivi sportif destinée au coach. |
| **Consulter le tableau de bord joueur** (`reporting.player`) | Ouvrir la vue destinée au joueur. |
| **Consulter le tableau de bord administration** (`reporting.administration`) | Ouvrir la vue de suivi administratif. |
| **Générer et télécharger ses exports de pilotage** (`reporting.export`) | Demander et télécharger ses propres exports à partir des vues et données auxquelles le compte a accès. |

## Administration, finances et partenariats

| Permission | À quoi elle sert |
|---|---|
| **Consulter les données administratives** (`administrative.read`) | Lire la partie administrative des fiches et les dossiers administratifs accessibles, dont les contrats. |
| **Modifier les données administratives** (`administrative.write`) | Renseigner ou corriger les informations administratives et préparer des dossiers de contrat. |
| **Valider les données administratives** (`administrative.validate`) | Approuver des étapes administratives, comme la validation d’un dossier de contrat, selon les règles du dossier. |
| **Consulter les données financières** (`financial.read`) | Voir les conditions et documents financiers pour lesquels le membre est habilité. |
| **Modifier les données financières** (`financial.write`) | Renseigner ou modifier des informations financières dans les dossiers accessibles. |
| **Valider les données financières** (`financial.validate`) | Valider les opérations ou dossiers qui exigent une approbation financière. |
| **Consulter les prospects, contacts et accords partenaires** (`partnerships.read`) | Voir le suivi des partenaires, de leurs accords et de leurs campagnes. |
| **Gérer les prospects, contacts et accords partenaires** (`partnerships.write`) | Créer et mettre à jour ces dossiers, ainsi que le suivi des campagnes et de leurs livrables. |
| **Valider les accords, expositions et preuves partenaires** (`partnerships.validate`) | Approuver les accords et les preuves de livraison selon les étapes de validation prévues. |

## Communications, tâches et médias

| Permission | À quoi elle sert |
|---|---|
| **Consulter les annonces et discussions ciblées** (`communication.read`) | Lire les annonces et discussions visibles pour le membre, selon leurs destinataires. |
| **Gérer les brouillons et les commentaires ouverts** (`communication.write`) | Préparer et modifier les brouillons d’annonces et gérer les commentaires selon les règles de la discussion. |
| **Publier ou retirer des annonces** (`communication.publish`) | Diffuser une annonce vers les destinataires choisis ou retirer une annonce publiée. |
| **Participer aux discussions accessibles** (`communication.comment`) | Ajouter un commentaire aux discussions ouvertes auxquelles le membre a accès. |
| **Consulter les tâches internes** (`tasks.read`) | Voir les tâches internes accessibles et leur état d’avancement. |
| **Créer et modifier les tâches internes** (`tasks.manage`) | Créer une tâche, renseigner son responsable ou mettre à jour son suivi. |
| **Consulter les médias partagés** (`media.read`) | Voir les références partagées de streams, VOD et clips accessibles. |
| **Gérer les médias, y compris les références privées** (`media.write`) | Ajouter, modifier ou archiver des références médias dans les dossiers autorisés. |
| **Autoriser le partage des médias et des scrims différés** (`media.publish`) | Étendre la visibilité d’une référence média ou autoriser le partage d’un enregistrement de scrim. |

## Que faire dans Équipes et jeux ?

| Rubrique | Ce que tu peux faire |
|---|---|
| **Équipes** | Rechercher les équipes par jeu, saison ou statut et consulter leurs effectifs, compositions et historiques. Avec **Gérer les équipes de l’organisation**, tu peux aussi créer, modifier et archiver des équipes, et gérer les effectifs et compositions. Il faut au moins un jeu activé pour créer une équipe. |
| **Jeux** | Voir les jeux disponibles. Avec **Gérer les jeux de l’organisation**, tu peux activer un jeu et choisir les plateformes, services de compte et régions autorisés pour ton organisation. Ces choix s’appliquent à ton organisation. |
| **Adversaires** | Consulter les références d’équipes ou de joueurs externes utilisées pour les matchs. Avec **Gérer les équipes de l’organisation**, tu peux créer, modifier et archiver ces références. |
| **Mes équipes autorisées** | Ouvrir les fiches des équipes auxquelles un rôle limité à une équipe te donne accès. Ce panneau reste accessible même quand tu ne disposes pas de la lecture sportive à l’échelle de l’organisation. |

Pour un **coach qui consulte seulement**, commence par **Consulter les données sportives**. Pour un **responsable qui prépare les jeux et construit les équipes**, ajoute **Gérer les jeux de l’organisation** et **Gérer les équipes de l’organisation**, puis active un jeu avant de créer la première équipe.
