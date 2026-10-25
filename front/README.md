# Interface UCG

Application React 19 et TypeScript de la plateforme UCG, construite avec Vite et
Tailwind CSS 4.

## Conventions d’interface

- `src/components/` contient les composants accessibles communs.
- `src/components/feedback/` sépare les alertes persistantes des notifications
  temporaires.
- `src/components/data/` fournit les tableaux et la pagination réutilisables.
- `src/i18n/fr.ts` est le catalogue français des textes appartenant au client.
- `src/api/errors.ts` transforme les erreurs API en `ApiClientError` sans perdre
  les erreurs de champ ni `meta.request_id`.

Les erreurs de validation sont affichées près des champs et le premier champ
invalide reçoit le focus. Les erreurs importantes restent visibles dans une
alerte. Les réponses API qui contiennent `notification` alimentent le système de
notifications partagé ; les notifications d’erreur et d’avertissement restent
affichées jusqu’à leur fermeture.

Le frontend utilise le `code` stable d’une erreur pour son comportement et le
`message` localisé pour l’affichage. Il ne déduit jamais une règle à partir du
texte. React échappe les messages affichés et la référence de support provient
uniquement de `meta.request_id`.

## Commandes

```powershell
npm run dev
npm run lint
npm test
npm run build
npm run test:e2e
```

Le test Playwright démarre automatiquement Laravel et Vite et vérifie notamment
le parcours de connexion ainsi que son ordre de navigation au clavier.

### Mesures de charge locales — UCG-QA-005

Depuis `front/`, avec PHP/PDO PostgreSQL, les dépendances du dépôt et les binaires
PostgreSQL 17 accessibles dans PATH :

```powershell
npm run test:load:harness
npm run test:load:smoke
npm run test:load:profile
npm run test:load:volume
npm run test:load:pressure
```

Si nécessaire, `UCG_BROWSER_PG_BIN` peut désigner uniquement un répertoire absolu
canonique de binaires PostgreSQL 17. Aucune URL, connexion de base existante ou
valeur de volume personnalisée n'est acceptée. Les quatre profils sont fixes et
doivent être exécutés successivement pour éviter de fausser les mesures.
Ils ne nécessitent ni navigateur ni serveur de développement déjà lancé.

| Profil | Données et activité |
| --- | --- |
| `smoke` | Trois comptes, 630 fiches, deux sessions, 100 lectures Beta par phase et trois exports. |
| `profile` | Même protocole ; agrégation des empreintes SQL, tables, appels et temps, sans SQL ni paramètres enregistrés. L'instrumentation supplémentaire interdit une comparaison directe des latences avec les autres profils. |
| `volume` | 500 comptes, 1 000 matchs et 100 000 parties jouées synthétiques ; 100 sessions distinctes sur quatre serveurs PHP. 50 lecteurs Beta au repos/après, 99 lecteurs Alpha/Beta et un acteur bruyant pendant la phase concurrente ; trois lectures par lecteur et par phase. |
| `pressure` | Même volume et 100 sessions que volume, avec trois imports de 1 000 lignes et trois exports de 500 lignes, en queue avant démarrage du worker. Vérification du refus d'un CSV de 1 001 lignes. |

Le peuplement volumineux garde les FK et déclencheurs actifs : participants créés
avant les transitions, règles et numéros de partie cohérents, mises à jour de
version avant chaque lot de scores. Les 100 000 parties sont des fixtures jouées
avec dates passées ; elles ne représentent pas 100 000 résultats approuvés via
le workflow métier. Les comptes lecteurs ordinaires ne reçoivent que sports.read ;
le MFA reste imposé aux acteurs privilégiés. Aucun limiteur n'est désactivé.

