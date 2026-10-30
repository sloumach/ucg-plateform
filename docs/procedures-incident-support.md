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

## OPS-003-BG — Activité et travaux différés

Exécuter `php artisan ops:inspect-background --probe --json` depuis `back`, avec
un accès serveur autorisé. Le suivi doit être activé sur une cible déjà qualifiée
avec un cache partagé database/redis et un namespace isolé. Aucun destinataire
n’est désigné par défaut : les `alert_candidates` sont des propositions locales,
pas des alertes transmises ou acquittées. Le rôle d’exploitation et son suppléant
restent à nommer ; l’utilisateur assure les accès utilisateurs et le premier support.

| Code | Vérification puis action à autoriser selon impact |
| --- | --- |
| `BACKGROUND_MONITORING_DISABLED`, `PROBE_NOT_REQUESTED` | Aucun diagnostic réalisé. Vérifier les prérequis d’activation ; ne pas activer le suivi sur une cible inconnue pour faire disparaître le code |
| `BACKGROUND_CONFIGURATION_INVALID` | Vérifier namespace, cache partagé, connexion de queue par défaut, liste des queues attendues et bornes numériques ; aucune ressource n’est lue après ce refus |
| `BACKGROUND_HEARTBEAT_MISSING` | Vérifier première activation, namespace/préfixe/store communs, disponibilité du cache, démarrage des workers et du scheduler ; un cache vidé produit aussi ce résultat |
| `BACKGROUND_ACTIVITY_STALE` | F4/F3 : vérifier processus, maintenance/pause, job long, équité des queues et horloges. Le seuil proposé est 180 s ; un marqueur ancien ne prouve pas un arrêt. Un autre worker de la même queue peut masquer l’arrêt d’un processus |
| `BACKGROUND_HEARTBEAT_INVALID` | Vérifier dérive des horloges et intégrité de la configuration/cache, préserver la preuve minimale. Ne pas publier la valeur brute ni forcer un nouveau marqueur pour acquitter |
| `BACKGROUND_HEARTBEAT_READ_FAILED`, journal `ops.background.marker_unavailable` / `BACKGROUND_HEARTBEAT_WRITE_FAILED` | F3 : vérifier la dépendance de monitoring et ses timeouts. L’écriture est contenue pour préserver les traitements ; son absence ne valide pas leur état. Aucun message/DSN d’exception n’est exposé par le composant |
| `BACKGROUND_WORK_REVIEW` | F4 : compteurs observés d’imports queued/processing trop anciens, imports failed, livraisons pending/sending anciennes ou uncertain. Vérifier dates, droits actuels, gel/inactivité du tenant et effet distant via les accès autorisés avant toute reprise ciblée. Un état incertain ne doit jamais provoquer de renvoi automatique |
| `BACKGROUND_WORK_SAMPLE_TRUNCATED` | Données partielles, sans total fiable. Préparer une investigation paginée/scopée autorisée ; ne pas augmenter arbitrairement le plafond ni clôturer sur cet échantillon |
| `BACKGROUND_WORK_READ_FAILED` | Vérifier connexion et schéma de la source concernée par accès serveur autorisé ; préserver les données, sans lancer de migration ou de réparation depuis le diagnostic |

Les codes `BACKGROUND_ACTIVITY_OBSERVED` et `BACKGROUND_WORK_METADATA_PASSED`
ne valident que la lecture et le passage observé. Les timestamps de travaux sont
des dernières modifications, pas une preuve de progression, de livraison ou de
lecture humaine. Les rappels non matérialisés et les échéances de compétition
nécessitent leur propre parcours métier autorisé. Le statut `blocked` n’est pas
un motif de rétablissement de droits ni de reprise forcée.

Exercice local réalisé : worker database daemon sur file vide, callback scheduler,
avance d’horloge contrôlée, signaux absents/anciens/futurs, cache indisponible,
imports et rappels fictifs, échantillon tronqué. Ce test ne constitue pas un essai
Redis/Horizon, cron/process manager, SMTP ou canal d’alerte sur l’hébergement.
Avant raccordement : adopter seuils/fréquence et timeouts, vérifier la charge,
nommer responsable/suppléant, choisir canal et espace de preuve, puis implémenter
et tester déduplication persistante, réception, acquittement, résolution et
escalade. Conserver seulement heure, révision, code, compteurs/portée et référence
d’incident dans l’espace restreint ; jamais les corps d’imports/rappels.

