# UCG-OPS-008 — Procédures d’incident et de support

Version du 26 octobre 2026. Trois lots : fiches d’intervention, coordination et
preuves, exercices fictifs. La CI de `50e4b37`, dont les deux sondes ClamAV
réelles, est validée par l’utilisateur. Cela ne qualifie pas le futur hébergement.

## 1. Cadre d’utilisation

L’essai actuel utilise exclusivement des données fictives ; l’hébergement est
différé. Ce document décrit les mécanismes existants et les décisions à prendre.
Il ne configure ni astreinte, ni alerte automatique, ni canal de communication.
Les rôles ci-dessous ont une fonction précise ; leurs titulaires et suppléants
restent à désigner. Les délais proposés sont des cibles à faire adopter par UCG,
pas des engagements de service ni des délais réglementaires.

Mise à jour REL-002 du 29 octobre 2026 : l’utilisateur confirme assurer lui-même
la gestion des accès utilisateurs et le support de premier niveau pour le moment.
Ce choix ne désigne pas automatiquement l’exploitant, le coordinateur d’incident,
le référent sécurité ou le vérificateur. Horaires, contacts privés, suppléants et
canal de secours restent à confirmer avant exploitation réelle. Les guides,
le registre de transfert et la checklist de lancement figurent dans le
[dossier REL-002](../README.md#ucg-rel-002--préparer-le-lancement-et-lexploitation).

Toute intervention réelle exige un opérateur authentifié sur l’environnement
autorisé, un incident identifié et une décision tracée. Un identifiant `--actor`
attribue un audit : il ne donne pas l’accès serveur. Un compte support applicatif
n’est pas un compte d’administration de l’hébergement.

## 2. Coordination et niveaux d’urgence

| Rôle fonctionnel | Responsabilité | Titulaire / suppléant / contact |
| --- | --- | --- |
| Coordinateur d’incident | Ouvre le suivi, classe l’incident, autorise le périmètre d’intervention, fixe la prochaine information, décide du rétablissement avec le vérificateur | À désigner |
| Opérateur technique | Diagnostique dans son périmètre autorisé, prépare le retour arrière, exécute et trace chaque action | À désigner |
| Référent sécurité et données | Pilote les suspicions de fuite, protège les preuves, fait examiner les obligations applicables avec CAD-004 | À désigner |
| Relais UCG | Centralise les signalements et prépare les messages aux personnes affectées ; ne promet pas une date sans validation du coordinateur | À désigner |
| Vérificateur | Contrôle le tenant, les effets, les accès temporaires et les critères de sortie ; valide la clôture avec le coordinateur | À désigner |

Une même personne peut assumer plusieurs rôles pendant l’essai fictif, en le
déclarant. Avant données réelles, prévoir une seconde revue pour restauration,
modification d’accès ou intervention susceptible d’affecter plusieurs tenants.
Sans intervenant habilité ou décision sûre, conserver l’état protégé et escalader ;
ne pas improviser de droit supplémentaire. La direction UCG doit désigner qui
reçoit cette escalade et le canal de secours indépendant du service en panne.

| Niveau | Déclencheur proposé | Prise en charge / information proposées |
| --- | --- | --- |
| S1 — critique | Une suspicion crédible de fuite, accès inter-tenant ou compromission ; perte/corruption suspectée ; indisponibilité générale confirmée par deux contrôles espacés d’une minute | Alerte immédiate au coordinateur et à la sécurité si applicable ; accusé sous 15 min ; point toutes les 30 min tant que l’impact reste majeur |
| S2 — majeur | Parcours essentiel d’un tenant indisponible 5 min ; plus ancien job admissible sans progression depuis 5 min ; trois échecs consécutifs d’une dépendance en 5 min | Coordinateur puis opérateur du composant ; accusé sous 1 h ; point toutes les 2 h |
| S3 — limité | Anomalie isolée, contournement sûr disponible, aucune exposition ou perte suspectée | Relais UCG puis opérateur ; accusé au prochain jour ouvré ; prochain point daté dans le suivi |

Une suspicion de fuite est S1 dès le premier signal crédible, sans attendre un
seuil statistique. Ces seuils sont des règles de triage humain à adapter aux
volumes et aux horaires ; les pauses planifiées ou jobs volontairement différés
ne sont pas automatiquement des incidents. À impact ou périmètre incertain,
retenir le niveau supérieur jusqu’à vérification. Les seuils n’impliquent pas
qu’une mesure ou une notification existe déjà dans le logiciel.

## 3. Suivi, preuves et messages

Ouvrir une fiche privée avec : référence `INC-AAAA-MM-JJ-NNN`, environnement,
révision déployée, heure de détection et fuseau, déclarant, niveau et justification,
composant/parcours touché, tenants concernés via identifiants internes, coordinateur,
opérateur, vérificateur et prochain point horodaté. Distinguer faits, hypothèses
et inconnues. Normaliser la chronologie en UTC tout en conservant le fuseau source.

Pour chaque action, noter auteur, autorisation, cible exacte, début/fin, résultat,
effets attendus et observés, preuve et possibilité de retour arrière. Conserver
uniquement les codes d’erreur, `X-Request-ID`/`meta.request_id`, identifiant du job
ou du scan, horodatages et extraits nécessaires. La progression des travaux se
suit notamment avec `tenant.work.started/handled/failed/rejected` ; `handled`
signifie retour du handler et ne prouve pas son succès métier.

Les journaux bruts, payloads de `failed_jobs`, exports et traces navigateur peuvent
contenir des informations privées. Les preuves restent dans un espace restreint
à définir avec l’hébergement, avec droits, empreinte, déposant et historique
d’accès. Référencer leur emplacement dans la fiche plutôt que copier leur contenu.
Ne publier ni cookie, secret, jeton signé, pièce jointe, corps métier ou données
d’un autre tenant. Si une preuve nécessaire contient un secret, conserver
l’original en accès restreint et utiliser une copie expurgée pour les échanges.
La durée de conservation et un éventuel gel des preuves restent à décider avec
CAD-004/OPS-007 ; ne lancer aucune purge de preuves pendant l’investigation.

Messages à préparer, puis faire valider par le coordinateur et transmettre via
un canal autorisé par le relais UCG (aucun envoi effectué par cet exercice) :

```text
Ouverture : [référence], détecté le [heure/fuseau]. [Parcours] est affecté sur
[périmètre utile au destinataire]. Faits confirmés : [...]. Mesure en cours :
[...]. Contournement sûr : [...] ou « aucun confirmé ». Prochain point : [...].

Mise à jour : faits nouveaux [...], impact actuel [...], action et résultat
[...], inconnues [...], prochain point [...]. Aucun délai de résolution confirmé
tant que la dépendance ou la vérification reste incertaine.

Rétablissement : [parcours] rétabli à [...], vérifié par [...]. Surveillance
jusqu’à [...]. Restrictions restantes [...]. Clôture ou nouvelle revue à [...].
```

Séparer rétablissement technique et clôture. Une fuite suspectée nécessite une
évaluation distincte des obligations de notification par les personnes habilitées,
selon les faits et pays concernés ; cette procédure n’invente aucun délai légal.

## 4. Fiches d’intervention

### F1 — Service indisponible ou dégradé

1. Vérifier environnement, révision, heure et étendue : un utilisateur, un tenant,
   plusieurs tenants ou toute la plateforme ; ouvrir le suivi et classer.
2. Contrôler l’accès HTTP `/up` et `/api/v1/system/status`, puis un parcours
   authentifié autorisé sur le tenant concerné. Le contrôleur de statut renvoie
   `operational` sans sonder base, stockage, scanner ou files ; un HTTP 200 ne
   permet pas à lui seul de déclarer le service sain.
3. L’opérateur vérifie les dépendances et journaux de l’hébergement par les outils
   autorisés, ainsi que le dernier changement. Ne pas multiplier les écritures
   de diagnostic sur les données réelles. Si l’intégrité est incertaine, suspendre
   les interventions qui la mettraient en danger et passer à F2/F5.
4. Préparer une correction ou un retour à une révision compatible, avec le plan
   de migrations et données. Pas de rollback SQL automatique ni de restauration
   générale pour corriger un seul tenant. Faire décider et tracer l’action.
5. Vérifier le parcours initial et un tenant témoin autorisé, les droits et les
   travaux différés. Proposer la sortie après deux contrôles réussis espacés de
   cinq minutes, sans nouvelle anomalie ; prolonger si le scénario l’exige.

### F2 — Fuite suspectée, mauvais tenant ou compte compromis

1. Classer S1, prévenir coordinateur et sécurité, conserver la corrélation et les
   faits minimaux. Ne pas explorer d’autres dossiers pour « confirmer » la fuite.
2. L’opérateur prépare une contention ciblée : révocation de session/compte ou
   accès concerné via les mécanismes d’identité existants, isolement du composant
   par l’exploitation. La sécurité et le coordinateur tracent cible et décision.
   Un incident ne justifie ni un rôle superadmin improvisé ni un accès métier support.
3. Préserver les traces avant rotation/expiration lorsque possible sans prolonger
   l’exposition. Si un secret est compromis, préparer son renouvellement et
   l’invalidation des accès associés avec l’exploitant ; ne pas changer `APP_KEY`
   sans plan pour les données chiffrées et les clés de sauvegarde distinctes.
4. Vérifier que les accès révoqués échouent et que le tenant témoin reste intact.
   La remise en service ne clôt pas l’analyse de l’exposition ni les suites CAD-004.

### F3 — Dépendance ou intégration en panne

1. Identifier la dépendance effectivement configurée : envoi de mail, stockage,
   Redis/Reverb ou ClamAV. Ne pas supposer l’existence d’un webhook ou service
   externe simplement parce qu’il est prévu dans le backlog.
2. Distinguer réseau, authentification, quota, signature périmée et refus métier.
   Corréler les erreurs avec le fournisseur sans lui transmettre de données privées.
3. Maintenir le refus sûr : panne du scanner ≠ fichier propre ; absence d’accusé
   externe ≠ échec certain de l’envoi. Suspendre les relances manuelles tant que
   les effets précédents et l’idempotence ne sont pas établis.
4. Après correction/configuration approuvée, vérifier un témoin fictif adapté puis
   un seul travail autorisé. Passer à F4 pour une reprise bornée. Sortie : effet
   confirmé une fois, permissions inchangées, file suivie et prochain point fixé.

### F4 — File bloquée ou travail échoué

1. Identifier connexion, queue, job et tenant ; regarder âge du plus ancien travail
   admissible, progression, erreurs et état du superviseur. Les files locales sont
   `critical,default,notifications,broadcasts,reports` ; le scanner utilise `reports`.
2. Les diagnostics ci-dessous sont à lancer depuis `back/` uniquement sur
   l’environnement autorisé. `horizon:status` concerne une installation Horizon/Redis ;
   `schedule:list` liste les tâches enregistrées, sans prouver que le cron tourne.

   ```text
   php artisan queue:failed
   php artisan horizon:status
   php artisan schedule:list
   ```

3. Relever la cause avant toute relance : acteur/tenant révoqué, conflit métier,
   worker arrêté, dépendance indisponible ou verrou encore légitime. Un refus
   d’autorisation après révocation doit rester refusé ; ne pas réattribuer de droits.
4. Après correction et accord ciblé, l’opérateur peut utiliser
   `php artisan queue:retry UUID_DU_JOB_VERIFIE` pour un seul job échoué identifié.
   Cette commande remet en file et retire l’entrée de la liste des échecs : conserver
   la preuve nécessaire avant relance. Le worker relit les droits actuels ; conserver
   les identifiants métier et vérifier l’absence d’effet externe déjà produit.
5. Un redémarrage via `php artisan queue:restart` affecte les workers partageant
   le marqueur de redémarrage ; le faire seulement dans une fenêtre/périmètre
   approuvés avec superviseur prêt à les relancer. Vérifier séparément Horizon
   s’il est utilisé. Ne pas effacer verrous, files ou caches globalement, ne pas
   utiliser `queue:retry all`, et ne pas renommer une queue pour contourner un refus.
6. Sortie : travail abouti ou refus métier expliqué, pas de doublon, progression
   du tenant affecté et maintien du tenant témoin. Une file vide seule ne suffit pas.

### F5 — Restauration ou récupération d’un tenant

1. Identifier perte suspectée, tenant, instant cible, modifications plus récentes,
   sauvegardes et clés disponibles. Geler la décision d’écriture jusqu’à revue
   de l’intégrité ; ne pas réactiver une organisation clôturée pour réimporter.
2. Appliquer les prérequis et limites des [exercices OPS-004/005](../README.md#consolidation-locale-ops-005--lots-1-à-4).
   Les scripts de laboratoire créent leurs propres clusters fictifs : ils ne sont
   pas des commandes de restauration de production ni une qualification de toutes
   les tables et de tous leurs états.
3. Préparer en espace isolé une source vérifiée et un plan de réconciliation sans
   écriture, incluant conflits, dépendances, gels/effacements et données récentes.
   Sans outil/plan qualifié sur l’hébergement, escalader et conserver la source ;
   aucune recette de SQL manuel ou réimport global n’est autorisée par cette fiche.
4. Avant une future application autorisée, faire revoir plan, sauvegarde de la
   cible, reprise après interruption, clés et barrière d’écriture. Après application,
   vérifier intégrité, audit, fichiers, absence de doublon et données du tenant témoin.
   Ne pas annoncer de RPO/RTO tant que l’infrastructure représentative n’est pas testée.

### F6 — Fichier suspect ou scanner indisponible

1. Relever tenant, version, dernier scan, politique, motif et corrélation, sans
   télécharger ni ouvrir le document suspect sur le poste de support.
2. Conserver la quarantaine logique : fichier privé chiffré, accès bloqué si le
   verdict est rejeté, échoué, périmé ou d’une ancienne politique. Un nouveau scan
   invalide les liens antérieurs ; ni suppression automatique ni verdict clean manuel.
3. En cas de panne, appliquer F3/F4 et vérifier signatures actuelles, configuration,
   réseau privé et worker. Après correction, un acteur ayant `administrative.write`
   peut demander une nouvelle analyse confirmée avec le `expected_scan_id` actuel.
4. Un malware détecté reste bloqué ; faire décider les suites par la sécurité.
   Sortie technique uniquement après le nouveau verdict concluant sur la politique
   courante et contrôle des téléchargements/autorisations ; conserver l’historique.

### F7 — Accès exceptionnel de support

1. Identifier incident, tenant, opérateur déjà habilité, motif, actions strictement
   nécessaires et durée. Session authentifiée, e-mail vérifié et MFA courant requis.
2. Employer l’[API support existante](conventions-api.md#accès-plateforme-et-support--ucg-iam-005),
   avec cookies/CSRF/Origin du client autorisé, jamais un changement SQL de rôle.
   Exemple de requête à préparer, sans l’exécuter pendant l’exercice :

   ```http
   POST /api/v1/platform/tenants/{tenant}/support-accesses
   Content-Type: application/json

   {"reason":"INC-AAAA-MM-JJ-NNN : diagnostic autorisé","duration_minutes":10,"actions":["organization.inspect"],"confirm":true}
   ```

3. Le service accepte 1–30 minutes, au plus deux actions (`organization.inspect`,
   `access.inspect`) et cinq accès actifs par tenant/opérateur. Les diagnostics
   exposent statut/fuseau/langue ou compteurs d’habilitations, aucune fiche privée.
   Sans cette habilitation initiale, escalader ; l’incident n’autorise pas à la créer.
4. Expiration contrôlée à chaque requête, sans prolongation implicite. À la fin,
   révoquer son accès via DELETE sur la même route suivie de `/{access}`, vérifier
   le refus du diagnostic, relever les événements d’ouverture/lecture/révocation.
   La révocation/réattribution du rôle ne doit pas ressusciter un ancien accès.

## 5. Clôture et préparation avant données réelles

Le coordinateur et le vérificateur consignent : cause confirmée ou incertitude
résiduelle, périmètre réel, actions/correctifs et révision, preuves de rétablissement,
absence d’effets inter-tenant ou doublons, sort des travaux différés et fichiers,
révocation des accès temporaires, messages approuvés et suites sécurité/données.
Créer des actions suivies avec responsable, échéance proposée et preuve attendue ;
un risque non résolu empêche la clôture ou reste explicitement porté par un suivi
accepté par le responsable compétent. Fixer une revue après S1/S2, cible proposée
dans les cinq jours ouvrés, sans effacer les traces initiales.

Avant exploitation réelle : nommer les titulaires/suppléants, éprouver le canal de
secours, adopter niveaux/délais, définir l’espace de preuve et sa conservation,
qualifier monitoring et permissions de l’hébergement, les interruptions/reprises,
les sauvegardes/clés et les contacts des prestataires. OPS-008 reste ouvert sur ces
validations organisationnelles et d’hébergement ; le dossier local est livré.

## 6. Exercices fictifs du 26 octobre 2026

Exercice sur table effectué par l’agent en confrontant chaque décision aux sources
du dépôt, sans participants UCG, panne injectée, envoi de message ou intervention
réelle. Alpha/Beta désignent des tenants fictifs. Les résultats sont une revue de
cohérence, pas une répétition d’astreinte ni une mesure de disponibilité.

| Référence fictive / inject | Décision et critère vérifiés | Résultat / limite |
| --- | --- | --- |
| EX-01 : HTTP 200 `operational`, mais parcours Alpha échoué depuis 6 min | F1/S2, élargir le diagnostic, ne pas clôturer sur le statut statique ; comparer Beta seulement avec autorisation | Cohérent avec SystemStatusController ; dépendances de l’hébergement non sondées |
| EX-02 : une personne signale des données Beta sur Alpha | F2/S1 immédiat, préserver corrélation, contenir sans explorer Beta, sécurité et coordinateur, aucune purge de preuve | Décision sur table conforme aux barrières tenant ; aucun incident réel reproduit |
| EX-03 : fournisseur mail sans accusé après timeout | F3/S2 si seuil atteint, vérifier l’effet distant avant retry, pas de promesse de livraison | Cohérent ; fournisseur réel et idempotence de chaque envoi restent à qualifier |
| EX-04 : job Alpha ancien, acteur révoqué entre mise en file et reprise | F4, garder le refus actuel, préserver la preuve d’échec, aucun retry global ni droit réattribué ; Beta continue | Relecture des droits vérifiée par TenantJobRunnerTest ; pas de panne Redis réelle simulée |
| EX-05 : fichier antérieurement clean puis rescan négatif / scanner en panne | F6 puis F3/F4 si nécessaire ; bloquer ancien lien et nouveau téléchargement, garder le chiffré et l’historique | Couvert par ContractDocumentSecurityControllerTest ; CI ClamAV de 50e4b37 validée par l’utilisateur |
| EX-06 : sauvegarde Alpha ancienne, changements Beta plus récents, clé manquante | F5, arrêter avant écriture, ne pas restaurer globalement ni recréer la clé ; escalader | Revue des prérequis OPS-004/005 ; aucune restauration relancée pendant cet exercice |
| EX-07 : support demande une fiche privée puis tente un accès expiré | F7, refuser le périmètre métier, respecter expiration/révocation, pas d’extension ni de SQL direct | Couvert par PlatformSupportAccessTest ; aucune ouverture d’accès réel |
| EX-08 : coordinateur/contact non désigné et canal habituel indisponible | Revenir au préalable organisationnel, garder l’état protégé, ne pas inventer de destinataire ou considérer l’alerte transmise | Écart explicite avant exploitation : désignation et canal de secours à valider |

Les contrôles techniques locaux et leurs résultats sont consignés dans
[l’état du projet](etat-projet.md). Aucun délai de prise en charge, message,
RPO/RTO ou intervention en production n’a été mesuré pendant ces exercices.

## OPS-003 — Traiter les résultats du diagnostic

Le diagnostic `php artisan ops:inspect-runtime --probe --json`, depuis `back`, est
réservé à un opérateur déjà habilité sur le serveur. Sans `--probe`, il ne lit pas
les dépendances et retourne 2. Il ne remplace ni les parcours applicatifs autorisés,
ni une vérification des workers/scheduler. Le statut HTTP `operational` est statique.
Le [README](../README.md#ucg-ops-003--premiers-diagnostics-opérationnels) décrit
les données lues et les limites exactes.

L’utilisateur assure actuellement les accès utilisateurs et le premier support.
L’opérateur infrastructure, le coordinateur d’incident, leurs suppléants et les
canaux d’alerte/secours restent à désigner avant exploitation réelle. Les rôles
ci-dessous sont des responsabilités requises, pas des personnes déjà affectées.

| Code / groupe | Investigation et procédure | Rôle requis |
| --- | --- | --- |
| `DATABASE_READ_FAILED`, `CACHE_READ_FAILED` | F1/F3 : comparer périmètre et dernières livraisons, vérifier la dépendance et ses accès via les outils serveur autorisés. Le rapport masque volontairement la cause brute ; aucun identifiant ou DSN dans le ticket | Opérateur infrastructure + coordinateur selon gravité |
| `QUEUES_READ_FAILED`, `FAILED_JOBS_READ_FAILED` | F4 : vérifier connexion et schéma du stockage de queue configuré ; conserver les travaux, ne pas lancer de migration/réparation depuis le diagnostic | Opérateur infrastructure |
| `QUEUE_READY_WAIT_REVIEW` | F4 : job disponible observé depuis au moins le seuil configuré (300 s par défaut). Vérifier workers, dépendances, impact et droits actuels avant une reprise ciblée autorisée. Ni retry global, ni réattribution de droits | Opérateur infrastructure + responsable du traitement concerné |
| `FAILED_JOBS_HISTORY_REVIEW` | F4 : constater un historique sur la connexion configurée, déterminer dates et état courant avec l’accès adéquat. Ne pas déduire une panne du seul compteur, ni publier payloads/exceptions. Préserver les preuves | Opérateur infrastructure + support selon impact |
| `PRIVATE_STORAGE_READ_FAILED`, `PRIVATE_STORAGE_ROOT_INVALID`, `PRIVATE_STORAGE_ROOT_UNREADABLE`, `PRIVATE_STORAGE_PUBLIC_ROOT` | F6/F3 : vérifier racine, montage, exposition et configuration par accès serveur autorisé. Un emplacement/une visibilité publics exigent une revue des accès et, selon exposition confirmée, F2 ; aucune copie ou lecture de documents pour ce diagnostic | Opérateur infrastructure + sécurité si exposition |
| `MONITORING_CONFIGURATION_INVALID` | Corriger les deux limites numériques dans la configuration revue ; le diagnostic refuse toutes les lectures tant que ces limites sont invalides | Mainteneur de l’application |
| `PROBE_NOT_REQUESTED`, `*_DRIVER_UNSUPPORTED`, `CACHE_READ_INCONCLUSIVE`, `QUEUE_TOTALS_ONLY`, `QUEUE_SAMPLE_TRUNCATED` | Résultat incomplet : documenter la limite et prévoir la vérification manquante autorisée. Ne pas clôturer un incident ni acquitter une alerte sur ce seul résultat | Opérateur infrastructure + coordinateur selon incident |

Les codes `*_PASSED` confirment seulement les lectures décrites dans le README.
Le code de sortie 1 prime sur 2 si un problème est observé malgré des contrôles
incomplets. Le rapport conserve `supervision_validated=false` et ne transmet aucune
alerte ; son exécution manuelle n’autorise pas à déclarer un canal opérationnel.
Consigner heure, révision applicative, environnement connu par l’opérateur, code
fixe, portée/troncature et référence de l’incident dans l’espace restreint prévu.
Ne pas recopier la configuration, les corps de jobs ou les exceptions brutes.

Avant de brancher une alerte automatique : désigner destinataire/suppléant, adopter
les seuils selon les parcours, qualifier fréquence, délais réseau/SQL et charge,
définir déduplication/acquittement/escalade et conservation des métriques ; injecter
ensuite une panne fictive sur une cible de test autorisée et mesurer réception,
prise en charge et retour au nominal. Ces validations restent à réaliser après
disponibilité de l’hébergement. Aucun message ni changement de service n’a été
effectué par ce lot.