Les sessions sont distribuées sur le même pool HTTP pour les deux organisations.
Le pic de requêtes en attente est mesuré ; il ne signifie pas que quatre serveurs
PHP exécutent 100 requêtes simultanément. Les phases restent en boucle fermée,
sans débit d'arrivée imposé ni simulation réaliste de temps de réflexion.
Chaque lecteur des grands profils consulte personnes, matchs et une page des
parties d'un match commun à son tenant (point chaud explicite). Les identifiants,
totaux et tailles sont contrôlés sur chaque ressource, y compris les parties.
Un import agrandit normalement la liste de personnes Alpha : pendant pressure,
les lecteurs Alpha restent donc sur matchs/parties ; les listes Beta continuent
à être contrôlées strictement. Les créations Alpha sont vérifiées après les jobs.
Les exports sont générés avant ces créations ; leur état completed n'atteste pas
qu'ils seraient encore téléchargeables après invalidation de leur source.
Les 429 restent comptés comme erreurs et gardent leur latence. Le client attend
le Retry-After de 1 à 60 secondes avant son opération suivante ; il ne remplace
pas la requête refusée par une tentative réussie. Les phases complètes continuent
pour observer récupération et intégrité, mais ces refus empêchent la validation
de performance. Une réponse 200 au contenu invalide interrompt immédiatement
le protocole. Le débit réalisé inclut les attentes, les p95 HTTP les excluent.

En pression, exports et imports partagent la queue reports : les trois exports
sont placés avant les imports pour garder une source de 500 fiches stable, puis
les chunks d'import de 25 lignes alternent dans le même worker. Ce profil mesure
la concurrence HTTP avec la queue chargée ; il ne certifie pas plusieurs workers
traitant simultanément les deux types de job. Les imports doivent terminer et
créer exactement 3 000 fiches supplémentaires dans Alpha, sans modifier Beta/Gamma.

Le lanceur crée son cluster et ses fichiers privés dans un répertoire temporaire
avec marqueur de propriété, vérifie le serveur réel avant bootstrap, exige un
schéma vide, puis applique les migrations uniquement à cette nouvelle base.
Un second peuplement est volontairement refusé et son absence d'effet vérifiée.
Le `.env` du projet et ses identifiants ne sont pas transmis aux processus.

Jeu fictif du profil smoke : trois comptes, trois organisations, 500 fiches Alpha, 120 Beta et
10 Gamma. Deux sessions distinctes se connectent réellement avec CSRF et MFA.
Après cinq lectures de chauffe, Beta effectue 100 lectures avant, 100 pendant
et 100 après l'activité d'Alpha, avec des pages alternées de 25 et 100 fiches.
Alpha lit ses pages en boucle (300 requêtes maximum) et trois exports réels de
500 lignes sont générés par un worker database. Le protocole exige le recouvrement
des 100 lectures sous charge avec l'activité HTTP d'Alpha ; il ne suppose pas que
les trois jobs occupent toute cette période.

Chaque réponse mesurée doit contenir les identifiants attendus, le bon total et
la taille paginée correcte. Les erreurs HTTP, de transport ou de contenu sont
conservées comme erreurs ; une phase incomplète ne passe pas. Les empreintes des
fiches et les compteurs d'exports/artefacts Beta et Gamma sont comparés avant/après,
avec contrôle des exports Alpha et de l'absence de jobs échoués ou encore en queue.

Rapport local ignoré par Git : `reports/load-<profil>-<horodatage>.json`, avec
échantillons, p50/p95/max globaux, p95 par taille de page, statuts, nombres de
requêtes SQL, temps SQL/bootstrap/traitement, écarts de p95, environnement,
commit de base, état du checkout, empreintes du lanceur et résultat du nettoyage.
Les profils volumineux alternent aussi les listes de matchs ; les latences et
requêtes sont ventilées par ressource et taille de page, ainsi que par tenant.
Le profil SQL conserve les noms des tables et les emplacements des appels ;
la première requête peut inclure l'ouverture de connexion et son temps n'est
donc pas assimilé au seul temps d'exécution PostgreSQL.
Les centiles utilisent le rang supérieur (`ceil(n × p)`), erreurs comprises.
Les compteurs SQL excluent le bootstrap, la preuve de propriété et la terminaison ;
aucun SQL, binding, cookie ni corps de réponse privé n'est conservé dans ce rapport.
Le temps SQL est inclus dans le traitement HTTP ; les p95 des sous-temps ne
s'additionnent pas.