## OPS-003-FINAL — Recette et cycle des alertes

Le diagnostic serveur agrégé est `ops:inspect-supervision`. `--probe` autorise
les lectures, `--external` les sondes distantes et `--record-alerts` l’écriture
transactionnelle du registre, après activation et migration revues. La commande
sans `--probe` ne sonde rien. Le [README](../README.md#ops-003--préparation-locale-complète-et-registre-dalertes)
décrit données, seuils, limites et exemples d’acquittement.

### Qualification des signaux et actions

| Signal | Investigation / décision | Responsabilité requise |
| --- | --- | --- |
| `API_ERROR_OR_LATENCY_REVIEW` | F1/F3 : rapprocher fenêtre/minimum d’observations, derniers changements et request IDs du journal expurgé. Vérifier parcours autorisé et proxy séparément ; aucun P95 ou capacité déduit de ces buckets | Exploitant et mainteneur application |
| `API_SAMPLES_INSUFFICIENT`, `API_COUNTER_INCOMPLETE`, `API_TELEMETRY_DISABLED` | Vérifier activation/namespace/store, couverture de collecte et éventuelles évictions. Un manque d’observations n’est pas un retour au nominal | Exploitant |
| `API_COUNTER_INVALID`, `API_COUNTER_INCONSISTENT`, `API_METRICS_READ_FAILED` | Vérifier configuration, cache et horloges ; conserver uniquement code/compteurs, sans contenu de cache ou exception | Exploitant et mainteneur |
| `REALTIME_CONNECTIONS_READ_PASSED` | Compteur agrégé seulement ; tester émission/réception et refus inter-tenant avec deux comptes fictifs autorisés avant d’adopter un critère de disponibilité | Exploitant temps réel et vérificateur métier |
| `*_PROBE_FAILED`, `*_UNREACHABLE`, `DEPENDENCY_CONFIGURATION_INVALID` | F3 : identifier la dépendance/configuration concernée avec accès serveur habilité, vérifier délais/transport. Le diagnostic masque les messages bruts et ne redémarre rien | Exploitant ; fournisseur selon cause confirmée |
| `MAIL_PROTOCOL_OBSERVED`, `SCANNER_PROTOCOL_OBSERVED`, `STORAGE_HEAD_OBSERVED` | Sonde partielle : qualifier respectivement livraison/TLS, signatures/analyse réelle et ACL/écriture/capacité. Ces résultats ne résolvent pas une alerte existante | Exploitant spécialisé et vérificateur |
| `REMINDER_SWEEP_REVIEW` | F4 : vérifier activation du cycle, ancienneté du dernier contrôle, volume et contexte du tenant. Les contrôles utilisent les métadonnées ; vérifier droits/éligibilité par parcours autorisé avant toute reprise. Ne pas forcer la création d’un rappel | Exploitant et responsable métier |
| `ALERT_VERSION_CONFLICT` | Relire l’entrée et son nouvel état ; refaire une décision humaine avec la version courante. Ne pas boucler automatiquement sur une confirmation obsolète | Opérateur chargé de l’incident |
| `ALERT_LEDGER_WRITE_FAILED`, `ALERT_ACTION_FAILED` | Préserver le rapport expurgé dans l’espace restreint et vérifier migration/connexion du registre. Ne pas affirmer qu’une alerte a été enregistrée ou acquittée après cet échec | Exploitant et coordinateur |

Une alerte est unique par namespace/composant. La revue répétée actualise ses
observations ; un changement de cause/sévérité exige une nouvelle prise en charge.
L’acquittement référence un incident UUID, avec version et confirmation explicites.
Le registre ne prétend pas authentifier l’humain par cette référence : l’accès
serveur et son audit doivent être attribués. Seul un contrôle concluant du composant
résout automatiquement l’épisode ; aucun bouton de fermeture forcée n’est ajouté.
L’escalade locale intervient une fois après le seuil proposé si l’alerte reste
ouverte non acquittée. Acquittée, elle reste à suivre jusqu’à résolution ; une cause
différente relance la prise en charge. La transmission et les délais de suivi
humain seront définis avec le canal choisi. Aucun de ces événements ne prouve
qu’un message externe a été reçu.

### Matrice locale et recette à réaliser sur cible autorisée

Les essais locaux emploient données fictives, cache/base de tests, doubles de
transport et horloge contrôlée. La colonne de droite décrit les preuves encore
requises sur l’hébergement ; aucun incident réel ni test de charge n’a été lancé.

| Référence | Preuve locale préparée | Preuve externe attendue |
| --- | --- | --- |
| S3-01 API | Réponse conservée, compteur 5xx/durée, seuil/minimum, budget de logs, filtre final anti-contexte privé | Parcours avec proxy et flux, couverture de collecte, latence et coût cache représentatifs, rotation/accès aux journaux |
| S3-02 Collecte indisponible | Panne de télémétrie ne remplace pas la réponse ; bucket incomplet non concluant | Cache interrompu puis rétabli sur cible isolée, observations manquantes et coût des timeouts mesurés |
| S3-03 Workers/scheduler | Daemon vide et callback exécutés, marqueurs absents/anciens/futurs, namespace séparé | Arrêt contrôlé d’un worker, pause/job long et scheduler absent ; détection et retour observés avec horloges synchronisées |
| S3-04 Rappels/imports | Métadonnées bornées, cycles jamais contrôlés, traitements réels fictifs créant rappels contrats/compétitions, droits métier conservés | Parcours complet des imports/rappels, refus après révocation, absence de doublon et réception attendue sur cible de test |
| S3-05 Reverb | Réponse agrégée et panne simulées, exceptions expurgées | Compteur rapproché de clients fictifs, réception de messages et refus de canaux privés/inter-tenant |
| S3-06 SMTP/scanner | Bannière/PING partiels et échec simulés, aucun envoi | Livraison d’un message fictif convenu, politique TLS, verdicts scanner clean/rejet/panne et fraîcheur de signatures |
| S3-07 Stockage | Racine publique rejetée, HEAD de clé absente simulé, aucun document lu/écrit | ACL privée et refus non autorisé, fichier fictif dédié, espace/capacité, chiffrement et cohérence avec restauration OPS-004 |
| S3-08 Registre | Déduplication, journal immuable, acquittement versionné, signal d’escalade, résolution et réouverture transactionnelles | Concurrence PostgreSQL, migrations et accès serveur audités, sauvegarde du registre, conservation adoptée |
| S3-09 Canal | `alert_dispatched=false`, aucun e-mail/notification issu des diagnostics | Destinataire et suppléant désignés, transport choisi puis raccordé ; panne simulée, message reçu, acquittement humain, résolution et escalade effectivement reçus |
| S3-10 Reprise | Rapports et procédure de retour préparés, aucun redémarrage implicite | Retour nominal mesuré, absence d’effet inter-tenant, preuves minimales conservées et accord de clôture |

Fiche de recette à renseigner dans l’espace opérateur restreint : cible/namespace
de test, révision livrée et migration, date/fuseau, opérateur/vérificateur nommés,
seuils/fréquence/timeouts adoptés, scénario, résultat attendu, mesures avant/pendant/
après, référence d’incident et d’alerte, preuve de réception/acquittement/escalade,
actions réalisées et accord de retour nominal. Les identités et adresses restent
dans cet espace approuvé, jamais dans les métriques ou des fixtures commitées.

L’utilisateur assure toujours les accès et le premier support. Exploitant,
suppléant, coordinateur, canal et espace de preuve doivent être désignés avant
recette. L’hébergement reste reporté : **S3-01 à S3-10 côté externe restent en
attente**, OPS-003 n’est pas clôturé et aucun destinataire n’est supposé joignable.

## OPS-004-FINAL — Recette des sauvegardes hébergées

L’hébergement est reporté. Cette recette est préparée, sans exécution externe ni
activation de sauvegarde réelle. L’utilisateur assure les accès et le premier
support ; exploitant sauvegardes, suppléant et détenteurs des clés restent à
désigner. Le laboratoire npm `test:recovery:pitr` utilise uniquement des données
fictives et des clusters jetables ; ne jamais le diriger vers une base utilisée.

| Lot | Preuve locale disponible | Recette hébergée attendue |
| --- | --- | --- |
| PITR | Base native vérifiée, WAL contigus, instant avant incident, comparaison base/fichiers | Restauration sur cible autorisée isolée, charge représentative, stockage fichiers versionné et cohérent, rôles/ACL/extensions et compatibilité majeures |
| Protection | Chiffrement authentifié, copie locale vérifiée, clés séparées, plan sans suppression | Compte/site indépendants, accès minimum et audit, immutabilité, rotation/restitution des clés et APP_KEY, conservation adoptée, destruction contrôlée des seules générations autorisées |
| Fiabilité | Reprise entre objets, manifeste en dernier, refus corruption/chaîne absente, redémarrage DB | Coupure réseau/processus/hôte, espace plein, perte de site, restauration avec source indisponible, détection des trous WAL et réplication du coffre |
| Recette | Rapport local, lecteur de reçus signé et alertes testées | Producteur/collecteur de reçus raccordés au fournisseur, réception humaine des alertes, fréquence adoptée, RPO/RTO mesurés et accord signé |

Avant toute manipulation : identifier cible et namespace, accord d’intervention,
révision applicative/PG, volumes, chaîne complète et chronologie UTC ; vérifier
les permissions, la disponibilité séparée des clés et un retour arrière. Une
restauration complète utilise une cible vide isolée ; aucune écriture sur un
tenant voisin ni écrasement d’une base active. Pour un tenant seul, appliquer
OPS-005 et la réconciliation OPS-007 après restauration avant toute réouverture.

Codes d’alerte : BACKUP_EVIDENCE_PATH_REFUSED/READ_FAILED, vérifier montage privé,
droits et configuration sans afficher clés ou payload ; SIGNATURE_INVALID,
isoler le reçu et vérifier son producteur, ne pas le resigner pour masquer
l’incident ; BACKUP_OR_WAL_STALE, inspecter dernière sauvegarde et archivage,
espace/permissions/connectivité puis restaurabilité de la chaîne ;
BACKUP_EVIDENCE_INCOMPLETE, considérer la génération indisponible ;
BACKUP_RESTORE_DRILL_REQUIRED, programmer un nouvel essai isolé.
Les codes complets portent le préfixe BACKUP_EVIDENCE_ quand applicable.

Un fragment de génération inachevée ne devient jamais une sauvegarde publiée.
Préserver les éléments de diagnostic et les générations vérifiées ; aucune purge
automatique ni suppression de données personnelles. Le plan local garde au moins
deux générations et tous les gels/copies non vérifiées. Définir séparément la
politique réelle des sauvegardes avec OPS-007/CAD-004 avant activation.

Le reçu HMAC est une déclaration du producteur, pas une vérification indépendante
du fournisseur. Un reçu local reste non qualifiant ; un reçu hébergé frais peut
résoudre l’alerte du reçu mais ne prouve pas la réussite de cette recette. Prévoir
protection contre rejeu, contrôle indépendant du catalogue et chaîne WAL, rotation
des clés de signature et preuve de réception/escalade avec OPS-003.

Fiche restreinte à conserver : opérateur/vérificateur, cible/site/comptes, révision,
date UTC, identifiants de sauvegarde et de clés sans secrets, instant demandé et
atteint, dernier commit récupérable, durée complète jusqu’au service utilisable,
volumes/charge, contrôle fichiers/déchiffrement/isolation/rôles, incidents injectés,
alertes reçues/acquittées, seuils adoptés et accord de retour nominal. Ne clôturer
OPS-004 qu’après mesures RPO/RTO, exercice distant et validation de ces preuves.