Le code de sortie est non nul en cas d'erreur, nettoyage non confirmé, ou p95
atteignant 500 ms dans une phase ou une combinaison ressource/taille. `completed=true` signifie
que le protocole est terminé, pas que l'objectif de performance est atteint :
lire aussi `interactiveTarget.metInAllPhases` et `cleanup`.
Une interruption est déclenchée après cinq minutes (smoke/profile) ou quinze
minutes (volume/pressure) ; les délais bornés de fin
de requête et de nettoyage peuvent s'y ajouter. Après arrêt confirmé de ses serveurs,
worker et cluster, le lanceur supprime son seul répertoire ; en cas de doute,
il conserve les ressources et leur chemin pour diagnostic. Un arrêt brutal du
système ou SIGKILL reste hors garantie de nettoyage.

Les mesures incluent les contrôles de propriété et le démarrage Laravel sur des
serveurs PHP de développement partageant le poste avec le générateur. Ces essais
ne qualifient pas l'hébergement ni REC-34. La CI exécute les neuf tests de garde-fous,
sans seuil de latence sur runner partagé.

### Recette finale sur infrastructure représentative — à exécuter

L'hébergement reste différé sur décision utilisateur du 25 octobre 2026. Le
lanceur local refuse les services distants : il ne faut pas supprimer ses
garde-fous pour viser une base ou un site existant. Avant recette finale, préparer
un environnement séparé, les données fictives et un générateur de charge autorisé
adapté au serveur web, aux workers, au cache et au temps réel réellement déployés.

| Vérification finale | Preuve attendue |
| --- | --- |
| Topologie et générateur séparé | Configuration, versions, ressources, limites et supervision enregistrées ; aucune donnée réelle. |
| Activité interne | 500 comptes, 100 actifs, 100 000 parties ; mix de rôles/routes et débit convenus, montée progressive, maintien puis récupération. |
| Tenant bruyant | Imports et exports avec workers représentatifs, concurrence et budgets réels ; p95/erreurs/débit de Beta, âge/profondeur de queue, mémoire/CPU/IO. |
| Scénarios publics | Identifier les endpoints effectivement livrés et le périmètre P0/P1 avant la charge de 2 000 visiteurs ; ne pas substituer une route de santé à une vitrine absente. |
| Rapport et seuils | p95 interactif < 500 ms par ressource, zéro fuite ou perte, refus de quota distingués des pannes ; p95 et ratio Beta avant/pendant/après, sans inventer un seuil de ratio. |
| Durée et livraison | Durée soutenue et profil d'arrivée convenus, rapports conservés, écarts analysés, décision de recette explicite. |

Les pages publiques sont planifiées au P1 dans le backlog et aucun module public
n'est livré ici. Leur charge n'est ni validée ni transformée implicitement en
critère satisfait. Rendu d'écran (2,5 s), propagation des scores (3 s), Redis/Horizon,
TLS/reverse proxy et longue durée restent à mesurer avec leurs outils adaptés.

## Fiches et profils — UCG-HUM-003

Le panneau Personnes apparaît dans l’organisation active selon les permissions
explicites. Il fournit recherche par début de pseudonyme, filtres/tri, pagination,
vues de fiches, création et modification, profils de jeu et comptes externes.
Les références disponibles proviennent du catalogue serveur ; les références
retirées restent consultables et archivables. Aucun accès implicite propriétaire.

Les panneaux sont remontés lors d’un changement d’organisation/révision/permissions,
les lectures sont annulées et les réponses obsolètes ignorées. Les formulaires
calculent les champs modifiés, transmettent la version et demandent confirmation
dans l’organisation affichée. Une modification de rang ou d’alias n’envoie pas
automatiquement l’ancienne date d’observation. La création sans droit de lecture
reste possible pour un auteur sportif, sans charger de données privées.

Tests : PeoplePanel.test.tsx couvre permissions, mutations, erreurs 422/409,
dates, références retirées et requêtes tardives. Les deux parcours people.spec.ts
utilisent des API métier simulées et vérifient aussi le changement de tenant et
le mobile 375/320 ; aucun profil réel n’est créé par les tests navigateur.

Si le Chromium de Playwright est absent mais Chrome est déjà installé :

```powershell
$env:PLAYWRIGHT_BROWSER_CHANNEL = 'chrome'
$env:PLAYWRIGHT_VIDEO = 'off' # seulement si FFmpeg Playwright est aussi absent
npm run test:e2e
```

Sans ces options, la CI conserve Chromium et les vidéos en cas d’échec.

### Cycle de vie des personnes — HUM-004

PersonLifecyclePanel affiche périodes datées, indisponibilité, checklist privée
et départ selon les permissions explicites. La checklist reste régularisable
après clôture. Le départ nécessite la confirmation de l’organisation et une
confirmation métier distincte, avec reconnaissance des éléments encore ouverts.
Le compte lié actuellement affiché est celui dont les accès seront retirés.
La réponse recharge les droits du tenant, y compris lors d’un départ de soi-même.
Statut et compte lié sont figés sur une fiche ayant un départ enregistré.

Les listes utilisent des pages de 10, versions optimistes et lectures annulables ;
les champs datetime-local sont normalisés avant comparaison des modifications.
API TypeScript : `src/api/personLifecycle.ts`. Les mutations transmettent CSRF,
révision tenant et uniquement les champs modifiés. Aucune donnée médicale collectée.

Validation totale : 65 tests Vitest, lint/build et 7 parcours Playwright verts.
PersonLifecyclePanel.test.tsx couvre permissions, confirmation, erreurs, fiche
clôturée et modifications partielles ; lifecycle.spec.ts exerce période, checklist,
départ et régularisation sur mobile 375/320 sans débordement global. Les API métier
sont simulées : aucune mutation réelle ni migration principale par les navigateurs.

### Import CSV privé — HUM-005

PersonImportsPanel est intégré à Personnes et exige les quatre droits explicites
sports.read/write et administrative.read/write. L’ouverture charge les imports du
tenant courant, par pages de 10. Dépôt multipart, mapping vers une liste fermée de
champs, defaults langue/fuseau/statut, prévisualisation et erreurs par ligne ;
aucun compte lié ou écrasement. Les valeurs reçues sont rendues comme texte échappé.

Chaque mutation conserve CSRF/révision et demande la confirmation de l’organisation ;
l’import exige aussi confirmation métier et reconnaissance des lignes refusées.
Après traitement, actualiser le suivi et les personnes. Reprise des seuls éléments
restants ; téléchargement du rapport via fetch avec session, aucune URL publique.
Le composant est remonté par tenant/révision, les lectures sont annulables et les
réponses tardives ignorées. API TypeScript : `src/api/personImports.ts`.

PersonImportsPanel.test.tsx couvre droits, fichier multipart, confirmation/annulation,
mapping, rendu échappé, conflits, pagination, rapport et réponses tardives. Le helper
tenancyRequest conserve le transport JSON existant et omet Content-Type pour le
multipart, afin que le navigateur ajoute sa boundary. Un rapport CSV retourne un
Blob, avec le même traitement des erreurs d’authentification/contexte.

imports.spec.ts couvre dépôt → preview → confirmation → rapport, mobile 375/320
sans débordement global. API métier simulées : aucune importation réelle dans le
navigateur. Validation frontend totale : 77 tests Vitest, lint/build et 8 parcours
Playwright verts.

### Références externes — TEAM-006

ExternalOpponentsPanel est intégré à TenantWorkspace et exige sports.read
organisation ; teams.manage ajoute création/édition/archivage/capture.
Source, date d’observation, nom public et pseudonyme sont rendus comme texte
échappé ; les URL ne sont pas suivies automatiquement. Les mutations demandent
confirmation du tenant et déclaration de faits publics autorisés, avec la version
courante. Seuls les champs modifiés sont envoyés, sans rafraîchir automatiquement
l’observation. Une archive reste lisible avec ses instantanés définitifs.

API TypeScript : src/api/externalOpponents.ts. Remontage par tenant/révision/droits,
lectures annulables, relecture après 409 et saisie conservée après 422.
ExternalOpponentsPanel.test.tsx couvre droits, rendu, confirmations, mutations,
conflits et réponses tardives. external-opponents.spec.ts couvre création → capture
→ renommage → archivage → historique conservé, mobile 375/320 et noms longs avec
police de repli. Les API métier sont simulées ; aucune donnée réelle créée.

### Contrats administratifs — CTR-001

ContractsPanel est intégré à TenantWorkspace avec remontage par tenant,
révision et permissions. Lecture administrative.read ; gestion aussi
administrative.write ; transitions de validation aussi administrative.validate.
Recherche par préfixe de référence, filtres type/statut, pages de 10, création
et édition partielle, sélection paginée du responsable et des parties People,
parties externes déclarées, dates et obligations.

Le détail distingue signature déclarée, période en vigueur et éligibilité
sportive non évaluée. L’approbation par le préparateur est masquée et refusée
côté serveur. Signature reçue hors plateforme : date, référence et déclaration
explicite ; aucune pièce jointe à ce stade. Historique paginé des statuts,
résiliation, régularisation des obligations puis archive définitive.

API TypeScript : src/api/contractRecords.ts. Confirmation de l’organisation
avant mutation, versions du dossier et de l’obligation, CSRF/révision,
notifications communes. Lectures annulables ; 422 conserve la saisie ;
409 impose relecture et nouvelle confirmation sans rejeu automatique.
Textes échappés, noms longs et police de repli vérifiés à 375/320 px.

12 tests ContractsPanel ajoutés ; 141 tests frontend au total dans 24 fichiers,
lint/build et 16 Playwright verts. contract-records.spec.ts exerce création →
revue → validation distincte → envoi → signature déclarée → activation →
résiliation → obligation levée → archive et historique. API métier simulées :
aucun contrat réel créé par les navigateurs.

### Documents et avenants — CTR-002

ContractDocumentsPanel est intégré au détail du contrat, sous les mêmes droits
administratifs et la même isolation tenant/révision. Ouverture à la demande,
documents et versions paginés par 10, original unique puis avenants liés.
Après succès, le parent recharge le dossier et sa version courante ; les
mutations suivantes utilisent cette version, sans rejeu automatique.

Création d’un document avec référence et titre ; dépôt d’un PDF en multipart
avec la version du contrat, distinction préparation/copie signée et confirmation
du tenant. Les PDF sont privés, en attente d’analyse et sans téléchargement.
Historique avec numéro, nom échappé, empreinte SHA-256 et nature de la version.
Identification définitive d’une copie signée : UUID choisi, date et déclaration
explicite. L’original signé est figé ; les évolutions passent par un avenant.

Préparation originale au brouillon/en revue ; signed_copy et identification
avec administrative.validate et contrat signé/actif/échu/résilié.
Archive et lecteur en consultation seule. Les avenants ne modifient pas
implicitement le statut ou les termes du contrat. Champs sensibles du fichier
et l’empreinte sont reçus du serveur, jamais fournis par le navigateur.

API TypeScript : src/api/contractDocuments.ts. usePeopleMutation réutilisé
pour confirmations, notifications et erreurs ; 422 conserve saisie/fichier ;
409 impose relecture/nouvelle confirmation. Lectures annulables, réponses
tardives ignorées, aucun lien public ou stockage navigateur permanent.

11 tests ContractDocumentsPanel ajoutés (152 frontend au total, 25 fichiers).
contract-documents.spec.ts couvre deux préparations → copie signée → liaison
définitive → avenant lié → dépôt → historique original conservé ; multipart,
révision, confirmations, textes échappés, mobile 375/320 et police de repli.
API métier simulées, aucune mutation documentaire réelle sur la base principale.


### Analyses et téléchargement privé — CTR-003

DocumentSecurityControls complète chaque version du panneau documentaire :
état et motif de blocage, date, historique des analyses paginé par 10,
demande de nouvelle analyse avec confirmation et identifiant du dernier scan.
Le bouton Actualiser les documents relit les résultats du worker ; aucune
relance automatique d’un fichier refusé. Les archives conservent leurs octets,
mais un utilisateur habilité peut renouveler leur analyse.

Téléchargement visible uniquement pour une version clean disponible et un scan
identifié. Chaque clic demande un lien neuf, puis récupère un Blob PDF avec
session, révision et redirections HTTP refusées. L’URL reçue doit désigner
exactement la version, le compte et le scan affichés. Aucun URL public/persistant
ou aperçu PDF exécuté ; l’URL Blob est libérée après déclenchement du téléchargement.
Fermeture ou changement de contexte annule la lecture et ignore les réponses
tardives. Refus 401/403/404/409 : relecture, sans rejeu automatique du téléchargement.
Le lecteur administratif peut télécharger mais ne voit aucune commande de relance.

168 tests Vitest dans 26 fichiers, lint/build et 17 Playwright verts. Le parcours
documentaire ajoute rejet, relance, résultat clean, historique et téléchargement
sur mobile 375/320 px avec police de repli. API métier et verdicts simulés dans
Playwright ; l’antivirus réel est vérifié séparément après configuration.


### Vérification des fichiers multipart en E2E

Le scénario documentaire attend la réponse HTTP de chargement des versions après
chaque ouverture d’historique. Les scénarios attendent ensuite que le champ
fichier soit actif avant setInputFiles().
Cette action ne vérifie pas elle-même l’état disabled : un rechargement du contrat
peut encore recréer le champ et vider une sélection effectuée trop tôt. Le test
documentaire vérifie ensuite le nom sélectionné et le fichier effectivement reçu.
Référence : [attentes Playwright](https://playwright.dev/docs/actionability).

Les scénarios documentaires et d’import CSV utilisent la fixture multipartUpload
de tests/e2e/multipart.ts. Elle ouvre un serveur HTTP sur 127.0.0.1 et un port
éphémère, reçoit le corps original envoyé par le navigateur, puis le lit avec
Response.formData(). Les assertions portent sur le fichier réellement reçu :
nom, contenu exact, version du contrat ou séparateur CSV, avec en-tête multipart.
Le hash et le poids du document simulé sont calculés sur les octets reçus.

Ce contrôle évite les expressions régulières sur request.postData(), car
l’inspection réseau Chromium peut omettre les parties fichier. Il ne remplace
pas le corps par un contenu attendu et ne désactive aucune assertion de dépôt.
Les réponses métier restent simulées ; aucun contrat, import ou fichier métier
réel créé. Corps limité à 1 Mo pour ces petits fixtures, délai de réception de
10 s et fermeture du serveur/socket en teardown. Aucun fichier clair écrit sur
disque par le récepteur. Aucun package ajouté.

Référence de la limite d’inspection :
[protocole Chromium, getRequestPostData](https://raw.githubusercontent.com/ChromeDevTools/devtools-protocol/master/json/browser_protocol.json).
Pour valider les différences de navigateur, utiliser le Chromium headless livré
avec la version installée de Playwright (configuration CI par défaut), en plus
du canal Chrome éventuellement utilisé en local.

## Conditions financières et approbations — CTR-004

Le détail du dossier propose « Conditions financières restreintes » uniquement
avec administrative.read et financial.read. Chargement à l’ouverture, aucun cache
local. L’édition exige financial.write et les droits administratifs ; les décimales
restent des chaînes jusqu’au serveur. Chaque sauvegarde confirme l’organisation
et porte la révision tenant, la version du contrat et la version financière attendue.

Le formulaire de préparation permet d’exiger un déclarant de signature distinct.
Les commandes incompatibles avec les droits ou cette séparation sont masquées.
Le serveur contrôle chaque opération. Les documents du dossier sont masqués sans
financial.read dès qu’un bloc financier est renseigné dans le dossier ou un avenant.

L’avenant présente son bloc financier, sa décision courante et un historique paginé.
Son approbation exige confirmation d’examen. Nouvelle préparation ou version
financière : réapprobation puis nouvelle copie signée. Un refus 403 ou conflit 409
invalide l’écran sans rejouer l’écriture. Les signatures historiques sont conservées
sans leur inventer de décision.

Tests dédiés aux panneaux financiers et d’approbation ; scénario mobile
contract-governance.spec.ts : préparation, approbation, troisième déclarant puis
coach sans lecture financière. API métier simulées dans le navigateur ; les tests
Laravel couvrent les droits, transactions, versions et contraintes réels.


## Échéances et rappels — CTR-005

Dans un dossier, « Échéances et rappels » charge à la demande les réglages et
l'historique paginé. La fin provient du contrat ; le préavis et le renouvellement
sont facultatifs. Délais par défaut 90, 30, 7 jours ; canaux application et e-mail
sélectionnables. L'activation par dossier est explicite. Le responsable doit
disposer d'un compte lié vérifié et des droits actuels, dont financial.read si
le dossier contient des conditions financières.

Les écritures passent par la confirmation humaine du tenant, CSRF, révision
active et versions optimistes. Les champs financiers du contrat ne sont jamais
ajoutés aux rappels. Le suivi distingue notification disponible, e-mail en attente,
envoi en cours, acceptation par le transport, blocage ou résultat à vérifier.
Un résultat SMTP incertain exige un contrôle humain : confirmer l'acceptation ou
autoriser une relance avec acceptation du risque de doublon. L'e-mail ne fournit
aucune preuve de livraison finale.

« Mes rappels de contrats » liste les notifications du responsable actuel et
ouvre le dossier choisi, y compris s'il était déjà sélectionné. Aucun chargement
avant ouverture ; lectures annulées au démontage, réponses privées sans cache,
contexte invalidé après refus ou conflit. Un profil de lecture n'obtient aucun
formulaire ; un dossier financier exige la lecture financière explicite.

Les indicateurs de configuration permettent de signaler l'attente d'activation
automatique ou du service e-mail. SMTP et futur adaptateur Brevo restent côté
backend ; aucune clé d'envoi dans le navigateur. Le worker peut être arrêté même
si la configuration est activée : l'indicateur ne constitue pas une sonde de santé.

Tests : ContractRemindersPanel.test.tsx (réglages, confirmation, versions, accès,
résolution, annulation, boîte), contract-reminders.spec.ts (mobile 390 px,
deux canaux, décision humaine et ouverture du dossier). Les API métier y sont
simulées et aucun e-mail réel n'est envoyé.


## Journal des accès sensibles — CTR-006

Le panneau de l'espace organisation exige audit.view ; les droits administratifs
ou le statut de propriétaire ne sont pas interprétés comme une autorisation
locale supplémentaire. Le serveur recontrôle la permission, la MFA et le statut
du compte. Aucun contenu de contrat n'est rendu dans ce panneau, même si une
réponse simulée contient des champs after arbitraires.

Lecture à la demande via audit-events?source=tenancy.sensitive, dix événements
par page. Filtres par action et identifiant du compte ; navigation par curseurs
opaques, réinitialisée après modification des filtres ou actualisation.
Les dates sont rendues dans le fuseau de l'organisation. Identifiants longs
coupés sur mobile, requêtes annulées au démontage, ancien journal effacé en cas
d'échec, contexte invalidé après retrait d'accès. Le composant est remonté
après changement de tenant, révision ou permissions.

Les libellés distinguent consultation, lien temporaire créé, téléchargement
autorisé et étapes de l'export opérateur. « Téléchargement autorisé » indique une
réponse préparée pour être servie, pas une preuve de réception du fichier.
Le motif est une catégorie ; aucun nom de personne, nom de fichier, titre,
montant ou motif libre n'est ajouté au journal. Les UUID ne sont pas des liens
d'accès à des documents.

Les API contractuelles bloquent la réponse avec 503 ACCESS_AUDIT_UNAVAILABLE
si l'audit ne peut pas être enregistré. L'API du journal reste privée, no-store.
Aucun bouton d'export d'archive opérateur n'est introduit dans le navigateur.

Validation : SensitiveAccessPanel.test.tsx couvre permissions explicites,
chargement, filtres, curseurs, fuseau, retrait d'accès, annulation et échappement.
sensitive-access.spec.ts vérifie le parcours mobile à 390 px, les filtres, la
navigation et l'absence du panneau après retrait de la permission. Les données
métier du navigateur sont simulées.
