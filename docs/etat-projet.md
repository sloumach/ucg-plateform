# État du projet UltraTools

> Document de transmission entre les discussions Codex. À lire au début d'une
> nouvelle tâche et à mettre à jour après chaque étape significative.

## Point de reprise

- Dernière mise à jour : 25 octobre 2026.
- Tâche courante : **UCG-CAD-004, fiche de décision des dossiers de personnes**.
  Le cadrage OPS-007 précédent est commité en `83fa8e4` (aucun push par l'agent).
  L'utilisateur confirme des mineurs et un périmètre MENA/Europe ; les pays exacts
  restent à préciser. Fiche ajoutée au README : huit sous-catégories, parcours et
  événements de départ à décider, six tables du noyau, copies/dépendances et
  parcours de représentants à définir. Aucune durée ni règle d'âge inventée,
  aucune configuration, donnée ou protection modifiée. CAD-004/OPS-007/REC-35
  ouverts ; détails en fin de document. Ce nouveau lot n'est pas commité.
- Lot précédent : **UCG-OPS-007, inventaire et matrice de conservation préparatoires**.
  L'utilisateur autorise la préparation prudente des règles, sans suppression,
  et confirme l'établissement d'UCG en **Tunisie (TN)**. Inventaire des sources :
  116 tables du catalogue de portabilité, classées en 18 familles, avec événements
  de départ proposés, dépendances et durées à valider. Matrice et fiche de décision
  ajoutées au README. Les comptes/journaux globaux, caches, jobs, logs, fichiers,
  fournisseurs et sauvegardes/réplicas sont distingués du catalogue tenant.
  Les expirations techniques ne sont pas des durées métier. Décision IAM-006 de
  conservation sans limite préservée, à réexaminer dans le cadrage tunisien ;
  aucune politique de purge activée ou modifiée. Aucune commande de purge,
  migration ou lecture d'une base réelle exécutée. OPS-007/CAD-004/REC-35 ouverts.
  Préparation documentaire commitée depuis en `83fa8e4`, sans push par l'agent ;
  détails en fin de document.
- Reprise courante : **UCG-QA-005, consolidation locale des lots 2–5 dans une tâche**.
  L'utilisateur confirme la CI de `9637ce9`, autorise cette tâche unique et son
  commit, puis confirme que l'hébergement reste différé. Profilage SQL, 500 comptes,
  100 sessions, 1 000 matchs/100 000 parties et pression de trois imports de
  1 000 lignes avec trois exports de 500 lignes ont été exercés localement.
  Les rapports complets volume/pression terminent avec intégrité ciblée et
  nettoyage conformes, mais **la cible de performance n'est pas tenue** : refus
  429 sous concurrence et p95 Beta de plusieurs secondes. Les refus restent dans
  les mesures ; les limites d'accès ne sont ni désactivées ni mises en cache.
  Le protocole de recette représentative est préparé dans le README frontend.
  QA-005/REC-34 restent ouverts sur ces écarts et l'infrastructure différée ;
  résultats détaillés et références des rapports dans la dernière section.
- Correctif CI précédent, livré dans `9637ce9` et CI validée par l'utilisateur :
  échec de la fenêtre UTC de
  `CalendarSubscriptionTest` reproduit localement. L'horloge est désormais fixée
  avant la création des adhésions/affectations, pour éviter qu'elles deviennent
  futures après le voyage temporel. Les 15 cas du fichier passent (114 assertions),
  Pint/PHPStan verts. Correctif limité au test et au suivi ; la validation CI
  utilisateur est reçue avant cette consolidation.
- Premier lot livré : **UCG-QA-005, mesure locale bornée**.
  Lanceur PostgreSQL jetable ajouté : trois tenants, trois comptes, 630 fiches,
  deux sessions, deux serveurs HTTP PHP et un worker database. Rapport final
  `load-smoke-1792929067358.json` : 100 lectures Beta par phase, p95 332,82 ms
  au repos, 378,47 ms sous activité Alpha et 381,10 ms après ; 100 lectures Alpha,
  trois exports réels de 500 lignes, aucune erreur HTTP, zéro job échoué/en attente.
  Refus du schéma déjà rempli et stabilité des identifiants/versions/horodatages
  Beta/Gamma vérifiés, nettoyage confirmé. Premier essai plus lent conservé :
  cette variabilité interdit de qualifier la capacité sur ce poste partagé.
  Cinq tests du nouveau lanceur et six garde-fous existants réussis ; lint,
  Pint, PHPStan et YAML verts. QA-005 et REC-34 restent ouverts pour les volumes,
  imports, concurrence à 100 actifs et infrastructure représentative. Aucun
  code métier, migration, dépendance ou donnée réelle modifié ; sans commit/push.
- Reprise courante : **UCG-QA-003 qualifié sur son périmètre convenu**.
  Après autorisation explicite de l'utilisateur, les trois artefacts frontend
  du SHA `b4dd21a` ont été téléchargés et leurs JUnit et données HTML relus :
  493 tests unitaires, 53 cas Playwright généraux, 8 parcours réels SQLite et
  8 PostgreSQL, sans échec, erreur ni exclusion. Les 69 cas Playwright ont chacun
  une seule tentative, aucun flaky ni erreur globale. Les neuf thèmes du ticket
  et les contrôles 375/320 px sont couverts. L'attente de lecture des artefacts
  est levée ; limites et preuves détaillées dans la dernière section.
  Prochain chantier proposé : QA-005/REC-34, non démarré. QA-002 reste ouvert
  pour cette charge, la rétention, la restauration et les limites hébergées.
- Dernière livraison : **UCG-QA-002, consolidation du périmètre local restant**.
  L'utilisateur autorise une tâche unique, sans validation intermédiaire par lot,
  avec prudence et commits cohérents. 45 cas HTTP ajoutés sur les révocations,
  un test de transport Reverb réel ajouté, 69 méthodes existantes intégrées au
  groupe critique : 246 cas, 3 831 assertions sur SQLite. Suites complètes :
  2 798 cas par moteur ; SQLite 2 748 réussites/50 exclusions, PostgreSQL
  2 794 réussites/4 exclusions, aucun échec ni erreur. Reverb réel : 1 test,
  26 assertions, trois exécutions réussies. Pint/PHPStan verts, clusters et
  processus de test arrêtés/supprimés. Livraison répartie en trois commits,
  sans push ; aucune logique métier ni migration modifiée. QA-002 reste ouvert
  sur les dépendances et limites détaillées dans la matrice finale.
- Décision utilisateur du 25 octobre 2026 : **aucun webhook entrant prévu au P0**.
  REC-30 est non applicable au périmètre P0 actuel ; API-002 reste à traiter si
  une intégration entrante est introduite. Cette décision ne valide aucun webhook.
- Reprise de la consolidation : `b7a6cba` présent sur `staging` et la référence
  locale origin/staging, arbre propre au départ. Sa CI n'a pas été confirmée par
  l'utilisateur ; dernière validation rapportée : `22ac7d5`. Aucune lecture distante.
- Livraison précédente : **UCG-QA-002, onzième lot suivi pédagogique Académie**.
  Onze cas ajoutés et un scénario étranger existant intégré au groupe critique :
  117 tests, 2 421 assertions sur SQLite. Quatre suites liées : 69 tests,
  777 assertions par moteur SQLite/PostgreSQL 17 temporaire, sans échec,
  erreur ou exclusion. Pint/PHPStan verts, cluster arrêté et supprimé.
  Livraison réunie dans un commit dédié, sans push ; détails en fin de document.
- Reprise du onzième lot : l'utilisateur valide la CI de `22ac7d5`, puis
  autorise le démarrage prudent. Confirmation utilisateur, sans nouvelle lecture
  distante ; arbre propre sur `staging` et référence locale origin/staging
  concordante au départ.
- Livraison précédente : **UCG-QA-002, dixième lot séances Académie et présences**.
  Neuf cas ajoutés et un scénario étranger existant intégré au groupe critique :
  105 tests, 2 131 assertions sur SQLite. Quatre suites liées : 77 tests,
  607 assertions par moteur SQLite/PostgreSQL 17 temporaire, sans échec,
  erreur ou exclusion. Pint/PHPStan verts, cluster arrêté et supprimé.
  Livraison réunie dans un commit dédié, sans push ; détails en fin de document.
- Reprise du dixième lot : l'utilisateur valide la CI de `5172f83`, puis
  autorise le démarrage prudent. Confirmation utilisateur, sans nouvelle lecture
  distante ; arbre propre sur `staging` et référence locale origin/staging
  concordante au départ.
- Livraison précédente : **UCG-QA-002, neuvième lot observations statistiques**.
  Huit cas ajoutés et un scénario étranger existant intégré au groupe critique :
  95 tests, 1 921 assertions. Quatre suites : 112 tests, 1 459 assertions sur
  SQLite et PostgreSQL 17 temporaire, sans échec, erreur ou exclusion.
  Pint/PHPStan verts, cluster arrêté/supprimé. Livraison réunie dans un commit
  UCG-QA-002 dédié au neuvième lot, sans push ; détails et limites en fin de document.
- Reprise du neuvième lot : l'utilisateur annonce lancer la CI de `a581415`,
  puis autorise le démarrage prudent du lot suivant. Aucun résultat de cette CI
  n'a été confirmé ; pas de nouvelle lecture distante ici. Arbre propre sur
  `staging` et référence locale origin/staging concordante au départ.
- Livraison précédente : **UCG-QA-002, huitième lot imports statistiques**.
  Neuf cas ajoutés et un scénario étranger existant intégré au groupe critique :
  86 tests, 1 695 assertions. Quatre suites : 104 tests, 1 082 assertions sur
  SQLite et PostgreSQL 17 temporaire, sans échec, erreur ou exclusion.
  Pint/PHPStan verts, cluster arrêté/supprimé. Livraison réunie dans un commit
  UCG-QA-002 dédié au huitième lot, sans push ; détails et limites en fin de document.
- Reprise du huitième lot : l'utilisateur valide la CI du commit `53d3c4e` et
  demande explicitement le commit du nouveau lot après vérification.
  Arbre propre sur `staging` et référence locale origin/staging concordante au
  départ ; confirmation utilisateur, sans nouvelle lecture des CI distantes.
- Livraison précédente : **UCG-QA-002, septième lot inscriptions aux compétitions**.
  Dix cas ajoutés et un scénario étranger existant intégré au groupe critique :
  76 tests, 1 395 assertions. Six suites : 66 tests, 531 assertions sur SQLite
  et PostgreSQL 17 temporaire, sans échec, erreur ou exclusion. Pint/PHPStan
  verts, cluster arrêté/supprimé. Livraison réunie dans un commit UCG-QA-002
  dédié au septième lot, sans push ; détails et limites en fin de document.
- Reprise du septième lot : l'utilisateur valide la CI du commit `9b55cbf`.
  Arbre propre sur `staging` et référence locale origin/staging concordante au
  départ ; confirmation utilisateur, sans nouvelle lecture des CI distantes.
- Livraison précédente : **UCG-QA-002, sixième lot rappels contractuels**.
  Sept cas ajoutés et un scénario étranger renforcé/intégré au groupe critique :
  65 tests, 1 173 assertions. Quatre suites : 81 tests, 538 assertions sur SQLite
  et PostgreSQL 17 temporaire, sans échec, erreur ou exclusion. Emails simulés,
  Pint/PHPStan verts, cluster arrêté/supprimé. Livraison réunie dans un commit
  UCG-QA-002 dédié au sixième lot, sans push ; détails et limites en fin de document.
- Reprise du sixième lot : l'utilisateur valide la CI du commit `11d6117`.
  Arbre propre sur `staging` et référence locale origin/staging concordante au
  départ ; confirmation utilisateur, sans nouvelle lecture des CI distantes.
- Livraison précédente : **UCG-QA-002, cinquième lot documents contractuels**.
  Neuf cas ajoutés : analyse sérialisée après bascule HTTP, révocation avant
  exécution, anciens liens après révocation et retrait granulaire d'écriture.
  Un scénario étranger existant intégré au groupe critique : 57 tests,
  1 024 assertions. Six suites : 89 tests, 618 assertions sur chacun des moteurs
  SQLite/PostgreSQL 17 temporaire, sans échec, erreur ou exclusion. Pint/PHPStan
  verts, cluster arrêté/supprimé. Livraison réunie dans un commit UCG-QA-002
  dédié au cinquième lot, sans push ; détails en fin de document.
- Reprise du cinquième lot : l'utilisateur valide la CI du commit `14117bd`.
  Arbre propre sur `staging` et référence locale origin/staging concordante au
  départ ; confirmation utilisateur, sans nouvelle lecture des CI distantes.
- Livraison précédente : **UCG-QA-002, quatrième lot imports de personnes**.
  Douze cas ajoutés et un scénario étranger renforcé : transport sérialisé après
  bascule HTTP, rejeu, révocation avant exécution/entre blocs, rapports et fichiers
  privés. Groupe `tenant-isolation` : 47 tests, 804 assertions. Cinq suites :
  69 tests, 488 assertions sur SQLite et PostgreSQL 17 temporaire, sans échec,
  erreur ou exclusion. Pint/PHPStan verts, cluster arrêté/supprimé. Modifications
  réunies dans un commit UCG-QA-002 dédié au quatrième lot, sans push ; détails
  et limites en fin de document.
- Reprise du quatrième lot : l'utilisateur valide la CI du commit `2c9d3bc`.
  Arbre propre sur `staging`, référence locale origin/staging concordante au départ ;
  confirmation utilisateur, sans nouvelle lecture des CI distantes.
- Livraison précédente : **UCG-QA-002, troisième lot de ressources rattachées**.
  Douze cas ajoutés sur les profils/comptes de jeu, périodes/checklist et
  compositions ; deux scénarios étrangers renforcés, trois scénarios existants
  intégrés au groupe critique. Groupe `tenant-isolation` : 34 tests,
  489 assertions. Onze suites : 220 tests et 1 160 assertions sur chacun des
  moteurs SQLite/PostgreSQL 17 temporaire, sans échec, erreur ou exclusion.
  Pint/PHPStan verts, cluster arrêté/supprimé. Livraison réunie dans le commit
  UCG-QA-002 dédié au troisième lot, sans push ;
  détails et limites en fin de document.
- Reprise du troisième lot : l'utilisateur valide la CI du commit `c3bbe85`.
  Arbre propre au démarrage sur `staging`, référence locale origin/staging
  concordante ; confirmation utilisateur, sans nouvelle lecture des CI distantes.
- Livraison précédente : **UCG-QA-002, deuxième lot Personnes/Équipes**. Neuf cas ajoutés,
  deux scénarios étrangers renforcés ; groupe `tenant-isolation` : 19 tests,
  270 assertions. Neuf suites sélectionnées : 180 tests et 1 094 assertions sur
  chacun des moteurs SQLite/PostgreSQL 17 temporaire, aucun échec ou scénario
  ignoré. Pint/PHPStan verts, cluster arrêté/supprimé. Livraison réunie dans le
  commit UCG-QA-002 dédié au deuxième lot, sans push ; détails en fin de document.
- Reprise du deuxième lot : l'utilisateur valide la CI du correctif `337f67a`.
  Staging et référence locale origin/staging concordantes, arbre propre au départ.
  Cette confirmation vient de l'utilisateur ; pas de nouvelle lecture distante ici.
- Historique du correctif CI QA-002 : le journal fourni signale
  l'échec de `communication.spec.ts` sur la visibilité du commentaire après
  confirmation, trois tentatives échouées. Attente explicite du POST, du
  rechargement des annonces et de la relecture des commentaires ajoutée au test.
  Dix répétitions locales réussies sans retry, tests unitaires communication
  10/10, lint et typage verts. Suite complète : 52/53, communications réussies ;
  échec temps réel avec le Vite local qui ne fournit pas la clé publique de test.
  Détails et limites dans la dernière section.
- Premier lot précédent : **UCG-QA-002**, matrice d'isolation. Huit cas
  ajoutés et comparaison renforcée des refus d'URL d'équipe étrangère/inexistante.
  Groupe `tenant-isolation` : 9 cas, 111 assertions. Validation : 312 tests SQLite
  (1 650 assertions) pour les suites auditées, 120 tests PostgreSQL temporaire
  (767 assertions) pour les cinq suites modifiées, aucun échec ni exclusion.
  Pint/PHPStan verts. Matrice REC-23–36 et limites en fin de document.
  Livraison incluse dans `2ee4f25`, observé sur staging et la référence locale
  origin/staging à la reprise du correctif CI. QA-002 reste ouvert ; le correctif
  frontend `337f67a` a depuis été validé en CI par l'utilisateur avant le lot 2.
- Reprise après QA-003 : l'utilisateur a validé les CI du lot `c3fb614` avant
  le démarrage de QA-002. Arbre propre et références locales staging/origin/staging
  concordantes à ce démarrage ; pas de nouvelle lecture des CI distantes ici.
- Livraison précédente : **UCG-QA-003**, extension du lanceur navigateur
  à PostgreSQL 17 temporaire. Les huit parcours réels PostgreSQL ont réussi
  (zéro échec, erreur ou exclusion, rapport XML relu), avec arrêt et suppression
  confirmés. Six tests de garde-fous et un test d'intégration PostgreSQL réussis ;
  SQLite revérifié : 8/8 sans exclusion, nettoyage confirmé ; frontend 493/493,
  typage/lint/Pint/PHPStan verts. Nouvelle matrice CI
  SQLite/PostgreSQL préparée, pas encore exécutée à distance. Détails en fin de
  document ; aucune base existante, migration métier ou configuration réelle modifiée.
- Reprise QA-003 : `staging` et `origin/staging` synchronisées au SHA
  `c1d5f341a7494f73fa6ea77861405170c02cd540`, arbre propre avant cette mise à jour.
  CI distante vérifiée via l'API GitHub : backend `40046960453` et frontend
  `40046960495` terminées avec succès. Les étapes PostgreSQL, Redis et navigateur
  avec API réelle sont explicitement réussies. Détails et limites dans la dernière
  section ; ce constat remplace l'attente de CI pour ce SHA, sans clore les autres
  tickets QA ni la recette hébergée.
- Périmètre sportif : organisation de tournois retirée des documents ; suivi
  interne des compétitions externes conservé. Voir la décision de périmètre ci-dessous.
- Branche de travail : `staging`.
- Dernier module métier implémenté avant la qualification QA-003 : **UCG-ACA-003**, objectifs individuels/collectifs et feedback,
  notes internes justifiées avec permission distincte, accès personnel en lecture seule.
  Migration `2026_10_22_224821` préparée, constatée Pending en lecture seule ;
  aucune migration courante appliquée par l’agent. PostgreSQL CI complet :
  2 385 réussis, 2 ignorés, aucun échec ; frontend 479/479, PHPStan/Pint/build/lint verts.
  Vérifications et limites en fin de document. Avant usage : revue/push, CI distante,
  sauvegarde, migration, droits explicites et recette avec comptes/API de recette.
- Reprise ACA-003 : arbre propre et staging synchronisé avec origin/staging au commit
  `4eaf848`. Migration ACA-001 constatée Ran batch 53 et ACA-002 Ran batch 54,
  en lecture seule le 23 octobre. L’utilisateur a validé la poursuite vers ACA-003 ;
  ce constat Git/base ne remplace pas une nouvelle vérification de la CI distante.
- Correctif CI ACA-002 validé localement : les deux rejeux GameCatalogue et
  le rejeu Team retiraient Calendar sans retirer les séances qui le référencent.
  Les tests retirent maintenant ACA-002 avant Calendar et le restaurent après ses
  parents, avec vérification des trois tables. Sélection PostgreSQL complète de la
  CI : 2 358 réussis, 2 ignorés, aucun échec, 13 663 assertions. Aucune migration
  applicative modifiée ; commit `4eaf848` présent sur staging et la référence locale
  origin/staging à la reprise ACA-003. La CI distante n’a pas été revérifiée ici.
- Socle précédent : **UCG-ACA-002**, séances reliées au calendrier,
  exercices, compte rendu et présences avec corrections auditées. Migration additive
  `2026_10_22_172532` constatée Ran batch 54 à cette reprise ; aucune attribution
  réelle de permissions par l’agent. Validation locale et limites décrites dans
  la section ACA-002 ; la livraison ACA-003 figure ci-dessus et en fin de document.
- Correctif CI ACA-001 : PersonSchemaTest retire puis recrée maintenant Academy
  autour du rejeu de People. Validé sur PostgreSQL isolé (57/57) ; commit `15bbb3a`
  présent sur staging et origin/staging à cette reprise, CI distante non confirmée.
  Aucun changement de migration dans ce correctif.
- Socle précédent : **UCG-ACA-001**, cohortes, cycles, programme
  hebdomadaire, mentors et inscriptions privés. Migration additive
  `2026_10_22_100213` constatée Ran batch 53 à cette reprise. Permissions
  academy.read/academy.manage explicites, aucune attribution automatique.
  Détails, validations et limites dans la section ACA-001 en fin de document.
- REP-004 : exports CSV privés
  asynchrones de six rapports organisationnels, backend et frontend. Migration
  additive `2026_10_21_224633` préparée, jamais appliquée à la base courante.
  Génération et nettoyage planifié désactivés par défaut ; aucun .env réel,
  worker, permission réelle ou donnée métier réelle modifié. Livraison incluse
  dans le commit UCG-REP-004 ; push et CI distante restent à confirmer.
  Validation et limites détaillées en fin de document ; revue/push, CI distante,
  sauvegarde, migration puis recette isolée avant activation.
- REP-003 : commit `355f42b` constaté au démarrage sur `staging` et la référence
  locale `origin/staging`, arbre propre. Cela remplace les mentions historiques
  « non committé » ; CI distante non vérifiée dans cette étape.
- REP-002 : commits `fae8c06` et correctif PostgreSQL `4ce0e30` créés sur staging.
  Le correctif ajoute REP-002 au démontage/rejeu manuel de OrganizationSchemaTest ;
  27 tests SQLite et 47 PostgreSQL réussis. La CI distante du correctif n’a pas été
  explicitement confirmée. Migration `2026_10_20_184653` à appliquer avant activation ;
  aucun .env/worker réel activé ni migration courante appliquée par l’agent.
- REP-001 : `0cfd94b` et correctif CI `531af55` poussés et synchronisés à la
  reprise. Le succès de la CI distante du correctif n’a pas été explicitement
  confirmé par l’utilisateur ; la synchronisation Git ne vaut pas validation CI.
- STAT-005 : commit `d656e0c`, présent sur `staging` et `origin/staging` à la
  reprise. La CI distante n’a pas été explicitement confirmée par l’utilisateur.
  Aucune nouvelle migration ; les traitements STAT-004 gardent leur réglage séparé.
- STAT-004 : `4c1b8fe` poussé et synchronisé à la reprise, CI validée par
  l’utilisateur. Migration `2026_10_19_183908` constatée Ran batch 50 en lecture
  seule. Ce constat ne confirme ni sauvegarde ni activation du traitement.
  `STATISTICAL_CALCULATIONS_ENABLED=false` reste la valeur par défaut.
- STAT-003B : `5f24f3f` poussé, CI validée par l’utilisateur. Migration constatée
  Ran batch 49 en lecture seule à la reprise. Le traitement des imports conserve
  son réglage séparé, désactivé par défaut ; aucune activation réelle effectuée.
- STAT-003A et correctif : `3f6f0d9` et `5165aad` présents sur staging et
  origin/staging à la reprise. Migration constatée Ran batch 48 en lecture seule.
  La CI distante et la sauvegarde ne sont pas revalidées par ce constat.
- STAT-002 : `aa6e52e` poussé et synchronisé à la reprise ; push, CI et migration
  validés par l’utilisateur. Migration constatée Ran batch 47. La sauvegarde
  n’a pas été explicitement confirmée dans cette reprise.
- STAT-001 : `cbeba13` poussé et synchronisé à la reprise ; push/CI/migration
  validés par l’utilisateur. Migration constatée Ran batch 46.
- COMP-003 : `045887d` poussé et synchronisé à la reprise ; push, CI, sauvegarde
  et migration validés par l’utilisateur. Migration constatée Ran, batch 45.
- COMP-002 : `67ddc29` poussé et synchronisé au départ de COMP-003 ; CI et
  migration validées par l’utilisateur. Migration constatée Ran, batch 44.
  Les rappels internes restent désactivés par défaut.
- COMP-001 : `abdb569` poussé, CI et migration validées par l’utilisateur.
  Migration constatée en lecture seule Ran, batch 43. Branche propre et
  synchronisée avec origin/staging au départ de COMP-002.
- MATCH-007 : `2c5d622` poussé ; CI validées par l’utilisateur avant COMP-001.
  Aucune migration pour ce ticket. Recette réelle Reverb + worker broadcasts
  toujours à réaliser en environnement isolé avant activation supervisée.
- MATCH-006 : `22f46fc` poussé ; CI et migration validées par l’utilisateur.
  Migration constatée en lecture seule Ran, batch 42. Départ propre et synchronisé.
  MATCH-008 reste P1.
- MATCH-005 : `f40d2a6` poussé ; CI et migration confirmées par l’utilisateur.
  Migration constatée en lecture seule Ran, batch 41. Départ propre et synchronisé.
- MATCH-004 : `f25f966` présent sur staging et origin/staging au démarrage ;
  CI et migration validées par l’utilisateur. Migration constatée en lecture seule
  Ran, batch 40. Départ propre et synchronisé avant MATCH-005.
- MATCH-003 : `085e3cc` présent sur staging et origin/staging au démarrage ;
  push et CI validés par l’utilisateur. Migration constatée en lecture seule
  Ran batch 39. Branche propre et synchronisée au démarrage de MATCH-004.
- MATCH-002 : `9a1c6e4` présent sur staging et origin/staging au démarrage ;
  CI et migration confirmées par l’utilisateur avant MATCH-003. Migration vérifiée
  en lecture seule Ran batch 38. Branche propre et synchronisée au démarrage.
- MATCH-001 : `6589368` poussé ; CI et migration confirmées par l’utilisateur
  avant MATCH-002. Migration vérifiée en lecture seule Ran batch 37. Staging
  propre et synchronisé avec origin/staging au démarrage de MATCH-002.
- CAL-006 : `064df63` poussé, CI et migration confirmées par l’utilisateur avant
  MATCH-001. Migration vérifiée en lecture seule Ran batch 36. Staging propre et
  synchronisé avec origin/staging au démarrage de MATCH-001. Les traitements
  calendar-changes demandent une activation et une recette à confirmer avant usage réel.
- CAL-005 : `fea7094` présent sur staging et origin/staging au début de CAL-006 ;
  migration vérifiée en lecture seule Ran batch 35. CI distantes non confirmées
  dans cette reprise. Branche propre et synchronisée au démarrage de CAL-006.
- CAL-004 : `661b438` et correctif `defc140` poussés ; CI validées par
  l’utilisateur avant CAL-005. Migration confirmée Ran, batch 34.
- CAL-003 (`dea7936`) : push, CI et migration confirmés par l’utilisateur avant
  CAL-004. Migration vérifiée en lecture seule : Ran, batch 33. Branche staging
  propre et synchronisée avec origin/staging au démarrage de CAL-004.
- Socle CAL-002 (`2c786d2`) poussé ; CI et migration confirmées par l’utilisateur
  avant CAL-003. Migration vérifiée en lecture seule : Ran, batch 32. Staging
  propre et synchronisé avec origin/staging au démarrage de CAL-003.
- Socle CAL-001 (`3051aa8`) poussé ; CI et migration confirmées par l’utilisateur
  avant CAL-002. Migration vérifiée en lecture seule : Ran, batch 31. Staging
  propre et synchronisé avec origin/staging au démarrage de CAL-002.
- Socle COM-001 (`8d48651`) poussé ; CI et migration confirmées par l’utilisateur
  avant CAL-001. Migration vérifiée en lecture seule : Ran, batch 30. Branche
  staging propre et synchronisée avec origin/staging au démarrage de CAL-001.
  Reliquat COM : rattachements événement/tâche/match/séance via leurs référentiels.
- MEDIA-001 (`1f7cc8c`) poussé ; CI et migration confirmées par l’utilisateur
  avant COM-001. Vérification en lecture seule : Ran, batch 29. Staging propre
  et synchronisé avec origin/staging au démarrage. Aucun migrate principal par l’agent.
- SPON-003 (`aba9205`) poussé ; CI et migration validées par l’utilisateur avant
  MEDIA-001. Migration vérifiée en lecture seule : Ran, batch 28. Staging propre
  et synchronisé avec origin/staging au démarrage. Aucun migrate principal par l’agent.
- SPON-002 (`a899bf2`, correctif `8fa6376`) poussé ; CI et migration validées par
  l’utilisateur avant SPON-003. Staging propre et synchronisé avec origin/staging
  au démarrage. Migration vérifiée en lecture seule : Ran, batch 27. Aucun migrate
  principal par l’agent. L’échec CI initial reste documenté dans la section dédiée.
- SPON-001 (`68ec32e`) poussé et synchronisé avec origin/staging au démarrage ;
  CI et migration validées par l’utilisateur. Migration vérifiée en lecture
  seule : Ran, batch 26. Aucun migrate principal par l’agent.
- CTR-006 (`8dd344d`) présent sur staging et la référence locale origin/staging
  au démarrage ; CI distante non vérifiée dans cette tâche. Migration vérifiée
  en lecture seule : Ran, batch 25. Aucun migrate principal par l’agent.
- CTR-005 (`cadd683`) poussé ; CI verte et migration confirmées par l’utilisateur
  avant CTR-006. Migration vérifiée en lecture seule : Ran, batch 24.
  Branche staging propre et synchronisée avec origin/staging au démarrage.
- CTR-004 (`5205b77`) poussé ; CI verte et migration confirmées par l’utilisateur
  avant CTR-005. Migration vérifiée en lecture seule : Ran, batch 23.
  Branche staging propre et synchronisée avec origin/staging au début de CTR-005.
- CTR-003 et correctifs `4fc416b` / `1a2c258` poussés ; CI verte confirmée par
  l’utilisateur avant le démarrage de CTR-004. Branche propre et synchronisée à
  cette reprise. Mise en service réelle de ClamAV/worker toujours à confirmer.
- CTR-003 (`32100e5`) poussé par l’utilisateur ; CI backend échouée sur le test
  de comptage global des requêtes (41 puis 39). Correctif local : comptage limité
  aux versions/analyses et couverture explicite des caches array/database.
  Échec corrigé par `4fc416b` ; CI désormais validée par l’utilisateur.
  Migration CTR-003 vérifiée en lecture seule après cet échec : Ran, batch 22.
- Correctif backend `4fc416b` poussé, branche synchronisée à la reprise ; CI
  désormais validée par l’utilisateur. CI frontend initialement en échec :
  extraction filename depuis request.postData(), alors que le nom transmis est vide.
  Course reproduite avec Chromium headless : sélection effectuée pendant le
  rechargement du dossier, puis champ recréé et sélection perdue. Correctif :
  attendre la réponse de chargement des versions puis le champ actif avant
  setInputFiles et vérifier le multipart réellement reçu sur un serveur de test
  local partagé par les tests PDF et CSV. Correctif `1a2c258` poussé ; CI validée.
- CTR-002 (`ce268f6`) : push, CI vertes et migration confirmés par l’utilisateur
  avant CTR-003 ; migration vérifiée en lecture seule : Ran, batch 21.
- CTR-001 (`87953e0`) : push, CI vertes et migration confirmés par l’utilisateur
  avant CTR-002 ; migration vérifiée en lecture seule : Ran, batch 20.
- TEAM-006 (`564dd59`) : push, CI vertes et migration confirmés par l’utilisateur
  avant CTR-001 ; migration vérifiée en lecture seule : Ran, batch 19.
- TEAM-005 (`0d46afe`) : push et CI vertes confirmés par l’utilisateur le
  14 octobre 2026. Migration appliquée par l’utilisateur, vérifiée en lecture
  seule : Ran, batch 18. Aucun changement aux données métier réelles par l’agent.
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
- HUM-004 (`510d9c0`) poussé par l’utilisateur ; CI backend
  [38306904589](https://github.com/sloumach/ucg-plateform/actions/runs/38306904589)
  échouée au formatage PHP. Correctif `51fb9b1` poussé ; CI confirmées réussies
  par l’utilisateur avant HUM-005. Branche synchronisée au début de HUM-005.
  Migration HUM-004 appliquée par l’utilisateur, vérifiée `Ran`, batch 12.
- HUM-005 (`f960be8`) poussé et CI validées par l’utilisateur avant TEAM-001 ;
  migration appliquée par l’utilisateur, vérifiée en lecture seule : Ran, batch 13.
  Branche propre et synchronisée avec origin/staging au début de TEAM-001.
- TEAM-001 (`427dae1`) poussé ; CI et migration validées par l’utilisateur
  avant TEAM-002. Migration confirmée en lecture seule : Ran, batch 14.
  Branche propre et synchronisée avec origin/staging au début de TEAM-002.
- TEAM-002 (`11872a3`) poussé ; CI et migration validées par l’utilisateur
  avant TEAM-003. Migration confirmée en lecture seule : Ran, batch 15.
  Branche propre et synchronisée avec origin/staging au début de TEAM-003.
- TEAM-003 (`f0681e7`) poussé et migré par l’utilisateur ; migration
  `2026_10_13_152226_create_team_membership_and_movement_tables` confirmée en
  lecture seule : Ran, batch 16. Branche propre et synchronisée avec origin/staging
  au début du correctif. CI frontend
  [38440198679](https://github.com/sloumach/ucg-plateform/actions/runs/38440198679)
  échouée sur le contrôle de débordement Playwright après promotion ; lint,
  tests unitaires et build réussis dans cette CI.
- Correctif TEAM-003 (`8a997ab`) poussé ; CI confirmée réussie par l’utilisateur
  avant TEAM-004. Branche propre et synchronisée avec origin/staging au démarrage.
- TEAM-004 (`59023ce`) poussé ; CI et migration validées par l’utilisateur avant
  TEAM-005. Branche propre et synchronisée au démarrage ; migration confirmée
  en lecture seule : Ran, batch 17.
- Ancienne consigne STAT-001 (historique) : revue/push de STAT-001, CI vertes, sauvegarde puis
  `php artisan migrate` depuis back/. Recetter un lecteur vérifié et un superadmin
  plateforme avec MFA, correction de formule, historique, révocation et concurrence.
  Aucune métrique ni attribution de rôle automatique. Le catalogue reste vide à
  l’installation ; définir explicitement les métriques utiles avant leur acquisition.
  Prochain P0 proposé : STAT-002 ; valeurs privées et recalculs restent à implémenter.
  Rappels internes désactivés par défaut : valider en environnement isolé le
  scheduler et le worker default/TEN-006, puis activer explicitement
  COMPETITION_REGISTRATION_REMINDERS_ENABLED sous supervision.
  Recetter séparément Reverb, la file broadcasts et VITE_REVERB_* avec deux comptes,
  avant activation supervisée ; vérifier les travaux en attente. Le recalcul
  MATCH-006 continue de dépendre de sa file persistante default.
  CAL-007/CAL-008 restent P1. Recetter report/annulation avant activation de
  calendar-changes et supervision scheduler/worker.
  Permissions explicites et activation supervisée de calendar-invitations restent
  à recetter avant usage réel.
  CAL-003 est confirmé poussé, CI vertes et migré. Attribuer calendar.resources.manage et calendar.conflicts.override
  seulement aux personnes habilitées via IAM-003 ; availability.read active le
  contrôle des disponibilités et la consultation des exceptions. Recetter deux
  comptes et les révocations. Aucune attribution réelle par l’agent ; migration
  CAL-003 principale appliquée par l’utilisateur, vérifiée Ran batch 33.
  CAL-002 est validé par l’utilisateur.
  Mise en service des rappels CTR-005 à confirmer : configurer le scheduler,
  le cache partagé et le worker default ; activer
  CONTRACT_REMINDERS_ENABLED puis, quand le SMTP est prêt,
  CONTRACT_REMINDERS_EMAIL_ENABLED (voir README, section CTR-005).
  Migration CTR-003 déjà appliquée, vérifiée Ran, batch 22 lors du correctif précédent.
  Préparer clamd/freshclam et le worker reports, puis exécuter le test opt-in réel
  documenté dans README avant l’ouverture des téléchargements.
  Code, tests, CI et docs dans le même commit. Aucune référence réelle, personne
  réelle, adhésion, attribution de rôle ou capture réelle créée par l’agent ;
  aucun e-mail envoyé.
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
- MEDIA-001 et SPON-003 confirmés poussés/migrés, CI validées. Le socle
  `UCG-COM-001` est implémenté ; ses rattachements aux futurs référentiels restent
  ouverts. Rapports SPON-004 et portail SPON-005 restent P1. Les P0 Contrats sont
  implémentés ; la signature électronique CTR-007 reste P1. ClamAV doit être vérifié en
  environnement cible ; aucun téléchargement sans analyse clean réussie.
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

### UCG-HUM-004 — Correctif du contrôle de formatage CI

- Run backend `38306904589`, job `115003045756` : étape Check PHP formatting
  échouée, analyse statique/tests/PG/Redis non exécutés. Push `510d9c0` confirmé.
- Reproduction avec `composer format:check` : `method_argument_space` dans
  PersonLifecyclePolicyTest et PersonLifecycleSchemaTest. Correction uniquement
  des espaces dans trois appels ; aucune logique métier ou migration modifiée.
- Le contrôle local limité aux fichiers dirty n’avait pas couvert ces nouveaux
  fichiers avant indexation. Clôture renforcée : contrôle complet identique à la
  CI (`composer format:check`) avant chaque commit PHP, nouveaux fichiers inclus.
- Après correction : Pint global et dirty verts, PHPStan niveau 8 vert,
  30 tests ciblés policy/schema et 92 assertions réussis sur SQLite en mémoire.
  Suites complètes HUM-004 précédentes conservées ; pas de nouveau test artificiel
  pour des espaces. Aucune migration principale ni donnée métier réelle modifiée.
- Code de correction et ce point de reprise regroupés dans le même commit.
  Nouveau push/CI à confirmer par l’utilisateur avant migration/HUM-005.

### UCG-HUM-005 — Import CSV privé et confirmé

- Import de création uniquement. Aucun écrasement d’une fiche existante, liaison
  de compte, invitation ou attribution de rôle. Statuts descriptifs conservés :
  une ligne former ne déclenche pas le départ HUM-004. Profils de jeu/équipes et
  rapprochement d’identité administrative ne font pas partie de cet import.
- Droits sports.read + sports.write + administrative.read + administrative.write
  requis pour dépôt, prévisualisation, confirmation, liste et rapport. Le CSV peut
  contenir des colonnes privées non encore mappées ; propriétaire sans grants et
  acteur technique refusés. HTTP session/MFA/CSRF/révision/confirmation tenant.
- UTF-8 avec BOM facultatif, virgule ou point-virgule explicite, guillemets échappés
  et champs multilignes. CSV mal formé/contrôles binaires/en-têtes vides ou répétés
  refusés avant réservation de fichier. Limites supplémentaires par fichier :
  1 Mio, 1000 lignes de données, 32 colonnes et 32768 octets par cellule ; quotas
  tenant import_bytes/import_rows/artifact_bytes/storage_bytes toujours appliqués.
- Source chiffrée avant stockage via TenantArtifacts, préfixe privé du tenant et
  comptabilité durable existants. Nom fixe sans nom utilisateur. En-têtes et valeurs/
  erreurs de lignes chiffrés en base. Échec de création du registre : seul l’objet
  nouvellement réservé est nettoyé ; nettoyage incertain signalé et quota conservé.
- Empreinte HMAC tenant + séparateur + octets du fichier, unique dans le tenant :
  redépôt identique retrouve le même import. Mapping field → index de colonne et
  valeurs par défaut langue/fuseau/statut ; liste fermée, sans user_id. Re-mapping
  autorisé avant confirmation seulement, version optimiste ; aucune fiche créée
  au dépôt ou en prévisualisation, seules métadonnées et lignes de staging persistées.
- Validation PersonInput par ligne et numéro de première ligne physique du record.
  Pseudonymes répétés dans le fichier ou déjà présents dans le tenant signalés,
  sans modifier/réactiver la personne existante. Comparaison existante LOWER/TRIM
  native au moteur, index tenant sur l’expression ; fichier normalisé par trim et
  mb_strtolower. Pas de rapprochement par e-mail/nom chiffré ni identité devinée.
  Une reconnaissance explicite des lignes invalides/doublons précède leur exclusion.
- Contrat PersonImportQueue → adaptateur Laravel → ProcessPersonImport (TenantJob,
  queue reports, contexte UUID/acteur/requête seulement). Droits et état du tenant
  revalidés par middleware puis service sous verrou organisation/import. Lots de
  25, création via PersonService, audit et statut de ligne dans la même transaction.
  Doublons apparus depuis la prévisualisation ignorés avec un code distinct.
- Commit par lot : échec annule tout le lot courant, lots précédents conservés.
  Relances/rejeux sautent les lignes créées. Après échec définitif, état failed et
  code fermé sans texte d’exception ; reprise HTTP confirmée des seules lignes
  restantes. Échec de dispatch après commit : état queued durable, reprise explicite.
  Le worker local existant doit écouter reports (`composer queue:work:local`).
- Deux tables tenant, FK composites vers artifact/import/personne, index et gardes
  PG/SQLite. Identité/source immuables ; mapping confirmé et lignes créées protégés,
  historique confirmé non supprimable. Rollback refuse tout registre non vide.
  Tests d’anciens rollbacks adaptés à l’ordre réel de dépendance, migrations
  déployées intactes. Dump de portabilité inclut les tables et leurs champs chiffrés.
- API /people-imports avec Form Request stricte, policy, repositories et Resource
  projetée. Listes et lignes par pages de 10, page bornée 1..10000. Comptages en bloc,
  absence de N+1 de liste vérifiée. Rapport CSV privé : numéro/statut/codes/id de
  personne uniquement, aucune valeur source ni formule tableur. Rapport après
  preview/fin/interruption ; pendant traitement, actualiser avant téléchargement.
- UI dans Personnes, uniquement avec les quatre droits : fichier, séparateur,
  mapping, prévisualisation paginée, confirmation métier et tenant, suivi manuel,
  reprise et rapport. Multipart garde CSRF/révision sans imposer de boundary ;
  réponses tardives ignorées, composant remonté par tenant/révision, rendu échappé.
- Confirmation push/CI/migration HUM-004 intégrée à ce commit. Aucune importation
  métier réelle ni migration principale par l’agent. Prochain P0 : TEAM-001 après
  confirmation utilisateur du push, des CI et de la migration HUM-005.
- Validation finale : 57 nouveaux tests backend / 179 assertions. Suites complètes
  avec cache database : SQLite 881 tests / 878 réussis / 3740 assertions / 3
  exclusions attendues ; PostgreSQL 17 881 / 879 / 3741 / 2 exclusions Redis
  attendues. Contrôle PG ciblé schema/import/architecture 68 tests / 235 assertions
  vert. Bases `ucg_hum005_verify_20261012_213959`, `_214346` et `_214458` supprimées
  après leurs vérifications ; dernière suite complète après correction des dépendances.
  Ancien test de rollback stockage adapte maintenant son ordre aux FK nouvelles.
- Pint global, contrôle complet `composer format:check`, Pint dirty, PHPStan niveau 8
  et architecture verts. Frontend : 77 tests, lint/build, 8 Playwright verts.
  API métier simulées au navigateur et mobile 375/320 sans débordement global.
  CI PG enrichie des 5 fichiers de tests ; SQLite intégral existant. Migration
  principale HUM-005 vérifiée Pending, HUM-004 Ran batch 12.

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

1. Exécuter les tests et contrôles pertinents. Pour toute modification PHP,
   formater les fichiers concernés puis exécuter depuis `back/` le contrôle
   complet `composer format:check`, identique à la CI ; `--dirty` seul ne couvre
   pas nécessairement les nouveaux fichiers non indexés.
2. Vérifier `git status` et les changements effectués.
3. Mettre à jour le point de reprise, le travail terminé, les décisions et la
   prochaine étape.
4. Mentionner les validations, les blocages et les éléments restant à faire.
5. Commiter ce fichier avec le code concerné, puis pousser selon le workflow
   convenu avec l'utilisateur : un seul commit de tâche couvre code, tests et docs.
6. Intégrer les confirmations ultérieures du push, des CI et migrations dans les
   docs du prochain travail ; ne pas créer de commit séparé de clôture documentaire.

## UCG-TEAM-001 — Catalogue commun et choix par organisation

- Catalogue persistant : game/platform/provider/region globaux ; mode/role/map/release
  rattachés à un jeu global. Clés, type et parent immuables, noms modifiables,
  disponibilité contrôlée ; retrait par active=false et conservation des lignes.
  Les rôles de jeu sont des métadonnées, distincts des grants IAM et appartenances.
- Superadmin vérifié avec MFA uniquement pour les écritures communes, confirmation
  humaine explicite confirm_global, version de catalogue + version de référence.
  Port public Identity PlatformCatalogueGuard : verrou compte et administration
  plateforme, droits revérifiés dans la transaction. Aucun accès métier tenant implicite.
- Réglages tenant : sports.read pour lire, sports.read + games.manage à portée
  organisation pour écrire. Jeux activés/désactivés, restrictions par catégorie ;
  absence d’une restriction = héritage, [] = aucun choix. Aucun nom, clé ou lien
  global modifiable par ces routes. Support et rôles techniques exclus du métier.
- Migration additive 2026_10_13_120201_create_game_catalogue_and_organization_games :
  reprise des quatre jeux, plateformes/fournisseurs/régions et activations existantes
  de la configuration validée avant DDL. Nouveaux jeux non activés par défaut.
  Overrides existants valides importés seulement pour leurs organisations.
  Aucun profil historique modifié, rôle attribué ou donnée métier réelle créée.
- Trois exceptions globales déclarées : game_catalogue_entries, game_catalogue_state,
  game_catalogue_events ; organization_games appartient au tenant. FK et scopes
  contrôlés, uniques/index de page, identité et suppression physique protégées,
  audit global append-only. Rollback technique admis avant toute modification ;
  refus dès modification globale, audit ou réglage tenant. Les edits SQL de
  métadonnées avancent aussi la révision ; baseline protégée.
- Cache des seules références globales indexé par UUID de révision, TTL 300 s,
  ignoré dans les transactions. Activations tenant relues séparément. Snapshot
  global borné, pas de N+1 par jeu ; listes paginées, détail d’un seul jeu borné.
  Portabilité inclut uniquement les réglages de l’organisation ciblée.
- GameProfileCatalogueBridge branche le port People sur les choix effectifs.
  Nouvelles fiches et comptes refusés après retrait global/local ; lecture et
  archivage restent possibles. Les observations game_role/rank restent déclaratives,
  sans conversion destructive vers les nouvelles références de rôle.
  OrganizationGameCatalogue expose assertReference pour les futurs usages Teams.
- UI séparée : catalogue commun accessible sans tenant sélectionné ; activation
  et choix locaux dans l’espace organisation. Confirmations globales et tenant
  distinctes, CSRF, erreurs localisées, versions et requêtes annulées au changement
  de tenant. Après un réglage local, l’espace et les choix de profils sont rechargés.
- HUM-005 f960be8 poussé, CI confirmées et migration Ran batch 13 par l’utilisateur.
  Migration TEAM-001 vérifiée Pending sur la base principale ; réservée à l’utilisateur.
  Code, tests, CI et ces docs dans un seul commit.
- Validation : 65 nouveaux tests backend / 268 assertions ; suites complètes SQLite
  946 tests, 943 réussis, 4008 assertions, 3 exclusions attendues ; PostgreSQL 17
  946/944, 4009 assertions, 2 exclusions Redis. Bases temporaires supprimées.
  Contrôles PG ciblés après correction de la FK autoréférente et de l’ordre du
  rollback de tests. Pint global et dirty, composer format:check, PHPStan niveau 8
  et architecture verts. Frontend 89 tests, lint/build ; 10 Playwright verts,
  erreurs console contrôlées sur les nouveaux parcours et mobile 375/320.
- Prochaine étape : push/CI utilisateur, sauvegarde puis php artisan migrate depuis
  back/. Ensuite TEAM-002 ; pas de recrutement HUM-006 ni teams/rosters dans ce lot.

## UCG-TEAM-002 — Équipes et archives par organisation

- TEAM-001 427dae1 poussé, CI et migration confirmées par l’utilisateur ;
  contrôle en lecture seule : Ran, batch 14. Ces confirmations sont intégrées
  ici au commit de TEAM-002, sans commit documentaire séparé.
- Modèle Team, Repository, Service, Form Requests, Policy et Resources ; API
  tenant GET liste/détail, POST création et PATCH partiel. Lecture sports.read ;
  mutations sports.read + teams.manage explicitement attribués à l’organisation,
  MFA, CSRF, confirmation humaine et révision du contexte tenant.
  Aucun droit métier implicite du propriétaire, support ou superadmin.
- Première/académie/réserve/événementielle/catégorie spécifique ; jeu disponible
  globalement et activé localement, saison descriptive, slug unique par tenant,
  nom et identité facultative. Jeu, slug, saison et organisation immuables pour
  ce cycle ; pour un autre cycle, créer une nouvelle équipe avec un nouveau slug.
  Statuts draft/active/archived ; archive terminale non modifiable/non supprimable.
  Archivage seul autorisé après retrait du jeu, historique conservé.
  Les champs d’identité publique restent privés : publication_status=private.
- Gardes SQL SQLite/PostgreSQL et modèle, FK tenant/catalogue, uniques et index.
  Verrou organisation puis disponibilité du catalogue ; version UUID optimiste,
  droits revérifiés et audit minimal atomique sans noms/descriptions. Portabilité
  limitée aux équipes du tenant. Aucun roster, appartenance ou scope IAM équipe
  introduit ici ; aucune équipe réelle créée ni permission attribuée.
- UI repliée initialement, filtres et pagination, jeux activés proposés à la
  création, édition partielle et archivage confirmé. Erreurs localisées, valeurs
  saisies conservées sur 422, rechargement sur conflit, requêtes annulées au
  changement de tenant ; textes échappés, contrôles mobile 375 et 320 px.
- Limites techniques : 1000 équipes par organisation, archives incluses ; pages
  1..100000, per_page 1..100 (UI 10). Relation jeu eager-loaded, nombre de requêtes
  constant vérifié ; aucune affirmation de benchmark à grande échelle.
- Validation : 50 nouveaux tests backend / 204 assertions. SQLite complet :
  996 tests, 993 réussis, 4212 assertions, 3 exclusions attendues ; PostgreSQL 17 :
  996 tests, 994 réussis, 4213 assertions, 2 exclusions Redis. Bases temporaires
  supprimées. Pint complet/dirty, composer format:check, PHPStan 8 et architecture
  verts ; 98 tests frontend, lint/build et 11 Playwright verts. CI PostgreSQL
  enrichie des trois fichiers Teams ; tests de rollback adaptés à l’ordre des FK.
- Migration 2026_10_13_143631_create_teams_table vérifiée Pending sur la base
  principale ; aucune migration principale par l’agent. Down refuse toute équipe,
  archives incluses. Code, tests, CI, README, API, architecture et point de reprise
  regroupés dans le même commit.
- Prochaine étape : push/CI utilisateur, sauvegarde puis php artisan migrate
  depuis back/. Ensuite UCG-TEAM-003, appartenances datées.

## UCG-TEAM-003 — Appartenances datées et mouvements conservés

- TEAM-002 11872a3 poussé, CI et migration confirmées par l’utilisateur ;
  contrôle en lecture seule : Ran, batch 15. Branche propre et synchronisée
  avec origin/staging au début du travail. Confirmation intégrée à ce commit,
  sans commit documentaire séparé.
- Modèles TeamMembership/TeamMovement, Repository, Service, Journal, Form Request,
  Policy et Resources. API liste paginée d’appartenances/mouvements, création et
  actions PATCH : close/cancel/transfer/promotion. Fonctions player/substitute/
  captain/coach/analyst ; lecture sports.read organisation, écriture aussi
  teams.manage organisation, MFA/CSRF/confirmation tenant. Aucun droit implicite.
- Intervalles UTC [début, fin), fin après début ; dates adjacentes admises.
  Même personne/équipe : player/substitute incompatibles, même fonction sans
  chevauchement, captain additif. Aucune exclusivité entre équipes ajoutée.
  Identité et début immuables ; fin seulement raccourcie, période terminale
  non modifiable/supprimable. Clôture future conservant l’effectivité avant
  l’échéance ; annulation avant début horodatée côté serveur.
- Mouvements atomiques vers une autre équipe du même jeu ; promotion academy →
  first. À la date d’effet, les fonctions sources qui la dépassent sont terminées
  et celles qui commencent à partir de cette date sont annulées. Destination :
  fonction choisie seulement, pas de copie d’autres fonctions ou permissions.
  Motif fermé, auteur, dates d’effet/enregistrement et références historiques
  conservés ; noms d’équipes courants affichés, pas de snapshot de match ici.
- Port SportingPeopleDirectory fourni par People sans cycle, pseudonymes en
  bloc, aucune identité administrative. Participant Teams branché sur le tag
  de départ HUM-004 : fin des périodes en cours, annulation des futures, audit
  dans la même transaction que personne/adhésion. Départ de l’acteur lui-même
  sans réautorisation après révocation ; rejeu sans deuxième mouvement.
- Annuaire IAM team activé via le port DatedAuthorizationScopeDirectory.
  Grants équipe explicites liés durablement à une période couvrant leurs dates,
  relus à chaque contrôle ; retour/annulation/remplacement ne réactivent aucun
  ancien grant. Pour un retour, révoquer l’ancien et attribuer un nouveau grant.
  Fonctions sportives distinctes des permissions. Détail d’équipe accessible
  aussi sous sports.read équipe effectif ; listes/roster/People et gestion restent
  au niveau organisation. Grants organisation explicites continuent à couvrir
  les équipes, sans imposer une fonction à chaque gestionnaire.
- Migration additive : team_memberships, team_movements et team_scope_grants,
  FK composites tenant,
  index, dates/chevauchements/identités protégés SQL PG/SQLite. PostgreSQL prend
  le verrou d’organisation aussi dans sa garde SQL. Mouvements et liaisons
  append-only ; audit minimal atomique sans noms. Export des trois tables limité
  au tenant. Down refuse toute période/mouvement/liaison, anciennes migrations
  déployées inchangées ; ordre des rollbacks de tests adapté aux nouvelles FK.
- Archivage d’équipe refusé tant que des périodes en cours/futures subsistent.
  Fin de période autorisée après retrait du jeu. Plafonds techniques : 1000
  périodes par personne/organisation, historiques inclus ; 1000 liaisons IAM
  par période. Pages API ≤100, UI 10 ; nombre de requêtes de pseudonymes constant
  testé, équipes des mouvements eager-loaded. Aucun benchmark de grande échelle.
- UI depuis « Effectif et historique » : recherche/pagination des personnes
  et destinations, fonction, dates, motifs, consultation à une date, historique.
  Confirmations humaines avant mutations, brouillon conservé sur 422, contexte
  rechargé sur conflit, lectures annulées à la fermeture/changement de tenant.
  Mobile 375/320 contrôlé avant et après promotion ; pseudonymes échappés.
- Migration 2026_10_13_152226_create_team_membership_and_movement_tables vérifiée
  Pending sur la base principale ; aucune migration principale, appartenance
  réelle, attribution réelle ou e-mail envoyé par l’agent.
- Validation finale : 48 nouveaux tests backend / 228 assertions ; SQLite complet
  1044 tests, 1041 réussis, 4440 assertions, 3 exclusions attendues ; PostgreSQL 17
  1044 tests, 1042 réussis, 4441 assertions, 2 exclusions Redis. Bases temporaires
  supprimées. PHPStan niveau 8 et architecture verts, Pint complet/dirty et
  composer format:check ; frontend 107 tests, lint/build et 12 Playwright verts.
  CI PostgreSQL enrichie des quatre fichiers du ticket. Vérifications du retour
  sans réactivation, réattribution explicite, modification sous fonctions cumulées,
  quotas, versions, départ de l’acteur, rollback d’audit, export et mobile 375/320.
- Code, tests, CI, README, API, architecture, backlog et point de reprise dans
  le même commit. Prochaine étape : push/CI utilisateur, sauvegarde puis
  php artisan migrate depuis back/. Prochain P0 : UCG-TEAM-004, lineups et snapshots.

### Correctif CI TEAM-003 — Débordement mobile après promotion

- Commit f0681e7 poussé et migration appliquée par l’utilisateur ; vérification
  en lecture seule : Ran, batch 16. CI frontend 38440198679 : 11 parcours
  Playwright réussis, celui de l’effectif échoué trois fois à la ligne 71 sur
  scrollWidth > innerWidth. Journal fourni par l’utilisateur ; logs GitHub privés.
- Le scénario original reste vert sur Chrome Windows. Reproduction du même
  débordement en utilisant une police monospace à 20 px dans le panneau : la
  colonne implicite de la carte s’élargit selon la largeur minimale du pseudonyme.
  La règle break-words prend le dessus sur overflow-wrap:anywhere dans le CSS
  généré ; le titre peut dépasser la carte malgré un scrollWidth égal à sa largeur.
- Colonnes explicites grid-cols-1 (minmax(0, 1fr)) dans le panneau, ses listes,
  cartes et formulaires ; deux colonnes conservées à partir de sm pour les
  formulaires. Suppression des règles break-words concurrentes, retour à la ligne
  anywhere conservé pour pseudonymes, dates et mouvements. Aucun masquage du
  débordement ni changement de logique métier ou d’API.
- Test Playwright renforcé : contrôle de page conservé à 375/320 px, contrôle
  des titres, paragraphes, listes et cartes avec dimensions dans l’erreur ; même
  vérification avec une police de repli plus large. Défaut reproduit avant le
  correctif, scénario ciblé puis suite complète verts après correction.
- Validation locale : lint/build, 107 tests frontend (20 fichiers), 12 Playwright
  avec Chrome Windows en mode CI/UTC, sans reprise de serveurs existants.
  Le runner Linux reste à confirmer après push ; aucune CI distante déclarée verte.
- Correctif, test, README, backlog et point de reprise dans un seul commit.
  Aucune nouvelle migration ni opération métier réelle. Prochaine étape : push
  et validation des CI par l’utilisateur, puis TEAM-004.

## UCG-TEAM-004 — Compositions datées et instantanés immuables

- Correctif TEAM-003 8a997ab poussé et CI validée par l’utilisateur. Migration
  TEAM-003 Ran, batch 16, contrôlée en lecture seule ; branche propre et
  synchronisée avec origin/staging au début de TEAM-004. Confirmation intégrée
  à ce commit, sans commit documentaire séparé.
- Modèles TeamLineup/TeamLineupEntry, Repository, Service, Journal, Form Request,
  Policy et Resource. API GET paginée/détail, POST brouillon, PATCH replace/
  finalize/cancel. Lecture sports.read organisation ; gestion aussi teams.manage
  organisation, MFA/CSRF/révision/confirmation tenant ; aucun droit implicite.
- Usage match/registration, équipe et jeu stables, date UTC, libellé humain.
  Les fiches des modules Matchs/Compétitions ne sont pas encore implémentées :
  aucun faux rattachement ni UUID de match/inscription accepté. Port public
  HistoricalLineups pour leur intégration, retournant la projection sportive
  figée sans dépendance à leurs modèles ni aux identités People courantes.
- Participants choisis parmi les appartenances player/substitute du tenant et
  de l’équipe couvrant la date [début, fin), une place par personne, ordre explicite
  et au moins un titulaire. La place starter/substitute peut différer de la
  fonction sportive de l’effectif. Captain seul/coach/analyst non sélectionnables.
  Une période closed à une date passée couverte reste admissible, y compris
  pour une personne ensuite partie ; cancelled ne l’est jamais.
- Brouillon modifiable et annulable, états finalized/cancelled terminaux et
  conservés. Figement revalidant équipe, catalogue, source et versions sous
  verrou organisation, avec capture fraîche des pseudonymes et noms sportifs
  puis auteur/date serveur. Renommage, transfert et départ organisationnel
  ne changent ensuite ni l’instantané ni les participants. Pas de reconstitution
  rétroactive de noms inconnus ; une correction exige une nouvelle composition.
- Identités administratives et permissions jamais copiées. Éligibilité complète
  de compétition réservée à TEAM-005 ; changements pendant le match à MATCH-002.
  Audit minimal atomique sans libellés/pseudonymes ; panne d’audit = rollback
  du statut, des versions et du remplacement des participants. Conflit : relecture
  puis nouvelle confirmation, aucun rejeu automatique.
- Migration additive team_lineups/team_lineup_entries, FK composites tenant
  sans cascade, uniques par personne et position, index tenant/équipe/date.
  Gardes SQL SQLite/PostgreSQL : identité, couverture au figement, états terminaux
  et participants figés non réécrits/supprimés ; verrou organisation aussi en PG.
  50 participants et 1000 compositions par équipe, annulées/historiques inclus.
  Pages API ≤100, UI 10 ; participants eager-loaded, nombre de requêtes constant
  testé. Export explicite des deux tables limité au tenant. Aucun benchmark
  de grande échelle. Down refuse les données ; migrations déployées inchangées,
  rollbacks de tests adaptés à l’ordre des FK.
- UI privée depuis l’équipe, filtres et pagination, sélection d’effectif daté,
  titulaires/remplaçants, édition du brouillon et confirmations avant mutation.
  422 conserve la saisie, 409 recharge le contexte, lectures annulées à la
  fermeture/changement de tenant. Pseudonymes/libellés échappés, contrôles mobile
  375/320 avant création, pendant les confirmations et après figement, avec noms
  longs et police de repli. Champs de sélection et confirmation partagés adaptés
  pour ces textes longs ; aucun masquage du débordement.
- Migration 2026_10_13_204834_create_team_lineup_tables vérifiée Pending sur la
  base principale ; aucune migration principale, composition réelle, attribution
  réelle ou e-mail envoyé par l’agent.
- Validation : 49 nouveaux tests backend / 256 assertions ; SQLite complet
  1093 tests, 1090 réussis, 4696 assertions, 3 exclusions attendues ; PostgreSQL 17
  complet : 1093 tests, 1091 réussis, 4697 assertions, 2 exclusions Redis.
  Première relance PG sur base réutilisée interrompue par des fonctions de triggers
  résiduelles ; base temporaire recréée à vide, puis suite complète verte.
  Base temporaire supprimée après validation. Frontend
  115 tests (21 fichiers), lint/build et 13 Playwright verts avec Chrome Windows
  en mode CI/UTC. PHPStan niveau 8, architecture, Pint complet/dirty et
  composer format:check verts. CI PostgreSQL enrichie des quatre fichiers du ticket.
- Code, tests, CI, README, API, architecture, backlog et point de reprise dans
  le même commit. Prochaine étape : push/CI utilisateur, sauvegarde puis
  php artisan migrate depuis back/. Prochain P0 : UCG-TEAM-005, éligibilité sportive.

## Éligibilité sportive — UCG-TEAM-005

- Règlements tenant par contexte de compétition nommé et jeu : tailles minimum/
  maximum de titulaires, maximum remplaçants, plateforme, régions, rangs déclarés,
  âge facultatif, ancienneté d’observation et échéance UTC exclusive. Critères et
  version de contenu immuables ; archivage conservé, nouvelle configuration par
  nouveau règlement. Aucun identifiant de fiche Competition fictive.
- Liaison unique et définitive au brouillon (ou lors de sa création) ; versions
  explicites, équipe/jeu disponibles, règlement actif et échéance non atteinte.
  Nouvelle version de composition à la liaison, pas de remplacement/suppression.
- Pré-évaluation conservée avec motifs fermés passed/failed/unknown ;
  eligible/ineligible/incomplete, refus connu prioritaire. Aucun profil choisi
  arbitrairement : absent/plusieurs/non daté/futur/trop ancien => incomplet.
  Région/rang déclaratifs, sans certification externe ni hiérarchie universelle.
- Finalisation liée : versions d’effectif recontrôlées, nouvelle évaluation et
  rapport final eligible exigés sous la même transaction que le snapshot et
  l’audit. Panne/refus annule rapport, capture et état. Échéance atteinte :
  modification/figement refusés, annulation conservée permise.
- Rapport final et références règlement/version exposés via HistoricalLineups.
  Renommage, transfert, départ, archivage ou modification de profil ne réécrivent
  pas la preuve. Anciennes compositions sans règlement restent unassessed ;
  aucune admissibilité de compétition présumée. Contrat, fonction et éligibilité
  restent trois notions séparées.
- Naissance People facultative YYYY-MM-DD, 1900..aujourd’hui, chiffrée et cachée,
  droits administratifs pour saisir/lire. Âge à la date UTC de composition ;
  29 février => 1er mars en année non bissextile. EligibilityPeopleDirectory
  transmet seulement passed/failed/unknown à Teams ; administrative.read exigé
  pour calculer. Sans ce droit, colonne non sélectionnée. Jamais date de naissance,
  âge numérique ou identité administrative dans rapports/audit.
- Projection People <=50 personnes et <=2 profils par personne, eager loading
  limité ; deux requêtes constantes vérifiées, sans N+1. Listes API <=100/UI 10,
  1000 règlements par organisation, 99 pré-évaluations + 1 place finale par
  composition. Index tenant/jeu/date et tenant/composition/date ; aucun benchmark
  de grande échelle. Contrôle final synchrone pour l’atomicité.
- Migration additive 2026_10_13_215251_create_team_eligibility_rules_and_reports :
  trois tables avec FK composites sans cascade, naissance nullable, critères JSONB
  PostgreSQL/texte SQLite, gardes SQL d’immutabilité, liaison/proof et échéance.
  Comparaison JSON vérifiée sur PostgreSQL ; snapshots/migrations antérieurs
  inchangés. Export explicite des trois tables et naissance chiffrée.
  Down refuse toute donnée avant DDL ; rollbacks de tests adaptés à l’ordre des FK.
- UI privée depuis les compositions : règlements, liaison, pré-évaluation,
  explications et historique. Écritures confirmées dans l’organisation active ;
  409 relecture sans rejeu, fermeture/switch tenant annule les lectures.
  Données échappées, contrôle mobile 375/320 avant/pendant confirmations et
  après figement, noms longs et police de repli plus large. Retour à la ligne
  des textes d’aide renforcé après la première vérification mobile.
- TEAM-004 59023ce : push/CI/migration validés par l’utilisateur ; Ran, batch 17.
  TEAM-005 Pending confirmé en lecture seule ; aucune migration principale ni
  règlement/personne/composition réelle créée par l’agent. Tests seulement sur
  SQLite et base PostgreSQL temporaire, ensuite supprimée.
- Validation locale : 51 nouveaux tests backend / 227 assertions ; suite SQLite
  1144/1141, 4923 assertions (3 exclusions attendues) ; PostgreSQL 17 complet
  1144/1142, 4924 assertions (2 exclusions Redis attendues). 118 tests frontend
  dans 22 fichiers ; 14 Playwright sous Chrome Windows, mode CI/UTC.
  Lint/build, PHPStan niveau 8, architecture, Pint complet/test et format:check
  verts. CI PostgreSQL enrichie des cinq fichiers du ticket ; CI distante à
  confirmer par l’utilisateur après push.
- Code, tests, CI, README, API, architecture, backlog et point de reprise dans
  un seul commit de tâche. Prochaine action : push/CI utilisateur, sauvegarde puis
  php artisan migrate depuis back/. Prochain P0 : UCG-TEAM-006, adversaires et
  organisations externes (références publiques privées au tenant).

## Références externes — UCG-TEAM-006

- Confirmation utilisateur du 14 octobre : TEAM-005 poussé et CI vertes ; migration
  principale appliquée par l’utilisateur et confirmée en lecture seule Ran, batch 18.
- Référentiel tenant organization/team/player : clé stable, nom public/pseudonyme,
  nom court, libellé d’organisation externe, jeu facultatif, source et observation.
  Aucune liaison vers un tenant voisin, personne privée, compte ou permission.
  Les URL sont des citations HTTPS, jamais téléchargées ou prévisualisées ;
  faits publics et autorisés déclarés par l’utilisateur, pas de certification.
- API GET liste/détail, POST et PATCH partiel/archive ; Policy et Form Requests,
  Controller mince, Services et Repository injectés, Resources fermées. Lecture
  sports.read organisation ; écriture aussi teams.manage, MFA, CSRF/révision et
  confirmation du tenant. Aucun droit implicite propriétaire ni périmètre équipe.
- Instantanés avec contexte sportif nommé, version de source, auteur/date et
  faits copiés côté serveur sous verrou organisation/référence ; archive et
  renommage ultérieurs sans réécriture. Port public ExternalOpponents pour les
  futurs modules Match/Competition. Aucune fiche métier fictive ni inscription.
- Audit minimal atomique (UUID/état/version/champs, pas les textes). Panne d’audit
  = rollback de création, édition ou capture. Conflit 409 = relecture et nouvelle
  confirmation ; pas de rejeu automatique. Lectures annulées et réponses tardives
  ignorées au changement de tenant/révision/droits ou fermeture de l’historique.
- Migration additive 2026_10_14_121838_create_external_opponents_and_snapshots :
  deux tables, FK tenant composites sans cascade, identité/archives protégées,
  snapshots append-only et copie cohérente protégées en SQL SQLite/PostgreSQL.
  Export fermé enrichi des tables/colonnes. Down refuse les données avant DDL ;
  tests de rollback des migrations antérieures adaptés à l’ordre des dépendances.
  Migrations déjà déployées inchangées ; aucun backfill.
- Pagination API <=100/UI 10, 1000 références/tenant et 1000 snapshots/référence,
  archives incluses ; index tenant/nom, type/statut, jeu/statut et source/date.
  Relations catalogue eager-loaded ; nombre de requêtes constant testé.
  Pas de benchmark de forte charge, droits non mis en cache.
- UI privée, recherche/filtres, création/édition/archive, provenance visible,
  déclaration de faits publics, capture et historique. 422 conserve la saisie et
  remet le focus sur le champ invalide. Textes échappés ; mobile 375/320 et longs
  noms/sources/contextes vérifiés, avec police de repli, sans débordement global.
- Validation : 68 tests backend ajoutés ; SQLite complet 1212 tests, 1209 réussis,
  5258 assertions et 3 exclusions attendues. PostgreSQL 17 complet : 1212 tests,
  1210 réussis, 5259 assertions et 2 exclusions Redis attendues. Première relance
  sur la base temporaire réutilisée invalide à cause de fonctions de triggers
  résiduelles ; base recréée à vide, suite complète verte, puis base supprimée.
  Base principale consultée uniquement en lecture avec migrate:status.
- Frontend : 129 tests (23 fichiers), lint/build et 15 Playwright verts avec
  Chrome Windows en CI/UTC. Captures mobile inspectées. PHPStan niveau 8,
  architecture, Pint complet/dirty, composer format:check et validate verts.
  CI PostgreSQL enrichie des trois fichiers du ticket.
- Nouvelle migration principale Pending, application réservée à l’utilisateur.
  Aucun adversaire réel, instantané réel, compte, adhésion ou rôle créé, aucun
  e-mail envoyé. Tests sur SQLite, PostgreSQL temporaire et API navigateur simulées.
- Code, tests, CI et documentation regroupés dans le même commit de tâche.
  Prochaine action : push et CI utilisateur, sauvegarde puis php artisan migrate
  depuis back/. Prochaine étape recommandée : documents et contrats, UCG-CTR-001.

## Dossiers contractuels — UCG-CTR-001

- TEAM-006 564dd59 poussé, CI vertes et migration confirmés par l’utilisateur.
  Branche propre et synchronisée au démarrage ; migration Ran, batch 19,
  vérifiée en lecture seule. Phase contrats/documents commencée par CTR-001.
- Dossier privé par tenant : référence et type fixes, titre, responsable People,
  dates, 1..10 parties et jusqu’à 50 obligations. Organisation partie hôte
  implicite ; autres parties People du tenant ou libellés externes déclarés.
  Fiches conservées après départ référençables sans réactivation ni droits IAM.
- Policy/Form Requests, Controller mince, ContractService/ContractRepository,
  ContractLifecycle et Resources fermées. Port AdministrativePeopleDirectory :
  id/libellé uniquement, noms administratifs sans coordonnées ou données sportives.
  Champs privés chiffrés ; nom du responsable copié côté serveur.
- Lecture administrative.read ; gestion aussi administrative.write ; validation
  aussi administrative.validate. MFA/CSRF/révision/confirmation du tenant.
  Pas de droit implicite propriétaire, technique ou sportif ; UUID étranger 404.
- Brouillon → revue → approbation distincte → envoi → signature reçue déclarée →
  activation → échéance/résiliation → archive. Retours contrôlés en revue,
  approbation annulée ; préparateur actualisé à l’édition des termes.
  Termes/parties figés après approbation ; responsable réaffectable ; obligations
  régularisables après clôture mais avant archive. Archive terminale exige
  zéro obligation pending ; remplacement des obligations régularisées refusé.
- Signature déclarée hors plateforme : date non future, référence chiffrée et
  confirmation explicite ; aucune version ou preuve de fichier avant CTR-002/003.
  In_force exige statut active et période UTC courante, date de fin incluse.
  Après échéance, in_force=false même si le statut reste manuel active.
  Sporting_eligibility=not_assessed ; aucun montant avant CTR-004, aucun rappel
  automatique avant CTR-005, signature électronique future CTR-007 (P1).
- Verrou organisation/dossier, droits revérifiés, versions UUID dossier/obligation.
  Historique de statuts append-only et audit minimal UUID/état/version sous
  transaction ; panne d’audit annule toute mutation. Pas de contenu privé dans
  l’audit. Conflit 409 : relecture et nouvelle confirmation, aucun rejeu.
- Migration additive 2026_10_14_184245_create_contract_records_and_lifecycle :
  contracts, contract_parties, contract_obligations, contract_transitions ;
  FK tenant composites sans cascade, index, gardes SQLite/PostgreSQL.
  Identité, transitions, validation distincte, signature, termes, parties,
  obligations et archive protégés en SQL. Export fermé enrichi des quatre
  tables/colonnes, chiffrement conservé. Down refuse les données avant DDL.
  Pas de backfill ; migrations déployées inchangées.
- Pages API ≤100/UI 10, 1000 dossiers/tenant, 200 transitions/dossier.
  Liste avec agrégats, détail eager-loaded et nombre de requêtes constant testé.
  Pas de benchmark à forte charge ; profiler pagination et verrou organisation
  avant de relever ces budgets. Aucun cache de permissions.
- React : recherche/filtres, création/édition partielle, sélecteur People paginé,
  signature déclarée, obligations, historique paginé et archive. Remontage par
  tenant/révision/droits, lectures annulables et réponses tardives ignorées.
  422 conserve la saisie. Textes échappés, noms longs, mobile 375/320 et police
  de repli vérifiés, capture inspectée.
- Validation locale : 65 tests backend ajoutés ; SQLite complet 1277 tests,
  1274 réussis, 5549 assertions, 3 exclusions attendues ; PostgreSQL 17 complet
  1277 tests, 1275 réussis, 5550 assertions, 2 exclusions Redis attendues.
  Base PostgreSQL temporaire distincte recréée à vide avant chaque suite pour
  éviter les fonctions de triggers résiduelles, puis supprimée après validation.
  Budget mémoire PHPUnit 512 Mo pour la suite complète (128 Mo local insuffisant),
  sans changer php.ini/application. Erreur de type du callback eager-loading
  détectée par les tests puis corrigée avant les suites complètes vertes.
- 141 tests frontend dans 24 fichiers, dont 12 nouveaux ; lint/build et
  16 Playwright verts sous Chrome Windows CI/UTC, API métier simulées.
  PHPStan niveau 8, architecture, Pint dirty/format:check et composer validate
  verts. CI PostgreSQL enrichie des trois fichiers Feature Contracts.
- Migration principale CTR-001 Pending, vérifiée en lecture seule.
  Aucun contrat, personne, compte ou rôle réel créé, aucun e-mail envoyé.
  Code/tests/CI/README/API/architecture/backlog/reprise dans un seul commit local.
  Prochaine action : push utilisateur, vérifier CI, sauvegarder puis
  php artisan migrate depuis back/. Prochain P0 : UCG-CTR-002, versions immuables
  des documents et avenants, avec CTR-003 pour leur dépôt privé.

## Versions documentaires et avenants — UCG-CTR-002

- CTR-001 87953e0 : push, CI vertes et migration confirmés par l’utilisateur.
  Branche propre et synchronisée au démarrage ; migration Ran, batch 20,
  vérifiée en lecture seule. Migration CTR-002 additive, jamais exécutée sur
  la base principale par l’agent.
- Document original unique, avenants distincts liés à cet original signé,
  référence/titre/type/origine/date d’effet immuables. Jusqu’à 100 avenants
  par contrat. Date d’effet déclarative, sans modification automatique de dates,
  parties, obligations, statut ou éligibilité sportive du contrat.
- PDF jusqu’à 5 Mo et 100 versions/document ; extension/MIME/en-tête vérifiés,
  nom affiché borné et sans chemin/contrôle. Chaque dépôt conserve un UUID neuf,
  numéro consécutif, empreinte SHA-256 calculée côté serveur, taille claire,
  acteur et date serveur. Titres et noms chiffrés ; fichiers chiffrés.
  Aucun hash, chemin, artefact, numéro ou contenu de preuve accepté du client.
- TenantArtifacts TEN-005 réutilisé pour le stockage privé et les quotas
  (octets chiffrés inclus). Le fichier n’est pas servi ou interprété :
  scan_status=pending/download_available=false, aucune route de téléchargement,
  aucun aperçu, aucune URL ni clé de stockage dans les Resources.
  Analyse de contenu et délivrance sécurisée relèvent du prochain CTR-003.
- Copie reçue hors plateforme déposée comme signed_copy puis identification
  explicite de sa version. Liaison définitive avec UUID/numéro/hash/date/acteur ;
  le document lié ne reçoit plus de version. Original : date de signature égale
  à celle déclarée dans le contrat. Signature externe déclarative, aucun
  certificat de validité ni approbation financière implicite.
  Les contrats signés existants restent sans document inventé.
- Contrôleur mince, Form Request/Gate/Resources fermées,
  ContractDocumentService et ContractDocumentRepository/EloquentRepository.
  Policy Contracts existante réutilisée ; lecture administrative.read,
  gestion aussi administrative.write, copie signée/identification aussi
  administrative.validate. MFA/CSRF/révision/confirmation du tenant actif,
  version UUID du contrat exigée et actualisée à chaque mutation.
  Aucun droit implicite propriétaire/technique/sportif ; références étrangères 404.
- Préparation originale seulement draft/in_review, met à jour prepared_by
  pour maintenir l’interdiction de s’auto-approuver. Copie signée/liaison après
  déclaration du contrat en signed/active/ended/terminated. Avenant exige
  l’original lié ; archive terminale en lecture seule. Pas de montant CTR-004.
- Verrou organisation/contrat, permissions et versions recontrôlées après I/O.
  Réservation durable avant transaction documentaire ; panne de metadata/audit
  annule la mutation et nettoie seulement le nouveau fichier. En cas de nettoyage
  incertain, erreur rapportée et réservation conservée pour réconciliation.
  Versions précédentes jamais écrasées/supprimées. Audit atomique minimal :
  UUID contrat/document/version et état, sans texte, nom, hash ou contenu.
- Migration 2026_10_14_193828_create_contract_documents_and_immutable_versions :
  contract_documents, contract_document_versions, contract_document_signatures.
  FK composites tenant/contrat/document/original/version/artefact sans cascade ;
  index de pages/numéros ; original et liaison signée uniques.
  Gardes SQL SQLite/PostgreSQL sur append-only, numérotation, hash hexadécimal,
  artefact prêt, état du contrat et cohérence de la copie signée.
  PostgreSQL verrouille aussi l’organisation dans les triggers d’insertion.
  Down refuse les données avant DDL ; aucun backfill ni migration déployée modifiée.
- Export fermé TEN-007 enrichi des trois tables/colonnes, chiffrement conservé ;
  fichiers couverts par le ledger existant. Tests de rollback vides de Contracts,
  People, imports, stockage et Tenancy adaptés à l’ordre des nouvelles dépendances.
- Pages API ≤100/UI 10. Listes avec comptages et signature eager-loaded ;
  versions sur endpoint séparé, jamais les fichiers chargés en liste.
  Nombre de requêtes constant vérifié sur 1/101 documents. Budgets techniques,
  pas de benchmark millions d’utilisateurs ; profiler latence de stockage,
  pagination, quotas chiffrés et contention du verrou avant de relever les limites.
- React : panneau dans le dossier, chargement à l’ouverture, documents et
  versions paginés, dépôt multipart, empreinte visible, identification déclarée
  de la copie signée et avenant lié. Après succès, parent rechargé avec sa version.
  Lectures annulables et réponses tardives ignorées ; changement de tenant/
  révision/droits remonte le workspace. 422 conserve saisie/fichier ;
  409 impose relecture et nouvelle confirmation sans rejeu automatique.
  Textes échappés, mobile 375/320, noms longs et police de repli vérifiés.
- 48 tests backend ajoutés ; SQLite complet : 1325 tests, 1322 réussis,
  5783 assertions, 3 exclusions attendues. Tests de transactions, quotas,
  100 versions retenues, conflit entre stockage et metadata, isolation, permissions,
  chiffrement, hash réel, origine signée, rollback, export et UUID majuscules.
  PostgreSQL temporaire recréé à vide avant chaque suite pour éviter les fonctions
  de triggers résiduelles. Ne pas lancer SQLite et PostgreSQL simultanément :
  leurs Storage::fake('local') nettoient le même répertoire, ce qui invalide les
  assertions de fichiers d’une autre suite. Relances réalisées successivement.
- PostgreSQL 17 complet : 1325 tests, 1323 réussis, 5784 assertions,
  2 exclusions Redis attendues. Base de test temporaire supprimée après réussite.
  Les tests de rollback vides imports/stockage ont détecté les nouvelles FK en
  PostgreSQL ; ordre des dépendances corrigé dans les tests, puis suite complète
  verte. Aucun changement de migration déjà déployée ni contournement par CASCADE.
- 152 tests frontend dans 25 fichiers, dont 11 nouveaux ; 17 Playwright verts
  sous Chrome Windows CI/UTC. Le parcours documentaire conserve deux préparations,
  la copie déclarée signée puis un avenant, sans remplacement de l’original.
  API métier simulées ; captures inspectées. Lint/build, PHPStan niveau 8,
  architecture, Pint dirty/format:check et composer validate verts.
  CI PostgreSQL enrichie des trois fichiers Feature documentaires.
- Migration principale CTR-002 Pending, vérifiée en lecture seule.
  Aucun document/contrat/personne/compte/rôle réel créé, aucun e-mail envoyé.
  Code, tests, CI, README/API/architecture/backlog/reprise dans un seul commit local.
  Prochaine action : push utilisateur, CI vertes, sauvegarde puis
  php artisan migrate depuis back/. Prochain P0 : UCG-CTR-003, analyse de contenu
  et téléchargement privé avec autorisation renouvelée et liens temporaires.


## Sécurité documentaire — UCG-CTR-003

- Reprise sur staging propre, synchronisé avec origin/staging, commit CTR-002
  ce268f6. Push, CI et migration validés par l’utilisateur ; Ran, batch 21,
  vérifié en lecture seule. Choix utilisateur : préparer ClamAV.
- Analyse après dépôt, relance explicite des versions anciennes, historique
  paginé. TenantJob reports avec contexte TEN-006 ; persistance et état séparés
  des versions immuables. Aucun fichier supprimé après échec de scan/dispatch.
- Client clamd TCP VERSION/INSTREAM injecté : taille/hash/PDF, date de signatures,
  chunks bornés, deadline monotone, résultat explicitement clean requis.
  Erreur, malware, intégrité, signatures anciennes, réponse ambiguë ou timeout
  bloquent le fichier. Pas de copie claire persistée par PHP, nom/hash/contenu
  absents des logs et audits ; conservation du résultat et moteur en base.
- Dernier clean valable moins de 24 h ; lien signé relatif cinq minutes lié au
  compte et tenant/version/scan, session MFA obligatoire. Autorisation et
  intégrité revérifiées après I/O ; corruption invalidant le clean une fois.
  Un nouveau scan empêche les liens précédents de fonctionner. Réponses PDF
  attachment/private/no-store/nosniff/CSP sandbox, sans accès direct au stockage.
- Demande active idempotente, lease de 300 s, expected_scan_id optimiste,
  maximum 1000 analyses/version. Une lease dépassée est affichée stale pour
  permettre une relance depuis l’interface même après arrêt d’un worker.
  Aucun scan remplacé pendant l’I/O ne peut finaliser un résultat plus récent.
- Permissions administrative.read pour historique/liens/octet ; write en plus
  pour relance, avec confirmation tenant. Copies signées et archives gardent
  leurs données immuables ; leur analyse peut être renouvelée. Aucun champ
  financier ajouté, aucune certification électronique de signature.
- Migration additive 2026_10_14_204339_create_contract_document_scans ; FK tenant
  composites, tentatives consécutives, transitions/dates encadrées, identité et
  résultats finaux retenus, suppression interdite. Down refuse toute analyse.
  Export fermé TEN-007 adapté ; migrations déployées inchangées, aucun backfill.
  Nouvelle migration principale Pending vérifiée en lecture seule.
- UI : états/motifs/date, historique, relance confirmée, actualisation manuelle,
  liens neufs et Blob PDF annulable. URL exacte/compte/scan contrôlés avant GET ;
  redirections refusées, URL Blob libérée. Changement de révision remonte le
  composant ; 401/403/404/409 impose relecture, aucun rejeu silencieux.
- 168 tests frontend dans 26 fichiers ; 17 Playwright verts. Le parcours
  documentaire couvre dépôt/signature/avenant et analyses refusée/clean,
  historique et téléchargement. Textes échappés, 375/320 px, police de repli,
  captures inspectées. API métier/verdicts simulés, aucun document réel créé.
- PHPStan niveau 8, Pint dirty/format:check, composer validate, lint/build verts.
  CI PostgreSQL enrichie de trois fichiers CTR-003 ; tests ClamAV de protocole
  dans la suite générale. Pair TCP local testé : chunks/hash, clean/FOUND,
  réponse inconnue/ambiguë/tronquée, limite, délai et signatures trop anciennes.
- Aucun clamd réel installé : test opt-in ClamAvIntegrationTest (PDF factice et
  EICAR en mémoire) fourni, non exécuté localement. Configurer clamd/freshclam
  avec scan PDF, alertes sur chiffrement/limites et endpoint privé ; TZ=UTC.
  Activer DOCUMENT_CLAMAV_ENABLED seulement avec moteur opérationnel, mettre à
  jour le cache config et redémarrer les workers. Sans analyse réussie, aucun
  téléchargement disponible. Guide exact et test réel dans README section CTR-003.
- 61 cas backend ajoutés. SQLite complet final : 1386 tests, 1382 réussis,
  6031 assertions, 4 exclusions attendues (test PostgreSQL spécifique, deux Redis,
  ClamAV réel opt-in). PostgreSQL 17 complet : 1384 tests, 1381 réussis,
  6026 assertions, 3 exclusions Redis/ClamAV attendues. Après ajustement d’affichage
  des leases expirées : 75 tests ciblés PostgreSQL, tous réussis, 335 assertions,
  incluant les deux nouveaux cas de frontière. Base temporaire recréée avant
  chaque suite puis supprimée ; suites Storage::fake exécutées successivement.
- Un seul commit local pour code, tests, CI et les six documents existants.
  Aucun push, migration principale, installation de service ni donnée métier réelle
  par l’agent ; aucun e-mail envoyé. Prochaine action utilisateur : push, CI vertes,
  sauvegarde/migrate et mise en service ClamAV/worker. Prochain P0 : UCG-CTR-004.


## Correctif CI de comptage documentaire — UCG-CTR-003

- Commit 32100e5 poussé par l’utilisateur. CI backend échouée uniquement sur
  test_version_list_loads_latest_verdicts_without_per_version_queries :
  première lecture 41 requêtes SQL, lecture suivante 39. Migration principale
  CTR-003 vérifiée en lecture seule : Ran, batch 22.
- Échec reproduit exactement en local avec TENANCY_CACHE_STORE=database,
  configuré dans .env.example repris par la CI. Le limiteur HTTP tenant crée
  les deux entrées cache (compteur et timer) sur la première requête, puis les
  réutilise. Le test comptait ces requêtes techniques dans son budget documentaire.
- Correctif limité au test : comparer les requêtes visant les versions/analyses
  sur une puis onze versions (page de dix), et exiger une seule requête de scans.
  DataProvider couvrant array/database, horloge figée, query log nettoyé en finally.
  Middleware, permissions, cache réel, ressources et base métier restent exercés.
  Aucun relâchement en tolérance ±2, aucune modification du code de production.
- Validation : composer test:ci avec TENANCY_CACHE_STORE=database, suite SQLite
  complète 1387 tests, 1383 réussis, 6041 assertions, quatre exclusions attendues.
  PHPUnit JUnit : zéro failure/error. PostgreSQL 17 ciblé : 11 tests du contrôleur,
  tous réussis, 92 assertions, incluant les deux caches. Base temporaire isolée
  créée uniquement pour ce correctif puis supprimée, sans migration principale.
  PHPStan niveau 8, Pint dirty/format:check et composer validate verts.
- Correctif et docs dans un commit local unique. Aucun push effectué par l’agent.
  Prochaine action : pousser ce correctif puis confirmer les CI ; aucune nouvelle
  migration à appliquer pour le correctif. Mise en service ClamAV inchangée :
  test réel opt-in après configuration, aucun téléchargement sans analyse clean.


## Correctif CI frontend des dépôts documentaires — UCG-CTR-003

- Reprise sur staging propre et synchronisé, correctif backend 4fc416b poussé.
  CI frontend signalée en échec : regexp filename sur request.postData() retourne
  null, puis la version attendue ne peut pas apparaître dans le scénario.
- Récepteur HTTP multipart de test ajouté pour observer le corps réellement
  envoyé par le navigateur. Chromium headless a révélé un fichier avec nom vide
  et contenu absent, après sélection sur un formulaire transitoire : cliquer
  l’historique rend brièvement l’ancien état avant que l’effet recharge les
  versions ; le formulaire est ensuite recréé et la sélection perdue.
  setInputFiles ne vérifie pas l’état disabled. Une simple attente du champ
  actif ne suffit pas à synchroniser l’ouverture de l’historique.
- Le test attend désormais le bouton d’historique actif, sa réponse GET versions,
  puis le champ actif avant sélection, et vérifie le nom dans input.files.
  Aucun sleep arbitraire ni augmentation des timeouts/retries. Avant correction,
  quatre échecs sur cinq répétitions documentaires avec le récepteur réel.
- Fixture multipartUpload partagée avec imports.spec.ts : serveur 127.0.0.1 sur
  port éphémère par test, corps original laissé intact par route.continue,
  parser standard Response.formData, noms/octet/contract_version/delimiter
  réellement reçus contrôlés. Hash et taille simulés calculés sur les octets reçus.
  Budget fixture 1 Mo, réception 10 s, erreurs explicites et cleanup en teardown.
  Aucune écriture de fichier clair ; réponses métier toujours simulées.
- Source de l’attente manquante :
  [Playwright actionability](https://playwright.dev/docs/actionability).
  Le récepteur évite aussi la limite d’inspection des fichiers multipart décrite
  dans [Chromium Network.getRequestPostData](https://raw.githubusercontent.com/ChromeDevTools/devtools-protocol/master/json/browser_protocol.json).
- Validation avec Chromium headless 156.0.8078.4/v1248 livré par Playwright :
  cinq répétitions PDF et cinq CSV réussies, retries=0, worker unique, CI/UTC.
  Runtime téléchargé dans front/node_modules/.cache/ms-playwright (ignoré),
  PLAYWRIGHT_BROWSERS_PATH pointant ce cache et PLAYWRIGHT_BROWSER_CHANNEL vide.
  Validation locale Windows ; la CI Ubuntu reste à confirmer après push.
- Validation finale : 17 Playwright complets réussis avec Chromium headless,
  retries=0, JUnit zéro error/failure ; dix répétitions ciblées déjà vertes.
  168 tests Vitest, lint, build et typecheck strict des trois fichiers E2E
  concernés verts. Tests navigateur sous Windows, API métier simulées.
- Aucun changement applicatif ni dépendance npm/PHP ajouté, aucune migration
  ou donnée métier réelle. Tests et deux documents existants dans un commit
  local unique ; aucun push par l’agent. Prochaine action : pousser et confirmer
  les CI. Migration CTR-003 déjà vérifiée Ran, batch 22 lors du correctif précédent.

## Approbations et champs financiers — UCG-CTR-004

- Reprise sur staging propre et synchronisé. L’utilisateur a confirmé les CI
  vertes après les correctifs CTR-003 4fc416b et 1a2c258, puis autorisé CTR-004.
- Conditions financières structurées chiffrées pour le dossier et chaque avenant :
  devise, rémunération/période, prime, part des gains et modalités sensibles.
  Décimales conservées en chaînes ; validation des clés, précision, bornes et
  versions. GET financier privé sans cache, PATCH confirmé avec deux versions.
- financial.read/write/validate restent des habilitations explicites, sans droit
  implicite du coach, propriétaire ni compte technique. Les valeurs financières
  sont absentes des réponses ordinaires et des journaux d’audit. Les archives
  opérateur conservent seulement leurs valeurs physiques chiffrées.
- La présence d’un bloc financier dans le dossier ou un avenant soumet tous ses
  documents à financial.read et leur préparation à financial.write. Analyses et
  téléchargements contrôlés côté service ; un lien signé antérieur ne contourne
  pas une restriction nouvelle. Vérification des droits après I/O conservée.
- Préparateurs et approbateur distincts. Option par dossier pour imposer un
  troisième intervenant déclarant la signature reçue, figée après approbation.
  signature_recorded_by conserve l’acteur des nouvelles déclarations, sans
  reconstruire les anciennes. Aucune signature électronique ajoutée.
- Avenants : dernière préparation PDF et version financière liées à une décision
  immuable ; historique paginé. Nouvelle préparation ou modification financière :
  réapprobation obligatoire et ancienne copie signée inutilisable pour la nouvelle
  décision. La liaison de copie est enregistrée dans la transaction du dépôt.
  Signatures historiques conservées ; dossier archivé et avenant signé figés.
- Interface : panneaux financiers à la demande, décimales en texte, confirmation
  tenant, examen explicite d’avenant, séparation des intervenants, historique,
  contrôles de droits et invalidation sur 403/409 sans rejeu d’écriture.
- Migration additive 2026_10_14_231650_add_contract_financial_governance :
  contract_financial_terms, contract_amendment_approvals, contract_approved_copies,
  option et acteur de signature dans contracts. Aucune migration déployée modifiée.
  SQLite : ALTER natif sur le parent pour préserver les triggers existants.
  Gardes SQL, références composites tenant, rollback refusé en présence d’usage.
  Pas de backfill, de rôle attribué ni de donnée métier réelle créée.
- Les tests manuels d’anciennes migrations ont été adaptés pour retirer/recréer
  CTR-004 avant/après leurs dépendances ; les erreurs initiales SQLite/PostgreSQL
  étaient dues à cet ordre incomplet. Les invariants restent vérifiés.
- Validation finale : composer test:ci avec TENANCY_CACHE_STORE=database :
  1 417 tests, 1 413 réussis, 6 231 assertions, quatre skips attendus
  (spécifique PostgreSQL, deux Redis réels et ClamAV réel opt-in).
  PostgreSQL 17 : 212 tests ciblés contrats et rollbacks réussis, 969 assertions,
  cache database, base dédiée ucg_ctr004_test_20261015_0040 supprimée ensuite.
  Analyse PHPStan niveau 8, Pint et composer validate --strict réussis.
- Front : 180 Vitest, lint et build réussis ; 18 Playwright Chromium headless,
  worker unique, CI/UTC, retries=0. Nouveau scénario mobile passé trois fois de
  suite, puis relancé après ajustement des données simulées de signature.
  Capture finale relue sans débordement ni montant affiché au coach. Typecheck
  strict E2E effectué. API métier du navigateur simulées, aucune donnée réelle.
- Vérification finale en lecture seule : CTR-003 Ran batch 22 ; CTR-004 Pending
  sur la base principale. Aucun migrate, seed, push, envoi d’e-mail ni installation
  de service exécuté par l’agent sur l’environnement métier.
- Un commit local regroupe code, tests et six documents existants. Suite :
  push utilisateur, CI distante, sauvegarde et migration CTR-004, attribution
  explicite des habilitations financières puis essai avec plusieurs intervenants.
  ClamAV réel/freshclam et worker reports toujours à confirmer selon le README.
  Prochain P0 après validation : CTR-005, échéances et rappels.


## Échéances et rappels — UCG-CTR-005

- CTR-004 5205b77 poussé, migration et CI verte confirmées par l’utilisateur.
  Lecture seule en début et fin de tâche : CTR-004 Ran batch 23, CTR-005 Pending.
  Aucun migrate principal exécuté par l’agent.
- Choix utilisateur : notifications dans l’application et e-mail ; SMTP Laravel
  préparé maintenant, Brevo API ultérieur via ContractReminderMailer. Aucun
  logiciel installé, secret SMTP renseigné ni e-mail réel envoyé pendant la tâche.
- Réglages par dossier : activation explicite, préavis/renouvellement facultatifs,
  fin portée par le contrat, délais distincts 90/30/7 par défaut, 0 à 365 jours.
  Le responsable lié à un compte vérifié doit conserver son adhésion, sa MFA et
  ses droits administratifs et financiers si nécessaires. Aucun envoi à une
  adresse privée fournie par le navigateur, aucun propriétaire impersonné.
- ProcessContractReminders réutilise le scheduler et les jobs système TEN-006.
  Lots bornés, dates calendaires dans le fuseau tenant, rattrapage du seul seuil
  atteint le plus urgent ; brouillons, contrats terminés/archivés, plans désactivés
  et dates dépassées ne produisent aucun nouveau rappel. Une modification des
  dates, du responsable, des canaux ou du statut est recontrôlée avant e-mail.
- Journal durable par occurrence et canal, clé unique et reprise dédupliquée ;
  boîte personnelle et historique paginés sans N+1 sur les lectures. Les droits
  et l’affectation sont revérifiés à la consultation. Les e-mails ne contiennent
  aucun titre, date, nom, montant, pièce jointe ou lien de téléchargement privé.
- SMTP : pending avant prise en charge, sending avant appel réseau, sent après
  acceptation ; exception ou envoi abandonné devient uncertain (cinq minutes
  pour un sending). Aucune relance automatique ambiguë. Vérification fournisseur
  puis décision humaine auditée, avec consentement au risque de doublon avant
  retry. Maximum vingt tentatives. L’acceptation SMTP ne prouve pas la livraison
  finale ; aucun exactly-once fournisseur revendiqué.
- Migration 2026_10_15_184218_create_contract_reminders : deux tables, références
  composites tenant, index et gardes d’identité SQLite/PostgreSQL, rollback
  interdit si données utilisées. Inventaire fermé d’export actualisé ; aucun
  contrat existant activé ni backfill. Anciennes migrations conservées, tests de
  round-trip adaptés à la nouvelle dépendance.
- API : réglages et historique par contrat, boîte du compte connecté, résolution
  d’un e-mail incertain. Form Requests, services/repository, policies, Resources,
  CSRF/révision/confirmation tenant, versions optimistes, erreurs françaises et
  notifications Flasher. Audit minimal configuration/décision avec UUID du rappel.
- Front : réglages à la demande, deux canaux, historique et pagination, décision
  explicite après incident, boîte personnelle et accès au dossier. Lectures
  annulées, invalidation sur refus/conflit. Le test mobile a révélé puis vérifié
  le correctif d’ouverture d’un dossier déjà sélectionné depuis sa notification.
- Validation : composer test:ci avec cache database, 1 445 tests dont 1 441 réussis,
  6 372 assertions et quatre skips attendus (cas spécifique PostgreSQL, deux Redis
  réels et ClamAV opt-in). PostgreSQL 17 : 239 tests contrats/rollbacks réussis,
  1 106 assertions. Base dédiée recréée entre suites, car migrate:fresh ne retire
  pas les fonctions PostgreSQL des migrations historiques ; base temporaire
  supprimée ensuite, jamais la base principale.
- En revue finale, le filtre de boîte sans personne liée a été rendu compatible
  avec les UUID PostgreSQL (IS NULL plutôt que chaîne vide), avec test dédié.
  Les délais exigent des entiers JSON stricts pour empêcher la combinaison
  30 et "30" de contourner l’unicité. Les 30 tests ciblés finaux passent sur SQLite et PostgreSQL (147 assertions
  chacun), y compris ces bornes.
- Front : 188 tests Vitest dans 29 fichiers, lint et build réussis, typecheck
  strict du nouveau test E2E. Les 19 parcours Chromium passent sans retry ; le
  nouveau parcours mobile passe aussi trois fois de suite. Capture relue à
  390 px sans débordement. API métier simulées, transport Laravel en mémoire.
  PHPStan niveau 8, Pint et composer validate --strict réussis.
- Un seul commit local réunit code, tests et les six documents existants.
  Suite utilisateur : push, CI distantes vertes, sauvegarde puis migrate dans
  back/. Configurer scheduler/cache partagé/worker default puis activer
  CONTRACT_REMINDERS_ENABLED. L’e-mail peut rester désactivé en attendant SMTP ;
  activer CONTRACT_REMINDERS_EMAIL_ENABLED seulement une fois le service prêt.
  Configuration et limites documentées dans README ; aucun service actif créé
  par l’agent. Prochain P0 après validation : UCG-CTR-006, consultations et exports.


## Audit des accès sensibles — UCG-CTR-006

- CTR-005 cadd683 poussé, CI verte et migration confirmées par l’utilisateur.
  Vérification en lecture seule : CTR-005 Ran batch 24 ; migration CTR-006 Pending.
  Le démarrage s’est fait sur staging propre et synchronisée avec origin/staging.
- Journal dédié organization_sensitive_access_events exposé par le port public
  Tenancy TenantSensitiveAccessJournal. DTO et actions fermés ; seules les
  métadonnées acteur/tenant/UUID cible/requête/action/motif catégorisé/date/IP
  facultative sont persistées. Aucun nom, montant, titre, nom de fichier, URL
  signée, empreinte, contenu contractuel ou motif libre.
- AuditContractAccess couvre les consultations HTTP réussies de Contracts,
  y compris données financières, listes documentaires, versions, approbations,
  analyses et rappels. Les contrôles actuels des Form Requests et policies sont
  conservés. La liste est tracée par une cible collection/organisation ; les
  détails et téléchargements par leur UUID de ressource. Les appels internes
  de jobs ne sont pas présentés comme des consultations humaines.
- X-Access-Purpose facultatif accepte operational, internal_control ou legal_review.
  Le contenu libre est rejeté en 422 ; la catégorie n’est qu’une déclaration.
  Les URL signées ne sont pas modifiées. Création de lien et autorisation de
  servir un PDF restent distinctes ; aucune réception client prétendue.
- Écriture synchrone avant remise de la réponse. Si l’audit échoue, réponse 503
  ACCESS_AUDIT_UNAVAILABLE, message français et Retry-After 30, sans corps métier
  JSON/PDF. Logs techniques limités à la classe d’erreur et aux identifiants.
  Les réponses contractuelles et le journal tenant sont private, no-store.
- Portabilité opérateur TEN-007 existante : demande et relance journalisées dans
  leur transaction, génération avec passage à ready, pas de seconde génération
  lors d’une reprise déjà ready. Lecture du stockage suivie d’un nouveau contrôle
  de l’opérateur et de l’opération, puis audit avant restitution du flux.
  Le flux est fermé si contrôle ou audit échoue. Motif libre non recopié ;
  catégorie operator_portability. Aucun nouvel export CSV métier ni endpoint
  navigateur d’archive opérateur ajouté.
- Source tenancy.sensitive intégrée à la timeline IAM-006, sans nouveau droit.
  audit.view et MFA actuels requis, profils techniques exclus, aucun droit de
  lecture financière conféré par la lecture des seules métadonnées d’audit.
  Curseurs chiffrés liés au tenant, à l’auditeur et aux filtres. La consultation
  du journal lui-même reste dans tenancy.audit pour éviter la récursion.
- Front : Journal des accès sensibles dans l’espace organisation, chargement
  à la demande, filtres action/compte, curseurs précédent/suivant, affichage dans
  le fuseau tenant, identifiants longs coupés, annulation des lectures et retrait
  du journal après perte de droits. Aucun contenu arbitraire before/after rendu.
- Migration additive 2026_10_15_192732_create_organization_sensitive_access_events :
  FK tenant/acteur, index tenant/date, tenant/acteur/date et tenant/action/date,
  cohérence SQL action/type de cible, gardes append-only et rollback refusé en cas
  d’usage. Anciennes migrations conservées. Round-trip Organization adapté.
  Inventaire fermé d’export mis à jour avec les colonnes de métadonnées.
- Validation PostgreSQL 17 : 328 tests contrats, audit, portabilité et anciens
  rollbacks réussis, 1 560 assertions. La garde SQL a nécessité des parenthèses
  autour de CASE dans IF PL/pgSQL ; corrigée dans la migration non déployée.
  Les 23 tests dédiés passent aussi seuls sur PostgreSQL (156 assertions).
  Base temporaire recréée entre suites puis supprimée ; aucune migration de la
  base principale. Les tests SQLite et PostgreSQL sont lancés successivement
  pour préserver l’isolation des fichiers Storage::fake.
- Une première passe complète SQLite a rencontré un timeout dans le simulateur
  TCP fake-clamd sur le cas base antivirale ancienne. Les dix tests scanner
  repassent isolément avec les 23 tests d’audit, sans modification du scanner ni
  de ses assertions. Aucun service antivirus réel installé ou sollicité.
- Front : 194 tests Vitest dans 30 fichiers, lint et build réussis ; typecheck
  strict du scénario E2E. Les 20 parcours Chromium passent sans retry ; le
  nouveau parcours mobile passe trois fois de suite. Capture 390 px relue
  sans débordement. API métier simulées, aucun document réel.
- Validation complète finale : composer test:ci avec cache database, 1 470 tests,
  1 466 réussis, 6 540 assertions et quatre skips attendus (cas PostgreSQL,
  deux Redis réels et ClamAV réel opt-in). Aucun échec à cette seconde passe.
- PHPStan niveau 8, Pint et composer validate --strict réussis. Les attentes
  de deux tests existants ont été ajustées pour la cinquième source de timeline
  et l’événement de demande désormais inclus dans le snapshot d’export
  (sept lignes au lieu de six). Bornes de requêtes et contrôles de fuite conservés.
- Un seul commit local regroupe code, tests et les six documents existants.
  Suite : push utilisateur, CI distante, sauvegarde et migrate depuis back/,
  puis redémarrage des workers reports pour charger l’audit des générations.
  Créer la nouvelle table avant de rouvrir les consultations. Aucun push,
  e-mail, export réel, migration principale ou installation de service par l’agent.
  Après validation, P0 Contrats implémentés ; prochain P0 : UCG-SPON-001.
  Signature électronique CTR-007 et Brevo API restent ultérieurs ; ClamAV et
  rappels SMTP réels nécessitent encore leur validation d’exploitation.

## Prospects et contacts partenaires — UCG-SPON-001

- Démarrage sur staging propre, 8dd344d présent sur la référence locale
  origin/staging. Migration CTR-006 vérifiée en lecture seule : Ran, batch 25 ;
  CI distante précédente non confirmée dans cette tâche.
- Nouveau parcours Partnerships : sociétés prospects, contacts commerciaux,
  opportunités et historique d’échanges renseignés manuellement. Les contacts
  restent propres au dossier, sans fiche People, compte utilisateur ou adhésion.
  Aucun e-mail ni message externe n’est envoyé en consignant un échange.
- Permissions explicites partnerships.read/write, MFA et preuve de session ;
  aucun droit implicite pour propriétaire/administrateur, ni accès métier pour
  un compte technique plateforme. Tests négatifs RH, contrats et équipes.
  Création du rôle par le propriétaire testée avec audit et privilège MFA,
  sans attribution automatique à un compte existant.
- Form Requests fermées, Policies sur chaque action, Services et repository,
  Resources explicites, pagination 1–100 et recherche par préfixe littéral.
  Réponses privées no-store, confirmation/révision tenant, versions optimistes
  et erreurs françaises/Flasher. Les droits sont relus sous verrou organisation.
- Migration additive 2026_10_15_211008_create_partnership_prospects_and_contacts :
  sponsors, sponsor_contacts, sponsorship_opportunities et sponsorship_interactions.
  FK composites tenant/dossier et liens contact/opportunité, identités immuables,
  suppression SQL interdite, archives figées et échanges append-only sur SQLite
  et PostgreSQL. Aucun backfill ni seed. Rollback refusé en présence de données.
  Catalogue complet de portabilité et round-trip Organization mis à jour.
- Mutations et audit dans la même transaction ; audit limité aux UUID, type et
  statut, sans coordonnées ni compte rendu. Un échec d’audit annule l’écriture.
  Statuts partenaire/gagnée déclaratifs : aucun accord, montant, contrat, accès
  externe ou exposition approuvée n’est créé. Ces fonctions restent SPON-002+.
- React : panneau chargé à la demande, listes paginées, contacts, opportunités,
  modification des statuts, consignation d’échange depuis un contact/opportunité,
  historique conservé après archivage et confirmation des mutations. Lectures
  annulées et contexte invalidé après refus/conflit. Affichage des dates dans
  le fuseau de l’organisation ; texte libre échappé.
- Validation finale SQLite avec cache database : composer test:ci, 1 515 tests,
  1 511 réussis, 6 833 assertions et quatre skips attendus (cas PostgreSQL,
  deux Redis réels et ClamAV réel opt-in). Aucun échec.
- Validation PostgreSQL 17 finale : 88 tests partenariats/rôles/round-trip
  réussis, 504 assertions, avec cache database. Base temporaire créée pour la
  suite puis supprimée ; exécution successive à SQLite pour préserver l’isolation.
- Front : 203 tests Vitest dans 31 fichiers ; les neuf nouveaux tests passent
  aussi isolément. Lint et build réussis, typecheck strict du scénario E2E.
  Les 21 parcours navigateur passent sans retry dans Chrome local ; API métier
  simulées, transport Laravel en mémoire. Le navigateur Chromium Playwright
  n’étant pas installé, Chrome existant a été utilisé sans installer de logiciel.
  Capture mobile à 390 px relue, sans débordement et avec texte script échappé.
- PHPStan niveau 8, Pint complet et composer validate --strict réussis. La CI
  PostgreSQL existante inclut désormais les trois fichiers de tests Partnerships.
- Aucun migrate principal, seed, attribution réelle de rôle, création de prospect
  réel, e-mail, push ni installation de service par l’agent. Migration principale
  vérifiée Pending en lecture seule. Code, tests et six documents existants
  regroupés dans un seul commit local de tâche.
- Suite utilisateur : revue/push, CI distantes vertes, sauvegarde puis migrate
  depuis back/, attribution explicite des droits via IAM-003 et recette avec un
  compte partenariats. Prochain P0 : UCG-SPON-002, accords et périmètres d’exposition.
  Mise en service ClamAV et rappels SMTP réels du bloc Contrats toujours à confirmer.

## Accords et périmètres d’exposition — UCG-SPON-002

- Démarrage sur staging propre et synchronisé avec origin/staging, commit
  SPON-001 68ec32e. CI et migration précédentes validées par l’utilisateur ;
  migration SPON-001 vérifiée Ran, batch 26, en lecture seule.
- Accords privés : référence immuable, période, responsable habilité,
  opportunité facultative du même sponsor, contrat sponsor facultatif et
  historique en ajout seul. Conditions numéraire/nature/mixte chiffrées,
  montants décimaux sous forme de chaînes, accès financier séparé et lecture
  tracée avant réponse. Audit indisponible : 503 sans données sensibles.
- Nouveau droit partnerships.validate, sans attribution automatique. Préparation
  et approbation par deux acteurs distincts, y compris l’auteur des conditions
  financières. L’approbateur dispose aussi de financial.read/validate ; chaque
  modification des conditions, du dossier ou d’un périmètre annule l’approbation.
- Périmètres organisation, équipe active ou joueur actif/remplaçant non parti,
  avec référence d’autorisation obligatoire. Validation des sujets par ports
  publics minimaux People/Teams ; responsables par Tenancy/Identity et pièces
  via Contracts. Aucune fiche RH, adresse électronique ou droit documentaire
  supplémentaire exposé. Événements et contenus refusés faute de référentiel.
- L’exposition n’est autorisée que pendant la période, avec approbation,
  responsable/approbateur toujours habilités, sujet éligible et contrat associé
  toujours actif à la version liée. Toute publication reste privée. Exclusivité
  par catégorie conservatrice sur toute l’organisation et les périodes approuvées
  qui se chevauchent. L’archivage du sponsor stoppe l’exposition mais conserve les
  réservations ; terminer l’accord auparavant si elles doivent être libérées.
- Pièces : réutilisation des versions privées Contrats, autorisations
  administratives/financières, analyse ClamAV et liens temporaires existants.
  Le rattachement est un choix administratif explicite ; vérifier humainement
  la cohérence de la partie externe. Aucune signature électronique nouvelle.
- Architecture MVC, Form Requests fermées, Policies, Services, Repository,
  Resources explicites, tenant confirmé pour les mutations et versions optimistes.
  Transactions/verrou organisation sérialisent les mutations et exclusivités ;
  audit minimal sans montant, texte libre ni référence d’autorisation.
- Migration additive 2026_10_16_000918_create_sponsorship_agreements_and_exposures :
  sponsorship_agreements, sponsorship_agreement_terms, sponsorship_exposures et
  sponsorship_agreement_events. FK composites, identités immuables, conditions
  cohérentes d’approbation, enfants modifiables seulement au brouillon, archives
  figées, historique append-only et suppression interdite sur SQLite/PostgreSQL.
  Rollback refuse les données existantes ; aucun backfill, seed ou rôle créé.
  Inventaire de portabilité et ordre des round-trips Organization/People/Contracts
  adaptés aux nouvelles dépendances, sans relâcher leurs assertions.
- React : onglet Accords chargé à la demande, listes/références/historique paginés,
  préparation, périmètres, consultation financière distincte, pièces et transitions
  confirmées. Libellés responsables groupés, sujets vérifiés par lots, borne de
  100 périmètres conservés par accord. Contexte invalidé sur refus/conflit,
  lectures annulables, texte échappé et capture mobile 390 px relue sans débordement.
- Validation frontend : 212 tests dans 32 fichiers, lint, build et typecheck strict
  E2E réussis ; 22 parcours Chrome local réussis sans retry, API métier simulées.
  Un échec ponctuel du scénario existant de départ sous charge a été suivi de
  trois réussites isolées puis d’une suite complète verte ; aucun changement de
  ce scénario ni de sa fonctionnalité. Aucun navigateur installé.
- Validation backend finale avec cache database : composer test:ci, 1 552 tests,
  1 548 réussis, 7 077 assertions, quatre skips attendus (cas PostgreSQL,
  deux Redis réels et ClamAV réel opt-in). PHPStan niveau 8, Pint complet et
  composer validate --strict réussis. Les tests TCP ClamAV exigent l’autorisation
  de réseau local hors bac à sable ; leurs premiers échecs n’ont pas entraîné de
  changement du scanner ou de ses tests. Suite complète verte après autorisation.
- Validation PostgreSQL 17 finale : 118 tests partenariats, accords, schémas
  People/Contracts/Organization et architecture réussis, 660 assertions, avec
  cache database. Base temporaire créée puis supprimée ; aucun chevauchement
  avec la suite SQLite. La CI PostgreSQL inclut les trois nouveaux fichiers.
- Aucun migrate principal, seed, donnée métier réelle, attribution réelle de rôle,
  e-mail, push ni installation de service par l’agent. Migration SPON-002 Pending.
  Un seul commit local regroupe code, tests, CI et six documents existants.
- Suite utilisateur : revue/push, CI vertes, sauvegarde puis migrate depuis back/.
  Recette avec deux membres habilités et ClamAV opérationnel pour les pièces.
  Prochain P0 : UCG-SPON-003, campagnes, livrables, échéances et preuves de
  réalisation. La référence d’autorisation d’exposition ne prouve pas une livraison.

## Correctif CI PostgreSQL SPON-002 — dépendances Catalogue/Teams

- Commit a899bf2 poussé, branche staging propre et synchronisée au démarrage.
  Journal CI fourni par l’utilisateur : 3 échecs, 1 361 tests réussis et 2 skips
  dans l’étape PostgreSQL. Migration principale vérifiée en lecture seule :
  SPON-002 Ran, batch 27. Aucun migrate ni rollback principal par l’agent.
- Cause reproduite sur PostgreSQL 17 : les deux round-trips de
  GameCatalogueSchemaTest et celui de TeamSchemaTest supprimaient teams alors que
  sponsorship_exposures la référençait encore par exposure_team (SQLSTATE 2BP01).
  La sélection PostgreSQL locale précédente de 118 tests ne couvrait pas ces
  scénarios ; la suite SQLite complète ne détectait pas ce blocage PostgreSQL.
- Correctif limité aux prérequis de ces trois tests : retirer la migration
  SPON-002 avant les tables parentes, puis la recréer après elles. Assertions,
  contraintes FK et code métier conservés ; aucun CASCADE ni test désactivé.
  Vérification de tous les appels de rollback teams dans les tests : le
  round-trip Organization traitait déjà cette dépendance.
- Validation ciblée après correction : 29 tests, 70 assertions réussis sous
  SQLite puis PostgreSQL, avec cache database. Les trois erreurs PostgreSQL ont
  été reproduites avant modification. Pint dirty, contrôle de formatage complet
  et PHPStan niveau 8 passent.
- Pour la validation PostgreSQL complète, utiliser une nouvelle base temporaire
  par invocation PHPUnit. Une première relance après les tests ciblés dans la
  même base a rencontré des fonctions de triggers déjà présentes lors du
  migrate:fresh ; cette erreur d’orchestration locale ne justifie aucune
  modification des migrations métier. Bases temporaires supprimées après usage.
- Validation finale : sélection PostgreSQL complète extraite des 64 chemins du
  workflow backend, cache array comme en CI, sur une base temporaire neuve.
  1 366 tests, 1 364 réussis, 2 skips Redis attendus, 6 255 assertions, aucune
  erreur ni échec. Base supprimée après exécution. Aucun changement frontend ;
  pas de nouvelle recette navigateur pour ce correctif de prérequis de tests.
- Un commit local regroupe correctif et documentation. Aucun push, seed, e-mail
  ou changement de données métier par l’agent. Suite : push utilisateur puis CI
  backend/frontend vertes ; aucune migration supplémentaire pour ce correctif.

## Campagnes et livrables partenaires — UCG-SPON-003

- Démarrage sur staging propre et synchronisé avec origin/staging, correctif
  SPON-002 8fa6376 poussé. CI et migration validées par l’utilisateur. Migration
  SPON-002 vérifiée en lecture seule : Ran, batch 27.
- Campagnes privées liées aux accords, période incluse dans l’accord, responsable
  membre habilité. Promesses publication/vidéo/présence événementielle/placement
  de marque, chacune liée à un périmètre du même accord, un responsable et une
  échéance ISO 8601 avec fuseau. Le type événementiel ne crée pas de référentiel.
- Cycle promis → livré → validé : livraison déclarée avec date non future et
  référence de preuve obligatoire, distincte d’une validation. Validation par un
  membre avec partnerships.read/write/validate, différent du préparateur, du
  responsable et du livreur. Aucun droit financier induit ou exigé pour cette
  revue opérationnelle. Accord approuvé et périmètre encore autorisé exigés.
- Statut historique préservé si accord/périmètre changent, avec indicateur de
  correspondance actuelle. Réouverture par un validateur : capture chiffrée de
  l’ancienne preuve puis retrait de la preuve/validation courantes. Annulation
  définitive possible pour promis/livré. Aucune exposition/publication déclenchée.
- Tableau tenant des campagnes actives : compteurs distincts et retards promis
  paginés, contexte sponsor/accord/campagne et noms de responsables, sans preuve
  détaillée ou montant. Retard : échéance passée et état promis ; une livraison
  tardive conserve son indicateur séparé. Actualisation après mutation, bouton de
  relecture et affichage selon le fuseau tenant ; champs de saisie avec fuseau local.
- Gardes complémentaires : dates de campagne couvrant les échéances conservées,
  réduction de période d’accord refusée si elle exclut une campagne active,
  campagne archivable seulement après validation/annulation de ses livrables,
  accord/partenaire archivables seulement après archivage des campagnes actives.
  Cela évite de figer une promesse encore à traiter derrière une archive parent.
- Migration additive 2026_10_16_013526_create_sponsorship_campaigns_and_deliverables :
  sponsorship_campaigns, sponsorship_deliverables, sponsorship_campaign_events.
  FK composites tenant/accord/campagne/périmètre, identités et annulations/archives
  immuables, contraintes de livraison/validation indépendante, preuve protégée
  pendant validation, événements append-only. Gardes parent dans la nouvelle
  migration ; aucune migration déployée modifiée. Rollback refuse les données.
- MVC, Form Requests fermées et Policy SPON-002 réutilisée, Services, Repository
  injecté, Resources à liste fermée, confirmation/révision tenant et versions
  optimistes accord/campagne/livrable. Verrou organisation puis dossier ; audit
  transversal minimal en transaction. Preuves/captures chiffrées, sans texte dans
  les journaux transversaux/événements HTTP. Portabilité des trois tables incluse.
- Références/noms chargés par lots, pages bornées à 100 ; tests de nombre de
  requêtes constant pour les périmètres et le tableau. Agrégats de taille fixe,
  pas de cache durable des droits ni traitement externe long dans les requêtes.
  Mesurer contention, agrégats et pagination profonde avant évolution à fort volume.
- Round-trips Organization, People, Contracts, Catalogue (deux scénarios) et Teams
  retirent SPON-003 avant SPON-002 puis le recréent après. La CI PostgreSQL inclut
  les deux nouveaux fichiers de tests. Validation globale sur une base neuve par
  invocation ; aucune réutilisation des fonctions PostgreSQL entre suites.
- Validation SQLite complète avec cache database : 1 580 tests, 1 576 réussis,
  7 348 assertions et 4 skips attendus (cas PostgreSQL, deux Redis réels, ClamAV
  réel opt-in). 144 tests ciblés réussis au préalable, 880 assertions. Pint complet,
  PHPStan niveau 8 et composer validate --strict réussis.
- Validation PostgreSQL 17 finale : sélection CI complète des 66 chemins,
  1 394 tests, 1 392 réussis, 2 skips Redis attendus, 6 526 assertions. Cache array
  comme en CI ; base temporaire neuve créée pour cette seule invocation puis
  supprimée. Aucun échec, notamment sur les six round-trips parent modifiés.
- Frontend : 221 tests dans 33 fichiers, lint/build et typecheck strict E2E réussis.
  23 parcours Chrome local passent sans retry avec API métier simulées. Nouveau
  scénario : campagne, promesse en retard, tableau actualisé, preuve échappée,
  séparation de validation, historique et affichage mobile. Captures à 390 px
  relues ; marges imbriquées réduites, aucun débordement. Aucun navigateur installé.
- Pas de téléchargement de preuve, d’appel externe, de publication, d’audience
  inventée, de portail ou de nouveau dépôt de fichier. Rapports et portail restent
  SPON-004/005 (P1). Les pièces Contrats gardent leurs contrôles/ClamAV existants.
- Aucun migrate principal, seed, rôle attribué, donnée métier réelle, e-mail ou
  push par l’agent. Migration SPON-003 vérifiée Pending. Un seul commit local
  regroupe code, tests, CI et les six documents existants.
- Suite : revue/push utilisateur, CI vertes, sauvegarde puis migrate depuis back/,
  recette avec deux membres habilités. Les nouveaux services/tableau nécessitent
  les tables SPON-003 avant ouverture du parcours. Prochain P0 du volet Live à
  cadrer : UCG-MEDIA-001 ; aucune autre fonctionnalité démarrée automatiquement.

## Streams, VOD et clips — UCG-MEDIA-001

- Reprise sur staging propre et synchronisé avec origin/staging, SPON-003
  aba9205 poussé ; CI/migration validées par l’utilisateur. Vérification en
  lecture seule : SPON-003 Ran, batch 28.
- Catalogue de médias lié à une équipe active du tenant : stream, VOD ou clip ;
  fournisseur Twitch/YouTube, titre, URL normalisée et état scheduled/live/
  ended/unavailable. VOD/clips limités à ended/unavailable. Le statut est une
  déclaration manuelle datée, jamais un score ou une disponibilité vérifiée.
  Une nouvelle source/un nouveau statut exige une observation non future et
  non antérieure à la précédente ; un renommage ne réaffirme pas cette date.
- Validation de domaine MediaLink : HTTPS et hôtes officiels exacts, chemins
  compatibles type/fournisseur, sans authentifiants, port, fragment, encodage
  de chemin ni paramètres de suivi. Aucune requête externe, téléchargement ou
  lecteur. Lien sortant volontaire, sans référent ; titre échappé dans React.
- Droits media.read/write/publish ajoutés au catalogue fermé, sans attribution.
  MFA et droits organisation requis ; droits RH/finance/sport/partenariat
  séparés. Propriétaire et compte technique n’ont pas d’accès métier implicite.
- Privé par défaut, y compris les scrims déclarés : seuls les gestionnaires
  médias y accèdent. Lecteurs simples limités au partage effectif, filtré dans
  les requêtes liste/détail avant pagination ; avant publish_at, aucun ID/URL.
  Archive masquée aux lecteurs ; son filtre exige media.write. Pas de cache
  durable des droits, réponses private/no-store.
- Tout partage maintenu/modifié exige media.publish et confirm_sharing accepté ;
  acteur/date d’autorisation conservés. Scrim : date future explicite lors de
  l’autorisation, reclassification en scrim ou modification de la date. À
  l’échéance, visibilité recalculée à la lecture ; aucun job de publication.
  Retrait/archive possibles avec read/write. Archive définitive, versionnée.
- Visibilité public = autorisation externe enregistrée ; aucune route anonyme,
  page publique ou modification chez le fournisseur. Un lien unlisted/public
  reste soumis aux règles du fournisseur. Aucun contrôle de score/audience.
- Matchs/événements absents des référentiels actuels : seul team_id est accepté.
  Scrim est une déclaration sur le média, sans match inventé. Les futurs liens
  exigeront des ports publics tenant Competition/Scheduling, sans accès direct
  aux modèles voisins. MEDIA-002/003 restent P1/P2.
- MVC, Form Request fermée, Policy pour chaque action, service, repository
  injecté, Resources explicites. Confirmation/révision tenant, version UUID,
  verrou organisation avant dossier/équipe, droits revérifiés en transaction.
  Audit transversal minimal media_id/action, atomique, sans titre/URL.
- Migration additive 2026_10_16_192806_create_media_items_table : media_items,
  FK composite tenant/équipe, états et cohérence de partage, identité immuable,
  archive figée/suppression interdite, contraintes SQLite/PostgreSQL. Rollback
  refuse une table non vide ; aucune ancienne migration modifiée. Inventaire
  d’export opérateur fermé étendu, index tenant/équipe/visibilité.
- MediaTeamsDirectory ne transmet que choix/noms actifs paginés et contrôle de
  référence. Méthodes internes referencePage/referenceLabels partagées avec
  Partnerships, autorisations séparées ; chargement des labels par lots avec
  test d’une seule requête pour toute la page, bornée à 100.
- Round-trips Organization, Teams et Catalogue (deux scénarios) retirent MEDIA-001
  avant les parents puis le recréent après. Aucune assertion existante retirée.
  Les quatre nouveaux fichiers backend sont ajoutés à la sélection PostgreSQL :
  70 chemins au total.
- 52 nouveaux cas backend et 8 tests frontend. Première passe ciblée corrigée :
  fixture d’adhésion créée deux fois et assertion du code JSON ; aucune erreur
  métier masquée. 122 tests ciblés/architecture/parents verts, 576 assertions.
- SQLite complet, cache database : 1 632 tests, 1 628 réussis, 4 skips attendus
  (cas PostgreSQL, deux Redis réels, ClamAV réel opt-in), 7 538 assertions,
  aucun échec. Pint dirty/complet, PHPStan niveau 8 et composer validate réussis.
- Orchestration PostgreSQL locale : ne pas lancer SQLite et PostgreSQL en même
  temps dans ce checkout. Storage::fake nettoie un dossier partagé entre
  processus : la première sélection complète (1 446 cas) a connu trois échecs
  d’exports/maintenance pendant le chevauchement, sans échec MEDIA-001. Relance
  monolithique seule interrompue par la limite locale de 900 secondes. La
  vérification finale exécute la même sélection en sept groupes successifs,
  chacun avec une base temporaire neuve puis supprimée, sans modifier les
  tests métier ni la commande CI pour contourner ces incidents. Les exports
  précédemment en échec passent dans le premier groupe (133 tests/654 assertions).

- Validation PostgreSQL 17 finale : même sélection CI de 70 chemins, exécutée
  intégralement en sept groupes séquentiels sur bases neuves : 1 446 tests,
  1 444 réussis, 2 skips Redis attendus, 6 716 assertions, aucun échec/erreur.
  Les sept bases temporaires ont été supprimées. Rapports JUnit par groupe et
  résumé JSON local ; aucune autre suite backend exécutée simultanément.

- Frontend complet : 229 tests dans 34 fichiers, lint/build et typecheck strict
  du nouveau scénario E2E réussis. Les 24 parcours Chrome passent sans retry,
  API métier simulées ; parcours MEDIA-001 : scrim privé, partage différé,
  confirmation tenant, titre échappé, archive et filtre. Capture mobile 390 px
  relue, aucun débordement ni chargement Twitch/YouTube automatique. Aucun
  navigateur installé ; composant média chargé à la demande.
- Migration principale MEDIA-001 vérifiée Pending. Aucun migrate principal,
  seed, rôle attribué, donnée métier réelle, e-mail, publication ou push par
  l’agent. Code, tests, CI et six documents existants dans un seul commit local.
- Suite : revue/push utilisateur, CI vertes, sauvegarde puis migrate depuis back/.
  Attribution explicite des droits médias et recette avec éditeur/lecteur/publieur.
  Prochain P0 du volet communication à cadrer : UCG-COM-001. Aucun autre ticket
  démarré automatiquement.

## Annonces et discussions ciblées — socle UCG-COM-001

### Livraison et limites

- MEDIA-001 `1f7cc8c` poussé ; CI/migration confirmées par l’utilisateur.
  Migration MEDIA vérifiée Ran, batch 29 ; démarrage COM sur staging propre et
  synchronisé avec origin/staging.
- Socle COM-001 : annonces organisation/équipe/jeu/rôle, brouillons éditables,
  publication confirmée, contenu/ciblage/expiration figés après publication,
  retrait définitif, ouverture des commentaires et discussions paginées.
- Cible équipe/jeu issue de l’effectif effectif du compte lié, équipes et jeux
  actifs ; cible rôle issue des attributions organisation actives de l’adhésion
  courante (ou du propriétaire). Contrôles de permission/MFA indépendants.
  Mode gestion explicite pour read + write, sans droit de participation hors audience.
- Quatre permissions communication.read/write/publish/comment explicites.
  Aucun accès métier implicite propriétaire/superadmin ; aucune attribution réelle.
- Boîte de notifications internes avec préférences personnelles versionnées.
  Événements opaques sans copie du contenu ni liste de destinataires. Remise
  par consultation avec droits/cibles/expiration/préférences actuels, y compris
  pour une notification déjà lue. Réponses sur opt-in pour auteur/participants
  déjà présents ; pas d’auto-notification de l’acteur de l’événement.
  Aucun e-mail, Discord, webhook, worker ou scheduler pour ce socle.
- Titres/corps chiffrés ; React les rend comme texte, sans HTML ni pièce jointe.
  Audit transactionnel limité aux identifiants/actions, contrôle optimiste UUID,
  verrou organisation puis annonce, API fermée et Resources explicites.
- Cinq tables tenant, FKs composites, index et unicités incluant organization_id,
  triggers SQLite/PostgreSQL pour figement/historique, rollback refusé en présence
  de données ; catalogue de portabilité étendu à toutes les colonnes.
  Round-trips Organization, Teams et Catalogue adaptés à l’ordre des dépendances.
- Contrats publics Teams/Tenancy ; aucun accès direct aux modèles inter-modules.
  Références paginées, audiences bornées avec refus explicite au-delà de 1 000,
  visibilité appliquée avant comptage et pagination, chargement des titres inbox
  par lot. Pas de cache durable de droits.
- **COM-001 demeure partiel** : événements et commentaires sur tâches/matchs/séances
  nécessitent leurs futurs référentiels métier. Ces UUID libres sont refusés.
  COM-002 Discord et MOD-001 restent P1 ; aucun autre ticket démarré.
- Six documents existants mis à jour, sans nouvelle documentation autonome :
  README, architecture, conventions API, backlog, CDC et état-projet.

### Vérifications locales

- Backend SQLite complet via composer test:ci : **1 669 tests**, **1 665 réussis**,
  **4 ignorés attendus**, **7 792 assertions**. Inclut 37 nouveaux cas COM-001 :
  autorisations, MFA, chiffrement, versions, transitions, audiences, notifications,
  révocations temporelles, contrôles DB et rollback.
- PostgreSQL dans une base temporaire dédiée, supprimée ensuite : **163 tests
  ciblés réussis**, **845 assertions**. COM controller/audience/schema, architecture,
  contraintes tenant, round-trips Organization/Team/Catalogue, rôles et MFA.
  Cette sélection locale ne remplace pas la suite PostgreSQL complète de CI.
- Le contrôle de schéma a signalé une unicité de commentaire sans organization_id
  lors de la première suite complète. Index corrigé avant la sélection PostgreSQL
  et la relance complète verte, sans assouplir l’inspecteur ni modifier une
  migration déjà déployée.
- Pint dirty puis format:check, PHPStan niveau 8 et composer validate --strict
  réussis. Routes contrôlées : onze endpoints privés de communication.
- Frontend : **239 tests dans 35 fichiers** réussis ; les dix nouveaux tests COM
  ont aussi été relancés après correction du typage des sélecteurs de test.
  Lint et build de production réussis.
- Chrome local : **25 parcours E2E réussis**, API métier simulées. COM sur mobile
  390 px : brouillon équipe, publication confirmée, réponse échappée, préférences,
  notification lue puis retrait ; absence de script exécuté et de débordement.
  Capture inspectée : front/reports/com001-mobile.png. Aucun navigateur installé.
- CI PostgreSQL étendue aux quatre fichiers COM ; CI frontend découvre tests et
  parcours automatiquement. CI distantes de COM-001 encore à valider après push.
- Migration principale COM-001 vérifiée **Pending** ; aucun migrate/seed principal,
  aucune donnée métier, permission ou notification réelle créée par l’agent.
  Aucun push. Un seul commit local contient code, migration, tests, CI et six docs.
- Prochaine action utilisateur : revue/push, CI vertes, sauvegarde puis
  `php artisan migrate` depuis back/. Attribuer les droits via IAM-003 et recetter
  deux comptes (audience autorisée puis révocation) avant utilisation réelle.

## Activités ponctuelles du calendrier — UCG-CAL-001

### Reprise et livraison

- Démarrage sur staging propre, synchronisé avec origin/staging, HEAD
  `8d48651`. Socle COM-001 poussé, CI et migration validées par l’utilisateur ;
  migration vérifiée en lecture seule Ran, batch 30. Ses rattachements contextuels
  restent ouverts ; CAL-001 ne les raccorde pas automatiquement.
- Six routes privées dans Scheduling : liste/détail/création/modification
  d’activités, choix d’équipes et de personnes paginés. Nouvelle paire de tables
  calendar_activities/calendar_participants, sans données ni permissions créées.
- Huit types ponctuels : entraînement, scrim, match, réunion, académie, bootcamp,
  sponsor, déplacement. Début/fin UTC, fuseau IANA d’origine conservé, durée
  positive de 31 jours maximum. Ce sont des activités de planning, sans création
  implicite d’un dossier Match/Académie/accord.
- CalendarTime fait un aller-retour de l’heure et de l’offset dans le fuseau :
  heures inexistantes refusées, heure répétée avec choix explicite de son offset.
  Le frontend propose les occurrences valides via Intl puis le serveur revalide.
  Fenêtre de 1 à 93 dates locales [début, fin[, minuits convertis en UTC ;
  bornes de 23/25 heures testées, journée locale entièrement sautée refusée.
- Affichage selon la personne actuellement liée au compte, sinon le tenant ;
  choix de fuseau pour la vue, fuseau et heures d’origine toujours affichés.
  Agenda chronologique paginé, sans grille mensuelle ou bibliothèque supplémentaire.
- Privé par défaut, y compris scrim : créateur et participants explicites.
  Partage équipe ajoute l’effectif effectif actuel d’une équipe active dans un jeu
  activé ; partage organisation ajoute les lecteurs habilités. Une inscription
  directe et une appartenance d’équipe sont deux voies d’accès distinctes.
  Départ personne/révocation de droits ou adhésion coupent leur voie d’accès.
- calendar.read/write/publish explicites au niveau organisation, MFA. Mode
  gestion explicite read + write pour toutes les activités du tenant. Aucun
  droit métier implicite propriétaire/administrateur/technicien. Partage interne
  soumis à publish et confirmation à chaque modification restant partagée.
- Participants : personnes non parties du même tenant, maximum 50, sans donnée
  RH ou identité globale dans les Resources. Références via contrats publics
  People/Teams ; validation et labels par lot. Repository interne Teams renommé
  TeamAudienceRepository pour partager les mêmes règles avec COM-001, sans
  modifier ses contrats publics ni le graphe des modules.
- Titres/descriptions/lieux/URL chiffrés ; rendu texte simple et liens HTTPS
  volontaires sans référent. Pas de vérification réseau ni d’envoi externe.
- Mutations sous transaction avec verrou organisation puis activité et droits
  recontrôlés, version UUID, audit minimal identifiant/action. Archive définitive
  conservant les participants ; aucun DELETE API ni réouverture.
- FKs composites tenant/activité/personne/équipe, uniques tenant, contraintes
  période et partage, protections d’identité et de conservation dans SQLite et
  PostgreSQL. Catalogue de portabilité étendu aux deux tables et à toutes les
  colonnes physiques ; rollback refusé si données présentes.
  Round-trips Organization/Team/Catalogue/Person adaptés à l’ordre de dépendances.
- Limites conservées : disponibilités CAL-002, conflits CAL-003, convocations et
  rappels CAL-004, ICS CAL-005, reports/annulations et notifications/historique
  CAL-006, récurrences CAL-007 P1. Pas de faux identifiants métier, score ou
  notification ; les raccordements COM/MEDIA exigeront un contrat public.
- Six documents existants actualisés avec les confirmations COM et CAL-001 :
  README, architecture, conventions API, backlog, CDC et état-projet.

### Vérifications et exploitation

- Backend SQLite complet : **1 718 tests**, **1 714 réussis**, **4 ignorés
  attendus**, **7 980 assertions**. 49 nouveaux cas CAL (API, policy, base,
  temps/liens), plus tests existants. Après durcissement du rejet des clés
  littérales à point, 31 cas CalendarController/Time relancés, 147 assertions.
- Sélection SQLite étendue aux parents, COM et architecture : 116 cas,
  115 réussis et un ignoré attendu. Contrôles de schéma/portabilité et comptage
  constant de requêtes avec relation participants eager-loaded.
- PostgreSQL dans une base temporaire dédiée, supprimée ensuite : **137 tests
  réussis**, **573 assertions**, aucun ignoré. Quatre fichiers CAL, round-trips
  Organization/Person/Team/Catalogue, trois fichiers COM et architecture.
  Cette sélection locale ne remplace pas la suite PostgreSQL complète de CI.
- Pint dirty puis correction explicite des fichiers nouveaux omis par la
  sélection dirty, format:check complet, PHPStan niveau 8 et composer
  validate --strict réussis. Aucun ignore/baseline ajouté ni dépendance changée.
- Frontend : **253 tests dans 37 fichiers** réussis, lint sans avertissement,
  build de production et typage strict du parcours E2E réussis. Les tests de
  date couvrent Paris, Kolkata, Lord Howe, Kiritimati et la journée sautée d’Apia.
- Chrome local : **26 parcours E2E réussis**, API métier simulées, sans nouvelle
  installation. CAL sur mobile 390 px : vue Tokyo/origine Paris, seconde occurrence
  de 02 h 30, scrim privé, personne/équipe, lien protégé, renommage n’envoyant que
  le titre/version, archive. Pas de script exécuté, requête externe ou débordement.
  Capture inspectée : front/reports/cal001-mobile.png.
- CI PostgreSQL étendue aux quatre fichiers CAL ; tests frontend/E2E découverts
  automatiquement. CI distantes CAL-001 à valider après push utilisateur.
- Migration principale CAL-001 vérifiée **Pending**. Aucun migrate/seed principal,
  rôle ou donnée métier réelle créé, aucun e-mail/message/notification envoyé.
  Aucun push ni changement d’environnement. Un seul commit local de tâche
  regroupe code, migration, tests, CI et les six documents.
- Prochaine action utilisateur : revue/push, CI vertes, sauvegarde, puis
  `php artisan migrate` depuis back/. Accorder les permissions calendrier
  via IAM-003 et recetter partage, participants et révocation avec deux comptes.
  Prochain ticket recommandé après validation : CAL-002 ; non démarré.

## Disponibilités et absences — UCG-CAL-002

### Reprise et périmètre livré

- Démarrage sur staging propre, synchronisé avec origin/staging, HEAD 3051aa8.
  L’utilisateur a validé la CI et la migration CAL-001 ; vérification en lecture
  seule Ran, batch 31. Aucun migrate principal par l’agent.
- Nouveau panneau tenant « Disponibilités et absences », chargé à l’ouverture,
  indépendant des permissions des activités. Statuts available/unavailable/uncertain,
  commentaire facultatif de 1000 caractères, sans motif médical exigé. Une absence
  de déclaration ne signifie pas disponibilité.
- Trois permissions métier organisationnelles explicites, toutes avec MFA :
  availability.self pour sa personne actuelle liée ; availability.read pour le
  planning collectif ; read+manage pour gérer les autres personnes. Aucun droit
  implicite propriétaire/administrateur ; opérateurs techniques refusés.
- La consultation collective retourne les créneaux et statuts sans commentaires
  ni historique des autres personnes. Les commentaires sont chiffrés dans l’état
  courant et les instantanés. La personne avec self et les gestionnaires peuvent
  les consulter ainsi que l’historique paginé.
- People expose AvailabilityPeopleDirectory, avec identité actuelle, préférence
  de fuseau, choix paginés et labels par lot ; repository de références partagé
  en interne avec CAL-001. Aucun import de modèle intermodule. Les personnes
  parties ne sont plus assignables ; aucun compte ou droit créé par ce choix.
- Sans personne liée actuelle : consultation personnelle vide, saisie personnelle
  expliquée et bloquée. L’accès personnel suit la liaison actuelle du compte,
  pas le créateur du créneau. Les gestionnaires peuvent gérer une personne sans
  compte ; après départ, ils peuvent consulter l’historique ou annuler un ancien
  créneau, sans en réécrire les valeurs.

### Dates, conservation et sécurité

- UTC et fuseau IANA d’origine réutilisent CalendarTime ; horaires d’origine et
  de consultation visibles. Fuseau de la personne puis du tenant, surcharge
  possible pour la vue. Heures inexistantes et offsets incohérents refusés ;
  choix explicite pour l’heure répétée. CalendarTimeChoice extrait pour les deux
  formulaires sans changement de comportement CAL-001.
- Durée positive jusqu’à 31 jours réels, fenêtre 1–93 dates locales avec fin
  exclue, chevauchement [début,fin[, 100 éléments/page. Filtres personne en mode
  collectif seulement, statut et annulé. Périmètre appliqué avant les compteurs
  et la pagination ; tri starts_at/id.
- Les créneaux actifs de la même personne ne peuvent pas se chevaucher, même
  s’ils portent le même statut ; adjacence et autres personnes autorisées.
  Verrou organisation puis créneau, identité/droits recontrôlés, version UUID.
  La création sans ligne préexistante est sérialisée par ce verrou organisation.
  Ce protocole concerne les écritures applicatives ; un accès SQL externe doit
  aussi le respecter.
- Identité personne fixe. Modifier un horaire exige début/fin/fuseau ensemble ;
  l’UI omet les champs inchangés. Une annulation terminale conserve les données
  et libère la période ; aucun DELETE ni réactivation. Un 409 ne rejoue rien.
- Deux tables tenant availabilities/availability_revisions, instantanés numérotés
  append-only copiés avec le texte chiffré conservé. FKs composites et garde de
  période/identité/version/rétention SQLite/PostgreSQL ; rollback refusé si données.
  État courant, instantané et audit minimal disponibilité/action atomiques.
- Contrôleur mince, Service/Repository, Form Request fermé, Policy sur chaque
  action, Resources explicites, CSRF/session/MFA/tenant, confirmation et blocage
  de session. Aucun champ médical ou serveur accepté, clés avec point refusées.
  L’export opérateur conserve seulement les lignes du tenant et leur texte chiffré.
- Pas de cache durable des droits. Labels groupés, pagination des listes,
  références et historiques. Deux index de fenêtre personnelle/collective.
  Profiler requêtes, pages profondes et contention avant d’affiner les index
  ou de changer la granularité des verrous.

### Vérifications et livraison

- 55 nouveaux tests backend : contrôleur 15, Policy 9, schéma 10,
  validation 21. Suite ciblée finale : 55 réussis, 281 assertions.
  Couverture : scope personnel/collectif, commentaires masqués, absence de compte
  lié, personnes étrangères/parties, révocation, historique, annulation, versions,
  chevauchement/adjacence, UTC/DST, rollback d’audit, portabilité et requêtes par lot.
- Suite backend SQLite complète : 1773 tests, 1769 réussis, 4 ignorés attendus,
  8264 assertions. Base en mémoire ; faux ClamAV des tests existants uniquement.
- PostgreSQL ciblé : 192 tests réussis, 854 assertions, aucun ignoré ; quatre
  nouveaux fichiers, CAL-001, round-trips Person/Team/Catalogue/Organization,
  COM et architecture/isolation. Nouvelle base temporaire dédiée, supprimée
  en finally. Ceci ne remplace pas la CI PostgreSQL complète.
- Frontend : 262 tests dans 38 fichiers, dont 9 nouveaux tests du panneau.
  Lint, build et vérification TypeScript stricte du nouveau parcours E2E réussis.
  27 parcours Chrome réussis en mode CI/UTC, sans reprise de serveurs existants
  et sans retry. API métier simulées ; contrôle visuel du screenshot mobile
  reports/cal002-mobile.png, textes échappés et absence de débordement.
- Incident local de vérification : identifiants d’historique dupliqués dans le
  fixture navigateur corrigés. Le bac à sable Windows empêchait le nettoyage des
  serveurs Playwright ; seuls les processus de test identifiés ont été arrêtés,
  puis la suite a été exécutée avec l’autorisation nécessaire. Aucun changement
  de configuration applicative pour contourner ce problème.
- PHPStan niveau 8 sans erreur ni suppression/baseline ; Pint dirty et contrôle
  complet, composer validate --strict. CI PostgreSQL enrichie des quatre fichiers
  Availability ; découverte automatique des tests frontend/E2E.
- Six documents existants mis à jour avec code, tests et CI dans le même commit
  local. Aucun push, migration/seed principal, rôle, personne, créneau métier,
  e-mail/message ou notification réelle créé par l’agent. Aucune dépendance ajoutée.
- Migration principale CAL-002 vérifiée **Pending** en lecture seule après les
  tests ; CAL-001 demeure Ran, batch 31.
- Prochaine action utilisateur : push, CI vertes, sauvegarde puis
  php artisan migrate depuis back/. Accorder les permissions via IAM-003 et
  recetter deux comptes avant utilisation réelle. Prochain P0 recommandé après
  validation : CAL-003, conflits personnes/équipes/ressources, non démarré.
- Les créneaux ne synchronisent pas les états/périodes HUM-003, ne convoquent
  personne et ne déclenchent aucun rappel. Conflits d’activités CAL-003,
  convocations/rappels CAL-004, ICS CAL-005, reports CAL-006, récurrences P1
  CAL-007 restent des lots distincts.

## Conflits de réservation — UCG-CAL-003

### Reprise et périmètre

- Démarrage sur staging propre et synchronisé avec origin/staging, HEAD 2c786d2.
  L’utilisateur a validé la CI et la migration CAL-002 ; vérification en lecture
  seule Ran, batch 32. Aucun migrate principal par l’agent.
- Contrôle des participants explicitement inscrits, de l’équipe associée et des
  ressources communes sur des intervalles UTC [début,fin[. Adjacence permise,
  activité elle-même exclue à l’édition, archives exclues des collisions.
  Aucun roster développé automatiquement : inscrire les personnes mobilisées.
- Disponibilités CAL-002 : unavailable/uncertain incluses uniquement avec
  availability.read ; aucun commentaire sélectionné. Sinon, résultat marqué
  non vérifié pour cette source et avertissement UI. available ne bloque pas,
  absence de déclaration ne signifie pas disponible.
- Référentiel minimal de ressources unitaires room/equipment/other : référence
  unique par tenant, nom chiffré, version UUID, création/renommage/archivage,
  20 ressources maximum par activité. Référence/type immuables, archive terminale.
  Pas de stock, capacité multiple, transport, hébergement ou logistique CAL-008.
- calendar.read/write pour contrôler/réserver et consulter les ressources ;
  calendar.resources.manage pour gérer le référentiel ; calendar.conflicts.override
  pour accepter une exception. Tous organisationnels, avec MFA ; aucun droit
  implicite propriétaire/administrateur/plateforme, opérateurs techniques refusés.
- Les writers sont déjà habilités au mode gestion des activités privées.
  L’aperçu retourne seulement titre/période/références communes/causes, sans
  description, lieu ou URL. L’historique d’exception exige aussi availability.read
  car les instantanés peuvent contenir d’anciennes indisponibilités.

### Contrôle et atomicité

- Même préparation métier pour aperçu/sauvegarde, notamment PATCH partiel avec
  horaires/participants/ressources effectifs. Les règles CAL-001 de fuseau,
  validation, partage confirmé, références tenant et durée restent appliquées.
- Aperçu POST privé sans confirmation préalable, avec CSRF/session/révision ;
  aucun champ privé dans une URL. Résultat borné à 100 conflits ; dépassement
  bloquant même avec droit d’exception. Références et historique paginés.
- Jeton Laravel chiffré/authentifié, cinq minutes, lié à l’acteur/tenant,
  activité/version, horaires, personnes, équipe, ressources et résultats.
  Toute modification du formulaire annule le contrôle et ses lectures en cours.
- Enregistrement après confirmation existante du tenant ; verrou organisation
  puis activité, droits/références/versions recontrôlés et conflits recalculés.
  Créations sans ligne préalable aussi sérialisées. Un changement entre aperçu
  et sauvegarde produit 409 et exige une nouvelle décision ; aucun rejeu automatique.
- API antérieure conservée pour une réservation sans conflit : aperçu facultatif
  mais vérifié s’il est transmis. Tout conflit exige aperçu courant, permission
  d’exception, confirmation explicite et motif opérationnel de 10–1000 caractères.
  Chaque nouvelle écriture restant conflictuelle exige un nouvel examen, sans
  dérogation permanente héritée d’une ancienne exception.
- Activité, participants, réservations, journal d’exception et audit minimal
  identifiant/action/nombre sont atomiques. Motif et instantané chiffrés,
  journal append-only avec activité/version/acteur/date ; aucun motif privé dans
  l’audit générique. Panne d’audit annule les nouveaux enregistrements.
- Trois tables additives calendar_resources/calendar_reservations/
  calendar_conflict_overrides, FKs composites tenant, contraintes de référence
  et rétention SQLite/PostgreSQL. L’archive d’activité conserve ses réservations
  et les libère du contrôle ; l’archive de ressource interdit nouvelles affectations
  et déplacements la conservant. Down refuse les données de réservation.
- Inventaire de portabilité physique et round-trips CAL-001/Person/Team/Catalogue/
  Organization adaptés. Requêtes filtrées avant limite, eager loading des relations,
  aucun cache durable de droits ou résultat. Profiler intersections et contention
  avant optimisation. Les accès SQL externes doivent respecter le protocole
  applicatif ; les triggers ne remplacent pas l’autorisation des conflits.
- Les collisions anciennes ne sont pas corrigées par la migration. Aucun
  rattachement fictif, score, convocation, notification ou récurrence ajouté.

### Vérifications et livraison

- Nouveaux tests backend : contrôleur des conflits 13, ressources 3, schéma 5,
  concurrence PostgreSQL 3, et 5 cas supplémentaires de Policy.
  Contrôles d’aperçu, expiration/falsification/acteur, changement des conflits,
  confidentialité des disponibilités, références étrangères/archivées, limites,
  chiffrement, archivage, rollback d’audit, inventaire et exceptions immuables.
- PostgreSQL ciblé final : 221 tests réussis, 1067 assertions, aucun ignoré.
  Inclut CAL-001/CAL-002, COM, architecture/isolation et round-trips des parents.
  Base temporaire dédiée supprimée en finally. Ceci ne remplace pas la CI
  PostgreSQL complète, dont la sélection est enrichie des quatre nouveaux fichiers.
- Les trois scénarios de concurrence créent chacun une base éphémère :
  deux acteurs/processus indépendants examinent le même créneau libre,
  une barrière commune libère les deux écritures ; une seule activité persiste,
  l’autre reçoit CALENDAR_CONFLICTS_CHANGED, sans exception ni audit parasite.
  Les bases et barrières sont supprimées en finally. Ces trois cas sont ignorés
  sous SQLite et réellement exécutés sous PostgreSQL, sans installation ajoutée.
- Incident de test local résolu : le flux d’entrée puis waitUntil de Symfony
  Process sous Windows pouvaient manquer un READY déjà lu et atteindre le délai maximal
  la libération. Le test utilise désormais une barrière commune et l’état de
  sortie accumulé, avec attente bornée ; aucune modification produit pour contourner
  ce défaut de synchronisation. Relance isolée puis sélection PostgreSQL verte.
- Suite complète SQLite : 1802 tests, 1795 réussis, 7 ignorés attendus, 8450 assertions. Les trois cas de concurrence sont exécutés dans la sélection PostgreSQL ci-dessus.
- Relecture finale des libellés : messages spécifiques aux ressources, au motif,
  à la confirmation d’exception et à la version d’aperçu ajoutés. Relance ciblée
  des deux contrôleurs : 16 tests réussis, 149 assertions, après ces seuls
  changements de messages et leurs assertions.
- Frontend : 268 tests dans 38 fichiers réussis, dont 6 nouveaux cas calendrier
  et adaptation des parcours précédents au contrôle préalable. Lint/build et
  vérification TypeScript stricte des deux parcours calendrier réussis.
- Chrome : 28 parcours réussis, mode CI/UTC, sans retry ni reprise de serveurs
  existants. API métier simulées ; création de ressource, conflit privé, motif/
  confirmation, historique et rendu mobile contrôlés. Screenshots
  reports/cal003-review-mobile.png et cal003-history-mobile.png inspectés :
  textes échappés, pas de débordement horizontal.
- PHPStan niveau 8 sans erreur, sans suppression/baseline ; Pint dirty et
  contrôle complet réussis, composer validate --strict valide. Dix-sept routes
  calendar vérifiées. Aucune dépendance ni configuration d’environnement modifiée.
- Migration principale CAL-003 vérifiée **Pending**, CAL-002 demeure Ran batch 32.
  Aucun push, migrate/seed principal, ressource/activité/personne/rôle réel,
  e-mail/message ou notification créé par l’agent. Données de tests isolées seulement.
- Code, migration, tests, CI et six documents existants dans un seul commit local.
  Prochaine action utilisateur : push, CI vertes, sauvegarde puis php artisan migrate
  depuis back/. Accorder les nouveaux droits explicitement via IAM-003 et recetter
  deux comptes avant utilisation réelle. Prochain P0 recommandé après validation :
  CAL-004, convocations/réponses/rappels, non démarré.

## Convocations, réponses et rappels — UCG-CAL-004

### Reprise et livraison

- Départ sur staging propre, HEAD/origin/staging dea7936. Push, CI et migration
  CAL-003 confirmés par l’utilisateur ; migrate:status confirme Ran batch 33.
- Deux tables additives : calendar_invitations et calendar_invitation_deliveries,
  migration 2026_10_17_153712_create_calendar_invitations_and_deliveries.
  Composite FKs tenant/activité/personne/convocation, unicités par version/personne
  et par type/seuil de remise, index de boîte et de sweep, gardes SQL SQLite/PG.
  Identité et paramètres figés ; remises append-only. Rollback refusé si données.
- Cinq endpoints privés ajoutés : boîte personnelle, suivi par activité,
  préparation des convocations, réponse personnelle et historique des remises.
  Form Request/validation partagée, Policy, Service/Repository/Resources, MFA,
  CSRF, confirmation tenant, révision et verrou de session en mutation.
- calendar.invitations.manage exige calendar.read/write ; respond exige read.
  Permissions et labels IAM ajoutés, audit minimal en liste fermée ; aucun droit
  implicite propriétaire ou opérateur technique. Pas de rôle réel attribué.
- Émission pour les participants explicitement inscrits (1–50), échéance future
  au plus tard au début, zéro à trois seuils distincts (1–10080 minutes).
  Compte destinataire figé à l’émission et comparé au lien actuel ; aucune
  réaffectation automatique. People résout les comptes en bloc via son port public,
  avec la même exclusion des personnes parties. Relations et labels groupés ;
  test du chargement des labels sans N+1.
- Boîte filtrée avant pagination : compte/personne actuels, remise effectuée,
  activité non archivée, version courante et participant toujours inscrit.
  pending/present/absent modifiables personnellement avant échéance, avec version
  UUID ; aucun manager ne répond pour autrui. Toute modification de l’activité
  invalide les convocations antérieures, conservées en suivi de gestion.
  Une configuration différente exige une nouvelle version de l’activité.
- ProcessCalendarInvitations réutilise TEN-006 : registre fermé, contexte tenant
  frais, queue default, scheduler chaque minute et verrou distribué du runner.
  Chaque remise revérifie aussi émetteur, destinataire, MFA, droits, personne,
  compte lié, activité/version, participation et échéance sous verrous
  organisation → activité → convocation. Les cas non habilités restent bloqués.
- Notification interne durable et état dans la même transaction. Clé d’occurrence
  indépendante du cache de fin de job ; reprise/concurrence sans doublon.
  Rattrapage du seul seuil le plus urgent, pas de rappel redondant pour les seuils
  déjà dépassés lors de la première remise ; aucune relance après réponse ou
  échéance. Sweep borné à 25 convocations par organisation et par passage.
- Interface : suivi de gestion dans chaque activité, boîte personnelle,
  confirmation des destinataires, échéance saisie en UTC et affichage dans le
  fuseau de l’organisation, rappels facultatifs, réponse et historique paginé.
  Textes échappés, erreurs 409 sans rejeu automatique, état de remise désactivée.
- Canal interne uniquement. Pas d’e-mail, webhook, Discord, ICS (CAL-005),
  notification automatique de report/annulation (CAL-006), ni récurrence.
  Le titre exposé est celui de l’activité actuelle, pas un snapshot historique.
  Aucun objectif de débit non mesuré : profiler retards de sweep, anciennes
  versions retenues et contention avant montée en charge.

### Vérifications et exploitation

- Suite complète SQLite : 1842 tests, 1832 réussis, 10 exclusions attendues,
  8647 assertions. Les exclusions comprennent les trois nouveaux cas de
  concurrence PostgreSQL, exécutés réellement ci-dessous.
- Sélection PostgreSQL finale : 158 tests réussis, 953 assertions, aucun ignoré,
  dans une base temporaire dédiée supprimée après validation. Inclut CAL-004,
  calendrier, conflits, disponibilités, communication, architecture et round-trips
  des parents Personnes/Teams/Catalogue.
- Trois scénarios CAL-004 utilisent chacun deux processus indépendants et une
  base PostgreSQL éphémère : deux émissions → une seule convocation/émission
  auditée et un 409 ; deux remises → une seule notification ; deux réponses
  différentes sur la même version → une seule écriture et un 409.
  Bases et barrières nettoyées en finally, sans toucher à la base principale.
- Défauts de validation locale corrigés : nouvelles catégories d’audit ajoutées
  au catalogue fermé ; ordre de retrait/remontage des tables enfants ajouté aux
  tests de schéma parents (aucune ancienne migration modifiée).
- Frontend : 274 tests dans 39 fichiers réussis, dont six nouveaux tests de
  convocations. Build, lint et vérification TypeScript stricte des nouveaux
  parcours unitaires/navigateur réussis.
- Chrome : 29 parcours réussis en CI/UTC, sans retry, API métier simulées ;
  cas CAL-004 contrôlé à 375 et 320 px, pas de débordement horizontal.
  Captures reports/cal004-issue-mobile.png et cal004-inbox-mobile.png inspectées.
- Particularité locale Windows : le lancement groupé des serveurs par Playwright
  terminait le scénario et le navigateur mais bloquait leur arrêt. Validation
  complète avec configuration temporaire ignorée sous reports/, mêmes tests,
  serveurs de test démarrés dans des sessions dédiées puis arrêtés explicitement.
  Aucun changement au produit ou à la configuration CI pour contourner cela.
- PHPStan niveau 8 sans erreur, sans suppression ni baseline ; Pint dirty et
  composer format:check complet réussis. composer validate --strict valide ;
  22 routes calendrier vérifiées. Tests PostgreSQL CAL-004 inscrits dans la CI.
- Dernier contrôle principal en lecture seule : CAL-003 Ran batch 33 ;
  CAL-004 Pending. Aucune migration/seed principale exécutée par l’agent.
  Aucun compte, personne, activité, rôle, convocation ou rappel réel créé ;
  aucun message/e-mail réel envoyé. Fichier .env actuel inchangé.
- Code, migration, tests, CI et les six documents existants regroupés dans un
  seul commit local de tâche. Aucun push par l’agent. Après push et CI vertes :
  sauvegarde, php artisan migrate depuis back/, permissions explicites via
  IAM-003, puis activation CALENDAR_INVITATIONS_ENABLED et supervision du
  scheduler/worker default. Flag false par défaut ; configuration du registre
  distincte de la santé réelle des processus. Recetter deux comptes et les
  révocations avant utilisation réelle. Prochain P0 : CAL-005, non démarré.

## Correctif CI CAL-004 — round-trip du schéma des organisations

- CAL-004 `661b438` poussé et migration appliquée par l’utilisateur ; état
  principal vérifié en lecture seule : Ran, batch 34. Staging propre et
  synchronisé avec origin/staging au démarrage du correctif.
- Échec backend transmis par l’utilisateur : 1653 tests réussis, deux exclusions
  et une erreur PostgreSQL dans OrganizationSchemaTest, ligne 127. Le retrait de
  calendar_activities rencontrait la FK calendar_invitation_activity.
- Reproduction exacte avant correction dans une base PostgreSQL temporaire :
  le test test_migrations_can_be_reversed_and_reapplied_in_the_test_database
  échoue avec SQLSTATE[2BP01]. La base de reproduction a été supprimée.
- Le round-trip global avait oublié les deux tables enfants CAL-004, alors que
  les tests de calendrier, personnes, équipes et catalogue avaient été adaptés.
  La sélection PostgreSQL locale de 158 tests de la livraison initiale
  n’incluait pas OrganizationSchemaTest ; elle ne prouvait pas la sélection CI complète.
- Correctif ciblé : retrait de la migration CAL-004 avant calendrier/organisation,
  puis recréation après ses parents. Assertions de disparition et de recréation
  de calendar_invitations et calendar_invitation_deliveries ajoutées, pour
  détecter aussi cette omission sous SQLite. Aucun DROP CASCADE et aucune
  modification de migration déployée, de contrainte ou de code métier.
- Tous les autres round-trips référençant la migration calendrier ont été
  revérifiés ; le seul oubli restant était le test global des organisations.
- Vérifications initiales : OrganizationSchemaTest sous SQLite, huit tests
  réussis et 31 assertions ; Pint dirty et composer format:check complet
  réussis ; PHPStan niveau 8 sans erreur.
- Validation PostgreSQL : sélection intégrale extraite de l’étape existante
  « Verify organization constraints on PostgreSQL » du workflow backend, sur une
  base dédiée éphémère. Rapport local ignoré :
  back/reports/cal004-ci-postgresql.xml. Résultat final consigné ci-dessous.
- Base principale et données métier inchangées par l’agent ; aucune nouvelle
  migration à appliquer pour ce correctif. Test, documentation de reprise et
  statut backlog restent regroupés dans un seul commit local de correctif.
- Validation finale : OrganizationSchemaTest sous PostgreSQL, huit tests réussis,
  31 assertions. Sélection CI PostgreSQL intégrale : 1656 tests, 1654 réussis,
  deux exclusions Redis attendues, 7882 assertions. Base dédiée supprimée après
  exécution ; base de reproduction et base de vérification ciblée également
  supprimées. Ces résultats locaux ne remplacent pas la CI distante du correctif.
- Aucune modification frontend ; ses contrôles ne sont pas relancés pour ce
  correctif de test uniquement. Aucun push par l’agent ; prochaine action :
  pousser le commit de correctif et vérifier les CI, sans nouvelle migration.

## Calendrier ICS privé — UCG-CAL-005

- CAL-004 et correctif defc140 : push et CI confirmés par l’utilisateur avant
  ce lot. Migration précédente vérifiée Ran batch 34.
- Livré : gestion de son lien personnel par GET/POST/PATCH calendar/feed et
  téléchargement ICS sans session par capacité. calendar.read + calendar.export
  explicites ; compte vérifié, MFA, organisation/adhésion/rôles actuels exigés.
  Aucun accès métier implicite pour owner ou compte technique. Les contrats
  publics existants People, Teams et Tenancy servent à recomposer l’audience.
- Secret aléatoire 256 bits, seul SHA-256 stocké, révélation unique dans le JSON
  de création/rotation et l’état React. Pas de stockage navigateur, audit ou job
  du secret. URL basée sur APP_URL de confiance, HTTPS hors local/tests.
  Renouvellement et révocation optimistes, garde compte → organisation → lien,
  unicité tenant/compte, audit transactionnel. Révocation efface le hash.
- Flux personnel même pour un gestionnaire : titres et horaires UTC uniquement,
  UID opaque stable, DTSTAMP et propriétés ICS minimales. Aucun descriptif,
  lieu, lien de réunion, participant, ressource, absence ou contrat. Audience
  personnelle SQL réévaluée à chaque lecture et activités archivées exclues.
- Fenêtre glissante UTC : 0–30 jours passés, 1–90 futurs, cumul ≤93. Secret valide
  1–90 jours ; défauts SPA 7/60 jours et validité 30 jours. Requête SQL bornée à
  501 lignes, 500 activités/512 Kio maximum et quota d’export tenant ; refus entier
  si dépassement. Pas de chargement de relations par activité. RFC 5545 : CRLF,
  échappement des textes et repli à 75 octets sans découper UTF-8.
- Cache-Control private/no-store, aucune réponse 304/ETag, en-têtes de confidentialité
  sur les erreurs aussi. Jeton retiré de l’URL interne avant traitement ; logs
  proxy/CDN/APM à masquer séparément. Limites 12/min par empreinte et 120/min/IP.
  Mauvais jeton, expiration, révocation et perte de droits donnent un 404 générique.
- Panneau avec confirmations tenant/export/révocation, version courante, expiration
  UTC, renouvellement et secret effacé à fermeture/révocation. Copies externes
  déjà importées non supprimables ; cadence de synchronisation dépend du lecteur.
- Migration additive 2026_10_17_164002_create_calendar_feeds_table, FK restrictives,
  contraintes de forme/identité et down protecteur. Inventaire de portabilité et
  round-trip OrganizationSchemaTest actualisés. Migration principale vérifiée
  Pending en lecture seule ; aucun migrate/seed ni habilitation réelle par l’agent.
- Vérification initiale globale : import d’une exception interne Tenancy relevé
  par le test d’architecture puis remplacé par l’exception domaine partagée.
  Première exécution simultanée SQLite/PostgreSQL invalidée par interférence de
  Storage::fake sur le dossier de fichiers simulés. Relances avec TEST_TOKEN
  distincts pour isoler ces dossiers, sans modifier les tests métier existants.
- Frontend : 278 tests sur 40 fichiers, build et lint réussis ; TypeScript strict
  des nouveaux tests unitaires/navigateur réussi. Playwright complet : 30 scénarios
  réussis, incluant création/secret éphémère/révocation sur mobile ; captures 375 et
  320 px inspectées, sans débordement. Serveurs locaux de test arrêtés après contrôle.
- PHPStan niveau 8 sans erreur ; Pint dirty et composer format:check complet réussis.
- Prochaines étapes après commit : push, CI distantes, migration et recette de
  calendar.export avec deux comptes. APP_URL HTTPS, masque des jetons dans les logs
  d’entrée et aucun cache partagé du flux. Recetter un lecteur externe ; aucun
  connecteur tiers n’a été activé. Révoquer les liens restaurés après sauvegarde.
  Prochain P0 : CAL-006, reports/annulations et historique.
- Suite backend SQLite complète après isolation : 1880 tests, 1870 réussis,
  10 exclusions attendues, 8960 assertions, zéro échec/erreur.
- Sélection CI PostgreSQL intégrale extraite du workflow actualisé : 1692 tests,
  1690 réussis, 2 exclusions Redis attendues, 8109 assertions, zéro échec/erreur.
  Rapport local ignoré : back/reports/cal005-ci-postgresql-isolated.xml. Les
  résultats remplacent la première exécution perturbée par les fichiers simulés.
- Paramètres contenant le secret marqués SensitiveParameter pour la résolution
  et la génération d’URL ; les traces PHP ne doivent pas révéler cet argument.
  Revalidation ciblée finale : 38 tests, 307 assertions ; PHPStan niveau 8 réussi.
- Bases PostgreSQL temporaires CAL-005 supprimées, absence confirmée via
  pg_database. Base principale : CAL-004 Ran batch 34, CAL-005 Pending ; contrôle
  final en lecture seule. Aucun push par l’agent ; code, tests et docs regroupés
  dans le commit local du lot sur staging.

## Reports, annulations et historique — UCG-CAL-006

- Reprise : staging propre et synchronisé avec origin/staging, CAL-005 fea7094 ;
  migration calendar_feeds vérifiée Ran batch 35 en lecture seule. La CI distante
  CAL-005 n’a pas été confirmée dans cette reprise.
- Report d’horaires ou de fuseau avec motif obligatoire 10–1000 caractères,
  recontrôle CAL-003 et version optimiste. Simple renommage ou période inchangée
  sans événement parasite. Annulation terminale via archive:true + change_reason,
  cancelled_at égal archived_at, participants/réservations conservés, créneau
  libéré, convocations obsolètes et activité retirée des prochaines lectures ICS.
- Historique immuable calendar_changes avec titre/motif chiffrés, versions,
  périodes avant/après et fuseaux. Union des participants explicites avant/après
  bornée à 100 ; identité personne/compte figée dans calendar_change_recipients.
  Aucun développement automatique d’équipe, compte ou permission.
- Modification, contrôle des conflits, version, historique, cibles et audit dans
  la même transaction. Rollback d’audit ou de conflit sans résidu. FKs composites
  tenant, contraintes d’unicité version/personne/compte, livraison acquise immuable
  et down protecteur. Les cinq tests de migrations parents retirent/remontent les
  enfants dans le bon ordre, notamment OrganizationSchemaTest qui vérifie aussi
  l’absence puis le retour des nouvelles tables et de cancelled_at.
- Remise interne asynchrone via tâche TEN-006 calendar-changes, queue default.
  CALENDAR_CHANGES_ENABLED=false par défaut ; .env réel inchangé. Lot de 25 par
  organisation, non contrôlés d’abord puis contrôles anciens ; transaction et
  verrous organisation → activité → message, relecture responsable/destinataire,
  droits, MFA, vérification email, rattachement et audience. Reprises sans double
  remise ; erreur transitoire sans checked_at partiellement commité.
- Boîte personnelle filtrée avant pagination par compte/personne/audience actuelle,
  annulations incluses pour l’historique. Messages historiques signalés selon la
  version actuelle. Historique de gestion sous calendar.read/write, compteurs
  agrégés seulement ; ni email ni liste de cibles dans les Resources. Prédicat SQL
  EloquentCalendarVisibility partagé entre calendrier, ICS et messages.
- Deux nouveaux GET fermés, pagination bornée, réponses privées sans cache.
  Panneaux React chargés à ouverture, motif et confirmation humaine, textes
  échappés et aucun rejeu automatique après 409. Anciennes archives distinctes
  des nouvelles annulations. Inventaire de portabilité mis à jour.
- Validation ciblée PostgreSQL : 91 tests réussis, 362 assertions, dont trois
  scénarios à deux processus indépendants (report, annulation et remise) et les
  migrations parents. Validation frontend : 284 tests/41 fichiers, build et lint
  réussis ; TypeScript strict des tests modifiés et nouveau scénario réussi.
  Cinq scénarios navigateur calendrier réussis (quatre régressions, nouveau
  parcours report → historique → annulation → boîte personnelle). Captures 320 et
  375 px inspectées sans débordement ni HTML exécuté.
- Premier essai du nouveau scénario navigateur : confirmation finale d’annulation
  oubliée dans le test ; scénario corrigé et réussi, comportement applicatif inchangé.
  Une demande facultative de couverture Vitest a signalé l’absence du package
  coverage-v8 ; aucune dépendance ajoutée, suite test:ci du projet exécutée avec succès.
- Pint dirty, format:check complet et PHPStan niveau 8 sans erreur. Contrôle
  git diff --check réussi. Code, tests et documentation livrés ensemble dans un
  seul commit local ; aucune donnée métier réelle, migration principale, permission,
  notification réelle ou push par l’agent.
- À faire après livraison : push/CI, migration additive, recette deux comptes et
  révocation d’accès, puis activation explicite et supervision scheduler/worker.
  Le flag activé seul ne prouve pas la disponibilité des traitements. Les tâches
  CAL-007/CAL-008 restent P1 ; prochain P0 sportif du backlog : MATCH-001.
- Sélection CI PostgreSQL intégrale extraite du workflow actualisé : 1726 tests,
  1724 réussis, deux exclusions Redis attendues, 8334 assertions, zéro erreur/échec.
  Rapport local ignoré : back/reports/cal006-ci-postgresql.xml. Bases temporaires
  CAL-006 supprimées ; absence confirmée via pg_database. Base principale inchangée :
  CAL-005 Ran batch 35, CAL-006 non appliquée (contrôle en lecture seule).
- Première suite SQLite complète : 1914 tests, 1899 réussis, 13 exclusions,
  deux erreurs JSON intermittentes du simulateur fake-clamd dans les tests existants
  ClamAvDocumentScannerTest (réponses ambiguë/trop longue). Relance ciblée :
  10 tests, 68 assertions, zéro échec/erreur, sans modification du scanner ou de
  sa fixture. Relance globale nécessaire pour confirmer l’ensemble.
- Serveur Vite de test démarré par l’agent puis arrêté ; aucun serveur utilisateur
  arrêté ou remplacé. Composer validate --strict et format:check final réussis.
- Relance SQLite complète réussie : 1914 tests, 1901 réussis, 13 exclusions
  attendues (Redis, ClamAV réel et concurrence PostgreSQL), 9155 assertions,
  zéro erreur/échec. Rapport local ignoré : back/reports/cal006-ci-sqlite-retry.xml.
  Tous les tests ont donc été revérifiés ensemble après l’échec intermittent.
  Ces validations locales ne remplacent pas les CI distantes à lancer après push.

## Dossier Match et machine d’état — UCG-MATCH-001

- Reprise : CAL-006 064df63 poussé, CI/migration confirmées par l’utilisateur.
  Migration Ran batch 36 vérifiée en lecture seule. Staging propre et synchronisé
  avec origin/staging au début ; aucune migration principale par l’agent.
- Competition reçoit son premier parcours métier complet : création/lecture/
  modification du dossier, références paginées, transition motivée et historique.
  Jeu disponible, scrim/officiel, BO1/BO3/BO5 ou lobby/free_for_all, période UTC et
  fuseau IANA, responsable interne et participants multiples. Pas de contrainte
  « deux équipes » ; deux participants en BO, 2–100 dans les autres formats.
- Participants de nature personne/équipe interne ou instantané adverse autorisé.
  Game/identité immuables, noms et versions conservés à la sélection, titre,
  responsable, libellés des participants et motifs chiffrés. Aucun roster d’un
  autre tenant, compte, permission ou accès adverse créé.
- Huit états dans MatchWorkflow ; parcours proposé → confirmé → en cours →
  terminé à valider, réouverture confirmed → proposed, annulation terminale depuis
  proposé/confirmé/en cours. Les états de résultat validated/disputed/forfeited
  attendent MATCH-003/004 ; commandes correspondantes refusées même avec des
  droits sportifs, sans validation fictive. Le noyau/participants est modifiable
  seulement en proposed. Confirmation/démarrage recontrôlent les références
  actuelles ; une référence retirée n’empêche pas l’annulation opérationnelle.
- Droits existants sports.read/write, MFA, sports.publish et consentement pour
  l’élargissement du partage. Privé par défaut ; responsable et personnes liées
  actuels, visibilité équipe pour les membres actuels des équipes participantes,
  organisation pour ses lecteurs sportifs. Mode gestion explicite de l’éditeur.
  Filtrage SQL avant pagination pour liste/détail/historique ; aucun accès métier
  implicite propriétaire/opérateur technique. Resources sans identité d’acteur,
  email, contact adverse, score ou note privée complémentaire.
- MatchService : validation fermée aussi hors HTTP, garde organisation puis
  verrou du match, contrôle de version, références par ports Teams/Scheduling,
  écriture/participants/événement/audit dans le même commit. Échec d’audit sans
  résidu ; 409 sur version périmée et aucun rejeu automatique. Lecture du jeu et
  snapshots groupés, insertion groupée après chiffrement, participants anticipés.
  Test dédié au nombre de lectures des personnes et à l’absence de N+1 de liste.
- Migration additive 2026_10_17_225945_create_matches_participants_and_status_events :
  matches, match_participants, match_status_events. FKs composites tenant,
  unicités de slots/sources/version d’événement, périodes/cardinalité/forme,
  transitions et gel SQL, événement append-only et rétention. Rollback protecteur
  si données présentes. Portabilité exhaustive et cinq tests de migrations
  parents actualisés (People, Teams, Jeux, Adversaires, Organisations), sans
  modifier les anciennes migrations déployées.
- Ponts : MatchReferenceDirectory dans Teams et MatchSchedule dans Scheduling ;
  SportingPeopleDirectory étendu pour sélections/snapshots bornés. Le graphe
  modulaire reste inchangé. Un import Identity initial dans la policy a été
  relevé par le test d’architecture puis remplacé par Authenticatable, comme
  les autres modules consommateurs. Aucune dépendance inversée ou directe vers
  les modèles People/Identity depuis Competition.
- Limites explicites : horaire du dossier sans réservation/calendrier automatique,
  sans garantie d’absence de conflit. Aucun lineup/éligibilité, partie, score,
  preuve de résultat, publication publique, convocation ou notification dans
  MATCH-001. Ces opérations restent MATCH-002/003/004 et lots suivants.
- Panneau React chargé à ouverture, formulaire de sélection paginé, période et
  fuseau avec choix des heures répétées, privé par défaut, partage confirmé,
  modification partielle, choix de transition autorisée, motif, confirmation
  nommant le statut et historique échappé. Ancien compte sans sports.read ne voit
  pas ce panneau ; aucune attribution réelle de permission par l’agent.
- Tests ciblés PostgreSQL : 92 réussis, 345 assertions, dont deux courses réelles
  à deux processus indépendants (confirmations/annulation et édition/confirmation).
  Une seule écriture gagne et une seule version/trace correspondante est produite.
- Suite backend SQLite complète : 1955 tests, 1940 réussis, 15 exclusions attendues,
  9359 assertions, zéro erreur/échec. Sélection CI PostgreSQL intégrale extraite du
  workflow actualisé : 1758 tests, 1756 réussis, deux exclusions Redis attendues,
  8525 assertions, zéro erreur/échec. Rapports locaux ignorés sous back/reports/.
- Vérification finale ciblée après les derniers ajustements : 43 tests réussis,
  249 assertions ; PHPStan niveau 8 sans erreur, Pint dirty, format:check complet
  et Composer validate --strict réussis. Aucun ignore/baseline d’analyse ajouté.
- Frontend : 291 tests sur 42 fichiers, build et lint réussis. Les sept nouveaux
  tests ont été relancés après correction de leur réponse simulée de création
  (identifiants/slots des participants), sans avertissement React de clé manquante.
  TypeScript strict des tests unitaires/navigateur ajouté réussi.
- Playwright : quatre scénarios Match, calendrier CAL-001/CAL-006 et équipes
  réussis ensemble. Nouveau parcours mobile avec deux équipes et un instantané
  adverse, confirmation, historique puis annulation. Captures 320/375 px inspectées,
  pas de débordement ni HTML exécuté. Le premier essai avait omis games:[] dans
  le catalogue simulé nécessaire au panneau Personnes ; fixture complétée, sans
  modification du comportement de production.
- Bases temporaires PostgreSQL MATCH-001 supprimées, absence confirmée via
  pg_database ; main CAL-006 Ran batch 36 et MATCH-001 non appliquée, contrôle en
  lecture seule. Serveur Vite de test démarré puis arrêté par l’agent, aucun serveur
  utilisateur arrêté. Aucun migrate/seed principal, match réel, notification,
  permission ou push par l’agent. Code/tests/docs dans un unique commit local.
- Suite : push et CI distantes, sauvegarde puis migration additive et recette à
  deux comptes habilités. Prochain P0 : MATCH-002 (lineups et remplaçants) ;
  MATCH-003/004 porteront scores et validations. CAL-007/CAL-008 restent P1.

## 18 octobre 2026 — UCG-MATCH-002 : compositions et remplaçants

- Autorisation utilisateur : commencer MATCH-002 avec prudence, après confirmation
  des CI et de la migration MATCH-001. Départ sur staging propre, synchronisé avec
  origin/staging ; MATCH-001 Ran batch 37 vérifié en lecture seule. Aucun changement
  de périmètre vers les scores, résultats, preuves ou notifications externes.
- Nouveau port public Teams MatchLineups pour sélectionner les compositions de
  match finalisées, conserver leur projection historique et réévaluer l’éligibilité
  au moment du match. Réutilisation du journal Teams et d’EligibilityEvaluator ;
  aucun accès direct Competition aux modèles d’un autre module.
- Service/repository/Form Request/Resource/policy/routes et panneau React complets.
  Affectation ou réaffectation avant démarrage ; échange titulaire/remplaçant du pool
  retenu avant/pendant le match ; aucune réécriture de la source Teams. Motif requis,
  date serveur, compte auteur interne, phase, numéro et noms conservés à chaque étape.
  Une équipe doit avoir sa composition pour démarrer ; aucun doublon de personne
  entre camps. Les individus et instantanés externes restent sans lineup imposée.
- Réévaluation du roster, des personnes et du jeu, puis du règlement retenu s’il
  existe : archived/ineligible/incomplete refusés. Absence de permission sur un fait
  privé nécessaire : aucune preuve positive inventée. Sans règlement, unassessed
  explicite et présence au roster vérifiée. Les lectures historiques n’exigent pas
  que l’ancien roster soit encore actif et ne recalculent pas ses noms.
- Verrou tenant puis dossier, version optimiste commune à toutes les commandes,
  journal et audit atomiques ; panne d’audit entièrement annulée. Les participants
  du dossier sont retenus après première affectation. Publication sportive et
  consentement requis à une nouvelle affectation sur match partagé ; aucun droit
  implicite propriétaire, rôle technique ou adversaire.
- Correction d’un défaut d’audience MATCH-001 révélé par les nouveaux tests : un
  compte sans fiche Personne rencontrait un filtre IS NULL sur les participants
  d’équipe privés. Le filtre personnel exige désormais person_id non nul. La liste,
  le dossier, l’historique de statuts et celui des compositions refusent ce cas,
  sans retirer le mode gestion explicite aux comptes habilités.
- Migration additive 2026_10_18_101019_create_match_lineup_revisions : snapshot,
  évaluation et motif chiffrés, FK composites tenant, séquence/phase/version/capacité
  contrôlées en SQL, journal append-only et down refusant toute table peuplée.
  Inventaire de portabilité exhaustif ; round-trips des migrations parents déposent
  cet enfant avant les parents. Anciennes migrations inchangées.
- Choix/historique paginés ; 500 révisions par match, compositions Teams à 50
  personnes au plus. Dernières révisions des participants lues ensemble ; contrôle
  des périodes et faits sportifs groupé par composition, sans requête par joueur.
  Les hausses de limites et caches privés demandent un profilage représentatif.
- Vérifications locales : suite SQLite complète 1971 tests, 1954 réussis et 17
  exclusions attendues, 9494 assertions ; ciblage final après les derniers ajustements
  57 tests réussis et 385 assertions ; contrôle supplémentaire de l’instant réel
  pendant le match : 12 tests API réussis, 125 assertions. Premier contrôle PostgreSQL ciblé : 84 tests
  réussis, 379 assertions, dont quatre courses à deux processus indépendants
  (transitions, édition/confirmation, deux affectations, deux remplacements) et les
  round-trips des parents. PHPStan niveau 8 sans erreur, Pint, format:check et
  Composer validate --strict réussis.
- Frontend : 296 tests sur 43 fichiers ; build, lint et TypeScript strict des nouveaux
  tests unitaires/navigateur réussis. Quatre parcours Playwright simulés Match,
  Compositions, Calendrier et Équipes passés ensemble ; parcours Compositions rejoué
  après précision du libellé de motif, et 12 tests unitaires Match/Compositions
  repassés. Captures 320/375 px inspectées : absence de débordement et de HTML exécuté.
- Le premier lanceur PostgreSQL local héritait APP_ENV=local, provoquant des refus
  CSRF et des erreurs d’environnement de test. APP_ENV=testing imposé dans le lanceur
  isolé, sans désactiver de protection de l’application ; son ciblage a ensuite passé.
  Une seconde exécution complète a isolé un seul échec Contrats (queued au lieu de
  failed) dû à QUEUE_CONNECTION=database hérité du même parent, au lieu de sync dans
  phpunit.xml. Le lanceur reprend désormais toutes les variables de phpunit.xml,
  puis substitue seulement la base temporaire et TEST_TOKEN. Contrats/Documents/
  Scans/Compositions repassés : 46 tests et 322 assertions. Aucun code ni test
  Contrats modifié pour contourner ces erreurs. Utiliser des TEST_TOKEN distincts
  pour les Storage::fake des suites SQLite/PostgreSQL. Lanceur et rapports restent
  ignorés et ne font pas partie de la livraison.
- Sélection PostgreSQL complète identique à la CI, avec environnement de tests
  isolé : 1774 tests, 1772 réussis, deux exclusions Redis attendues, 8691 assertions.
  Les quatre courses à deux processus et les migrations parents passent aussi dans
  cette exécution complète. Toutes les bases temporaires créées ont été supprimées ;
  absence vérifiée en lecture seule. Base principale : MATCH-001 Ran batch 37,
  nouvelle migration MATCH-002 non appliquée par l’agent.
- Aucun migrate/seed principal, compte/droit/match réel, message ou notification
  externe ni push exécuté par l’agent. Le serveur Vite de recette simulée appartient
  à l’agent et a été arrêté. Code, tests et six documents existants dans un seul
  commit local ; push/CI distantes, sauvegarde, migration additive puis recette
  supervisée par l’utilisateur. Suite P0 : MATCH-003, puis MATCH-004.


## 18 octobre 2026 — UCG-MATCH-003 : parties, cartes et score de série

- Autorisation utilisateur : commencer MATCH-003 avec prudence après confirmation
  de la CI et de la migration MATCH-002. Départ staging propre, synchronisé avec
  origin/staging au commit 9a1c6e4 ; migration MATCH-002 Ran batch 38 vérifiée en
  lecture seule. Aucun migrate/seed ni changement de données principales par l’agent.
- Livré : deux tables additives match_score_rules et match_game_instances ; règles
  explicites définitives et historique chiffré/numéroté des tentatives, carte ou
  mode, version du jeu, scores de tous les participants du dossier, noms conservés,
  auteur interne, date serveur et motif. La référence de composition conservée est
  celle en vigueur à la saisie, sans reconstitution d’une partie saisie tardivement.
- Calculs de domaine par stratégie : BO1/BO3/BO5 comptent les victoires, arrêt à
  majorité ou 1/3/5 parties prévues ; les nuls autorisés consomment une partie sans
  victoire, puis le nombre de victoires départage. Lobby/individuel additionnent
  les points déclarés sur le nombre prévu. Chaque partie compte tous les camps
  sans imposer deux participants aux lobbies ; trois participants exercés via API.
- Les nuls et égalités finales requièrent allow_draw explicite avant saisie.
  Forfait/abandon de partie : scores attribués selon règlement et motif obligatoire,
  sans inventer un barème officiel ni passer le dossier en forfeited. Remake :
  tentative sans score et sans effet sur la série ; aucune partie antérieure
  supprimée. Série complète : ajout suivant refusé, même un remake. Aucun total,
  vainqueur, auteur, nom ou date issu du client. Les scores restent non approuvés.
- MatchScoreService/Repository, deux stratégies derrière ScoreStrategy, Form Request,
  contrôleur mince, Resources explicites et routes API privées. Validation fermée
  aussi hors HTTP, sports.read/write, MFA, audience Match et mode gestion explicite.
  Match partagé : sports.publish et consentement sur les règles et chaque résultat.
  Aucune permission implicite au propriétaire ni au compte technique.
- Version commune au dossier/compositions/scores, garde tenant puis verrou Match,
  audit/version/règles/partie dans la même transaction ; panne d’audit ou refus
  entraîne un rollback complet. Les tests de révocation/MFA et tenant étranger
  refusent sans écrire. Trois courses réelles à deux processus sur PostgreSQL :
  deux configurations, deux parties et partie contre annulation ; un succès,
  un 409 MATCH_VERSION_STALE, sans doublon ni perte silencieuse.
- SQL SQLite/PostgreSQL : FK tenant, correspondance phase/version/format du parent,
  limite/séquence/unicité et UPDATE/DELETE interdits. Down refuse toute table
  peuplée avant suppression. Inventaire opérateur complet des deux tables et
  colonnes chiffrées ; anciens fichiers de migration inchangés. Round-trips
  Organisations/People/Teams/Jeux/Adversaires/Match/Compositions actualisés.
- Premier ciblage PostgreSQL : collision des noms automatiques des FK Match et
  règles, corrigée par un nom explicite distinct sur la nouvelle migration.
  Relance sur base neuve : 113 tests réussis, 685 assertions ; inclut concurrence
  à deux processus et migrations parents. La base temporaire en échec et celle
  réussie ont été supprimées par les lanceurs isolés.
- Bornes : 100 tentatives (remakes inclus), 100 participants ; page 1–100000,
  per_page 1–100. Trois requêtes de parties par GET (faits, compte, page), sans
  requête par joueur/partie ; compositions courantes lues en bloc. Le GET garde
  le verrou du dossier jusqu’à matérialisation résumé/page d’une version cohérente.
  Pas de cache privé partagé ; profiler contention et chiffrement avant extension.
- Panneau React : feuille chargée à ouverture, version fraîche de la feuille,
  confirmation des règles/résultats et du tenant, notifications Flasher existantes,
  erreurs localisées, historique paginé et texte échappé. Lecteurs, anciennes
  pages et états terminaux sans mutation ; 409 invalide le contexte sans rejeu.
- Vérification SQLite complète avec cache database : composer test:ci,
  2007 tests, 1987 réussis, 20 exclusions attendues, 9807 assertions. Ciblage final
  scores/domaine/schéma : 33 tests réussis, 309 assertions. PHPStan niveau 8 sans
  erreur, Pint/format:check et Composer validate --strict réussis.
- Frontend : 304 tests dans 44 fichiers réussis, build, lint et TypeScript strict
  des nouveaux tests unitaires/navigateur réussis. Cinq parcours Playwright avec
  API simulée (Scores, Compositions, Matchs, Calendrier, Équipes) passés ensemble
  dans Chrome installé ; captures 320/375 px inspectées, sans débordement ni HTML
  exécuté. Le scénario Scores couvre règles, remake, saisie, pagination et conflit.
- Sélection PostgreSQL complète identique à la CI : 1807 tests, 1805 réussis,
  deux exclusions Redis attendues, 9036 assertions. Les trois nouvelles courses
  et les quatre courses Match/Compositions existantes passent dans cette sélection.
  Base temporaire complète supprimée. Base principale vérifiée en lecture seule :
  MATCH-002 Ran batch 38, migration MATCH-003 Pending.
- Livraison : code/tests/six documents existants regroupés dans un seul commit
  local de tâche ; aucun push, migration principale, donnée métier réelle, compte,
  attribution de droit, message ou notification externe par l’agent. Après push
  et CI vertes : sauvegarde, migration additive
  2026_10_18_135008_create_match_score_rules_and_game_instances puis recette à deux
  comptes explicitement habilités. Le rollback refuse l’historique ; privilégier
  une correction en avant. Prochain P0 : MATCH-004, preuves/soumission/approbation ;
  corrections motivées et recalcul MATCH-006, diffusion MATCH-007 non démarrés.


## 18 octobre 2026 — UCG-MATCH-004 : résultats et approbation indépendante

- Autorisation utilisateur : poursuivre MATCH-004 après push et CI validée de
  MATCH-003. Départ staging propre/synchronisé avec origin/staging sur 085e3cc ;
  migration MATCH-003 constatée Ran batch 39 en lecture seule. Aucun migrate/seed
  ni changement de données principales par l’agent.
- Implémentation complète : MatchResultWorkflow de domaine, MatchResultService,
  repository injecté, Form Request réutilisable hors HTTP, Policy, Resources,
  routes privées et panneau React. API GET/POST results, POST approvals/disputes.
  sports.read/write pour soumettre ; read/validate pour approuver ; write ou
  validate avec read pour contester. MFA, tenant confirmé et version courante.
  Le validateur sans write respecte l’audience ; management garde son droit write.
- Résultat joué : série complète calculée par MATCH-003, aucun total/snapshot/
  vainqueur imposé par le client. Forfait/abandon de rencontre : bénéficiaire
  explicite autorisé parmi les participants, également en confirmed avant départ ;
  scores partiels conservés, aucune partie ni victoire fictive. La soumission
  place le dossier en awaiting_validation et fige définitivement les scores ici.
- Preuve : référence textuelle 10–2000 caractères, conservée chiffrée et confirmée
  consultable par l’approbateur. Le fichier demeure dans l’espace privé géré par
  l’organisation : pas de dépôt, archivage, téléchargement, ouverture automatique
  ni garantie d’intégrité/disponibilité externe. Convention de preuve référencée
  cohérente avec SPON-003 ; limite explicitée dans le produit, README et CDC.
- Snapshot serveur : règles, série calculée/partielle/null, participants/libellés,
  bénéficiaires et IDs des parties immuables. Contributeurs (auteur des règles,
  toutes les tentatives et soumissionnaire) chiffrés et exclus des Resources.
  L’approbateur, avec sports.validate, doit être distinct de tous et confirme sa
  consultation personnelle de la preuve. Aucun auteur ne peut faire approuver
  ses propres scores en demandant simplement une soumission par un autre compte.
- Approbation played/abandon → validated ; forfeit → forfeited. Contestation
  motivée possible sur proposition ou approbation, y compris forfeited → disputed.
  Toute contestation retire l’approbation courante sans effacer le passé ; nouvelle
  proposition puis nouvelle vérification indépendante nécessaires. Le gel des
  parties reste actif : corrections et recalculs réservés à MATCH-006.
- Transactions : garde tenant puis verrou Match, UUID de version commun aux
  transitions/compositions/scores/résultats ; événement autorisant une transition
  exacte base_version → match_version, puis dossier/historique de statut/audit.
  Une panne d’audit annule journal, statut, historique et version. Ancienne version
  ou mauvais submission_id : 409, aucune perte silencieuse ni rejeu automatique.
- Migration additive 2026_10_18_144624_create_match_result_events_and_guards :
  journal match_result_events, FK tenant/Match/soumission propre au match, numéro/
  version uniques, preuves/snapshots/motifs chiffrés, UPDATE/DELETE interdits.
  Gardes SQL Match/statuts adaptées à partir d’une copie historique conservant
  les invariants antérieurs ; nouveaux états exigent l’événement de même version.
  Inserts de scores refusés après une soumission, même sur une série incomplète.
  Aucun ancien fichier de migration modifié. Attente de verrou PostgreSQL 5 s.
  Down refuse un journal peuplé, restaure les anciennes gardes à vide puis retire
  les ajouts. Inventaire de portabilité physique complet, round-trips parents mis
  à jour avant leurs suppressions ; pas de backfill ni modification de valeur existante.
- Bornes : 200 événements par match ; historiques page 1–100000, per_page 1–100 ;
  snapshots bornés à 100 participants/tentatives. Pas de requête d’auteur par ligne
  ni cache privé partagé ; GET matérialisé sous verrou pour une version cohérente.
  Profiler contention, volumes et chiffrement avant modification des limites.
- PostgreSQL ciblé initial : 139 tests réussis, 987 assertions ; quatre nouvelles
  courses réelles à deux processus (deux soumissions, deux approbations,
  approbation/contestation et score/soumission), plus courses Match/Compositions/
  Scores existantes et migrations parents. Un succès, un 409 MATCH_VERSION_STALE,
  sans doublon ni perte silencieuse. Base isolée supprimée.
- SQLite complet avec cache database : composer test:ci, 2041 tests, 2017 réussis,
  24 exclusions attendues, 10042 assertions. Ciblage final résultats/policy/schéma/
  workflow : 30 tests réussis, 235 assertions. Revue du test de gel SQL : première
  partie valide d’un BO1 encore incomplet refusée à cause du résultat soumis,
  sans confondre ce gel avec une série déjà terminée ; trois tests/26 assertions
  repassés sur SQLite et PostgreSQL après cet affinage, base temporaire supprimée.
- Frontend : 312 tests/45 fichiers réussis ; sept tests Résultats repassés après
  nettoyage des paramètres inutilisés. Build/lint et TypeScript strict des nouveaux
  tests unitaires/navigateur passent. Six parcours Playwright à API simulée
  Résultats/Scores/Compositions/Matchs/Calendrier/Équipes réussis dans Chrome installé.
  Résultats couvre deux comptes, refus d’auto-approbation dans les actions serveur,
  soumission/approbation/contestation/nouvelle preuve, historique et conflit sans
  rejeu. Captures 320/375 px inspectées : pas de débordement ni HTML exécuté.
- Sélection PostgreSQL complète identique à la CI : 1831 tests, 1829 réussis,
  deux exclusions Redis attendues, 9323 assertions. Les quatre nouvelles courses
  et les sept courses Match/Compositions/Scores existantes passent. Toutes les
  bases temporaires créées ont été supprimées ; absence confirmée en lecture seule.
  Base principale : MATCH-003 Ran batch 39, nouvelle migration MATCH-004 Pending.
  Serveur Vite de recette arrêté ; aucune modification des données principales.
- PHPStan niveau 8, Pint/format:check et Composer validate --strict réussis.
  Aucun paquet ajouté. Code/tests/six documents existants dans un seul commit local ;
  aucun push, compte/rôle/match réel, donnée métier principale, e-mail ou notification
  externe par l’agent. Après push et CI : sauvegarde, migration additive puis recette
  supervisée à deux comptes habilités. Prochain P0 : MATCH-005, préparation/VOD/
  débrief ; correction de scores MATCH-006 et diffusion MATCH-007 non démarrées.


## 18 octobre 2026 — UCG-MATCH-005 : préparation, VOD et débrief

- Autorisation : poursuivre prudemment après confirmation utilisateur de la CI et
  de la migration MATCH-004. Commit f25f966 poussé et départ propre/synchronisé ;
  migrate:status confirme MATCH-004 Ran batch 40. Aucun migrate/seed principal.
- Livré : objectifs, notes, VOD HTTPS et annotation horodatée, débrief, actions
  avec état open/done et échéance UTC. API versionnée et panneau React, listes,
  filtres et historique paginés, toasts et confirmation du tenant avant écriture.
- Accès : sports.read et audience du match à la lecture ; management exige write.
  Notes internes exigent sports.notes.read ; stratégies exigent
  sports.strategies.read. Écrire exige en plus sports.write et le droit write de
  l’espace. Shared exige sports.publish et confirm_sharing à chaque version.
  Aucun héritage de permission via administration, auteur ou sports.validate.
  Les comptes sans lecture sensible ne voient ni contenus, ni totaux, ni versions
  de cet espace ; accès direct à un identifiant masqué retourne 404.
- Shared signifie partage avec les lecteurs authentifiés du match ; aucune
  publication anonyme ni partage inter-tenant. La vitrine reste dans son lot P1.
  Audience et type fixes : une synthèse partageable doit être rédigée séparément.
- Persistance : match_notebook_revisions append-only, FK tenant/Match/racine,
  séquence et audience/type gardés en SQL, titre/texte/lien chiffrés. Inventaire
  explicite de portabilité et round-trips parents mis à jour ; down à vide,
  refus si historique peuplé. Aucun ancien fichier de migration modifié.
- Versions indépendantes du score : un débrief après approbation ne change ni
  statut, ni version du match, ni scores. Transaction verrou tenant → match,
  contrôle droits/version puis révision et audit minimal atomiques. Deux
  processus ne peuvent pas remplacer silencieusement une même version.
- Liens : HTTPS sans identifiants ; affichage texte échappé ; ouverture humaine
  avec noopener/noreferrer et no-referrer ; aucun téléchargement, contrôle du
  contenu distant, iframe ou notification automatique. Chaque annotation est un
  contenu VOD avec lien, horodatage et texte ; plusieurs annotations peuvent
  référencer la même VOD.
- Bornes : 500 contenus/match, 100 révisions/contenu ; titre 200, notes 10000,
  lien 2000 caractères ; horodatage 0–604800 secondes ; pages bornées à 100.
  Requête anti-existence indexée pour ne lire que la dernière version, filtrage
  des espaces avant total/pagination ; aucune requête d’auteur par ligne.
- Migration en attente : 2026_10_18_155140_create_match_notebook_revisions.
  Après livraison : push, CI, sauvegarde/migration supervisée et recette avec
  rôles explicitement délégués. Suite : MATCH-006, puis MATCH-007.


Vérifications locales : suite SQLite complète 2083 tests, 2058 réussis et 25
exclusions attendues (Redis/PostgreSQL/ClamAV) ; sélection PostgreSQL complète
1871 tests, 1869 réussis et deux exclusions Redis. Les deux derniers scénarios
(débrief après approbation et sélection tenant périmée/non confirmée), ajoutés
pendant le lancement PostgreSQL complet, passent ensuite dans les 42 tests finaux
de MATCH-005 (288 assertions), dont une course réelle à deux processus. SQLite
final ciblé : 41 réussis, 275 assertions. Première commande Composer SQLite arrêtée
par son délai standard ; relance complète réussie avec COMPOSER_PROCESS_TIMEOUT=0.
319 tests frontend/46 fichiers, cinq parcours navigateur sportifs à API simulée,
build, lint, TypeScript strict, PHPStan niveau 8, Pint et Composer strict réussis.
Sept tests frontend du carnet relancés après l’ajustement de son libellé de filtre ;
parcours carnet relancé après normalisation des UUIDs simulés. Captures 320/375 px
inspectées, sans débordement ni exécution HTML. Bases PostgreSQL de test supprimées,
serveur Vite de recette arrêté ; aucun effet sur les données métier principales.


## 18 octobre 2026 — UCG-MATCH-006 : corrections et recalcul asynchrone

- Autorisation : poursuivre prudemment après CI/migration MATCH-005 validées par
  l’utilisateur. f40d2a6 poussé ; migration Ran batch 41 vérifiée en lecture seule.
- API et interface : journal avant/après, correction de parties sélectionnées,
  motif/confirmation, pagination, statut pending/applied et relance explicite.
  Aucun score agrégé ou vainqueur fourni par le client ne remplace le calcul.
- Droit sports.correct distinct, ajouté au catalogue sans attribution réelle ;
  nécessite sports.read/write et MFA. Match partagé : sports.publish et consentement.
  Écriture sous verrou tenant → match, droits revérifiés ; tâche sous TenantJob
  avec contexte restauré et autorisation courante. Une révocation bloque la tâche.
- Corrections et agrégats append-only : données sources et preuves non réécrites,
  champs avant/après/motif chiffrés, une complétion unique par correction. Audit
  minimal atomique ; rollback commun à la contestation et à la nouvelle correction.
- Clé d’idempotence par tenant/match, empreinte de la demande et auteur contrôlés :
  répétition identique sans deuxième audit/tâche ; contenu différent refusé.
  Version sportive et score_revision distinctes : un match déjà disputed conserve
  sa version mais toute nouvelle proposition/correction doit reprendre la révision
  courante des scores. Une demande pending bloque toute nouvelle opération sportive.
- Recalcul : file persistante et worker default requis, tentative après commit ;
  une panne de dispatch laisse la demande durable et relançable. Les doublons
  de tâches ne créent ni nouveau calcul enregistré ni nouvel audit. Les erreurs
  de cache/audit laissent pending ; les droits de la personne qui relance sont
  revérifiés à l’exécution. Aucun modèle ni contenu métier sérialisé dans le job.
- Cache : seulement les séries après correction, TTL 60 secondes ; clé tenant,
  match, version sportive et correction, contrôle d’accès avant lecture. Invalidation
  avant demande et complétion ; pending masque le total même si un cache ancien
  existe. Les lectures et projections paginées utilisent la même version sous
  verrou ; aucun cache HTTP partagé (private,no-store).
- Une approbation existante passe par dispute avant correction ; nouvelle preuve,
  soumission avec révision fraîche et approbateur indépendant nécessaires. Les
  auteurs des corrections s’ajoutent aux contributeurs exclus de l’approbation.
  Aucun broadcast ou republication automatique ; diffusion réservée à MATCH-007.
- Bornes : 100 corrections/match, 100 parties/participants, pages d’historique de
  10 maximum. Corrections des scores/résultats des parties existantes uniquement ;
  cartes, règles, format, composition et exclusion remake fixes. Série cohérente,
  résultat complet ne devenant pas incomplet ; pas de création/suppression de partie.
- Migration additive : match_score_corrections et match_score_recalculations,
  FK tenant/match/correction et gardes d’immutabilité, séquence, complétion unique
  et gel pending. Les migrations précédentes restent intactes. Portabilité
  explicite chiffrée intégrée ; down refuse un historique peuplé.
- Une première suite SQLite a détecté une dépendance Application → Infrastructure :
  corrigée par MatchRecalculationQueue et son adaptateur Laravel. Aucun test
  d’architecture désactivé. La première recette navigateur a été corrigée pour
  attendre la soumission avant le changement de compte.
- Livraison : un seul commit local code/tests/six docs ; aucun push, migration
  principale, changement .env, permission réelle ou notification externe par l’agent.
  Après validation : MATCH-007, diffusion autorisée en temps réel.


Vérifications finales MATCH-006 : SQLite complet 2129 tests, 2101 réussis,
28 exclusions attendues et 10792 assertions ; sélection PostgreSQL complète
1919 tests, 1917 réussis, deux exclusions Redis et 10139 assertions. Sous-ensemble
PostgreSQL final : 88 réussis, dont trois courses réelles (deux corrections,
répétition de la même clé, deux tâches de recalcul). Contrôles finaux SQLite de
correction/worker/schéma/policy et architecture : 47 réussis, 519 assertions.
Frontend complet : 327 tests dans 47 fichiers ; six parcours sportifs navigateur
à API simulée réussis, nouveau parcours correction → attente → reprise → preuve
et approbation indépendante relancé après correction de la synchronisation du test.
Captures 320/375 px inspectées, sans débordement ni interprétation du HTML.
PHPStan niveau 8, Pint, Composer strict, build/lint et TypeScript strict réussis.
Un test réseau ClamAV existant a échoué transitoirement dans un lancement ; ses
10 cas isolés puis la suite SQLite complète ont repassé, sans changement ClamAV.
Le dernier passage PostgreSQL utilise le code figé après correction du contrat de
file ; les échecs du lancement antérieur chargé avant ce changement sont remplacés
par ce passage complet réussi. Serveur Vite de recette arrêté ; bases PostgreSQL
de test supprimées et absence de reliquat contrôlée en lecture seule.
Migration principale MATCH-006 toujours Pending, aucune attribution réelle,
activation de worker, modification .env ou notification externe par l’agent.


## 18 octobre 2026 — UCG-MATCH-007 : scores autorisés en temps réel

MATCH-006 (`22f46fc`) : push, CI et migration validés par l’utilisateur avant
MATCH-007 ; migration principale constatée Ran batch 42 en lecture seule.
Départ propre et synchronisé sur staging/origin/staging. MATCH-007 ne crée
aucune migration et ne modifie aucune dépendance ni variable .env.

- Vue « Scores en direct » séparée des formulaires : état provisoire/validé,
  totaux effectifs, vainqueur et issue approuvés (y compris forfait/abandon),
  horodatage de lecture. Aucun total exposé pendant un recalcul en attente.
- Canal privé `tenants.{organization_uuid}.matches.{match_uuid}`, événement
  `match.score.changed` après commit uniquement. Rollback : aucun événement.
  Changements du match, règles/parties, soumission/approbation/contestation et
  demande/fin de correction invalident la vue. Une reprise de correction déjà
  appliquée ou une requête idempotente ne produit pas de nouvel effet.
- Charge utile limitée à `event_id`, `organization_id`, `match_id`, `changed_at`.
  Aucun score, preuve, motif, acteur, composition, stratégie ou contenu du carnet
  dans le WebSocket. Chaque affichage relit une projection HTTP autorisée.
- Signature sur route tenant protégée : session courante, MFA, organisation active,
  confirmation/révision de contexte, sports.read et audience actuelle. Gestionnaires
  sports.write : même accès de gestion que les API sportives existantes. Le canal
  global /api/broadcasting/auth ne peut signer ces abonnements de match.
- Transport sur file `broadcasts`, 3 tentatives, timeout 30 s, délais 1/5/15 s.
  Si la mise en file échoue après commit : journal structuré sans secrets et action
  sportive conservée comme réussie. Pas d’outbox durable : rattrapage par relecture.
- Client : signaux regroupés 250 ms, 200 identifiants de déduplication maximum,
  ancien événement => relecture de l’état actuel, retour en ligne/réabonnement,
  secours toutes les 30 s lorsque la page est visible. Désabonnement et annulation
  des lectures à la fermeture/changement de contexte ; retrait des totaux sur erreur
  d’accès et rechargement du contexte. Échec transitoire : secours maintenu.
- Fraîcheur : délai `changed_at` → réponse relue affiché, incluant transaction,
  queue, transport et relecture ; dépend de la synchronisation des horloges client
  et serveur. Aucune promesse de latence de télémétrie du jeu.
- Diffusion authentifiée interne uniquement. Aucun canal public ni accès adverse :
  visibilité métier limitée à private/team/organization ; publication publique
  distincte de la vitrine P1. Les preuves et stratégies restent dans leurs API privées.
- Aucun worker principal lancé, notification externe envoyée, attribution réelle,
  donnée métier réelle créée, migration/seed principal ou push par l’agent.

Recette de déploiement : configurer Reverb côté serveur et VITE_REVERB_* côté
front puis reconstruire ; utiliser une file persistante et superviser broadcasts
(et default pour les recalculs). Contrôler d’abord les travaux en attente.
Les lectures fonctionnent avec le secours si Reverb n’est pas configuré.
À grande échelle : Reverb et workers séparés, cache/queue Redis partagés ; le
secours ne s’exécute que sur le match ouvert et la page visible. Les API conservent
leurs contrôles d’audience/tenant et les bornes existantes (100 parties/participants).
Après validation du push/CI et recette : prochain P0 COMP-001, suivi des compétitions
externes ; veto/sélection des cartes MATCH-008 reste P1.

Temps réel désactivé (`BROADCAST_CONNECTION=null`) : aucune tâche BroadcastEvent
n’est ajoutée à la file ; les scores et leur relecture restent disponibles.

Vérifications MATCH-007 : SQLite complet final 2149 tests, 2121 réussis,
28 exclusions PostgreSQL/Redis prévues, 10958 assertions ; PostgreSQL sélection CI
complète 1937 tests, 1935 réussis, 2 exclusions Redis prévues, 10291 assertions,
puis 20 cas temps réel finaux réussis, 166 assertions, après le garde-fou null/MFA.
Bases PostgreSQL temporaires supprimées dans finally. Frontend complet : 338 tests,
49 fichiers ; 7 scénarios navigateur sportifs réussis, dont protocole WebSocket
Pusher simulé pour le direct, passage pending/corrigé, doublons, tenant étranger,
révocation et désabonnement. Captures 320/375 px inspectées, sans débordement ni
interprétation HTML. PHPStan niveau 8, Pint/format:check, composer validate strict,
tsc/build, typage strict des nouveaux tests et oxlint réussis.
Serveurs temporaires arrêtés. La recette Reverb réelle + worker broadcasts reste
à effectuer en environnement isolé avant activation supervisée ; aucun worker ou
service temps réel principal n’a été démarré. Le WebSocket navigateur est simulé,
la signature réelle et la mise en file sont vérifiées par les tests backend.


## 18 octobre 2026 — UCG-COMP-001 : suivi privé des compétitions externes

MATCH-007 (`2c5d622`) poussé et CI confirmées par l’utilisateur avant ce lot.
Sa recette réelle Reverb/worker broadcasts reste à faire avant activation supervisée.
COMP-001 suit des compétitions organisées ailleurs, au sein du module Competition.

- Dossier interne : référence et jeu immuables, organisateur, région/saison,
  URL HTTPS et dix contacts structurés. Édition motivée, historique chiffré et
  archivage conservant la lecture, sans suppression ni réouverture.
- Documents privés : un règlement logique et des notes, texte chiffré, versions
  immuables et téléchargement authentifié `.txt`, y compris des anciennes versions.
  Aucun upload binaire ; pièces et échéances d’inscription réservées à COMP-002.
- Lecture : sports.read + sports.competitions.read, MFA. Écriture : droit
  sports.competitions.write supplémentaire, CSRF et contexte tenant confirmé.
  Aucun droit attribué automatiquement aux rôles existants.
- Verrous organisation puis dossier, versions attendues, audit atomique et
  gardes SQL. Un conflit 409 impose une relecture et une nouvelle confirmation ;
  aucune nouvelle tentative automatique. Réponses privées sans cache.
- Nouvelle migration additive `2026_10_18_210127`, en attente sur la base principale.
  Aucun migrate/seed principal, donnée métier réelle ou worker activé par l’agent.
  Après revue/push et CI vertes : sauvegarder, migrer depuis back/, déléguer les
  droits via IAM-003 puis recetter deux comptes et révocations. Prochain P0 : COMP-002.

Vérifications COMP-001 : SQLite complet 2191 tests, 2160 réussis et 31 exclusions
attendues (concurrence PostgreSQL, Redis/scanner réel opt-in) ; 11 179 assertions.
Frontend complet 349 tests, lint/tsc/build verts ; 8 parcours Chrome à API simulée,
captures 320/375 px et absence de débordement. PHPStan niveau 8, Pint et
composer validate strict réussis. PostgreSQL : sélection CI de
1981 cas exécutée (1976 réussis, 2 exclusions Redis, 3 erreurs de démontage de
schéma), puis correctif de l’ordre de démontage/recréation validé avec 67 tests
réussis et 331 assertions, incluant les 42 cas COMP-001 et les trois courses réelles.
La suite SQLite complète ci-dessus est exécutée après ce correctif. ClamAV simulé
revalidé (35 cas avec les schémas), puis inclus dans SQLite complet réussi.
Bases PostgreSQL temporaires supprimées et serveurs navigateur arrêtés.
Le contrôle de clés JSON à jokers a été reproduit en échec puis corrigé/testé.
Aucun test du service Reverb réel dans cette tâche.


## 19 octobre 2026 — UCG-COMP-002 : participation et échéances externes

COMP-001 (`abdb569`) poussé ; CI/migration confirmées par l’utilisateur.
Migration précédente constatée Ran, batch 43, branche staging initialement propre
et synchronisée. Ce lot ajoute un suivi interne par compétition externe.

- Statuts déclarés de l’inscription, du paiement, du roster et du check-in,
  référence/source externes et exigences d’âge, résidence et rang. Le paiement
  reste un statut renseigné ; aucun montant bancaire ni transaction financière.
- Roster borné à 20 personnes du tenant et 50 éléments de suivi : pièces demandées,
  inscription, paiement, roster, check-in ou tâches manager. Chaque élément a son
  état, une échéance ISO avec fuseau, un responsable et une note facultatifs.
  Les dates sont normalisées en UTC et les secondes sont conservées dans le formulaire.
- Pièces suivies par leur état et leur remise ; rattachement facultatif à une
  version texte privée de COMP-001. Aucun dépôt de PDF/fichier binaire dans ce
  parcours. Les originaux et leur transmission restent chez l’organisateur.
- Chaque écriture ajoute une révision chiffrée, attribuée et motivée. Contrôle de
  la version du suivi et du parent, verrous tenant/compétition, historique SQL
  protégé. Une modification du roster déjà approuvé impose de revoir son statut.
  Archivage de la compétition = suivi en lecture seule, historique conservé.
- Lecture : sports.read, sports.competitions.read et sports.registrations.read.
  Écriture : sports.registrations.write supplémentaire, MFA et tenant confirmé.
  Aucun besoin implicite sports.write/competitions.write ni grant automatique.
- Rappels internes personnels : seuils 7 jours, 1 jour et dépassement. Un rattrapage
  choisit le seuil le plus urgent, sans rafale. Clé durable par élément, échéance,
  responsable/compte et seuil, indépendante des notes et du cache de fin de job.
  Droits/MFA/appartenance actuels revérifiés ; aucun rappel pour élément terminé,
  responsable sans compte habilité ou compétition archivée. Aucun e-mail.

Nouvelle migration additive `2026_10_18_231153_create_competition_registration_tracking`,
Pending sur la base principale. Après push/CI vertes : sauvegarder puis
`php artisan migrate` depuis back/. Recetter les droits dédiés avec deux comptes.
Les rappels utilisent TEN-006 (scheduler, cache partagé et worker default), avec
`COMPETITION_REGISTRATION_REMINDERS_ENABLED=false` par défaut. Examiner la file
et recetter en environnement isolé avant activation supervisée du flag.
Aucun migrate/seed principal, attribution réelle, rappel métier ou push par l’agent.
Prochain P0 : COMP-003 ; recette réelle Reverb/broadcasts toujours distincte et requise.

Vérifications COMP-002 : SQLite complet 2229 tests, 2195 réussis, 34 exclusions
attendues (concurrence PostgreSQL, Redis et scanner réel opt-in), 11 386 assertions.
Sélection CI PostgreSQL complète : 2019 tests, 2017 réussis, deux exclusions Redis,
10 791 assertions ; revalidation finale ciblée : 72 réussis, 398 assertions,
comprenant les trois courses réelles (édition, archivage, rappels dédupliqués).
Frontend complet final : 356 tests/52 fichiers ; deux parcours Chrome à API simulée
COMP-001/COMP-002 réussis, captures 320/375 px sans débordement. Erreurs 422,
focus après retrait de ligne, conflit sans rejeu et secondes UTC vérifiés.
PHPStan niveau 8, Pint, Composer strict, lint, tsc et build verts. Bases PostgreSQL
temporaires supprimées et serveurs de recette arrêtés. Aucun changement de dépendance.
Les pièces restent une checklist avec références texte privées : pas d’upload binaire.

## 19 octobre 2026 — UCG-COMP-003 : matchs, classement et dotations

Le dossier externe propose « Matchs et classement » et, avec financial.read,
« Dotations de compétition ». Le classement est celui communiqué par
l’organisateur, consigné avec sa source et sa date de constat. Les matchs liés
restent soumis à leur audience actuelle, dans les vues courantes et anciennes :
un lecteur non habilité voit une référence opaque « Match à accès restreint ».
L’ouverture d’un résultat recontrôle l’accès et ne présente le score comme
approuvé que si sa validation est encore valable et aucun recalcul n’est en attente.
Les preuves du résultat ne sont pas recopiées dans ce panneau.

Deux flux de révisions indépendants conservent les changements motivés : sport et
awards. Les droits sportifs sont sports.read + sports.competitions.read, puis
sports.competitions.write aux écritures et au choix des matchs. Les dotations
ajoutent financial.read puis financial.write aux écritures ; aucun octroi
implicite au propriétaire/administrateur. Les montants ne figurent pas dans les
réponses sportives. MFA, CSRF, contexte tenant confirmé et vérification actuelle
des droits restent obligatoires pour les écritures.

La dotation annoncée et le montant reçu cumulé sont distincts. Chaque état indique
sa devise explicite (EUR/USD/GBP/CAD/CHF/JPY/AUD), sa source et sa date ; une réception
connue, même nulle, exige une date de réception. Les montants sont des entiers
positifs ou nuls en unités mineures, au plus 1 000 000 000 000. Le formulaire convertit
les chaînes décimales par arithmétique entière (deux décimales, aucune pour JPY).
Changer de devise efface les montants à ressaisir ; aucune conversion implicite,
paiement, écriture comptable ou publication n’est déclenché.

Migration additive `2026_10_19_005858_create_competition_outcome_tracking.php` :
competition_outcome_revisions et competition_outcome_matches, clés étrangères
composites tenant, chiffrement du contenu/motif, séquences bornées à 1000 par
flux, 20 matchs par révision. Historique immuable, suppression SQL interdite,
retour arrière refusé dès qu’il existe des données. Les liens exigent le même
jeu et le match conserve ce jeu après rattachement. L’inventaire de portabilité
inclut les deux tables avec leurs colonnes physiques et le contenu chiffré.

L’écriture verrouille organisation puis dossier ; elle vérifie les versions du
dossier, de la dernière révision et des nouveaux matchs. Historique, liens et
audit sont atomiques. Les choix et historiques sont paginés (10 par page au
maximum), les liens inclus dans un état sont bornés à 20. Leur projection utilise
une lecture groupée, et les scores sont consultés à la demande. L’archivage fige
les deux flux sans supprimer leur historique.

COMP-002 `67ddc29` était poussé et synchronisé à la reprise ; CI et migration
validées par l’utilisateur, migration constatée Ran batch 44. Cette livraison
reste locale : push/CI, sauvegarde et migration principale à valider par
l’utilisateur. Aucun migrate/seed principal, droit réel, donnée réelle ou worker
activé par l’agent. Prochain P0 proposé : STAT-001 après validation et recette.
La recette réelle Reverb/broadcasts et l’activation des rappels COMP-002 restent
séparées.

Vérifications COMP-003 : 34 nouveaux cas backend (31 exécutables sur SQLite,
3 courses entre processus PostgreSQL) et 16 nouveaux cas frontend. Les suites
ci-dessous couvrent tout le projet, pas seulement cette fonctionnalité.
SQLite complet : 2261 cas, 2224 réussis, 37 exclusions attendues, puis 143 cas
ciblés finaux réussis, incluant les forfaits/abandons et tous les retours arrière
concernés. Sélection CI PostgreSQL intégrale rejouée sur deux bases indépendantes :
2053 cas, 2051 réussis, 2 exclusions Redis attendues, 11032 assertions ; les 146
contrôles ciblés finaux étaient également verts. Toutes les bases jetables supprimées.
Frontend : 372 tests/53 fichiers réussis, puis les 16 cas du nouveau panneau rejoués
sur la version finale. Trois parcours Chrome distincts COMP-001/002/003 réussis ;
COMP-003 rejoué après finition, captures 320/375 px sans débordement, lecture
financière absente pour le profil sportif et texte malveillant échappé.
PHPStan niveau 8, Pint, Composer strict/format:check, oxlint, TypeScript (y compris
nouveaux tests) et build Vite verts.

Les premières passes ont révélé l’ordre à adapter dans d’anciens tests de retour
arrière (deux cas SQLite, trois PostgreSQL). Les dépendances sont maintenant
retirées/recréées dans l’ordre, sans modifier les anciennes migrations ni utiliser
CASCADE. Un écart de comptage global dans AuditJournalTest (46 contre 45, cache
array) n’a pas été reproduit dans les contrôles ciblés ni dans la seconde suite
SQLite complète ; aucun seuil ni code d’audit n’a été modifié.

## 19 octobre 2026 — UCG-STAT-001 : dictionnaire global des métriques

Le module Performance fournit le dictionnaire commun, séparé des futures valeurs
privées des organisations. Chaque métrique possède un code stable unique par jeu,
un nom, une définition, une unité, un niveau de mesure (manche/partie/match/séance/
période), une méthode d’agrégation, une formule documentaire versionnée et ses
conditions de disponibilité. Les références facultatives de mode, rôle et patch
proviennent du catalogue de jeux, avec leurs libellés conservés dans chaque révision.
Mesure objective/subjective et disponibilité manuelle/source à confirmer/indisponible
sont explicites. Un ratio impose un comportement pour le dénominateur nul ; aucune
moyenne de ratios ni exécution de code utilisateur n’est déclenchée.

Le catalogue est lisible par les comptes vérifiés via l’API de session. L’écriture
exige le superadmin plateforme et la MFA, y compris un recontrôle dans la transaction.
Les droits de propriétaire/administrateur tenant ne suffisent pas. Le formulaire
exige une confirmation globale distincte ; aucune donnée privée d’organisation ne
doit être saisie dans ces définitions communes. Les valeurs, observations, imports,
connecteurs et calculs effectifs restent dans les tickets STAT suivants.

Trois tables globales déclarées dans l’inventaire fermé : metric_definitions,
metric_revisions et metric_catalogue_state. Identité jeu/code immuable, historique
append-only avec acteur, motif, request_id et date ; compteur par métrique et
compteur global contrôlés par SQL sur PostgreSQL et SQLite. Une révision publie
atomiquement la version du catalogue ; aucune entrée partielle si le journal échoue.
Les écritures vérifient la version du dictionnaire, la version du catalogue de jeux
et, pour une correction, celle de la définition. Retrait/réactivation produisent
des révisions ; les anciennes formules restent accessibles par leur UUID exact.

Le dictionnaire est limité à 10 000 métriques et 1000 révisions par métrique.
Lectures et historiques paginés (10 maximum par page), références également
paginées ; projection d’une page en trois requêtes, sans N+1. La résolution de la
dernière version compare les numéros, sans MAX sur des UUID PostgreSQL. Les trois
tables sont exclues des exports privés tenant : aucune valeur privée n’y est stockée.
Le verrou global convient à la curation de définitions et ne servira pas aux
écritures massives d’observations privées de STAT-002.

Migration additive `2026_10_19_094551_create_metric_dictionary.php`, sans métrique
préremplie ni modification des anciennes migrations. Down refusé si un historique
existe. Les tests d’aller-retour du catalogue parent retirent/recréent ces nouvelles
dépendances dans l’ordre. La dépendance Performance → Identity est déclarée pour
les seuls contrats publics d’accès ; le catalogue de jeux est consommé via le
nouveau port public Teams MetricGameDirectory.

COMP-003 `045887d` était poussé et synchronisé à la reprise, migration constatée
Ran batch 45 ; push/CI/sauvegarde/migration validés par l’utilisateur. STAT-001 reste
local, nouvelle migration principale en attente. Aucun migrate/seed principal,
droit réel, métrique réelle, valeur privée, fournisseur ou worker activé. Suite
proposée après push/CI, sauvegarde/migration et recette : STAT-002. Les recettes
réelles Reverb/broadcasts et rappels COMP-002 restent distinctes.

Validations locales STAT-001 : 28 cas backend dédiés au dictionnaire et deux cas
PostgreSQL de concurrence entre deux superadmins ; sept tests frontend et un
parcours Chrome (création/correction/historique/lecteur, 320 et 375 px) rejoué après
les derniers ajustements. Les grands totaux suivants couvrent tout le projet,
pas seulement ce ticket : suite SQLite complète, 2252 réussis et 39 ignorés sur
2291 cas ; sélection CI PostgreSQL complète en deux bases isolées, 2079 réussis
et deux ignorés Redis sur 2081 cas ; frontend complet, 379 réussis dans 54 fichiers.
Ces suites complètes précèdent les deux derniers cas sur le niveau de mesure ;
la vérification finale ciblée inclut ces ajouts et les schémas parents : 53/53 sur
SQLite et 55/55 sur PostgreSQL, concurrence comprise. Toutes les bases PostgreSQL
de test ont été supprimées. PHPStan sans erreur, Pint, validation Composer,
TypeScript, lint et build frontend réussis. Les captures mobiles ont été relues.
La CI distante de STAT-001 reste à exécuter après le push utilisateur.

Environnement local : Artisan annonce Laravel 13.35.0, tandis que les métadonnées
Composer installées et le lock annoncent 13.34.0. Aucun fichier de dépendances
n’a été modifié ; les contrôles ci-dessus portent sur ce vendor local. La CI
doit reconstruire les dépendances depuis composer.lock.

## UCG-STAT-002 — observations statistiques privées (19 octobre 2026)

Livraison locale sur `staging` : saisie manuelle d’une observation pour un joueur,
avec version exacte de métrique, période, date de constat, source et taille
explicite de l’échantillon. Sources distinctes : officiel, scrim, entraînement et
évaluation humaine ; cette dernière exige une métrique déclarée subjective et
peut concerner une partie ou un match visible, officiel ou scrim. Les
sources officielles restent des relevés manuels, sans certification automatique.
Contexte jeu/mode/rôle/patch vérifié par les ports publics Teams ; région, saison,
carte et adversaire sont des libellés documentaires privés. Une métrique au niveau
partie/manche exige une partie existante du même match ; le niveau match exige
un match, la manche son numéro et la séance une référence documentaire.

Deux tables tenant : `statistical_observations` (provenance et contexte immuables)
et `observation_revisions` (valeur, disponibilité, note, état, motif, acteur,
request_id, date et version précédente). Valeur décimale transportée en chaîne,
sans flottant : 12 chiffres entiers et six décimales maximum ; zéro reste une
valeur disponible. Une absence exige valeur nulle et motif explicite. Les champs
privés textuels et valeurs sont chiffrés au repos. Les corrections, invalidations
et réactivations ajoutent une version ; aucune suppression ou réécriture SQL de
l’historique. La source importée d’origine est conservée même après correction ;
aucun importeur n’est activé dans STAT-002. Pour corriger un joueur, une période
ou la source elle-même : invalider l’observation puis en saisir une nouvelle.

Droits organisation explicites, sous MFA : `sports.read` +
`sports.statistics.read` pour lire ; en plus `sports.write` +
`sports.statistics.write` pour saisir ; `sports.statistics.correct` pour corriger.
Ni propriétaire, ni administrateur, ni rôle technique plateforme ne reçoit ces
droits implicitement. Pas de grant réel ni de nouveau rôle prédéfini par ce lot.
Les droits d’équipe seuls ne donnent pas accès au registre d’organisation.
Une observation liée à un match suit son audience ordinaire actuelle, y compris
pour les listes, historiques et versions exactes ; aucun accès de gestion des
matchs ajouté implicitement. Les références hors tenant ou hors audience renvoient
404. Le pseudonyme est résolu à la lecture, sans recopier l’identité administrative.

Écritures sous confirmation du tenant actif et contrôle de révision de session.
Réautorisation dans la transaction après le verrou métier tenant ; contrôle de
version optimiste et motif obligatoire pour corriger. Un conflit exige un
rechargement, sans rejeu automatique. Identité, première révision et audit minimal
sont atomiques : aucun contenu privé n’est copié dans le journal transverse.
Les séquences et références composites sont protégées SQL sur les deux moteurs.
Le rattachement aux parties ajoute l’index unique composite `so_part_reference`
sur `match_game_instances`, sans modification d’une ancienne migration.

Listes, choix et historique paginés, dix éléments maximum par page (historique
par défaut cinq), 1000 versions maximum par observation. Audience match appliquée
par sous-requête via le port public Competition `ObservationMatchDirectory`, sans
charger tous les identifiants en mémoire. Chargement anticipé de la dernière
révision et résolution groupée des pseudonymes : nombre de requêtes stable quand
une page passe d’une à dix observations. Index tenant/joueur/période et
tenant/métrique/période. Les deux tables et leurs colonnes sont déclarées dans le
catalogue fermé des exports privés ; les valeurs y restent chiffrées. Les verrous
globaux du dictionnaire sont conservés pour la saisie manuelle contrôlée ; le
pipeline d’import de STAT-003 devra traiter les lots avec des limites explicites,
pas exécuter une transaction longue contenant toutes les lignes.

Migration additive `2026_10_19_113618_create_statistical_observations.php`, sans
reprise ni seed principal ; down refuse tout historique existant. À appliquer par
l’utilisateur après push/CI et sauvegarde, puis recette lecteur/saisisseur/correcteur,
révocation, changement d’organisation et audience match. STAT-001 `cbeba13` était
synchronisé au démarrage et sa migration constatée Ran batch 46 ; push/CI/migration
validés par l’utilisateur. Aucun push, migrate principal, donnée ou permission
réelle, connecteur, worker ou fournisseur activé par l’agent. Prochain P0 proposé
après validation : STAT-003, import statistique privé avec prévisualisation et
confirmation. Les calculs effectifs et agrégats restent STAT-004.

Validations STAT-002 : 34 cas backend du registre, plus un cas PostgreSQL avec deux
correcteurs dans des processus distincts (une seule correction acceptée, l’autre
reçoit OBSERVATION_STALE). Vérification finale dédiée : 34/34 SQLite et 35/35
PostgreSQL, y compris les évaluations humaines liées à une partie et les séances,
la validation des choix et leurs liens de pagination. Six tests frontend dédiés ;
suite frontend complète : 385 réussis dans 55 fichiers. Un parcours Chrome réel,
avec API simulée, a été rejoué : création zéro, correction en indisponibilité,
ancienne valeur conservée, accès lecteur et absence de débordement à 320/375 px.
Les captures ont été relues. Typage de l’application et des tests, lint, build,
PHPStan (zéro erreur), Pint et validation Composer réussis ; dépendances inchangées.

Les nombres suivants couvrent tout le projet, pas seulement les nouveaux cas :
suite SQLite complète, 2328 cas dont 2288 réussis et 40 ignorés (tests réservés
aux autres environnements). Cette exécution précède les derniers ajustements de
filtrage tenant, pagination et évaluation subjective, tous revérifiés dans les
34 cas dédiés ci-dessus. Les tests de démontage/recréation des schémas parents
ont été adaptés aux nouvelles dépendances ; cinq erreurs d’ordre détectées dans
la première sélection générale PostgreSQL ont motivé leur remise en ordre, sans
modification des anciennes migrations.

Sélection CI PostgreSQL validée en lots : 2082 cas hors classes de concurrence,
dont 2080 réussis et deux ignorés Redis ; 36 cas de concurrence réussis issus de
la sélection complète, sans erreur parmi ces cas. La course STAT-002 a également
été rejouée avec le code final dans les 35 contrôles dédiés. Au total, la sélection
couvre 2118 exécutions, dont 2116 réussies et deux ignorées. Les cinq erreurs de
schéma de la première passe sont corrigées et passent dans le lot général repris.
Les schémas concernés et le registre ont aussi passé 54 contrôles SQLite ciblés.
Toutes les bases PostgreSQL temporaires ont été supprimées. CI distante, push,
sauvegarde, migration et recette avec habilitations réelles restent à réaliser
par l’utilisateur ; aucun de ces résultats locaux ne vaut validation de la CI distante.

## UCG-STAT-003A — dépôt CSV et prévisualisation (19 octobre 2026)

STAT-002 `aa6e52e` est poussé, CI et migration validées par l’utilisateur ;
migration constatée Ran batch 47 à la reprise. Ce lot ajoute uniquement le dépôt
privé et l’aperçu de CSV : UTF-8, virgule ou point-virgule, 1 Mio et 100 lignes
maximum, avec quotas tenant également appliqués. Joueurs et métriques utilisent
des identifiants et versions explicites ; erreurs et doublons du fichier sont
affichés par ligne source. Aucun rapprochement approximatif ni création de joueur.
Les valeurs sont des décimales exactes, et indisponible reste distinct de zéro.

Fichier et aperçu chiffrés, réservés au déposant dans son organisation ; l’audience
actuelle des matchs reste appliquée. Nouvelle permission explicite
`sports.statistics.import` en plus de `sports.read`, `sports.write`,
`sports.statistics.read` et `sports.statistics.write`, sous MFA et tenant confirmé.
Aucun droit réel n’est attribué par l’agent. Listes et lignes paginées par dix,
rendu React échappé, fichier conservé sur 422, aucun rejeu sur 409.

Migration additive `2026_10_19_143154_create_statistical_import_previews.php` :
FK artefact du même tenant, compteurs bornés, historique immuable, down refusé
si non vide. Appliquer après push → CI → sauvegarde, puis recette utilisateur.
Aucun push ni migrate/seed principal exécuté par l’agent. Aucun worker nécessaire.

**Aucune observation créée et aucun bouton de traitement dans STAT-003A.**
STAT-003B reste à livrer pour confirmation, revalidation, idempotence des
observations incluant l’organisation, traitement et reprise. Le statut « valide
à la préparation » décrit un aperçu daté, pas un import effectué.

Validation STAT-003A : 21 nouveaux cas backend d’import, six nouveaux tests
frontend et un parcours Chrome réel avec API simulée (dépôt multipart confirmé,
références, zéro, erreurs, absence de traitement, lecture sans permission refusée,
aucun débordement à 320/375 px ; captures relues). Vérification backend ciblée
finale : 92/92 SQLite avec les architectures ; sélection PostgreSQL dédiée :
137/137 dans une base isolée supprimée, incluant les schémas parents, les imports
People, les observations et la concurrence de correction existante. Le workflow
CI inclut le nouveau test d’import dans la sélection PostgreSQL.

Les nombres suivants couvrent les suites du projet : SQLite complète finale,
2349 cas dont 2309 réussis et 40 ignorés ; frontend complet, 391 réussis dans
56 fichiers. Les 12 cas frontend des observations/imports ont été rejoués après
les derniers ajustements de libellés. Un ancien test du protocole antivirus simulé
avait produit une erreur de décodage lors de la première passe SQLite ; ses dix
cas ont réussi à la relance, puis la suite complète a réussi sans changement
de ce code. PHPStan zéro erreur, Pint, typage application/tests/navigateur, lint,
build et validation Composer réussis. Dépendances inchangées ; CI distante
à exécuter après push utilisateur.

## Correctif CI STAT-003A — ordre de démontage PostgreSQL

La CI distante a révélé deux tests de réversibilité oubliés dans la sélection
locale : PersonSchemaTest et TenantStorageSchemaTest. Reproduction locale :
16 réussis et deux erreurs de dépendance sur artifacts_tenant_id_unique.
Les tests démontaient l’import People (qui possède cet index) avant la table
statistical_imports, dont la FK composite en dépend.

Correction limitée aux tests : démonter statistical_imports avant l’import
People, puis la recréer après celui-ci. Vérifier également que la table et
l’isolation tenant sont restaurées. Aucun CASCADE, ancienne migration modifiée,
nouvelle migration, changement applicatif ou donnée réelle touchée.
Les quatre tests qui démontent cet index ont tous été revus.

Contrôles ciblés après correction : 54/54 PostgreSQL en base jetable supprimée,
54/54 SQLite ; Pint et PHPStan sans erreur.

Sélection PostgreSQL complète du workflow CI rejouée localement après correction :
2139 cas, 2137 réussis, deux ignorés, aucun échec et 11794 assertions.
La base PostgreSQL jetable a été supprimée. La CI distante reste à relancer après
push du correctif ; aucune nouvelle migration nécessaire pour ce correctif.

## UCG-STAT-003B — confirmation, acquisition et reprise (19 octobre 2026)

Objectif : terminer le pipeline CSV sans modifier les aperçus A ni créer une
observation avant confirmation. POST {import}/confirm exige la version de
l’aperçu immuable, confirm accepté et un accord si des lignes sont invalides.
Droits, joueur, jeu, audience match et version de métrique sont revalidés à la
confirmation puis à chaque ligne. Référence modifiée avant confirmation : nouvel
aperçu nécessaire ; après confirmation : ligne refusée, autres lignes poursuivies.
Les cinq permissions de A, session/e-mail vérifiés, MFA, tenant confirmé et
révision de session restent requis. Import et résultats privés au déposant ;
audience de tous les matchs réappliquée au détail. Réponses private/no-store.

Deux tables additives : statistical_import_executions pour état/version/progression
et résultats chiffrés, statistical_import_receipts pour la déduplication durable.
FK composites tenant/import et tenant/observation, unicité tenant/identité de
source, reçus immuables, identité d’exécution protégée et compteurs bornés.
Aucune ancienne migration modifiée, aucun backfill ou lancement des aperçus A.
Tables et colonnes incluses dans le catalogue fermé des exports privés.
Down refusé si historique non vide. Tests de démontage des schémas parents adaptés
aux dépendances, sans CASCADE. Migration `2026_10_19_175141` laissée Pending.

Identité canonique : organisation, joueur, métrique, jeu, source, période,
match/partie et contexte sportif, indépendamment du fichier et du déposant.
Empreinte HMAC ; valeur exacte normalisée, disponibilité, échantillon et version
de métrique contrôlés séparément. Même contenu : duplicate, aucune révision.
Contenu différent : OBSERVATION_CONFLICT, aucun écrasement. Utiliser la correction
STAT-002 avec ses permissions dédiées. Zéro reste distinct de valeur absente.

ProcessStatisticalImport réutilise TenantJob/TEN-006 et la file reports, trois
essais, timeout 60 s et backoff 5/30/120 s. Transport : identifiants tenant,
acteur, requête, import et génération ; aucune cellule ou preuve MFA sérialisée.
Dix lignes maximum par job, une transaction par ligne : observation, révision,
reçu, audit et résultat atomiques. Verrous tenant et catalogue libérés entre
lignes. Limites 1 Mio/100 lignes conservées ; aucun gros traitement synchrone
sur toute la collection. Listes/détails paginés, état chargé en bloc pour la liste.

Après panne, les lignes commises restent conservées. failed stocke uniquement
PROCESSING_INTERRUPTED ; une panne de dispatch laisse queued durable et un message
localisé, avec journal technique limité aux IDs/type d’exception. POST {import}/resume
exige confirmation et version courante : nouvelle génération, anciens jobs et
callbacks failed inertes. Reprise possible pour failed ou queued après perte du
worker ou de la mise en file. Les lignes refusées restent définitives pour cet
import ; les corriger dans un nouveau CSV. completed signifie toutes les lignes
examinées : compteurs created/duplicate/rejected et code par ligne source.

React : confirmation explicite, accord sur lignes invalides, progression,
résultats paginés et reprise. Aucun traitement au dépôt ou à l’ouverture.
Confirmation tenant avant mutation, pas de rejeu sur 409, import sélectionné
conservé à l’actualisation, rendu échappé et captures mobiles 320/375 px.

Mise en service : push → CI → sauvegarde → migration, puis recette isolée avec
habilitations explicites, worker reports, révocation, concurrence et interruption.
Activer ensuite explicitement STATISTICAL_IMPORT_PROCESSING_ENABLED sous supervision,
avec rechargement habituel des configurations et workers. Le dépôt conserve false ;
.env, base principale et droits réels inchangés. Aucun scheduler/fournisseur externe
nécessaire au CSV. Recette et CI distantes restent à valider par l’utilisateur.

Validation locale STAT-003B : 21 nouveaux cas backend de traitement/confirmation,
un cas PostgreSQL avec deux processus indépendants, trois nouveaux tests frontend,
et parcours Chrome avec API simulée : confirmation, accord sur lignes invalides,
interruption, reprise, résultats et confidentialité. Captures 320/375 px relues,
aucun débordement ; contenu dangereux affiché comme texte. API réelle et worker
sont couverts séparément dans les tests backend, dont transport sync/TEN-006.

Suite frontend complète : 394 réussis dans 56 fichiers ; lint, build et TypeScript
application/tests/navigateur validés. Backend : suite SQLite complète, 2368 cas,
2327 réussis et 41 ignorés. Cette passe précède les derniers cas MFA/tenant,
changement de métrique et panne de validation après écriture ; sélection finale
SQLite 80/80 avec ces cas, observations, aperçus et architecture. PHPStan zéro
erreur, Pint, Composer strict et git diff --check réussis. Dépendances inchangées.

Sélection PostgreSQL élargie : 194/194 réussis, incluant tous les schémas parents
concernés et les courses d’import/correction. Cette sélection précède le dernier
affinement : seules les erreurs de draft sont des refus de ligne ; une exception
de validation en écriture/audit reste une panne réessayable et annule la ligne.
Le test ajouté vérifie la reprise, sans création d’observation ou reçu orphelin.

Validation PostgreSQL finale après cet ajustement : 22/22 réussis, couvrant les
21 cas de traitement et la course entre deux imports. Les bases PostgreSQL jetables
ont été supprimées après chaque passe. Aucun résultat local ne remplace la CI
distante ou la recette avec les habilitations réelles.

## UCG-STAT-004 — agrégats, ratios et recalcul ciblé (19 octobre 2026)

Livraison locale backend et frontend sur staging ; commit demandé par l’utilisateur,
push et CI distante à suivre.
Le calcul porte sur une sélection explicite de 1 à 1 000 observations existantes,
privée au demandeur initial dans son tenant. Les sources doivent avoir le même
joueur, jeu, provenance et contexte ; les versions de métrique sont exactes,
les origines répétées et périodes period chevauchantes sont refusées.

Le dictionnaire peut définir un ratio par 1 à 4 composants additifs par côté,
avec versions figées et validation du jeu/niveau/périmètre. Une modification
documentaire ne remplace pas implicitement ces versions dans le formulaire.
Aucune formule textuelle évaluée. Le moteur stat-004.v1 traite sommes, moyennes
pondérées par sample_size, minimum, maximum, dernière valeur et ratios des sommes.
Décimales exactes, six décimales maximum et arrondi half_up ; absence, invalidation,
composant manquant, échantillon incohérent et dénominateur réellement nul explicites.
La politique zero ne transforme jamais un composant absent en zéro.

Une correction/invalidation/réactivation d’observation marque atomiquement les
seuls calculs dépendants stale, remplace leur génération et masque le résultat
courant. Le fanout après commit pagine 25 calculs sous les droits du demandeur
initial, pas ceux du correcteur. Calcul hors verrou puis publication courte avec
revérification de génération ; doubles jobs et callbacks anciens inertes.
L’historique immuable conserve résultat, moteur et révisions exactes des sources.
Panne de queue/fanout : état récupérable, sans fausse fraîcheur ; reprise explicite
confirmée et sans rejeu automatique sur 409.

API paginée sous statistical-calculations avec Form Requests, Policy, Resources,
Service/Repository, notifications, MFA, tenant confirmé et audience match actuelle.
Autre tenant/utilisateur : 404 ; révocation d’audience masque détail/liste/historique.
Interface : sélection, recette du ratio, confirmation, suivi, sources paginées,
historique, statut obsolète et recalcul. Chaînes privées échappées ; aucun résultat
obsolète affiché comme courant. Portabilité du tenant inclut les trois tables.

Migration additive 2026_10_19_183908 ; aucune migration précédente modifiée.
Les tests de démontage des schémas parents respectent les nouvelles dépendances,
sans CASCADE. Aucun migrate/seed principal exécuté et aucune donnée réelle créée.
STATISTICAL_CALCULATIONS_ENABLED=false par défaut ; .env réel, workers, fournisseurs
et habilitations inchangés. Après push utilisateur : CI, sauvegarde, migration,
recette isolée puis activation supervisée et worker reports selon procédure.

Limites : 100 calculs par demandeur/tenant, 1 000 sources et 1 000 révisions.
Définitions et sélection fixes ; une nouvelle observation exige une nouvelle
sélection. Profils/comparaisons et filtres étendus restent STAT-005. Avant hausse
des quotas, profiler déchiffrement, index de dépendance et verrous tenant.

Validation : 20 cas unitaires du moteur, 18 cas fonctionnels et deux scénarios de
concurrence PostgreSQL. Suite SQLite complète : 2 411 cas, 2 368 réussis,
43 ignorés, aucun échec et 12 870 assertions. Sélection PostgreSQL de ce lot : 234/234 réussis,
1 619 assertions, base isolée supprimée. Suite frontend complète : 400/400 réussis
dans 57 fichiers ; après la dernière protection des versions de composants,
15 cas ciblés dictionnaire/calculs réussis, incluant deux cas ajoutés.
Parcours Chrome réel avec API simulée réussi : sélection, confirmation tenant,
ratio, correction, résultat obsolète masqué, historique puis recalcul explicite ;
trois mutations attendues, échappement et absence de débordement à 375/320 px.
Captures mobiles relues. PHPStan zéro erreur, Pint (fichiers suivis et nouveaux),
build, lint, typage application/tests/navigateur et validation Composer réussis.
Dépendances inchangées ; la CI distante de STAT-004 reste à exécuter après push.

## UCG-STAT-005 — profils et comparaisons contextualisés (19 octobre 2026)

Livraison locale backend et frontend sur staging ; commit demandé par l’utilisateur,
push et CI distante à suivre. Départ propre
et synchronisé sur 4c1b8fe ; CI STAT-004 validée par l’utilisateur. La migration
STAT-004 a été constatée Ran batch 50 en lecture seule. Aucune nouvelle migration,
modification de .env réel, seed, donnée privée, habilitation, fournisseur ou worker
activé pour STAT-005.

Les lecteurs sports.read + sports.statistics.read, sous session vérifiée et MFA,
peuvent consulter un joueur ou en comparer deux, par jeu/métrique/version exacte.
Le sélecteur propose aussi les anciennes définitions et les métriques retirées.
Personnes et observations restreintes au tenant et à l’audience actuelle des
matchs. Les profils sont consultables par les lecteurs habilités du tenant ;
les calculs enregistrés STAT-004 restent privés au demandeur initial.

Filtres exacts : saison, patch, rôle, mode, région, carte, adversaire, type/libellé
de source et fenêtre. Seules les périodes entièrement incluses sont retenues,
sans découpage implicite. Chaque contexte reste séparé, y compris ses valeurs
non renseignées. La chronologie paginée conserve la métrique/version/unité de
chaque composant, taille d’échantillon, période, date d’observation et dernière
correction. Absences et invalidations explicites, zéro conservé. Aucun historique
de rang externe ni donnée fournisseur reconstitué en l’absence de source.

Le moteur décimal STAT-004 est réutilisé pour les profils. Ratios par versions
figées des composants, aucune moyenne de ratios ni formule évaluée. Origines
répétées/périodes superposées : agrégat indisponible avec motif. Une comparaison
exige même définition, contexte déclaré, données complètes/disponibles et ensemble
identique d’intervalles couverts ; des bornes min/max égales ne masquent pas un
trou de couverture. Écart décimal gauche − droite seulement si compatible.
Tailles différentes signalées ; aucun classement automatique.

Les profils relisent les observations à chaque GET et intègrent donc nouvelles
sources et corrections à l’actualisation. Date de consultation distincte des dates
de source/correction. Un changement de filtre masque le résultat précédent ;
changement de tenant/compte/révision réinitialise les états privés et annule les
lectures obsolètes. Les échantillons fixes et jobs STAT-004 restent indépendants :
aucun calcul enregistré ni job créé par une consultation, même si son worker est
désactivé. Aucun cache privé pouvant conserver une ancienne audience.

Limites : deux joueurs, 366 jours inclusifs, 1 000 candidats maximum par joueur
avant déchiffrement des filtres de contexte/libellé, dix éléments par page.
Dépassement refusé en 409 STAT_PROFILE_LIMIT, sans résultat tronqué ; réduire la
fenêtre ou préciser le type de source. Les différentes collections sont rabattues
à leur dernière page pour ne pas masquer le côté le plus court. Candidats bornés,
dernières révisions chargées en bloc et requêtes constantes avec le volume.
Profiler plans SQL, déchiffrement et usage réel avant d’ajouter un index de recherche
chiffré ou d’élargir les quotas. Aucun schéma ou inventaire de portabilité modifié.

Validation locale : 18 nouveaux cas fonctionnels backend. Sélection profils,
calculs, observations et architecture SQLite : 74/74 réussis, 903 assertions.
La même sélection PostgreSQL réussit 74/74, 903 assertions, dans une base isolée
supprimée après l’exécution.
Suite frontend complète : 411/411 réussis dans 58 fichiers, dont neuf cas de
profils (lecture seule, versions historiques, filtres, zéro/XSS, comparaison,
incompatibilité, fraîcheur, quota, révocation/tenant). Parcours Chrome réel avec
API simulée réussi : consultation, filtres, différence prudente, refus après
actualisation, changement de filtre masquant les résultats et zéro mutation.
Captures 375/320 px relues, aucun débordement et aucune erreur navigateur.
Pint (nouveaux fichiers inclus), PHPStan zéro erreur, build, lint et typage strict
application/tests/navigateur réussis. Dépendances inchangées. La sélection CI
PostgreSQL inclut le nouveau test ; CI distante et recette utilisateur à faire
après le commit demandé et le push utilisateur.

## UCG-REP-001 — tableaux de bord par rôle (19 octobre 2026)

Cinq vues de consultation, sélectionnées parmi celles autorisées par le serveur.
Permissions configurables et privilégiées `reporting.direction`, `reporting.manager`,
`reporting.coach`, `reporting.player`, `reporting.administration`, limitées à
l’organisation et cumulées aux droits métier. Aucun droit implicite d’un rôle
nommé, d’un propriétaire ou d’un administrateur technique. Aucun compte ou rôle
réel modifié. Attribuer les droits choisis dans l’IAM avant la recette.

Reporting orchestre six contrats publics des modules sources. Ceux-ci gardent
leurs services, repositories, autorisations et audiences. Pas de stockage propre
ni de cache privé. Deux GET : catalogue de vues/cartes et lecture individuelle
paginée ; filtres fermés, fenêtre de 31 jours inclusifs, fuseau du tenant,
instantanés indépendants des dates clairement identifiés. DTO/Resource dédiés,
Gate lié à l’acteur, MFA et isolation du tenant communs. Erreurs SQL/déchiffrement
localisées en REPORTING_SOURCE_UNAVAILABLE, journaux sans contenu privé et
no-store. Chaque lecture vérifie les droits actuels.

Cartes livrées : effectif actif ; contrats signés/actifs à échéance ; livrables
sponsors promis en retard ; activités et matchs de l’audience actuelle ; prochaine
échéance d’inscription déjà persistée dans sa dernière révision ; convocations et
disponibilités du compte/fiche liés ; calculs privés existants du demandeur,
sans valeur de génération obsolète ; fiches sans compte et statuts d’import.
Aucun contact civil, terme financier, commentaire personnel ou contenu de fichier
projeté. Les présences, résultats sportifs, éligibilités ou complétudes ne sont
pas inférés à partir d’un simple statut. Zéro accessible distinct d’une source
indisponible ou en erreur.

Frontend chargé à l’ouverture, filtre de vue autorisée et dates, définitions et
périmètres visibles. Actualisation/erreur/reprise/pagination propres à chaque
carte. Les résultats précédents disparaissent au changement de filtre,
organisation, acteur, révision ou droits ; lectures obsolètes annulées.

Limites explicites : budgets indisponibles jusqu’à la source Finance ; progression
agrégée indisponible jusqu’à REP-002. Conflits consolidés, objectifs, feedback,
actions et alertes attendent leurs sources ; ce ticket ne clôt pas la couverture
de tous les indicateurs cibles du CDC. Les COUNT restent en direct avec les
paginations des modules ; pas d’agrégats lourds ni de déclenchement de calcul,
import ou envoi. Agrégats versionnés, fraîcheur, cache/invalidation et recalculs
ciblés restent REP-002. Profiler les plans SQL réels avant d’élargir les bornes
ou d’ajouter des index/cache. Aucune migration, dépendance, configuration réelle,
activation de worker ou modification de la portabilité.

Validation locale :
- SQLite : 237/237 tests ciblés et d’architecture, 1 846 assertions.
- PostgreSQL : 233/233 tests ciblés, 1 803 assertions, dans une base isolée
  créée puis supprimée. Aucune migration sur la base de développement.
- 19 cas RoleDashboardTest et un cas d’intégration dans StatisticalCalculationTest :
  droits des cinq vues, absence d’élévation métier, tenant, MFA, révocation,
  audience des équipes après fin d’appartenance, données personnelles, fenêtres
  locales/changement d’heure, échéances, projections, dernières révisions,
  zéro/indisponibilité, calcul périmé, absence de jobs et requêtes constantes.
- Frontend : 421/421 tests dans 59 fichiers, dont dix cas DashboardPanel.
  Typage strict application/tests/navigateur, build et lint réussis.
- Parcours Chrome réel avec API simulée : vues autorisées, filtres, instantané,
  indisponibilité, erreur indépendante et reprise, ancien résultat masqué,
  zéro mutation. Captures 375/320 px relues, aucun débordement ni erreur navigateur.
- Pint nouveaux fichiers et fichiers suivis ; PHPStan zéro erreur.
  RoleDashboardTest ajouté à la sélection CI PostgreSQL.

Livraison incluse dans le commit UCG-REP-001. Push, CI distante et recette utilisateur
restent nécessaires. Aucun accès réel attribué automatiquement.
## UCG-REP-001 — correction du test de requêtes en CI (20 octobre 2026)

La CI du commit `0cfd94b` a signalé un écart 53 → 51 dans RoleDashboardTest.
Reproduit localement en définissant TENANCY_CACHE_STORE=database uniquement pour
le processus de test, comme dans .env.example utilisé en CI. Le premier GET
initialise deux entrées SQL du limiteur de débit (compteur et expiration) ; les
suivants les réutilisent. Les requêtes métier et d’autorisation sont identiques.
Le poste local utilisait le cache array pour ce réglage, expliquant pourquoi
les validations initiales n’avaient pas révélé l’écart.

Correctif limité au test : un GET initialise le limiteur avant les deux mesures.
L’égalité exacte du nombre total de requêtes entre un et cinq matchs est
conservée, avec vérification du total et des lignes retournées. Le scénario
s’exécute explicitement avec les caches array et database ; le journal SQL est
désactivé et vidé en finally. Aucun seuil élargi, filtrage de requêtes ou mock
ne masque un éventuel N+1. Le refus de source corrompue est toujours vérifié.

Validation : 20/20 cas et 150 assertions sur SQLite avec le cache database par
défaut, et 20/20 cas, 150 assertions sur PostgreSQL dans une base isolée supprimée
après le test. Pint et PHPStan zéro erreur. Suite complète backend avec
TENANCY_CACHE_STORE=database : 2 407 tests réussis, 43 ignorés, aucun échec
(2 450 cas, 13 296 assertions). Rapport local reports/rep001-ci-fixed.xml.
Aucun changement du code métier,
du frontend, des dépendances, du schéma, de la configuration réelle ou des workers.
Correctif inclus dans le commit UCG-REP-001 dédié ; push, nouvelle CI distante et recette restent à faire.

## UCG-REP-002 — six indicateurs organisationnels différés (20 octobre 2026)

Objectif : déplacer les comptages de six cartes dans des jobs, matérialiser leurs
versions et dater la fraîcheur sans conserver de snapshot d’audience individuelle.
Cartes : active_people, unlinked_people, people_imports, expiring_contracts,
overdue_deliverables, registration_due. Les totaux et répartitions par statut
réutilisent les permissions et filtres des modules propriétaires, via leurs
contrats publics et des COUNT/GROUP BY en repositories. Aucun nom, fichier,
commentaire, montant ou pseudo n’est intégré au résultat agrégé. Aucune moyenne
de métriques hétérogènes ou progression sportive inventée.

Reporting possède service, processor, contrat de queue, repository, modèles et
job TenantJob sur reports avec contexte explicite. Deux endpoints supplémentaires
GET/POST /dashboards/{card}/indicator : lecture sans calcul/écriture/queue et
demande confirmée, validée et autorisée avec version pour renouveler un résultat.
Contrôle actuel des droits/tenant/MFA ; sélections et cache privés au demandeur.
L’activation refuse les queues synchrones et reste désactivée par défaut.
Une lecture désactivée n’interroge pas les nouvelles tables ; les détails actuels
restent disponibles pour accompagner le déploiement avant migration/activation.

Migration additive 2026_10_20_184653 : dashboard_source_versions (tenant/carte),
dashboard_indicators (identité privée, clé tenant et génération de demande),
dashboard_indicator_revisions (résultats chiffrés immuables, moteur, version de
source, dates et request_id). Unicités et FKs composites tenant, gardes sur
identité/état/historique. Invalidation par déclencheurs SQL sur les sept tables
sources, y compris bulk SQL et rollback, sans importer Reporting dans les modules
sources. Inventaire de portabilité mis à jour pour les trois tables et colonnes.
Les demandes et publications ajoutent un audit de métadonnées fermé.

Le worker calcule hors verrou puis recontrôle version, source, droits, fuseau,
activation et date de validité sous verrou métier du tenant, de la sélection et
de sa version de source. Il refuse une publication périmée ; un rejeu ne crée pas
une seconde révision. Callback d’échec limité à la génération encore en attente.
Les défauts de transport sont localisés et journalisés sans détail privé ; reprise
possible. Limites : 64 sélections par acteur/tenant, 1 000 révisions par sélection,
validité maximale 300 secondes. Le cache est la matérialisation SQL, sans double
cache Redis privé. Aucune lecture ne déclenche de fallback synchrone.

Frontend : cartes concernées sur agrégat/fraîcheur par défaut, détails actuels
chargés à la demande. Confirmation explicite du calcul, version de concurrence,
notification, historique paginé des dates et versions, zéro distinct du manquant,
de l’échec et du périmé. Valeur périmée toujours masquée, y compris à l’expiration
du délai sur une page ouverte ; changements de filtres/compte/tenant/révision et
lectures obsolètes gérés comme REP-001. Les GET de contrôle ne sont pas des
recalculs. Pas de polling ou lancement automatique de jobs dans le navigateur.

Limites de cette livraison : recalcul ciblé à la demande, sans scan/recalcul global
automatique. Les échéances passant sans écriture se reflètent après un nouveau
calcul ; l’évaluation et sa durée de validité sont affichées. Les cartes privées
restent en lecture directe. Budgets et progression d’équipes attendent leurs
sources/définitions adaptées ; REP-002 ne clôt pas tous les indicateurs du CDC.
À grande échelle, profiler plans COUNT/GROUP BY et contention des versions avant
d’affiner leur granularité ou d’ajouter un outbox de recalcul automatique.

Validation locale : 19 cas DashboardIndicatorTest (six sources, droits/acteur,
fenêtres, zéro, expiration, bulk SQL/rollback, publication concurrente, replay,
transport, confirmation, quota, historique, schéma et rollback protégé).
Sélection PostgreSQL avec Reporting, audit, architecture et portabilité :
106/106 réussis, 634 assertions, base isolée créée puis supprimée.
Frontend complet : 430/430 tests dans 60 fichiers ; après les derniers ajustements
de validité réseau et de libellé, 20/20 cas Reporting repassés (dix calculs et
dix REP-001, dont un nouveau cas de réponse lente). Build, lint et typage strict application/tests/navigateur
réussis. Deux parcours Chrome réels avec API simulée réussis : REP-001 et REP-002,
confirmation sans POST automatique, génération/fraîcheur, résultat zéro, périmé
masqué, erreur, détails à la demande, filtres et mobile 375/320 px sans débordement.
Pint (nouveaux fichiers inclus) et PHPStan zéro erreur. Suite backend complète
avec TENANCY_CACHE_STORE=database : 2 426 réussis, 43 ignorés, aucun échec
(2 469 cas, 13 449 assertions), rapport reports/rep002-sqlite-confirmation.xml.
Un premier passage avait rencontré une erreur de décodage du pair de test ClamAV
sur une réponse surdimensionnée ; les dix cas scanner ont ensuite repassé
isolément, puis la suite entière est passée sans modification du scanner.
La dernière vérification du typage strict application/tests/navigateur, du build
et du lint est réussie. Le nouveau test est dans la sélection CI PostgreSQL.
Dépendances inchangées.

Aucun commit/push, aucune migration courante ni activation réelle effectuée.
Après CI et sauvegarde, appliquer la migration, vérifier un worker reports et
la connexion asynchrone, puis choisir l’activation opérateur
DASHBOARD_INDICATORS_ENABLED. STAT-004 garde son propre réglage indépendant.


## UCG-REP-003 — recherche limitée au tenant (20 octobre 2026)

Objectif : REC-24, recherche et suggestions sans révéler les fiches d’une autre
organisation ou les données auxquelles le lecteur n’a pas accès. GET search/types
énumère uniquement les catégories autorisées ; GET search exige type et q.
Les droits sources suffisent ; aucun droit reporting supplémentaire n’est attribué.
Les périmètres autorisés sont ceux des listes existantes : sports.read pour les
pseudonymes/équipes/matchs, sports.competitions.read en plus pour compétitions et
leurs documents, administrative.read pour les documents contractuels. Les droits
limités à une équipe ne donnent pas de recherche globale sur cette organisation.

Reporting orchestre les contrats publics PeopleSearch, TeamSearch,
CompetitionSearch et ContractDocumentSearch. Les services propriétaires
réautorisent chaque appel, les repositories portent les prédicats tenant/audience
avant chargement. Les matchs utilisent la même requête visible que leur lecture
standard, sans vue de gestion implicite. Les documents contractuels dont le contrat
porte des conditions financières (y compris sur un avenant) sont exclus avant
déchiffrement en l’absence de financial.read. Seul le titre courant d’un document
de compétition est recherché ; les anciennes révisions n’apparaissent pas.

Résultats fermés : id, label, reference, parent_id. Aucun corps de document,
contact, identité administrative, coordonnées, montant, chemin de stockage ou lien
de téléchargement. La recherche porte sur un préfixe littéral du libellé ou de la
référence, sans jokers SQL ; comparaison Unicode avec mb_strtolower. Les données
chiffrées restent dans leur stockage source, aucune copie/index/cache de recherche.

Borne explicite : au plus 101 candidats autorisés chargés (100 examinés et une
sentinelle), 1–20 résultats par partie, cinq maximum en mode suggest. Tri stable
par identifiant, curseur sur le dernier candidat examiné et non sur le dernier
résultat. Une partie vide peut avoir une suite et n’est jamais présentée comme
une absence globale. Pas de total exhaustif ou de parcours automatique de toutes
les fiches. Les curseurs chiffrés/authentifiés expirent après quinze minutes,
sont liés au tenant, acteur, catégorie, préfixe et taille effective. Les permissions
restent revérifiées à chaque lecture. Reprise après modification du jeu de données
sans garantie d’instantané historique ; recommencer pour actualiser le parcours.

API privée no-store, FormRequest à filtres fermés et validation dans le service,
Gate lié à l’acteur, limitation commune search/suggest/types de 60 requêtes/minute
par organisation et utilisateur, erreurs source localisées sans texte sensible
dans les logs. Pas de requête HTTP sortante ni nouvelle dépendance.

Interface intégrée au workspace : ouverture explicite, catégories permises,
suggestions après 350 ms et deux caractères, sélection au clavier, filtre par
catégorie, pagination par curseur, reprise et erreurs. Effacement immédiat des
résultats lors d’un changement de saisie/catégorie/tenant/acteur/révision/droits ;
annulation et rejet des réponses obsolètes. Résultats en texte React échappé,
affichage mobile 375 et 320 px contrôlé. Les résultats servent de repérage par
libellé/référence ; aucune ouverture ou lecture automatique du contenu documentaire.

Limite de volumétrie : la borne contrôle mémoire, transport et déchiffrement de
chaque appel, pas le coût total d’une recherche exhaustive. Sur un gros tenant,
plusieurs parties peuvent être nécessaires, y compris pour les pseudonymes et
équipes. Profiler les plans SQL et la distribution des recherches avant de choisir
un index de préfixes autorisés ou un index chiffré dédié ; ne pas déchiffrer tous
les enregistrements dans une requête web ou externaliser les champs confidentiels.

Validation : 25 cas TenantSearchTest (isolation, droits, six sources, Unicode,
jokers littéraux, limites, suite après partie vide, suggestions, curseurs,
classification financière, version documentaire courante, erreur source,
compte de requêtes constant avec caches array/database et quotas). Architecture
incluse : 29/29 cas SQLite, 236 assertions. Sélection PostgreSQL search, REP-001,
REP-002, OrganizationSchemaTest et architecture : 76/76 cas, 584 assertions,
base temporaire supprimée. Suite frontend complète : 440/440 cas dans 61 fichiers.
PHPStan zéro erreur, Pint nouveaux fichiers et dirty passés. Build/lint et typage
strict des tests React/Playwright passés. Deux parcours Chrome avec API simulée
réussis : REP-003 et REP-002, suggestions, filtres, suite par curseur, libellés
échappés, erreurs et 375/320 px sans débordement. Le premier mock d’erreur du
nouveau test omettait errors ; corrigé pour respecter le contrat API. La fermeture
des webservers Playwright sous Windows est restée bloquée ; la passe confirmée
utilise le même test avec serveur Vite géré séparément, arrêté ensuite, et sans
webservers automatiques. Configuration temporaire de vérification retirée.
Suite backend complète avec TENANCY_CACHE_STORE=database : 2 451 réussis,
43 ignorés, zéro erreur/échec (2 494 cas, 13 648 assertions), rapport
reports/rep003-sqlite.xml. Après harmonisation de la constante de borne, les
25 cas search sont repassés (193 assertions), Pint et PHPStan restent verts.
Le test de recherche est ajouté à la sélection CI PostgreSQL. Au bilan Git :
45 fichiers concernés, 24 modifiés et 21 créés ; aucun fichier d’environnement réel,
rapport généré ou configuration temporaire n’est ajouté au suivi.

Aucun commit/push, aucune migration sur la base courante ni activation réelle.

## UCG-REP-004 — Exports privés asynchrones de pilotage

Reprise de REP-003 : commit `355f42b` présent sur staging et la référence locale
origin/staging ; arbre propre au démarrage, CI distante non vérifiée. L'ancienne
mention REP-003 non committé dans le point de reprise est corrigée.

Livraison locale backend/frontend :

- Demande fermée et idempotente par UUID client, tenant et acteur ; permissions
  explicites `reporting.export`, vue et droits métier actuels. Pas de permission
  automatiquement attribuée, même aux propriétaires.
- Six rapports fondés sur les projections publiques existantes : active_people,
  unlinked_people, people_imports, expiring_contracts, overdue_deliverables,
  registration_due. Aucun accès direct aux modèles d'un autre module.
- File persistante `reports`, TEN-006, restauration du contexte et MFA, droits
  revérifiés, verrou tenant/export de 120 secondes pour un job limité à 60 secondes.
  Une livraison en double sous verrou est ignorée sans invalider le travail actif.
  Les erreurs permanentes terminent la demande ; les erreurs de transport/stockage
  restent réessayables selon le contrat TenantJob.
- CSV UTF-8 à point-virgule, colonnes fermées, sans identité administrative brute,
  contenu documentaire ni montant supplémentaire. Formules neutralisées et
  guillemets échappés. Une source vide donne uniquement l'en-tête.
- Fenêtre datée inclusive de 31 jours maximum, instantanés explicitement signalés,
  500 lignes, 8 Mio, dix demandes non expirées/tenant, quotas techniques applicables.
  Les pages sont traitées en worker, avec contrôle du total, des doublons, du temps
  écoulé et de la version de source ; aucun résultat partiel n'est publié.
- Fichier chiffré au repos, stockage privé TenantArtifacts et quota existant,
  durée d'une heure depuis la demande, accès réservé au demandeur.
  Chaque téléchargement revérifie droits, acteur, tenant, fuseau, expiration,
  intégrité et génération transactionnelle de la source, puis écrit les journaux
  transverse et sensible. Une modification de source refuse l'ancien fichier ;
  aucun calcul lourd n'est relancé dans HTTP. Cache HTTP private/no-store,
  pas d'URL publique ni signée.
- Métadonnées paginées et filtrées selon les droits source avant comptage.
  Interface avec confirmation explicite, toasts, téléchargement Blob, expiration
  calculée depuis l'heure serveur, annulation des réponses obsolètes et suppression
  des résultats lors d'une erreur/révocation/changement de contexte.
- Expiration optionnelle des artefacts intégrée à Tenancy sans raccourcir les
  fichiers existants. Nettoyage opérateur `tenancy:expire-artifacts`, au plus
  100 candidats et boucle de 45 secondes, libération du quota seulement après
  suppression réussie. Les échecs de stockage restent réservés, sont décalés
  derrière les autres candidats et peuvent être réessayés. Les fichiers expirés
  sont illisibles avant même le nettoyage ; le départ de l'acteur ne l'empêche pas.
  Les fichiers non expirables et les historiques sont conservés.

Migration additive `2026_10_21_224633_create_report_exports_and_artifact_expiry` :
table report_exports tenant, index, gardes SQL d'identité/état terminal et
expiration nullable dans tenant_artifacts. Retour arrière refusé si les nouvelles
données ou des expirations sont utilisées. Catalogue opérateur de portabilité
mis à jour explicitement pour la nouvelle table/colonne ; OrganizationSchemaTest
démonte/rejoue ce lot avant les tables antérieures. Aucun migrate sur la base
courante, backfill, activation d'environnement ou worker réel. Déployer la migration
après sauvegarde/CI et avant le nouveau code d'accès aux artefacts.

Réglages séparés et désactivés par défaut :
`REPORT_EXPORTS_ENABLED=false`, `EXPIRE_TENANT_ARTIFACTS_ENABLED=false`.
Les queues sync/null sont refusées ; database/Redis persistants et cache partagé
sont requis pour la recette. Le scheduler de nettoyage est opt-in. Une recette
avec plusieurs comptes/organisations, révocation, source modifiée, échec stockage,
reprise et expiration doit précéder l'activation supervisée.

Limites de périmètre assumées : aucune exportation des cinq cartes à audience
individuelle ou contextuelle, ni des cartes sans source ; pas d'export exhaustif
des données métier, de rapports financiers détaillés ou de documents bruts.
Les gros rapports dépassant 500 lignes sont refusés ; augmenter cette capacité
nécessite de profiler les sources et d'ajouter des parcours de lecture dédiés
plutôt que d'élargir aveuglément les pages et délais. TEN-007 reste un parcours
opérateur indépendant.

Validation :

- REP-004 final : 35/35 cas SQLite, 241 assertions ; même sélection 35/35 sur une
  base PostgreSQL temporaire supprimée après le test. Couverture des six rapports,
  CSV, formule/espaces/tabulation, source vide, permissions, tenant/acteur, droits
  révoqués en worker, limites, changement source pendant/après génération,
  intégrité, expiration sans scheduler, nettoyage après départ, échec/reprise
  stockage, concurrence par verrou et livraison en double.
- Suite backend complète : 2 482 réussis, 43 ignorés, zéro erreur/échec
  (2 525 cas, 13 861 assertions), rapport reports/rep004-sqlite.xml.
  Cette passe précède les quatre derniers cas de robustesse, validés ensuite
  dans la sélection finale de 35 cas.
- Sélection PostgreSQL élargie Tenancy, REP-001/REP-002/REP-004 et architecture :
  368 réussis, deux ignorés (370 cas, 1 886 assertions). Bases temporaires
  supprimées. Les 35 cas REP-004 finaux ont été repassés séparément sur PostgreSQL.
  REP-004 est ajouté à la sélection PostgreSQL de CI.
- Frontend complet : 448/448 dans 62 fichiers, avant le dernier cas d'expiration
  locale ; sélection finale ReportExportPanel : 9/9. Build, lint sans avertissement,
  typage strict React/Playwright validés.
- Parcours Chrome avec API simulée : confirmation, absence de génération automatique,
  file, téléchargement CSV réel, refus après changement source, rendu 375/320 px
  sans débordement. Captures examinées visuellement. Cette recette simulée ne
  remplace pas l'activation du worker/stockage réel en environnement isolé.
- PHPStan zéro erreur ; Pint fichiers nouveaux et dirty passés, diff sans erreurs
  d'espacement. Les premiers contrôles ont révélé le catalogue opérateur oublié,
  corrigé puis les tests de portabilité/maintenance ont été repassés. Le problème
  d'écriture initial de Pint sur le provider Tenancy est résolu et dirty repasse.

Livraison incluse dans le commit UCG-REP-004 ; push non effectué dans cette étape.
Aucun .env réel ni permission/donnée métier
réelle modifié, aucun message externe envoyé. Revue du diff, CI distante,
sauvegarde, migration et recette réelle restent les prochaines étapes.

## UCG-ACA-001 — Cohortes, cycles et inscriptions (22 octobre 2026)

### Livraison et règles

- Reprise sur staging propre ; REP-004 `d00c257`, REP-003 `355f42b` et correctif
  REP-002 `4ce0e30` constatés dans l’historique local. La référence locale
  origin/staging pointait sur REP-004 ; aucune nouvelle validation distante de CI.
- Module Academy raccordé à Laravel et à l’espace tenant React, chargé à la demande.
  Cohortes nommées, cycles datés, fuseau IANA, programme hebdomadaire de zéro à
  28 créneaux (jours lundi à dimanche), inscriptions et mentors avec périodes.
  Créneaux dans la même journée, sans chevauchement ; dates d’inscription incluses
  dans celles du cycle. Une personne ne peut avoir deux affectations actives qui
  se chevauchent dans le même cycle, même sous deux rôles différents.
- academy.read permet la consultation, academy.manage exige également academy.read
  pour toute mutation et les choix de personnes. Aucun droit implicite propriétaire,
  administrateur ou mentor ; MFA, contexte serveur, confirmation d’organisation et
  révision de session suivent les mécanismes existants. Aucun périmètre cohorte IAM
  n’est activé par ce lot : autorisations à l’échelle de l’organisation.
- Les personnes sont résolues par le port public AcademyPeopleDirectory de People,
  sur une projection minimale existante (identifiant et pseudonyme). Personnes parties
  exclues des nouvelles affectations et pseudonymes historiques devenus indisponibles
  omis. Pas d’accès au dossier administratif, aux coordonnées ou aux notes internes.
- Un inscrit peut ne pas avoir de compte. Un mentor doit avoir un compte vérifié
  effectivement membre ou propriétaire du tenant. Tout compte lié, même pour un
  inscrit, est revérifié par TenantBusinessGuard ; aucune admission ni permission
  n’est créée. Résolution avant transaction, verrou compte puis organisation, personne
  revérifiée sous verrou et refus si son lien au compte a changé entre-temps.
- Modifications avec version UUID et verrous transactionnels. Archivage définitif
  d’une cohorte ou d’un cycle ; inscription clôturée par retrait ou fin, sans suppression.
  Une cohorte archivée ferme les mutations de ses enfants. Les dates d’un cycle ayant
  déjà des inscriptions sont figées. Les programmes peuvent évoluer avec historique.
- Historique par révisions chiffrées append-only et journal transverse minimal dans
  la même transaction. L’API d’historique expose les métadonnées de changements, pas
  les anciens dossiers ou snapshots déchiffrés. Les affectations restent des données
  historiques après révocation ou départ ; elles ne prouvent jamais un droit courant.

### Schéma, exploitation et limites

- Migration additive `2026_10_22_100213_create_academy_cohorts_cycles_and_enrollments` :
  academy_cohorts, academy_cycles, academy_enrollments, academy_revisions.
  FK composites organisation/cohorte/cycle/personne, index des listes et périodes,
  gardes SQLite/PostgreSQL sur identité, états clôturés et conservation de l’historique.
  down() fonctionne sur les tables vides et refuse d’effacer des données existantes.
- Catalogue fermé des tables/colonnes de portabilité mis à jour ; cycle manuel de
  démontage/rejeu OrganizationSchemaTest étendu avant les tables People/Tenancy.
- Listes, historique et sélecteurs paginés (1 à 100), ordre stable, requêtes toujours
  limitées au tenant. Pseudonymes des inscriptions résolus en bloc, une requête par
  page. Pas de cache durable de droits, de chargement exhaustif ou de job nécessaire.
  Les écritures sont sérialisées par organisation ; profiler la contention et les
  recherches par préfixe avec la volumétrie cible avant toute optimisation.
- L’emploi du temps est un programme hebdomadaire local au fuseau du cycle, pas des
  occurrences concrètes. Aucune activité calendrier, présence, notification, séance,
  objectif ou feedback créé automatiquement. ACA-002 et ACA-003 restent à réaliser.
- Aucun .env réel, worker, donnée métier, adhésion ou permission réelle modifié.
  Aucune migration de la base courante appliquée, aucun e-mail envoyé, aucun push.
  Avant usage : revue/push, CI distante, sauvegarde, migration, attribution explicite
  des droits par un propriétaire habilité puis recette réelle sur données fictives.

### Vérifications

- API ACA-001 : 29 tests SQLite réussis, 127 assertions ; matrice des Policies,
  invités, propriétaire sans droits, lecteur, isolation, IDs de cycles hors cohorte,
  dates/créneaux invalides, mentors, adhésion suspendue, version périmée, archivage,
  historique chiffré, pagination, query count des pseudonymes et rollback protégé.
- Sélection finale PostgreSQL Academy, concurrence réelle (deux processus),
  architecture, contrôleur de schéma, cycle des organisations et portabilité :
  74/74 réussis, 372 assertions. Les bases de tests et de concurrence ont été
  supprimées. Deux écritures sur la même version donnent un succès et un conflit ;
  deux inscriptions simultanées de la même personne ne créent qu’une seule ligne.
- Le premier passage complet a détecté les trois FK directes organization_id
  manquantes malgré les FK composites ; elles ont été ajoutées avant la nouvelle
  validation du contrôleur de schéma. Aucun assouplissement des contrôles existants.
- La suite complète SQLite (2 560 cas, 45 ignorés car conditions optionnelles ou
  PostgreSQL) a ensuite rencontré quatre cas documentaires perturbés par une autre
  suite utilisant le même Storage::fake local. Les bases étaient séparées mais pas
  ce répertoire. Le contrôle PostgreSQL a été relancé après la suite complète :
  74/74 ci-dessus. Les quatre classes documentaires ont été rejouées séparément
  avant clôture : 54/54 réussis, 337 assertions. Aucun échec reproductible restant.
  Ne pas lancer ces suites simultanément sans isoler aussi les
  fichiers de test (par exemple TEST_TOKEN).
- Frontend complet : 457 tests réussis dans 63 fichiers ; après ajustement du hook
  de lecture, sélection Academy/TenantWorkspace 15/15. Build et lint sans avertissement.
- Parcours Chrome avec API simulée : création de cohorte, cycle avec créneau,
  mentor, confirmations de tenant, historique et retour aux cycles ; absence de
  débordement horizontal à 320, 375 et 1280 px, capture 320 px inspectée visuellement.
  Cette validation ne remplace pas la recette avec l’API et les comptes réels de recette.
- PHPStan : zéro erreur. Pint appliqué aux fichiers PHP du lot ; écriture directe
  initialement refusée sur bootstrap/providers.php, résolue par formatage de copies
  dans le workspace puis recopie des contenus, sans modifier les ACL.

Livraison réunie dans un seul commit UCG-ACA-001 avec code, tests et docs ; aucune CI distante revendiquée.

Prochain développement métier : UCG-ACA-002, séances reliées au calendrier,
présences et exercices ; vérifier d’abord les validations de livraison ci-dessus.
## Correctif CI UCG-ACA-001 — Rejeu du schéma People

La CI PostgreSQL a signalé le refus de suppression de people, référencée par
academy_enrollments. Le test de démontage/rejeu PersonSchemaTest avait été oublié
lors d’ACA-001 ; seul le scénario OrganizationSchemaTest avait été adapté. Le
contrôle PostgreSQL précédent ne comprenait pas PersonSchemaTest, contrairement
à la sélection CI. Le succès SQLite ne prouvait pas cet ordre de dépendances.

- PersonSchemaTest charge et retire la migration Academy avant de démonter People,
  puis la rejoue une fois People et ses autres dépendances restaurés.
- Deux assertions vérifient le retrait puis la recréation d’academy_enrollments ;
  le contrôle global TenantSchemaInspector demeure actif après le rejeu.
- Audit des références à la migration People dans les tests : OrganizationSchemaTest
  est déjà raccordé ; le scénario de refus d’un rollback contenant des personnes
  conserve son comportement de protection, avant toute tentative de suppression.
- Aucun DROP CASCADE, contrainte supprimée ou migration applicative modifiée.
- SQLite : 57 cas, 56 réussis, un ignoré, 220 assertions. PostgreSQL : même sélection,
  57/57 réussis, 221 assertions, sur une base temporaire supprimée après validation.
  Sélection : PersonSchemaTest, AcademyTest, OrganizationSchemaTest et les tests
  Feature/Architecture/Database. Pint dirty réussi, PHPStan zéro erreur.
- Code et documentation réunis dans le commit correctif UCG-ACA-001. Aucun push,
  aucune migration sur la base courante, aucune donnée ou permission réelle modifiée.
  Prochaine action : pousser le correctif et relancer la CI ; aucune nouvelle
  migration n’est nécessaire pour ce correctif de test.

## Livraison locale UCG-ACA-002 — Séances, présences et exercices

### Comportement livré

- Dans le détail d'un cycle : panneau de séances chargé sur ouverture, listes,
  sélecteurs et historique paginés, création/modification/annulation avec confirmation
  du tenant, exercices structurés et compte rendu. Pas de présence automatique.
- Chaque séance référence une activité existante de type Académie, partagée avec
  l'organisation, ni annulée ni archivée. Le programme hebdomadaire ne génère pas
  d'occurrences. Le mentor responsable doit être actif sur la période dans le même
  cycle, avec compte vérifié et adhésion courante ; son affectation ne donne aucun droit.
- `academy.read` pour consulter ; `academy.manage` pour gérer les séances ; nouvelle
  permission indépendante `academy.attendance.manage` pour saisir/corriger les
  présences. `calendar.read` pour choisir/confirmer la source et saisir une présence.
  Sans ce droit ou si la source devient privée, la référence calendrier et les
  horaires sont masqués ; les données Académie gardent leur propre autorisation.
- Présent, absent, excusé ou retard de 1 à 240 minutes. L'inscription doit être
  active et couvrir la séance. Saisie après le début seulement ; correction avec
  version et motif obligatoire. Une absence de ligne ne signifie pas absent.
- Calendrier versionné : changement de source détecté et saisie bloquée jusqu'à
  confirmation explicite. Une présence fige source, période et responsable.
  Annulation définitive ; séance terminée seulement après sa fin, sans réouverture.
- Titres, exercices, comptes rendus, motifs et snapshots chiffrés au repos.
  Révisions append-only et audit minimal dans la transaction métier ; historique
  consultable limité aux métadonnées. Aucun dossier médical/psychologique.

### Architecture et migration

- Contrat public `AcademySchedule` et DTO minimal de Scheduling, implémentation et
  requêtes conservées dans ce module. Academy ajoute Scheduling à ses dépendances,
  sans accès à ses modèles internes. Services, repository, FormRequest, Policy et
  Resources dédiés. Réautorisation transactionnelle et versions UUID concurrentes.
- Migration additive `2026_10_22_172532_create_academy_sessions_and_attendances` :
  academy_sessions, academy_attendances, academy_session_revisions et index composite
  des inscriptions. FK directes tenant et composites tenant/cycle, index des listes,
  unicités activité/présence, gardes SQL SQLite/PostgreSQL et rollback protégé.
- Inventaire de portabilité et CI PostgreSQL étendus. Les démontages/rejeux
  PersonSchemaTest, OrganizationSchemaTest, CalendarSchemaTest et AcademyTest
  retirent le nouveau schéma avant ses parents et le restaurent après eux.
  Le scénario qui avait échoué en CI ACA-001 est inclus dans la sélection PG.
- Résolution des pseudonymes et activités en bloc par page, limites de pagination
  1 à 100 et recherche calendrier sur 93 jours au plus. Pas de cache durable de
  droits ; écritures sérialisées par organisation. Profiler contention et plans SQL
  avec la volumétrie cible avant optimisation, sans chargement exhaustif.

### Vérifications et limites de livraison

- Suite backend complète SQLite : 2 590 cas, 2 543 réussis, 47 ignorés selon leurs
  conditions optionnelles/PostgreSQL, 14 159 assertions, aucun échec. Une seule
  suite PHP à la fois afin de ne pas partager simultanément Storage::fake.
- Sélection PostgreSQL isolée : 113/113 réussis, 593 assertions. Inclut les nouveaux
  tests séances/calendrier, les quatre courses concurrentes Academy à deux processus,
  les tests Academy existants, PersonSchemaTest, OrganizationSchemaTest,
  CalendarSchemaTest/CalendarControllerTest, architecture et schéma/portabilité.
  Les bases temporaires et celles des processus concurrents ont été supprimées.
- Nouveaux scénarios API : droits, isolation tenant/cohorte/cycle, calendrier privé
  ou périmé, limites de dates, mentor/inscription, version et doublon concurrents,
  historique chiffré, gardes SQL, rollback, pagination et lectures groupées.
- Frontend complet : 467/467 tests dans 64 fichiers. Deux options de requête DOM
  non typées dans le nouveau test ont été retirées après détection par TypeScript :
  sélection finale 10/10, build TypeScript/Vite réussi, lint sans avertissement.
- Parcours Chrome : création séance/exercice/compte rendu, saisie puis correction
  motivée d'une présence, historique, confirmations et refus avec calendrier périmé.
  API simulée ; absence de débordement à 320, 375 et 1280 px ; captures 375 et 1280
  inspectées. Ce contrôle ne remplace pas une recette avec API/comptes de recette.
- PHPStan : zéro erreur ; Pint dirty réussi. Code, tests et documentation réunis
  dans un seul commit local UCG-ACA-002. Aucun push ni succès de CI distante affirmé.
- Migration courante non appliquée, aucun .env réel, worker, donnée métier,
  adhésion ou permission réelle modifié. Conserver la clé de chiffrement ; inclure
  les trois tables dans les sauvegardes. Revue/push, CI distante, sauvegarde,
  migration, attribution explicite des droits et recette isolée avant usage.

Prochain ticket métier : UCG-ACA-003, objectifs et feedback avec séparation du
feedback partagé et des notes internes ; commencer après validation de livraison.

## Correctif CI UCG-ACA-002 — Rejeux GameCatalogue et Team

La CI PostgreSQL a refusé trois suppressions de calendar_activities, encore
référencée par academy_sessions. Les deux scénarios de GameCatalogueSchemaTest et
celui de TeamSchemaTest maintiennent leur propre liste de dépendances. Ces chemins
indirects de démontage de Calendar n'avaient pas été adaptés pendant ACA-002.
Les 113 tests PostgreSQL précédents ne comprenaient pas ces deux classes ; la suite
SQLite complète avait réussi. Ce succès local ne couvrait donc pas la sélection
PostgreSQL de la CI, qui applique ce contrôle de dépendances même sur des tables vides.

- Les trois scénarios retirent désormais ACA-002 avant Calendar et le recréent
  après ses parents. Ils vérifient le retrait puis la recréation des trois tables
  séances/présences/révisions. Les contrôles existants de schéma et de catalogue
  sont conservés. Aucun DROP CASCADE ni suppression de contrainte.
- Audit des références à la migration Calendar dans tous les tests : les autres
  chemins sont CalendarSchemaTest, PersonSchemaTest et OrganizationSchemaTest,
  déjà adaptés. Le test de refus d'un rollback contenant des activités conserve
  sa protection avant suppression. AcademyTest démonte également ACA-002 avant ACA-001.
- Pour éviter une nouvelle validation partielle, la sélection PostgreSQL de cette
  reprise est extraite du workflow backend-quality.yml (153 chemins), sans liste
  réduite recopiée. Elle s'exécute sur une base temporaire et seule parmi les suites
  PHP, afin d'isoler aussi les fichiers de test. Le README décrit désormais cette
  exigence pour les changements de schéma/FK et la recherche des chemins indirects.
- SQLite ciblé : 29/29 tests réussis, 82 assertions. Pint dirty appliqué ; PHPStan
  zéro erreur. Sélection PostgreSQL complète du workflow : 2 360 cas, 2 358 réussis,
  2 ignorés, 13 663 assertions, aucun échec (environ 20 minutes sur cette machine).
  La base temporaire a été supprimée après exécution. Ce résultat local ne vaut
  pas confirmation d'une nouvelle exécution de la CI distante.
- Aucune migration courante, donnée métier, permission, .env réel ou worker modifié.
  Le correctif concerne les tests et la documentation ; aucun push effectué.

## Livraison locale UCG-ACA-003 — Objectifs et feedback

### Périmètre et accès

- Objectifs individuels ou collectifs d’un cycle, échéance, mentor responsable,
  description pédagogique et statut en cours/atteint/annulé. Le destinataire
  individuel ne change plus après création ; le collectif conserve cette audience.
  Responsable et destinataire actifs dans le même cycle et couvrant l’échéance.
  Le responsable a un compte vérifié et une adhésion courante ; aucun droit implicite.
- Staff : academy.read pour la lecture, academy.manage pour les objectifs,
  academy.feedback.manage pour publier/corriger/retirer les retours partagés,
  academy.notes.manage pour consulter/gérer les notes internes justifiées. Les trois
  permissions de gestion exigent aussi academy.read et restent indépendantes.
- Joueur : nouveau panneau « Mes objectifs et retours », autorisé uniquement par
  academy.self.read, sans besoin d’accès aux écrans staff, séances ou calendrier.
  La personne liée au compte est résolue par le contrat public People ; jamais par
  un identifiant de personne du client. Seules ses inscriptions étudiant actives à
  la date locale du cycle permettent de lire ses objectifs individuels et ceux du
  collectif. Retrait/expiration, départ ou révocation réévalués à chaque requête.
- Feedback partagé actif seulement dans la projection personnelle. Aucune note,
  justification, ancienne valeur, version de commande, inscription ou auteur de
  feedback exposé au joueur. Pas de mutation ou d’historique personnel.
- Les notes internes ont un parcours distinct, une justification obligatoire et une
  classification immuable, sans conversion directe vers le partagé. Le lecteur staff
  sans permission interne ne voit ni les notes ni leurs révisions ou leur nombre.
  Le filtrage s’effectue en SQL avant pagination et déchiffrement.
- Corrections avec version UUID et motif ; clôture des objectifs et retrait des
  retours définitifs. La commande de clôture accepte seulement version/statut/motif,
  même après retrait d’inscription ; elle ne peut réaffecter ni modifier le contenu.
  Un objectif annulé ou des parents archivés bloque les nouvelles commandes enfants.
  Les lectures historiques staff restent disponibles selon les droits courants.

### Persistance, confidentialité et exploitation

- Migration additive `2026_10_22_224821_create_academy_objectives_and_feedback` :
  academy_objectives, academy_feedback, academy_coaching_revisions. FK directes tenant
  et composites cycle/inscription, index de pagination, gardes SQL SQLite/PostgreSQL
  sur audience, identités, états terminaux et historique append-only. L’index composite
  des inscriptions vient d’ACA-002, qui doit donc précéder cette migration.
- Chiffrement des titres, descriptions, contenus, justifications et snapshots.
  Verrous métier puis ressources, réautorisation et validation transactionnelles,
  révision et audit minimal atomiques ; aucun texte ou motif dans l’audit transverse.
  Historique public limité aux métadonnées autorisées, sans snapshot déchiffré.
- Inventaire fermé de portabilité étendu aux trois tables. Rejeux Academy, Calendar,
  People, Tenancy, GameCatalogue (deux chemins) et Team adaptés ensemble, sans DROP
  CASCADE. Les trois classes ACA-003 sont ajoutées à la sélection PostgreSQL CI.
- Listes et sélecteurs paginés de 1 à 100 ; destinataire/responsable eager-loadés,
  pseudonymes en lots de 100. Le sélecteur conserve les choix entre pages.
  Résolution personnelle bornée à 100 inscriptions candidates plus sentinelle ;
  comparaison finale dans chaque fuseau IANA, dépassement refusé sans troncature.
- Pas de cache durable de permissions, de worker, de notification ou de calcul à
  activer. Le verrou par organisation sérialise les commandes ; profiler contention
  et plans de lecture/COUNT avec la volumétrie cible avant optimisation. Pas de
  progression statistique ou d’évaluation automatique implicite ; ACA-004 reste P1.
- Aucune migration courante ni donnée, adhésion, permission ou .env réel modifié.
  Migration ACA-003 constatée Pending en lecture seule, ACA-001/002 Ran batches 53/54.
  Conserver la clé de chiffrement et inclure les trois tables dans les sauvegardes.
  Avant usage : revue/push, CI distante, sauvegarde, migration puis attribution
  explicite des droits et recette sur comptes/données de recette. Éviter de donner
  academy.read aux joueurs quand seul leur espace personnel est souhaité.

### Vérifications

- Suite backend SQLite complète avant les derniers ajustements de clôture/lecture : 2 616 cas,
  2 567 réussis, 49 ignorés, 14 321 assertions, aucun échec. Après cet ajustement,
  les trois classes ACA-003 ont été rejouées : 25/25 réussis, 154 assertions.
- Scénarios : droits distincts, invité/propriétaire sans accès implicite, audience
  individuelle/collective, personne non liée/partie, révocation/retrait, fuseau du
  cycle, version périmée, champs inattendus, dates/affectations incohérentes,
  historique chiffré, SQL d’identité/audience, rollback protégé, pagination et
  résolution groupée des noms. Deux nouvelles courses à processus indépendants
  couvrent les versions concurrentes des objectifs et du feedback.
- Frontend complet final : 479/479 tests dans 65 fichiers ; sélection ACA-003
  12/12 (pagination avec sélection conservée et retrait motivé/versionné inclus).
  TypeScript/Vite et lint réussis.
- Chrome avec API simulée : création d’un objectif individuel, publication explicite,
  note justifiée puis passage au joueur, qui consulte le partagé sans requête de
  notes ou écran staff. Confirmations de tenant vérifiées ; pas de débordement à
  320, 375 et 1280 px ; captures personnelles 320 et staff 1280 inspectées.
  Cette validation ne remplace pas la recette avec API et comptes de recette.
- Sélection PostgreSQL complète du workflow (156 chemins, sans liste locale
  réduite) : 2 387 cas, 2 385 réussis, 2 ignorés, 13 837 assertions, aucun échec.
  Inclut les six courses concurrentes Academy, les trois classes ACA-003, les
  rejeux People/Tenancy/Calendar/GameCatalogue/Team et les tests des autres modules
  sélectionnés en CI. La base temporaire et celles des workers ont été supprimées.
  Une seule suite PHP à la fois pour isoler aussi les fichiers Storage::fake.
- Pint dirty réussi, PHPStan zéro erreur. Code/tests/docs réunis dans un seul
  commit UCG-ACA-003, sans push ni succès de CI distante revendiqué.

Après validation de livraison, réévaluer les P0 restants du backlog ; ACA-004
conserve les ressources, évaluations et progression dans son périmètre P1.

## UCG-OPS-001 — Socle de préparation des environnements (23 octobre 2026)

Le responsable confirme la migration ACA-003 et sa CI distante verte. Il valide
une préparation indépendante du fournisseur : hébergement et gestionnaire de
secrets ne sont pas encore choisis.

- Gabarits staging/production backend et frontend, sans secrets et avec domaines
  `.invalid`. Traitements métier désactivés ; transport mail log. Exclusion Git
  étendue aux `.env*` backend, avec exceptions limitées aux trois gabarits.
- Commande `ops:check-environment --expect=staging|production [--json]` en lecture
  seule, fondée sur la configuration effective. Messages fixes, aucune valeur
  ni secret dans le rapport. Contrôles du profil, namespaces, PostgreSQL, Redis,
  cache/queues, cookies, CORS/Sanctum, Reverb, volume privé et mail de recette.
  Aucun contrôle réseau ni preuve d'isolation des ressources réelles revendiqué.
- Profil Horizon staging borné, sans démarrage de processus. Vite refuse les noms
  de variables publiques non documentés et les configurations distantes HTTP,
  URL avec identifiants, domaines `.invalid` ou Reverb incohérent. Le profil
  distant doit être explicite ; les builds locaux conservent leur fonctionnement.
- Procédures dans le README : ressources dédiées, injection runtime/fichier monté,
  données fictives et comptes de recette, rotation par service, conservation des
  anciennes clés de chiffrement et dépendance à la restauration OPS-004.
- Validation finale du nouveau diagnostic et des gabarits : 30/30 tests backend,
  157 assertions. Sélection diagnostic/cache/queue avant le dernier test des
  gabarits : 35 réussis, 1 intégration Redis ignorée, 133 assertions. Aucune suite
  PostgreSQL complète rejouée : aucune migration ni requête métier modifiée.
  Frontend complet : 493/493 tests, 66 fichiers ; TypeScript/Vite, lint, Pint dirty
  et PHPStan (zéro erreur) réussis. Exclusions Git et diff sans erreurs vérifiés.
- Aucun `.env` réel, secret, base courante, donnée ou permission modifié ; aucune
  migration appliquée, rotation exécutée, service activé ou infrastructure créée.
  Livraison prévue en un commit local UCG-OPS-001, sans push ni nouvelle CI distante
  revendiquée.

OPS-001 reste ouvert : provisionner l'infrastructure après choix du fournisseur,
injecter les secrets distincts, créer les données fictives, vérifier les accès et
la séparation effective, puis effectuer la recette avec comptes/API réels.
OPS-002 (déploiement), OPS-003 (supervision), OPS-004 (restauration) restent distincts.

## UCG-QA-003 — Premier parcours navigateur avec API réelle (23 octobre 2026)

Le responsable reporte l'hébergement à la fin du développement. OPS-001 conserve
son socle préparé ; son provisionnement et sa validation sur infrastructure sont
différés. QA-003 avance d'abord en local et en CI avec données fictives isolées.

- Suite Playwright distincte : identifiants incorrects, connexion, MFA TOTP réel,
  choix d'Alpha, confirmation et création d'une personne, persistance après
  rechargement, passage à Beta en lecture seule puis retour et déconnexion.
  Vérification des refus invité, MFA non terminé, jeton CSRF absent, tenant non
  accessible, ressource étrangère, ancienne révision et écriture sans droit.
  Largeurs 375 et 320 px sans débordement.
- Lanceur Node avec environnement fermé : SQLite neuve, `.env` vide, APP_KEY
  éphémère, cache/verrous en base et stockage/sessions temporaires. Fixtures de
  trois tenants. Aucune réponse API simulée ; routes navigateur limitées aux
  deux origines locales de test. Pas de nouvelle route de fixture ou dérogation
  d'authentification dans l'application publique.
- Refus avant bootstrap de production/DB externe/mauvais nonce/cache existant.
  Ports disponibles sans réutilisation d'un serveur existant ; démarrage direct
  des processus PHP/Vite et nettoyage après leur arrêt sous Windows. Lecture
  des variables Vite dans le dossier temporaire et rapports exclus du watcher.
  Rapports/trace/capture/journal fictif sur échec conservés séparément en CI.
- Défaut détecté : le catalogue global cachait des modèles Eloquent alors que la
  désérialisation de classes est refusée. Au cache chaud, la lecture échouait avec
  `__PHP_Incomplete_Class`. Correction par cache d'attributs simples et réhydratation,
  format de clé v2, sans purge globale ni assouplissement de la désérialisation.
  TTL/révision/contournement du cache en transaction conservés. Régression backend
  hors transaction pour exercer un vrai cache en base entre deux lectures HTTP.
- Premiers essais Windows : chemin absolu de cache puis stockage HTTP explicités,
  cache fichier remplacé par le cache en base requis pour les verrous tenant.
  Les trois sessions temporaires initialement créées dans le stockage local ont
  été identifiées par leurs ports de test et supprimées ; les lignes de journal
  techniques de ces essais ont été conservées. Aucun `.env` réel ni base de
  développement modifié, aucun compte réel ni permission réelle attribué.
- Validation : scénario réel complet réussi sous Chrome ; trois tests du lanceur
  réussis ; sélection backend catalogue/profils/cache : 97/97, 451 assertions.
  Frontend complet : 493/493, 66 fichiers. Build, lint, typage strict du nouveau
  scénario, Pint dirty et PHPStan (zéro erreur) réussis. Workflow frontend étendu ;
  pas de succès de CI distante revendiqué. Pas de nouvelle migration applicative.
  Code, tests et documentation réunis dans un commit local UCG-QA-003, sans push.

QA-003 reste ouvert pour les autres parcours : invitations, enrôlement/récupération
MFA, rosters, calendrier, matchs, contrats et exports. SQLite locale/CI ne remplace
pas PostgreSQL, les workers, Reverb réel ni la recette sur future infrastructure.

## UCG-QA-003 — Deuxième parcours réel : personne, équipes et roster (23 octobre 2026)

- Création par l'interface d'une personne active et de deux équipes actives ;
  ajout au roster source, transfert vers la seconde équipe en tant que remplaçant,
  puis clôture de la période. Rechargement complet et relecture de la persistance
  et des mouvements. La source finit exactement au début de la destination ;
  les deux périodes restent consultables et ne sont plus effectives après clôture.
- Refus vérifiés : chevauchement, personne/équipe/appartenance étrangères,
  ancienne version, nouvelle clôture d'une période terminale, création d'équipe
  et ajout au roster sans permission. Les refus n'ajoutent pas de période ni
  de mouvement ; aucune donnée Alpha ne reste affichée après passage à Beta.
  Le sélecteur de personnes n'affiche pas les personnes des tenants étrangers.
- Formulaires d'équipe contrôlés à 375 et 320 px, suite des créations/transfert/
  clôture à 320 px, historique sans débordement aux deux largeurs. API Laravel,
  MFA, confirmations et autorisations réels ; aucun résultat API simulé.
- Fixtures étendues à cinq organisations et deux comptes de scénario : les
  écritures du premier test ne servent pas au second. Tenant tiers inaccessible,
  équipe/personne/appartenance de lecture seule et catalogue global existant.
  TOTP et filtrage des origines partagés dans un utilitaire de test.
- Vérification : deux parcours réels sous Chrome réussis ; sélection backend
  équipes/appartenances : 54/54 tests, 306 assertions ; composants équipes/roster :
  18/18 ; trois contrôles du lanceur réussis. Lint, typage strict des deux scénarios,
  Pint dirty et PHPStan (zéro erreur) validés. Pas de suite complète backend ou
  PostgreSQL rejouée pour ces changements de tests et fixtures uniquement.
- Aucun code métier, migration applicative, `.env` réel, base de développement
  ou permission réelle modifié. Ressources temporaires nettoyées après arrêt des
  serveurs. Livraison dans un commit local UCG-QA-003, sans push ni nouvelle CI
  distante revendiquée. Le workflow existant découvre automatiquement le scénario.

QA-003 reste ouvert pour les invitations et les parcours MFA avancés, calendrier,
matchs, contrats et exports ; les promotions et annulations futures du roster
restent des cas à approfondir. L'hébergement demeure différé selon la décision du responsable.

## UCG-QA-003 — Troisième parcours réel : calendrier (23 octobre 2026)

- Compte et deux organisations propres au calendrier, dans la SQLite temporaire.
  Sept tenants fictifs et trois comptes de scénario distincts au total. Droits
  explicites calendar.read/write et availability.read dans Alpha, calendar.read
  seulement dans Beta ; ni publication ni exception de conflit autorisée.
- Indisponibilité fictive de 10 à 11 h UTC : détectée dans le formulaire et le
  rapport réel sans commentaire privé. Écriture sans confirmation de tenant,
  sans revue de conflit ou avec exception non autorisée refusée ; aucune activité
  partielle créée. Créneau libre choisi ensuite de 12 à 13 h UTC.
- Création d'une activité privée avec équipe et participant, puis déplacement de
  14 à 15 h UTC et ajout d'un second participant avec motif opérationnel. Affichage
  Asia/Tokyo contrôlé (21 h puis 23 h), versions périmées refusées. Participants
  et nouvelle version conservés après rechargement complet.
- Annulation motivée, refus de modifier l'activité archivée, disparition du
  calendrier courant même après rechargement et consultation en archives de gestion.
  Historique limité aux deux changements attendus : rescheduled et cancelled.
- Identifiants d'activité étrangers et participant étranger refusés sans révéler
  les titres privés. Passage à Beta en lecture seule, création refusée et liste
  inchangée. Formulaire et actions à 320 px, contrôle de débordement à 375 et 320.
- Validation : trois parcours réels sous Chrome ; sélection backend calendrier,
  conflits, changements et disponibilités : 53/53, 513 assertions ; composants
  calendrier/changements et conversions de dates : 26/26 ; trois contrôles du
  lanceur réussis. Lint, typage strict (avec les types Vite du contrat calendrier),
  Pint dirty et PHPStan zéro erreur. Pas de nouvelle suite complète PostgreSQL
  pour ces changements de tests et fixtures uniquement.
- Aucun code métier, migration applicative, `.env` réel, base de développement
  ou permission réelle modifié. Pas de notification réelle, d'activation de
  scheduler ou de worker ; ressources temporaires supprimées après arrêt des
  serveurs. Commit local UCG-QA-003, sans push ni nouvelle CI distante revendiquée.

QA-003 reste ouvert pour invitations/MFA avancé, matchs, contrats et exports,
ainsi que ressources/récurrences/notifications avancées du calendrier. Le prochain
parcours métier proposé est celui des matchs avec API réelle.

## UCG-QA-003 — Quatrième parcours réel : matchs (23 octobre 2026)

- Deux organisations dédiées aux matchs, compte créateur avec sports.read/write/
  correct/validate et approbateur distinct avec sports.read/validate. Le créateur
  dispose uniquement de sports.read dans Beta. Neuf tenants fictifs au total,
  quatre comptes de scénario et un compte d'approbation, sans dépendance aux
  écritures des autres scénarios.
- Match privé BO1 créé par le navigateur : jeu, responsable, horaires UTC et deux
  équipes. Le responsable est une personne liée à l'approbateur, ce qui lui donne
  accès au dossier privé sans publication. Les deux compositions finalisées sont
  des prérequis fictifs ; leurs affectations passent par les formulaires réels.
- Transitions proposé → confirmé → en cours, fixation des règles, partie à 13–7
  et soumission d'une preuve fictive. L'auteur ne peut pas approuver son résultat,
  même avec sports.validate. Un second contexte navigateur authentifié et MFA
  approuve ; ses actions d'écriture sportive restent absentes et la correction
  forcée par API est refusée.
- Correction motivée à 7–13, statut pending reçu puis applied constaté via API.
  Le vrai job est consommé par un worker dédié à la file database/default dans
  la même SQLite temporaire. Anciennes et nouvelles valeurs conservées, vainqueur
  inversé et résultat remis en contestation. Les parties sources restent figées ;
  nouvelle preuve et nouvelle approbation indépendante requises et vérifiées.
- Rechargements complets, journal des deux approbations et correction terminée
  contrôlés. Version périmée, référence de personne étrangère et identifiants de
  matchs étrangers refusés ; aucune création partielle après refus. Passage à
  Beta : dossier Alpha absent, création refusée et liste Beta inchangée. Actions
  et formulaires à 320 px ; absence de débordement contrôlée à 375 et 320 px.
- Lanceur : worker démarré comme processus enfant, attente de disponibilité,
  arrêt avant suppression du dossier temporaire et durée maximale de dix minutes.
  Entrée PHP gardée, refus d'une file synchrone ou d'une connexion de file externe.
  Les paramètres applicatifs hérités et les `.env` réels restent exclus.
- Validation : quatre parcours réels sous Chrome ; 85 tests backend ciblés
  (compositions, scores, résultats, corrections et worker), 1 000 assertions ;
  36 tests de composants matchs ; quatre contrôles du lanceur. Lint, typage strict
  des quatre scénarios, Pint dirty et PHPStan sans erreur. Pas de suite complète
  PostgreSQL ni de nouvelle CI distante revendiquée.
- Aucun code métier, migration, `.env` réel, base de développement ou permission
  réelle modifié. Le broadcast reste journalisé ; aucune validation Reverb ou
  activation de worker hébergé. Documentation et tests réunis dans un commit
  local UCG-QA-003, sans push.

QA-003 reste ouvert pour invitations/MFA avancé, contrats, exports et cas avancés
du calendrier et des matchs. Le prochain parcours métier proposé est celui des
contrats avec API réelle. Hébergement et configuration fournisseur restent différés.

## UCG-QA-003 — Cinquième parcours réel : contrats (24 octobre 2026)

- Deux organisations propres aux contrats et trois comptes MFA : préparateur avec
  droits administratifs et financiers, approbateur avec lecture/validation financière
  sans écriture financière, gestionnaire administratif sans droit financier. Le
  préparateur dispose uniquement d'administrative.read dans Beta. Onze tenants et
  huit comptes de scénario/intervenants au total, dans la SQLite temporaire.
- Création et modification d'un dossier joueur par les formulaires : responsable
  avec identité administrative, partie interne, dates et obligation de suivi.
  Références étrangères et versions périmées refusées ; aucune création partielle.
- Conditions financières de test à 1 234,56 EUR mensuels et modalités sentinelles.
  Versions du dossier et des conditions contrôlées séparément. Réponses ordinaires
  et interface du gestionnaire administratif sans montant ni modalité sensible ;
  lectures/écritures financières directes et approbation du dossier refusées.
- Passage en revue, auto-approbation refusée même avec les permissions explicites.
  Le second compte lit les conditions et approuve depuis le navigateur ; son
  formulaire d'écriture financière reste absent. Modification des conditions après
  approbation refusée. Signature externe déclarée puis activation : in_force vrai,
  sporting_eligibility conservée à not_assessed, sans autorisation sportive déduite.
- Résiliation puis tentative d'archivage refusée tant que l'obligation reste ouverte.
  Accomplissement via le formulaire, archivage puis modification refusée. Après
  rechargement : dossier archivé, signature et conditions conservées, pas d'écriture
  financière proposée. Historique limité aux huit statuts attendus. Passage à Beta :
  dossier Alpha absent, création refusée et liste Beta inchangée.
- Formulaire contrôlé à 375 et 320 px, actions des trois comptes à 320 px ; absence
  de débordement vérifiée. Scénario ajouté à la suite réelle automatiquement
  découverte par la CI existante. Aucune réponse API métier simulée.
- Validation : cinq parcours réels sous Chrome ; sélection backend contrats,
  gouvernance financière, service et audit : 89 tests, 587 assertions ; composants
  contrats/finances : 18 tests ; quatre contrôles du lanceur. Lint, typage strict,
  Pint dirty et PHPStan sans erreur. Pas de nouvelle suite complète PostgreSQL ni
  de CI distante revendiquée.
- Aucun code métier, migration applicative, `.env` réel, base de développement ou
  permission réelle modifié. Pas d'envoi réel, d'activation de service hébergé ou
  de validation d'antivirus. Documentation et tests dans un commit local UCG-QA-003,
  sans push.

QA-003 reste ouvert pour invitations/MFA avancé, exports, documents/avenants/rappels
des contrats et cas avancés du calendrier et des matchs. Le prochain parcours
proposé est celui des exports avec API réelle. Hébergement toujours différé.

## UCG-QA-003 — Sixième parcours réel : exports (24 octobre 2026)

- Deux organisations propres aux exports, un demandeur habilité dans les deux,
  un collègue avec les mêmes droits dans Alpha et un lecteur sans reporting.export.
  Treize tenants et onze comptes de scénario/intervenants au total. Personnes de
  test distinctes dans Alpha/Beta, identité administrative sentinelle et pseudonyme
  commençant par une formule. Un export de fixture déjà échu, sans artefact, teste
  l'expiration sans avancer l'horloge des sessions ni attendre une heure.
- Blocage d'interface découvert : ReportExportPanel exigeait active.confirmed mais
  ne proposait pas de confirmation. La première demande était inaccessible après
  sélection d'organisation. Réutilisation de usePeopleMutation : confirmation
  explicite nommant le tenant, confirmation HTTP de session si nécessaire, puis
  demande avec clé d'idempotence conservée en cas d'échec et renouvelée au succès.
  Tests de composants et scénario simulé adaptés ; pas de contournement serveur.
- Génération activée seulement dans l'environnement fermé du lanceur, avec le
  worker existant étendu à default,reports sur la même SQLite temporaire. Nettoyage
  planifié désactivé ; aucune configuration réelle de worker ou de déploiement modifiée.
- Demande active_people par l'interface, état queued reçu, job réel puis état
  completed lu par API. Même demande rejouée : même identifiant, une seule nouvelle
  entrée. Rapport de contrats refusé faute de permission métier. Métadonnées sans
  artefact, empreinte, version de source ni clé de demande.
- Après rechargement, téléchargement réel du CSV par le navigateur : deux lignes
  Alpha, en-tête attendu, formule neutralisée et aucune identité administrative ou
  donnée Beta. Collègue pourtant habilité : liste vide, accès direct et téléchargement
  refusés ; lecteur sans permission : panneau absent, demande et accès refusés.
- Modification d'une personne par API publique : ancien téléchargement refusé avec
  REPORT_EXPORT_STALE, sans régénération HTTP. Passage à Beta : liste vide et accès
  à l'identifiant Alpha refusé. Second export généré et téléchargé : une seule ligne
  Beta, sans identifiant ni contenu Alpha. Formulaires et téléchargements à 320 px,
  contrôle de débordement à 375 et 320 ; pas d'erreur JavaScript ni origine externe.
- Validation : six parcours réels sous Chrome ; ReportExportTest : 35 tests et
  241 assertions ; composants exports/tableaux de bord : 19 tests ; quatre contrôles
  du lanceur. Build, lint, typage strict des six scénarios, Pint dirty et PHPStan
  sans erreur. Scénario Playwright simulé réussi avec vidéo désactivée (binaire vidéo
  absent localement) ; lanceur standard interrompu après son résultat, son arrêt des
  serveurs restant bloqué sous Windows. Le lanceur isolé réel termine normalement.
- Tests et stockage fictifs uniquement, pas de migration applicative ni de `.env`
  réel modifié. Commit local UCG-QA-003 sans push. Aucune CI distante ou nouvelle
  suite complète PostgreSQL revendiquée. Les délais réels d'expiration, le nettoyage
  et les autres rapports restent distincts du scénario navigateur livré.

QA-003 reste ouvert pour invitations et MFA avancé, cinq autres rapports exportables,
documents/avenants/rappels des contrats et cas avancés des autres modules. Le prochain
lot proposé couvre les invitations avec API réelle, puis l'enrôlement/récupération
MFA. L'hébergement reste différé selon la décision du responsable.

## UCG-QA-003 — Septième parcours réel : invitations (24 octobre 2026)

- Deux organisations dédiées, gestionnaire MFA avec invitations.manage et
  memberships.manage dans Alpha et accès simple dans Beta. Destinataire déjà
  vérifié et enrôlé MFA, sans organisation initiale. Quinze tenants et treize
  comptes de scénario/intervenants au total. Pas de dépendance aux écritures des
  autres scénarios.
- Invitation Alpha expirée : état affiché et aucune action Accepter ; tentative
  directe refusée avec INVITATION_EXPIRED. Invitation Beta refusée par le formulaire
  de confirmation ; aucune organisation accessible ni adhésion créée.
- Gestionnaire : invitation avec e-mail normalisé, rôle Membre et période explicite
  UTC, formulaire à 320 px après contrôle 375/320. Doublon pending et délégation
  administrateur par un non-propriétaire refusés. Tentative d'accepter par un autre
  compte et révocation d'un identifiant d'invitation étranger refusées.
- Invitation conservée après rechargement puis révoquée depuis l'interface.
  Destinataire : état Révoquée sans bouton d'acceptation et nouvel essai direct
  refusé. Aucun membre destinataire avant la réémission. Nouvelle invitation
  distincte, rôle injecté refusé, acceptation réelle puis sélection d'Alpha.
- Une seule adhésion active, rôle member, fin de période conservée ; contexte
  limité à organization.view. Nouvelle acceptation refusée, gestion des invitations
  interdite et Beta inaccessible. État accepté et accès Alpha conservés après
  rechargement. Historique Alpha limité à expired/revoked/accepted. Gestionnaire
  passé à Beta : formulaire absent, données Alpha disparues et création refusée.
- E-mails seulement mis en file notifications, non consommée par le worker du
  lanceur. Profil array et exclusion de SMTP hérité vérifiés. Ce lot ne valide pas
  la livraison d'e-mail, la création de compte, la vérification d'adresse ou
  l'enrôlement MFA du destinataire.
- Premier lancement : verrou SQLite pendant la réservation d'un job d'export
  existant. Correction du bootstrap de test uniquement : WAL, attente de 5 s et
  transactions IMMEDIATE pour sérialiser les écritures HTTP/worker. Aucun changement
  de back/config/database.php ou de base réelle. Les garde-fous restent exécutés
  avant le bootstrap et le nettoyage suit l'arrêt des processus.
- Validation : sept parcours réels sous Chrome ; InvitationControllerTest :
  17 tests, 93 assertions ; TenantWorkspace : 7 tests ; quatre contrôles du lanceur.
  Typage strict, lint, Pint dirty et PHPStan sans erreur. Pas de nouvelle CI distante
  ou suite complète PostgreSQL revendiquée.
- Aucun code métier ou schéma applicatif modifié, pas de migration appliquée sur
  une base existante, de `.env` réel modifié ou d'envoi SMTP réel. Tests et documentation
  réunis dans un commit local UCG-QA-003 sans push.

QA-003 reste ouvert pour l'enrôlement/récupération MFA, les cas avancés d'invitation,
les cinq autres rapports exportables, les documents/avenants/rappels des contrats
et les cas avancés des autres modules. Le prochain lot proposé porte sur
l'enrôlement et la récupération MFA avec API réelle. Hébergement toujours différé.

## UCG-QA-003 — Huitième parcours réel : MFA (24 octobre 2026)

- Propriétaire fictif vérifié, sans MFA initial, avec une organisation dédiée.
  Seize tenants et quatorze comptes de scénario/intervenants au total. Connexion
  avec mot de passe réel puis accès aux organisations refusé avant l'enrôlement.
- Mot de passe incorrect refusé, clé Base32 obtenue depuis l'interface, TOTP calculé
  par un client RFC 6238 indépendant du domaine PHP. Confirmation depuis une autre
  session refusée ; code incorrect refusé puis activation réelle. Réponses no-store,
  dix codes de secours uniques affichés puis masqués, absents après rechargement.
- Deux contextes navigateur : activation invalidant la session antérieure,
  connexion demandant un second facteur, code d'activation rejoué refusé et accès
  métier bloqué. Un code de secours permet la récupération et révoque l'autre
  session. Le même code consommé est refusé lors d'une nouvelle connexion.
- Connexion TOTP sur un nouveau pas réel, sans manipulation d'horloge serveur.
  Désactivation obligatoire interdite au propriétaire par API et bouton absent.
  Renouvellement des codes avec mot de passe, révocation de l'autre session, ancien
  code inutilisé refusé puis récupération avec un nouveau code. État MFA vérifié et
  accès à la bonne organisation conservés après rechargement. Secret et codes
  absents du compte sérialisé ; interface sans réaffichage des codes.
- Limite réelle de cinq opérations MFA par minute : le scénario vérifie 429 et
  un Retry-After borné, attend le délai indiqué puis réessaie une seule fois. Un
  TOTP est recalculé si cette attente l'a fait expirer. Aucune exemption ni cache
  de limitation purgé. Délai du scénario porté à six minutes ; la suite peut prendre
  plusieurs minutes en respectant ces protections.
- Formulaires exercés à 320 px et contrôle initial à 375/320 ; aucune origine
  externe ni erreur JavaScript attendue. Les secrets n'existent que dans la base
  temporaire, l'interface et la mémoire/traces fictives du test, pas dans fixture.json.
- Validation : huit parcours réels sous Chrome ; MfaAuthenticationTest : 29 tests,
  185 assertions ; MfaPanel : 4 tests ; quatre contrôles du lanceur. Typage strict,
  lint, Pint dirty et PHPStan sans erreur. Pas de nouvelle CI distante ni de suite
  complète PostgreSQL revendiquée.
- Aucun code métier, migration ou configuration réelle modifié. Compte, clé
  applicative, fichiers et base temporaires supprimés après arrêt des processus.
  Livraison dans un commit local UCG-QA-003, sans push.

Les parcours principaux de QA-003 ont maintenant chacun un scénario réel. Le ticket
reste ouvert pour la revue globale de couverture et les cas avancés documentés :
remplacement d'authentificateur, désactivation d'un compte ordinaire, expirations,
autres rapports, documents/avenants/rappels et cas avancés des modules. Prochaine
étape proposée : bilan de couverture QA-003 et préparation de la recette PostgreSQL.
L'hébergement reste différé selon la décision du responsable.

## Bilan de couverture QA-003 et préparation PostgreSQL (24 octobre 2026)

Référentiel : QA-003 exige les parcours invitation, MFA, changement de tenant,
personne, roster, calendrier, match, contrat et export, stables aux tailles convenues
avec des erreurs exploitables. REC-23 à REC-36 est le critère de QA-002, et non un
critère supplémentaire créé pour QA-003. QA-004/005 couvrent concurrence et charge.

| Parcours réel | Preuve dans front/tests/real-api | Couverture principale | Limites navigateur restantes |
| --- | --- | --- | --- |
| Identité, personne et contexte | identity-people.spec.ts | Connexion, challenge MFA, confirmation, création, persistance, déconnexion, CSRF et refus d'accès | Inscription, vérification d'adresse, reset de mot de passe |
| Équipes et roster | teams-roster.spec.ts | Création, appartenance, transfert, clôture, conflits et isolation | Promotions/annulations futures, départ conservant un ancien lineup |
| Calendrier | calendar.spec.ts | Indisponibilité, création, déplacement, participants, annulation, UTC/Asia-Tokyo | Récurrences/DST, réservations, notifications et abonnements |
| Match | matches.spec.ts | Compositions, scores BO1, preuve, validation distincte, correction et vrai job | BO3 incohérent, substitution, carnet, Reverb réel |
| Contrat | contracts.spec.ts | Préparation, finances restreintes, approbation, signature déclarée, résiliation et archivage | Documents/avenants, antivirus, rappels et liens temporaires |
| Export | exports.spec.ts | Job, CSV, demande idempotente, demandeur, tenants, expiration de fixture et source périmée | Cinq autres rapports, expiration réelle/nettoyage et droits révoqués |
| Invitation | invitations.spec.ts | Rôle/période, refus, expiration, révocation/réémission, admission unique | Inscription du destinataire, courrier livré, invitation administrateur par propriétaire |
| MFA | mfa.spec.ts | Activation, TOTP, secours/renouvellement, sessions révoquées, limite réelle 429 | Remplacement d'authentificateur, désactivation ordinaire, expiration d'enrôlement, limite IP multi-comptes |

Les neuf thèmes du ticket sont donc représentés par huit scénarios. Les scénarios
utilisent 375/320 px et vérifient l'absence de débordement à leurs étapes ciblées.
Les réponses d'erreur, codes métier et refus d'actions sont contrôlés ; ceci ne
constitue pas un audit exhaustif de navigation clavier, lecteurs d'écran ou de tous
les navigateurs. Les tests restent sous Chrome/Chromium, avec retries=0.

### Décision de qualification

- Couverture fonctionnelle principale atteinte localement. Ne pas déclarer tout le
  ticket validé en CI depuis ces seuls résultats locaux : obtenir les artefacts du
  SHA contenant les derniers scénarios et vérifier les exclusions/échecs.
- Les écarts du tableau sont des suites à prioriser, pas des anomalies critiques
  découvertes dans le runtime. Leur absence au navigateur ne signifie pas absence
  de tests backend : les fichiers Feature dédiés couvrent notamment schémas,
  autorisations, expiration et concurrence.
- QA-003 ne vaut pas validation de QA-002, QA-004, QA-005 ni de la release. REC-23,
  REC-25 et la séparation des fichiers de REC-27 ont une preuve navigateur ciblée.
  REC-24 reste partiel (sélecteurs, sans parcours de recherche globale) ; les caches
  de REC-27 et le rejeu après changement de contexte de REC-28 ne sont pas clos par
  ce smoke test. REC-26/29/30/32–36 nécessitent leurs preuves dédiées. Le CDC ne liste
  pas REC-31 ; aucune exigence correspondante n'est inventée.
- Les validations de Reverb, SMTP, antivirus, restauration et exploitation restent
  séparées et à réaliser avant une mise en production selon leurs tickets. Aucun
  choix d'hébergement n'est imposé par ce bilan.

### Préparation de la recette PostgreSQL

Le workflow backend dispose déjà de PostgreSQL 17 et de tests de contraintes,
rollbacks protégés et courses sur bases filles temporaires. Le workflow frontend
reste en SQLite : environment.mjs, browser-application.php et browser-seed.php
refusent un remplacement arbitraire par PostgreSQL. Une réussite PostgreSQL au
backend ne prouve donc pas l'exécution des huit parcours navigateur sur ce moteur.

L'audit de la liste manuelle PostgreSQL a identifié quatre omissions Contrats :
ContractAccessAuditTest, ContractFinancialGovernanceTest, ContractReminderTest et
ContractReminderMailTest. Elles sont ajoutées au step existant. Les autres tests
hors sélection PostgreSQL ne sont pas déclarés couverts par cette correction.
Syntaxe YAML vérifiée avec Symfony YAML et les 160 chemins sélectionnés existent.

Ordre de recette préparé dans le README : schémas parents (People, catalogue, Teams),
contrats sensibles et rappels, courses calendrier/résultats/corrections, puis sélection
complète du workflow. Attendus : aller-retour de migration à vide, refus de perte
d'historique, aucune FK orpheline, un résultat concurrent accepté et l'autre refusé
selon le contrat testé, aucune écriture ou notification dupliquée. Lire les exclusions
au lieu de les compter comme réussites. Les tests de concurrence ont besoin d'un rôle
de test autorisé à créer/supprimer leurs seules bases filles, dans un serveur jetable.

Contrôles locaux de ce lot : les quatre fichiers ajoutés passent sous SQLite
(82 tests, 489 assertions). Ce résultat ne vaut pas exécution PostgreSQL. PDO pgsql
et psql 17 sont disponibles ; le moteur Docker n'est pas joignable (pipe absent).
Aucun service n'a été démarré et aucune base locale existante n'a été modifiée.
Nouvelle CI distante et recette PostgreSQL restent à exécuter et à rattacher au SHA.

La suite réelle complète a été répétée pour ce bilan : 8 réussites, aucun échec,
erreur ou scénario exclu, environ 4,1 minutes ; nettoyage des ressources confirmé.
Résultats dans le rapport local front/reports/playwright-real.xml. Sources, tests, configuration
métier et migrations restent inchangés ; seules la sélection CI et la documentation
évoluent. Livraison locale sans push. Prochaine action : obtenir une exécution de la
CI PostgreSQL complétée, puis traiter ses éventuels échecs avant d'étendre le lanceur
navigateur à PostgreSQL. Hébergement toujours différé.

## Vérification des CI du bilan QA-003 (24 octobre 2026)

- Après `git fetch origin`, `staging` et `origin/staging` pointent sur
  `c1d5f341a7494f73fa6ea77861405170c02cd540`. Aucun push ni nouveau lancement
  n'est nécessaire pour ce SHA : les deux workflows ont déjà été exécutés.
- [Backend quality — 40046960453](https://github.com/sloumach/ucg-plateform/actions/runs/40046960453) :
  statut completed, conclusion success. Vérification individuelle des étapes :
  formatage, analyse statique, tests backend, contraintes PostgreSQL et isolation
  Redis terminés avec succès. La sélection PostgreSQL inclut les quatre fichiers
  Contrats ajoutés dans le bilan précédent.
- [Frontend quality — 40046960495](https://github.com/sloumach/ucg-plateform/actions/runs/40046960495) :
  les jobs lint/tests/build et Playwright sont terminés avec succès. Les étapes
  Playwright et parcours isolés avec API Laravel réelle sont explicitement réussies.
  Le workflow utilise toujours SQLite pour les parcours navigateur.
- Artefacts confirmés par l'API : `backend-test-report`, `frontend-test-report`
  et `playwright-reports`, présents et non expirés au moment de la vérification.
  Leur contenu n'a pas été téléchargé ni relu. Aucun nouveau décompte de tests,
  aucune absence d'exclusions et aucune exécution navigateur PostgreSQL ne sont
  déduits du seul statut success.
- L'attente d'une CI distante réussie du SHA `c1d5f34` est levée. QA-003 reste
  en qualification pour la lecture des rapports JUnit et l'examen des exclusions
  et écarts déjà recensés. Prochaine étape : examiner ces rapports, puis préparer
  le lanceur navigateur PostgreSQL isolé avec ses garde-fous et son nettoyage.
  Les critères QA-002/004/005, SMTP, antivirus, Reverb, restauration et exploitation
  restent distincts. Aucun service réel, schéma ou donnée métier modifié.

## UCG-QA-003 — Lanceur navigateur PostgreSQL isolé (24 octobre 2026)

- Mode explicite `--postgresql` / `npm run test:e2e:postgresql` ; SQLite reste
  le mode par défaut. Utilisation des binaires natifs PostgreSQL 17, sans Docker,
  sans connexion au service installé et sans lecture du `.env` de développement.
  `UCG_BROWSER_PG_BIN` sélectionne seulement un répertoire de binaires canonique,
  jamais une adresse de serveur ou une base existante.
- `initdb` crée un cluster neuf dans `ucg-browser-*/pgsql`, avec authentification
  SCRAM, compte dédié, secret aléatoire du lancement, port libre et écoute loopback
  seulement. Une nouvelle base `ucg_browser_<identifiant aléatoire>` y est créée.
  Les ports HTTP/Vite/PostgreSQL sont distincts ; aucune variable DB/PG héritée
  n'est transmise. Le serveur HTTP et le worker partagent uniquement ce cluster.
- Avant boot Laravel, le garde contrôle le propriétaire du répertoire, le manifeste,
  le profil testing, les coordonnées exactes et la version, puis compare le
  `data_directory` renvoyé par PostgreSQL au chemin temporaire attendu. Le seed
  refuse un schéma public déjà rempli avant toute migration. Les garde-fous
  SQLite, caches de configuration, queues et fichiers privés sont conservés.
- Arrêt des serveurs HTTP/worker avant `pg_ctl stop` ; vérification du chemin,
  du jeton et du PID avant arrêt du seul cluster créé. Suppression du répertoire
  après arrêt confirmé. Une annulation ne désactive pas le nettoyage ; en cas
  de doute ou d'échec, statut d'échec et conservation des ressources pour diagnostic.
  SIGKILL/arrêt brutal du système restent hors garantie de nettoyage.
- Correctif du lanceur sous Windows : `pg_ctl` écrit dans un fichier temporaire
  au lieu de pipes hérités par le daemon, qui empêchaient la fin du sous-processus.
  Les deux instances temporaires de diagnostic ont été arrêtées après vérification
  de propriété, puis supprimées par leurs lanceurs. Aucun service existant arrêté.
- La matrice frontend prépare des jobs SQLite/PostgreSQL indépendants, sur
  Ubuntu 24.04, avec binaires 17 installés depuis PGDG sans cluster standard.
  Artefacts distincts et rapport `front/reports/playwright-real-postgresql.xml`.
  Ni le workflow backend ni le code métier ni les migrations ne changent.
- Validation locale : six contrôles Node du lanceur ; test d'intégration PostgreSQL
  prouvant refus de coordonnées modifiées, d'un autre répertoire réel malgré un
  manifeste plausible, d'une base déjà remplie et d'un arrêt sans propriétaire.
  Arrêt également vérifié après annulation ; relance de l'arrêt sans effet.
  Huit parcours réels PostgreSQL réussis en environ 6,2 minutes ; XML relu :
  8 tests, 0 échec, 0 erreur, 0 exclusion. Nettoyage confirmé après cette suite.
  Régression SQLite : huit parcours réels réussis en environ 4 minutes, XML relu
  avec les mêmes compteurs, arrêt et suppression des ressources confirmés.
  Frontend : 493 tests dans 66 fichiers réussis, typage et lint verts ; Pint dirty
  et PHPStan sans erreur. YAML de la matrice analysé avec Symfony YAML.
- Limites : lancement PostgreSQL initial refusé par le jeton Windows restreint,
  puis validé avec les droits normaux de la session. Un essai SQLite sous sandbox
  n'a pas trouvé l'exécutable navigateur ; nettoyage confirmé et relance dans la
  session utilisateur. Cela ne constitue pas un échec d'un parcours métier.
  Cette relance SQLite est réussie (8/8), sans téléchargement de navigateur.
  La nouvelle CI Linux/PostgreSQL et ses rapports doivent être validés après
  revue/push. Les preuves CI du SHA `c1d5f34` concernent la version précédente.
  QA-002/004/005, services externes et recette hébergée restent des lots distincts.
  Livraison réunie dans le commit UCG-QA-003 dédié à ce lanceur ; aucun push
  effectué à cette étape. Nouvelle CI distante à confirmer après push.

## UCG-QA-002 — Premier lot de preuves d'isolation (24 octobre 2026)

Objectif : relier les critères du CDC aux tests existants et compléter les
changements de contexte et révocations, sans ouvrir de nouveau parcours métier.
Le ticket est XL : ce lot ne le clôt pas et ne remplace pas une qualification
exhaustive des CRUD de chaque module. Le CDC ne contient pas de REC-31.

### Cas ajoutés et renforcés

- Export demandé dans Alpha, job capturé puis sérialisé, passage HTTP à Beta,
  exécution et rejeu via `dispatchSync` et ses middlewares : un seul artefact,
  source Alpha uniquement, session Beta conservée et aucun export dans Beta.
  Le retour à Alpha permet de télécharger le CSV attendu. Ce test ne remplace
  pas le worker externe ; les tests TenantJobRunner couvrent aussi la vraie
  queue database et la réutilisation du worker en processus de test.
- Révocation de l'adhésion Alpha ou de la permission source après envoi puis
  sélection de Beta : les droits Beta ne sauvent pas le job Alpha. Export marqué
  failed, aucun fichier ni artefact créé, Beta reste accessible.
- Recherche et autocomplétion : même acteur et même préfixe dans deux tenants,
  changement HTTP de contexte, rejet du curseur Alpha dans Beta, puis révocation
  du rôle Beta. Aucun résultat Beta après révocation ; Alpha reste accessible.
- Cache : même clé dans deux tenants, contexte Alpha conservé en mémoire puis
  adhésion révoquée. Lecture Alpha refusée, valeur Beta intacte et valeur Alpha
  toujours accessible à son propriétaire légitime.
- Fichiers privés : après révocation d'adhésion Alpha, lecture et suppression via
  l'ancien contexte refusées. Fichier et compteur Alpha conservés ; fichier Beta
  de même nom et compteur Beta inchangés pour le même acteur.
- URL directe d'équipe : code et message identiques pour une équipe étrangère
  connue et un UUID inexistant ; aucun UUID de l'objet/tenant étranger dans le
  refus. Le test d'écriture étrangère existant est conservé.

Ces cas portent le groupe PHPUnit `tenant-isolation` : 9 cas, dont 8 nouveaux,
dans les cinq fichiers modifiés. Ils restent inclus dans la suite Feature normale
et dans la sélection PostgreSQL existante ; aucune modification de workflow requise.

### Matrice initiale et limites de preuve

Les noms ci-dessous désignent des fichiers sous `back/tests/Feature/` ; le préfixe
`Tenancy/` abrège `Modules/Tenancy/`. Les suites citées ont été exécutées sous
SQLite dans ce lot ; seules les cinq suites modifiées ont aussi été rejouées
sur PostgreSQL. Une preuve ciblée n'est pas une certification globale du critère.

| Critère | Suites de référence | Preuve et limite actuelle |
| --- | --- | --- |
| REC-23 | TeamControllerTest | URL étrangère et inconnue indiscernables, modification étrangère refusée ; autres CRUD à inventorier module par module. |
| REC-24 | TenantSearchTest | Sources autorisées, curseurs, recherche et suggestions isolés après changement de contexte/révocation ; couvre le moteur livré. |
| REC-25 | Tenancy/ActiveOrganizationControllerTest, TenantSearchTest, ReportExportTest | Session, révision et permissions recalculées côté API ; menus/navigateurs restent couverts séparément par QA-003. |
| REC-26 | TeamControllerTest, Tenancy/TenantDatabaseIsolationTest | Slug local et unicités tenant ; inventaire des contraintes de tous les modules encore à compléter. |
| REC-27 | ReportExportTest, Tenancy/TenantCacheTest, Tenancy/TenantArtifactServiceTest | Même rapport, même clé et même nom privés séparés ; pas de nouveau test Redis réel dans ce lot. |
| REC-28 | ReportExportTest, Tenancy/TenantJobRunnerTest, Tenancy/TenantQueuedMailTest | Transport sérialisé, rejeu, contexte persisté, révocations et queue database ; jobs des autres modules à recouper. |
| REC-29 | MatchRealtimeTest | Autorisation de canal privé et payload minimal testés sans réseau Reverb ; livraison réelle aux abonnés reste ouverte. |
| REC-30 | Aucun endpoint webhook métier identifié dans app/Modules ou routes | Non démontré : qualifier le connecteur/signature/idempotence avant clôture ou décision explicite de périmètre. |
| REC-32 | PlatformRoleManagementTest, PlatformSupportAccessTest | Refus d'élévation tenant et séparation des rôles ; conserver les exigences d'audit par parcours. |
| REC-33 | PlatformSupportAccessTest | Actions explicites, motif, audit, révocation et expiration sans scheduler. |
| REC-34 | Tenancy/TenantTechnicalLimitsTest, ReportExportTest, Tenancy/TenantArtifactServiceTest | Bornes de volume et quotas ; la non-dégradation sous charge reste à prouver par QA-005. |
| REC-35 | Tenancy/OrganizationPortabilityTest, Tenancy/OrganizationMaintenanceTest | Export ciblé et purge autorisée/protégée sur fixtures ; exhaustivité de tous les modules et politique de conservation à qualifier. |
| REC-36 | Tenancy/OrganizationMaintenanceTest | Retour d'archivé à suspendu sans réécriture des données ; ne prouve pas une restauration ni une réconciliation d'archive. OPS-004/005 restent ouverts. |

### Validation et reprise

- Groupe critique : 9 tests réussis, 111 assertions.
- Sélection SQLite de 15 suites : 312 tests réussis, 1 650 assertions,
  rapport `back/reports/phpunit-qa002-sqlite.xml`.
- Les cinq suites modifiées sur un cluster natif PostgreSQL 17 neuf :
  120 tests réussis, 767 assertions, rapport
  `back/reports/phpunit-qa002-postgresql.xml`. Cluster créé par le lanceur
  temporaire existant, port libre, secret propre, puis arrêt et suppression
  confirmés. Aucun serveur/base existant utilisé.
- Formatage Pint et analyse PHPStan sans erreur. Les trois rapports XML ont été
  relus : aucun échec, erreur ou scénario ignoré. Aucun répertoire temporaire
  du lanceur restant au contrôle final. Aucune nouvelle CI distante revendiquée
  pour ces changements.
- Aucun code métier, endpoint, migration, `.env`, permission ou donnée réelle
  modifié. Stockages locaux simulés et bases SQLite en mémoire/PostgreSQL jetable.
  Pas de SMTP, webhook, Redis ou serveur Reverb contacté dans ces vérifications.
- Prochaine étape : revue/push et CI du lot, puis poursuivre l'inventaire
  CRUD et jobs par module. REC-29/30/34/35/36 conservent les limites ci-dessus ;
  QA-002 ne doit pas être fermé depuis la seule réussite du groupe critique.

## Correctif CI QA-002 — Synchronisation du test de commentaires COM-001 (24 octobre 2026)

- Journal utilisateur : `test:e2e`, 53 scénarios annoncés, échec de
  `tests/e2e/communication.spec.ts` sur le texte littéral
  `<script>Réponse</script>` après ajout et réouverture des commentaires.
  Les trois tentatives CI échouent au même endroit. Le journal ne donne ni SHA
  ni URL de run ; aucune conclusion sur les autres jobs à partir de cet extrait.
- Lecture du code : la disparition du formulaire ne constitue pas à elle seule
  une preuve d'enregistrement, car le formulaire est aussi absent pendant le
  chargement des commentaires. Une course de synchronisation est plausible,
  mais l'échec CI n'a pas été reproduit localement : cinq répétitions du scénario
  initial ont réussi. Aucune perte de commentaire ou anomalie backend démontrée.
- Le test attend maintenant le POST de commentaire et vérifie son statut 201
  et le contenu enregistré, puis le GET de rechargement des annonces et son statut
  200 avant réouverture. Le GET des commentaires doit à son tour répondre 200
  et contenir exactement le commentaire attendu avant l'assertion de visibilité.
  Ces attentes sont enregistrées avant les clics correspondants.
- Aucun timeout, délai artificiel ou retry supplémentaire ; les assertions XSS,
  mobile, notifications, préférences et retrait sont conservées. Aucun code de
  l'application, backend, migration ou `.env` modifié.
- Validation locale : dix répétitions du scénario corrigé, un worker, zéro retry,
  toutes réussies. Les dix tests unitaires CommunicationPanel, le lint et le
  typage TypeScript passent. Suite complète : 52 réussites sur 53, un worker,
  zéro retry ; communications réussies dans l'ordre de la CI.
- Le démarrage avec `CI=true` a refusé le port 8000 déjà utilisé ; aucun serveur
  existant arrêté. Les vérifications locales réutilisent les serveurs présents
  et les API entièrement simulées du scénario, avec un worker et sans retries.
  Elles ne reproduisent pas exactement l'environnement Linux/Chromium de GitHub.
  L'échec local restant concerne `match-realtime.spec.ts` attendant « Direct
  connecté. » : il intercepte seulement `/app/test-key`, alors que le module Echo
  servi par le Vite réutilisé ne contient pas cette configuration publique de test.
  La configuration du serveur existant est conservée, sans modification de ce test
  distinct. La suite complète n'est donc pas déclarée verte sur ce poste.
  Nouvelle CI distante requise après revue/push pour confirmer la résolution.
  Correctif limité au test de communications et au suivi, réuni dans un commit
  UCG-QA-002 dédié ; aucun push effectué à cette étape.

## UCG-QA-002 — Deuxième lot Personnes/Équipes (24 octobre 2026)

Objectif : compléter les preuves HTTP de cloisonnement et de révocation, y compris
pour un acteur autorisé dans deux organisations. Le périmètre de ce lot est la
fiche personne, son départ, l'identité de l'équipe et les rattachements d'effectif.
Les neuf nouveaux cas sont ajoutés aux tests existants, sans les remplacer.

- Personne étrangère : même code/message que pour un UUID inexistant, aucun UUID
  étranger révélé. Modification et départ refusés même avec les droits métier
  requis dans le tenant de la route ; aucune fiche, version, sortie ou audit métier
  modifié. Le parcours de départ utilise sa route réelle, son motif et son consentement.
- Équipe étrangère : l'archivage est désormais testé en plus de la lecture et de
  la modification déjà couvertes ; nom, statut, version et nombre de fiches préservés.
  Aucune route DELETE de personne/équipe n'est inventée pour cet inventaire.
- Après lecture initialement autorisée, révocation soit de l'adhésion, soit de
  l'affectation de rôle Alpha : listes et détails ne renvoient plus de données,
  créations/modifications et départ de personne ou archivage d'équipe refusés.
  Réponses 404 pour l'adhésion révoquée, 403 pour le rôle retiré. Les versions et
  compteurs restent inchangés et aucun audit de mutation n'est créé. Le même
  acteur peut encore lire et modifier sa fiche Beta autorisée, avec audit Beta seul.
- Retrait d'une permission sans retrait du rôle : `sports.write` retiré laisse
  la lecture et une modification administrative autorisées, mais interdit la
  création et la modification sportives. `teams.manage` retiré laisse les lectures
  d'équipe, mais interdit création, modification et archivage. Aucun droit implicite
  n'est accordé par les permissions administratives restantes.
- Effectifs : acteur gestionnaire dans Alpha et Beta, lectures positives des deux
  effectifs avant essais. Personne Beta dans un rattachement Alpha, équipe Beta
  dans une URL Alpha, période Beta sous l'équipe Alpha et transfert vers Beta sont
  refusés. Source/destination, versions, états actifs et fins nulles préservés ;
  aucun mouvement, rattachement supplémentaire ou audit de mutation créé.
- Effectifs après révocation d'adhésion/rôle : liste, historique, ajout et clôture
  refusés après un premier accès réussi ; période existante intacte et lecture
  Beta encore autorisée.

### Validation

- Groupe critique complet `tenant-isolation` : 19 tests, 270 assertions.
  Le lot ajoute 9 cas et groupe aussi le scénario Personne étrangère déjà existant ;
  le scénario Équipe étrangère appartenait déjà au groupe du premier lot.
- Neuf suites : PersonControllerTest, PersonServiceTest, PersonPolicyTest,
  PersonLifecycleControllerTest, TeamControllerTest, TeamServiceTest,
  TeamMembershipControllerTest, TeamMembershipServiceTest, TeamLineupControllerTest.
  SQLite en mémoire : 180 tests, 1 094 assertions ; PostgreSQL 17 neuf : mêmes
  compteurs. XML relus : aucun échec, erreur ou test ignoré.
- Rapports : `back/reports/phpunit-qa002-lot2-critical.xml`,
  `back/reports/phpunit-qa002-lot2-sqlite.xml`,
  `back/reports/phpunit-qa002-lot2-postgresql.xml`.
- Pint et PHPStan sans erreur. Présence des neuf suites vérifiée dans la sélection
  PostgreSQL du workflow backend ; les cas sont également inclus dans Feature.
  Aucun changement de workflow ni dépendance requis.
- PostgreSQL créé via le lanceur temporaire existant : port libre et secret propres,
  stockage de test séparé, arrêt et suppression confirmés, aucun répertoire de
  lanceur restant au contrôle final. Aucune base existante, donnée réelle,
  permission réelle, migration, `.env`, frontend ou code métier modifié.

### Limites et reprise

La CI validée par l'utilisateur concerne `337f67a`, pas ce nouveau lot. Nouvelle
CI à obtenir après revue/commit/push. Les tests de révocation utilisent une session
authentifiée et les routes tenant explicites ; ils ne rejouent pas une bascule
de contexte dans le navigateur, déjà traitée séparément par QA-003.

Ces preuves complètent REC-23/25 et les contrôles de mutation du périmètre choisi,
sans fermer tous les CRUD de People/Teams. Prochaine extension : profils de jeu
et comptes externes, périodes/éléments de départ et compositions d'équipe, en
partant de leurs tests négatifs existants plutôt que les dupliquer. Les jobs des
autres modules et les limites REC-29/30/34/35/36 restent ouvertes. Livraison
réunie dans le commit UCG-QA-002 dédié au deuxième lot ; aucun push effectué.

## UCG-QA-002 — Troisième lot de ressources rattachées (24 octobre 2026)

### Objectif et couverture

Compléter les tests négatifs existants des profils de jeu/comptes externes,
périodes/éléments de départ et compositions d'équipe. Douze cas ajoutés dans
trois suites de contrôleurs ; scénarios étrangers profils/cycle de vie renforcés,
et trois scénarios préexistants ajoutés au groupe `tenant-isolation`.

- Identifiants étrangers : acteur autorisé dans les deux organisations, mais
  lectures et mutations sous le mauvais tenant refusées en 404. Les périodes et
  la checklist Beta restent lisibles sous leur URL légitime. Une composition
  Beta reste lisible dans Beta, sans pouvoir être annulée depuis une équipe Alpha.
- Parent du même tenant : profil sous une autre personne, compte sous un autre
  profil/personne, période et checklist sous une autre personne, composition sous
  une autre équipe refusés. Les listes du parent local restent isolées.
- Révocation après lecture réussie : retrait d'adhésion (404) ou d'attribution
  de rôle (403), immédiatement appliqué aux lectures, créations et modifications.
  Le départ d'une personne et l'annulation/finalisation de compositions sont
  également refusés ; les réponses de lecture ne contiennent pas de données.
- Retrait des seules permissions d'écriture : lectures encore autorisées,
  créations/modifications refusées. Versions, états, rattachements et nombres de
  lignes conservés ; aucun audit de mutation supplémentaire. Pour les compositions,
  le brouillon et son entrée créés avant la révocation restent intacts, avec
  exactement l'audit initial.

### Validation

- Groupe critique complet : **34 tests, 489 assertions** sur SQLite en mémoire.
- Onze suites : GameProfileControllerTest, GameProfileServiceTest,
  GameProfileSchemaTest, PersonLifecycleControllerTest, PersonDepartureServiceTest,
  PersonLifecyclePolicyTest, PersonLifecycleSchemaTest, TeamLineupControllerTest,
  TeamLineupServiceTest, TeamLineupPolicyTest, TeamLineupSchemaTest.
  **220 tests, 1 160 assertions sur chaque moteur**, SQLite en mémoire puis
  PostgreSQL 17 neuf. Rapports XML relus : zéro échec, erreur ou test ignoré.
- Rapports : `back/reports/phpunit-qa002-lot3-critical.xml`,
  `back/reports/phpunit-qa002-lot3-sqlite.xml`,
  `back/reports/phpunit-qa002-lot3-postgresql.xml`.
- Pint et PHPStan sans erreur. Les onze suites sont déjà sélectionnées par le
  workflow PostgreSQL backend et les cas appartiennent à Feature ; aucun changement
  de workflow ni dépendance requis.
- Cluster PostgreSQL lancé par le mécanisme temporaire existant, avec port libre,
  secret propre et stockage de test séparé ; arrêt et suppression confirmés.
  Aucune base existante, donnée réelle, migration, `.env`, frontend ou logique
  métier modifiée. Changements limités aux tests et à la documentation existante.

### Limites et reprise

La CI validée par l'utilisateur concerne `c3bbe85` ; ce troisième lot attend
revue/commit/push puis validation CI. Les révocations sont exercées sur des routes
tenant explicites avec une session authentifiée ; la bascule de contexte dans le
navigateur reste couverte séparément par QA-003. QA-002 reste ouvert : les autres
CRUD/jobs/modules et les limites REC-29/30/34/35/36 ne sont pas déclarés terminés.
La prochaine extension doit partir de l'inventaire REC-23–36 pour sélectionner
les prochains contrôles manquants, sans ajouter de doublons aux suites existantes.

## UCG-QA-002 — Quatrième lot imports de personnes (24 octobre 2026)

### Objectif et couverture

Compléter REC-28 pour les imports livrés, ainsi que leurs frontières HTTP et de
stockage. Douze cas ajoutés dans PersonImportControllerTest et
PersonImportProcessorTest ; scénario HTTP étranger existant renforcé et ajouté
au groupe critique. Le test direct de révocation de rôle préexistant est conservé.

- Confirmation via HTTP, capture du véritable ProcessPersonImport, sérialisation,
  bascule HTTP Alpha vers Beta puis exécution et rejeu via Bus::dispatchSync et
  les middlewares tenant réels. Les deux personnes sont créées dans Alpha une
  seule fois, sans admission de compte. Un pseudonyme identique déjà présent dans
  Beta ne bloque pas Alpha ; la fiche Beta et sa version restent intactes.
- Révocation Alpha d'adhésion, d'attribution de rôle ou de la seule permission
  administrative.write, avant traitement ou après un premier bloc validé. Le job
  sérialisé puis rejoué échoue avec les métadonnées opérationnelles attendues :
  aucune nouvelle personne, lignes créées/en attente inchangées, aucun audit
  métier supplémentaire. Les droits complets conservés dans Beta ne secourent pas
  Alpha ; session Beta préservée et portée d'exécution nettoyée.
- Révocation après lecture réussie : liste, détail, rapport, téléversement,
  prévisualisation et confirmation refusés ; aucun job ajouté. Versions, lignes,
  fichiers chiffrés (empreintes relues) et audits métier préservés. Beta reste
  consultable. Les tests sans bascule explicite vérifient les statuts 404/403 ;
  après bascule, le garde de contexte peut répondre 409 avant la politique métier.
- Même CSV et même nom téléversés dans deux tenants : imports, empreintes
  d'idempotence et artefacts distincts ; lecture légitime vérifiée et lecture de
  l'artefact étranger refusée via le port interne TenantArtifacts. Listes isolées,
  pas de création de personne ni de traitement implicite.
- Transport valide pour Beta avec un identifiant d'import Alpha : refus sans
  changement du run Alpha, de ses lignes, des personnes ou audits, y compris
  lorsque le gestionnaire dispose des permissions d'import dans les deux tenants.

### Validation

- Groupe critique complet sur SQLite en mémoire : **47 tests, 804 assertions**.
- Cinq suites : PersonImportControllerTest, PersonImportProcessorTest,
  PersonImportPolicyTest, PersonImportSchemaTest, PersonCsvParserTest.
  **69 tests, 488 assertions par moteur**, SQLite en mémoire et PostgreSQL 17 neuf.
  Rapports XML relus : zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot4-critical.xml`,
  `back/reports/phpunit-qa002-lot4-sqlite.xml`,
  `back/reports/phpunit-qa002-lot4-postgresql.xml`.
- Pint/PHPStan verts ; les cinq suites figurent déjà dans la sélection PostgreSQL
  du workflow backend et dans Feature. Aucun changement de workflow ou dépendance.
- Cluster temporaire arrêté et supprimé ; aucune base existante, donnée réelle,
  migration, `.env`, frontend ou logique métier modifiée. Stockage simulé local
  pour les artefacts ; aucune file ni aucun worker de production sollicité.

### Limites et reprise

La CI validée par l'utilisateur concerne `2c9d3bc`. Ce quatrième lot est livré
dans un commit dédié, sans push ; sa CI reste à confirmer après livraison distante.
Le transport capturé par HTTP passe par la file synchrone et
les middlewares réels, sans démontrer le fonctionnement d'un worker Redis en
exploitation. Dans les cas entre blocs, le premier bloc est validé par le service
réel avec chunk_size=1 ; le job sérialisé est ensuite exécuté après révocation.
La reprise transactionnelle après erreur technique reste prouvée par le test
préexistant du processeur. QA-002 reste ouvert pour les autres CRUD/jobs et les
limites REC-29/30/34/35/36 ; ce lot ne les clôture pas.

## UCG-QA-002 — Cinquième lot documents contractuels (24 octobre 2026)

### Objectif et couverture

Compléter REC-27/28 pour les analyses en file et les téléchargements privés des
documents contractuels. Neuf cas ajoutés dans ContractDocumentSecurityControllerTest
et ContractDocumentScanTest ; un scénario HTTP étranger préexistant ajouté au
groupe critique. Les tests existants de révocation pendant l'analyse ou la lecture
du stockage restent conservés.

- Téléversement HTTP d'une version, capture du véritable ScanContractDocument,
  sérialisation puis bascule HTTP Alpha vers Beta. Exécution et rejeu via
  Bus::dispatchSync et les middlewares tenant réels : scanner simulé appelé une
  seule fois avec les octets attendus, verdict clean conservé au rejeu, aucune
  analyse créée dans Beta. Version du document, version du contrat, fichier
  chiffré et nombre d'audits préservés au rejeu. Retour dans Alpha et téléchargement
  des octets originaux vérifiés.
- Révocation Alpha d'adhésion, de rôle ou de la permission administrative.write
  avant exécution : scanner et lecture d'artefact jamais appelés. Le callback de
  file marque uniquement l'analyse failed/worker_failed, sans démarrage ni moteur ;
  le rejeu conserve ces métadonnées. Versions, fichier et audits métier restent
  intacts, les permissions Beta ne secourent pas Alpha. Session Beta préservée et
  portée d'exécution nettoyée après traitement.
- Lien signé émis et déjà utilisé avant révocation d'adhésion, de rôle ou de
  lecture : le même lien, les listes de versions/analyses, l'émission d'un nouveau
  lien et la relance d'analyse sont refusés en 404/403. Aucun octet privé servi,
  aucune lecture d'artefact ni nouvelle analyse ; fichier et verdict existants
  conservés. Le contexte Beta reste accessible au même acteur.
- Retrait de la seule permission d'écriture : ancien lien et lectures encore
  valides, nouveau lien autorisé, mais relance d'analyse et téléversement interdits.
  Version du contrat, verdict, nombre de versions/artefacts et audits préservés.
- Transport valide pour Beta portant un identifiant d'analyse Alpha : refus avant
  appel du scanner ou lecture du fichier, sans altérer l'analyse Alpha, son fichier
  ou les audits, même pour un gestionnaire commun aux deux tenants.

### Validation

- Groupe critique complet sur SQLite en mémoire : **57 tests, 1 024 assertions**.
- Six suites : ContractDocumentControllerTest, ContractDocumentServiceTest,
  ContractDocumentSchemaTest, ContractDocumentSecurityControllerTest,
  ContractDocumentScanTest, ContractDocumentScanSchemaTest.
  **89 tests, 618 assertions par moteur**, SQLite en mémoire et PostgreSQL 17 neuf.
  Rapports XML relus : zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot5-critical.xml`,
  `back/reports/phpunit-qa002-lot5-sqlite.xml`,
  `back/reports/phpunit-qa002-lot5-postgresql.xml`.
- Pint/PHPStan verts. Les six suites figurent déjà dans la sélection PostgreSQL
  du workflow backend et dans Feature ; aucun changement de workflow ou dépendance.
- Deux commandes SQLite lancées simultanément ont initialement interféré sur le
  même Storage::fake. Relance séquentielle des suites puis du groupe critique :
  tout passe. Ne pas lancer ces commandes en parallèle avec le même storage_path.
  PostgreSQL utilise un stockage temporaire distinct, arrêté/supprimé avec son
  cluster ; aucun répertoire du lanceur restant au contrôle final.

### Limites et reprise

La CI validée par l'utilisateur concerne `14117bd` ; ce cinquième lot est livré
dans un commit dédié, sans push. Sa CI reste à confirmer après livraison distante.
Scanner simulé, stockage local simulé et transport via
la file synchrone : ces tests ne prouvent pas un scan ClamAV réel ni l'exploitation
d'un worker Redis. Aucune base existante, donnée réelle, migration, `.env`, frontend
ou logique métier modifiée. QA-002 reste ouvert pour les autres CRUD/jobs et les
limites REC-29/30/34/35/36.

## UCG-QA-002 — Sixième lot rappels contractuels (24 octobre 2026)

### Objectif et couverture

Compléter REC-28 sur les rappels contractuels et leurs notifications privées.
Sept cas ajoutés dans ContractReminderTest ; scénario étranger préexistant
renforcé par les droits explicites dans les deux tenants et ajouté au groupe
critique. Les tests existants de révocation/récupération, MFA, réattribution,
ambiguïté SMTP et contenu générique des emails sont conservés.

- Même compte responsable dans Alpha et Beta : deux RunTenantScheduledTask
  sérialisés avec le même identifiant d'exécution, puis traitement Alpha/Beta
  via Bus::dispatchSync et les middlewares réels. Chacun produit son propre rappel,
  sa notification en application et sa clé d'idempotence email. Rejeu puis nouveau
  passage après seize minutes : aucun envoi ni ligne supplémentaire, états,
  tentatives et versions de livraison identiques. Session HTTP Beta préservée,
  portée d'exécution nettoyée ; boîtes de réception et historiques ne montrent
  que le rappel du tenant demandé.
- Emails initialement en attente : révocation Alpha d'adhésion, de rôle,
  d'administrative.read ou de financial.read avant l'envoi. Le passage Alpha
  sérialisé/rejoué bloque l'email sans tentative d'envoi, même si le destinataire
  garde ses permissions complètes dans Beta. La livraison Beta reste d'abord
  intacte puis est envoyée une seule fois lors de son propre passage.
  Paramètres et historique Alpha refusés en 404/403 ; boîte Alpha refusée, ou vide
  pour le seul retrait financier. Lectures Beta légitimes maintenues.
- Résolution d'une livraison Beta sous un contrat Alpha, ou d'une livraison Alpha
  sous un autre contrat du même tenant : 404 sans modification des livraisons ni
  audit de mutation. Les historiques restent isolés ; résolution Beta sous son
  URL légitime autorisée, sans changer l'incertitude Alpha ni réémettre d'email.
- Retrait d'administrative.write : lectures encore permises et email encore
  éligible avec le droit de lecture conservé ; configuration et résolution
  interdites. Versions du contrat/plan, audits et nombre de rappels préservés.

### Validation

- Groupe critique complet sur SQLite en mémoire : **65 tests, 1 173 assertions**.
- Quatre suites : ContractReminderTest, ContractReminderMailTest,
  ContractFinancialGovernanceTest, Modules/Tenancy/TenantScheduledTaskTest.
  **81 tests, 538 assertions par moteur**, SQLite en mémoire et PostgreSQL 17 neuf.
  Rapports XML relus : zéro échec, erreur ou exclusion. La suite modifiée a aussi
  été relancée après normalisation du type de la capture de livraisons :
  34 tests, 273 assertions, sans échec.
- Rapports : `back/reports/phpunit-qa002-lot6-critical.xml`,
  `back/reports/phpunit-qa002-lot6-sqlite.xml`,
  `back/reports/phpunit-qa002-lot6-postgresql.xml`.
- Pint/PHPStan verts. Les quatre suites sont déjà incluses dans la sélection
  PostgreSQL du workflow backend, directement ou via Modules/Tenancy, et dans
  Feature. Aucun changement de workflow ou dépendance.
- Commandes SQLite exécutées successivement. PostgreSQL utilise son stockage
  temporaire distinct ; arrêt et suppression confirmés, aucun répertoire du
  lanceur restant au contrôle final. Aucune base existante, donnée réelle,
  migration, `.env`, frontend ou logique métier modifiée.

### Limites et reprise

La CI validée par l'utilisateur concerne `11d6117` ; ce sixième lot est livré
dans un commit dédié, sans push. Sa CI reste à confirmer après livraison distante.
Les tâches planifiées portent un contexte système sans
acteur ; les révocations testées concernent l'éligibilité actuelle du destinataire.
Transport sérialisé exécuté via la file synchrone et emails capturés par le faux
mailer existant : aucun SMTP réel ni worker Redis d'exploitation validé ici.
QA-002 reste ouvert pour les autres CRUD/jobs et les limites REC-29/30/34/35/36.

## UCG-QA-002 — Septième lot inscriptions aux compétitions (24 octobre 2026)

### Objectif et couverture

Compléter REC-23/28 pour les dossiers d'inscription, leurs rappels privés et les
références aux documents de compétition. Dix cas ajoutés dans
CompetitionRegistrationControllerTest et CompetitionRegistrationReminderTest ;
un scénario HTTP étranger préexistant ajouté au groupe critique. Aucun test
existant supprimé, aucune fonctionnalité de téléversement binaire introduite.

- Même compte responsable dans Alpha et Beta : deux RunTenantScheduledTask
  sérialisés avec un identifiant d'exécution commun, exécution via Bus::dispatchSync
  et middlewares réels après sélection HTTP de Beta. Génération limitée au tenant
  traité, deux occurrences distinctes rattachées aux bonnes révisions. Rejeu et
  nouvelles exécutions sans ligne supplémentaire ni changement des rappels
  existants. Session Beta préservée, portée d'exécution nettoyée ; consultation
  des rappels de chaque tenant limitée à son propre résultat.
- Révocation Alpha d'adhésion, de rôle ou de sports.registrations.read après
  sérialisation : aucun rappel Alpha généré. Les permissions Beta du même compte
  restent utilisables et permettent exactement un rappel Beta. Le rejeu n'ajoute
  pas de doublon et les deux révisions d'inscription sont conservées.
- Après accès HTTP réussi aux dossiers, historiques, choix de personnes et
  rappels : mêmes révocations appliquées aux lectures et à l'écriture, statuts
  404/403, aucune donnée privée dans la réponse. Révision chiffrée, rappel existant
  et nombre d'audits préservés ; contexte Beta encore accessible.
- Retrait de sports.registrations.write : dossier, historique et rappels déjà
  générés restent consultables, modification et choix de personnes interdits.
  Le passage à l'étape d'échéance suivante ne génère pas de nouveau rappel sans
  le droit d'écriture requis par ce module ; contenu de la révision et audits
  intacts.
- Version documentaire d'une autre compétition du même tenant ou d'un autre
  tenant : lecture sous son URL légitime vérifiée, lien depuis le dossier Alpha
  et historique/téléchargement sous le mauvais parent refusés en 404. Aucun ajout
  de révision ni audit de mutation, document d'origine toujours accessible après
  refus. Acteur explicitement autorisé dans les deux tenants pour le cas étranger.

### Validation

- Groupe critique complet sur SQLite en mémoire : **76 tests, 1 395 assertions**.
- Six suites : CompetitionRegistrationControllerTest,
  CompetitionRegistrationReminderTest, CompetitionRegistrationPolicyTest,
  CompetitionRegistrationSchemaTest, ExternalCompetitionDocumentTest,
  Modules/Tenancy/TenantScheduledTaskTest.
  **66 tests, 531 assertions par moteur**, SQLite en mémoire et PostgreSQL 17 neuf.
  Rapports XML relus : zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot7-critical.xml`,
  `back/reports/phpunit-qa002-lot7-sqlite.xml`,
  `back/reports/phpunit-qa002-lot7-postgresql.xml`.
- Pint/PHPStan verts. Les six suites sont déjà incluses dans la sélection
  PostgreSQL du workflow backend, directement ou via Modules/Tenancy, et dans
  Feature. Aucun changement de workflow ou dépendance.
- Commandes SQLite exécutées successivement. PostgreSQL avec stockage temporaire
  distinct, arrêté et supprimé ; aucun répertoire du lanceur restant au contrôle
  final. Aucune base existante, donnée réelle, migration, `.env`, frontend ou
  logique métier modifiée.

### Limites et reprise

La CI validée par l'utilisateur concerne `9b55cbf` ; ce septième lot est livré
dans un commit dédié, sans push. Sa CI reste à confirmer après livraison distante.
Les tâches planifiées portent un contexte système sans
acteur ; les révocations concernent l'éligibilité du destinataire. Transport via
la file synchrone : aucun worker Redis d'exploitation validé. Les documents liés
sont les versions de texte privées déjà livrées ; aucun fichier binaire ni service
externe ajouté ou sollicité. QA-002 reste ouvert pour les autres CRUD/jobs et les
limites REC-29/30/34/35/36.

## UCG-QA-002 — Huitième lot imports statistiques (24 octobre 2026)

### Objectif et couverture

Compléter REC-23/28 pour les imports statistiques livrés. Neuf cas ajoutés dans
StatisticalImportProcessingTest ; scénario HTTP multi-acteur/tenant préexistant
ajouté au groupe critique. Les tests existants de références étrangères, reprise
transactionnelle, déduplication et générations sont conservés.

- Deux imports Alpha/Beta du même acteur, confirmés par HTTP : capture et
  sérialisation des véritables ProcessStatisticalImport puis bascule HTTP dans
  Beta. Exécution et rejeu via Bus::dispatchSync et les middlewares tenant réels :
  les deux observations Alpha gardent leurs valeurs zéro, révisions et reçus,
  sans modifier l'exécution Beta en attente. Son propre job crée ensuite uniquement
  l'observation Beta. Fichiers chiffrés inchangés, session Beta préservée et portée
  d'exécution nettoyée. Liste Beta limitée à son import ; résultat Alpha et reprise
  sous une URL Beta refusés, résultat Alpha lisible après retour dans son contexte.
- Révocation Alpha d'adhésion, de rôle ou de sports.statistics.import, avant
  exécution ou après dix lignes validées sur douze. Job sérialisé puis rejoué :
  exécution marquée failed/PROCESSING_INTERRUPTED, compteurs et résultats déjà
  validés préservés, aucune observation/révision/reçu supplémentaire ni audit de
  mutation. Liste, résultats et reprise Alpha refusés en 404/403. Droits Beta
  conservés : son import termine normalement, sans modifier les écritures Alpha.
- Reprise HTTP d'un import après son premier bloc : nouvelle génération capturée
  et sérialisée. L'ancien job et son callback d'échec ne modifient ni la génération
  reprise ni les dix lignes validées. La nouvelle génération termine les deux
  lignes restantes ; vérification des enregistrements initiaux et rejeu des deux
  générations sans doublon ni écrasement, douze observations/révisions/reçus.
- Transport valide pour Beta portant l'identifiant d'import Alpha : refus avant
  création d'observation ou de reçu, exécution Alpha conservée, portée nettoyée,
  même avec les permissions complètes du même acteur dans les deux tenants.

### Validation

- Groupe critique complet sur SQLite en mémoire : **86 tests, 1 695 assertions**.
- Quatre suites : StatisticalImportProcessingTest, StatisticalImportTest,
  ObservationTest, Modules/Tenancy/TenantJobRunnerTest.
  **104 tests, 1 082 assertions par moteur**, SQLite en mémoire et PostgreSQL 17 neuf.
  Rapports XML relus : zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot8-critical.xml`,
  `back/reports/phpunit-qa002-lot8-sqlite.xml`,
  `back/reports/phpunit-qa002-lot8-postgresql.xml`.
- Pint/PHPStan verts. Les quatre suites sont déjà incluses dans la sélection
  PostgreSQL du workflow backend, directement ou via Modules/Tenancy, et dans
  Feature. Aucun changement de workflow ou dépendance.
- Commandes SQLite exécutées successivement. PostgreSQL avec stockage temporaire
  distinct, arrêté et supprimé ; aucun répertoire du lanceur restant au contrôle
  final. Aucune base existante, donnée réelle, migration, `.env`, frontend ou
  logique métier modifiée.

### Limites et reprise

La CI validée par l'utilisateur concerne `53d3c4e` ; celle de ce huitième lot
reste à confirmer après livraison distante. Lot livré dans un commit dédié,
sans push, conformément à la demande. Le premier bloc des scénarios entre blocs
est exécuté par le service réel ; le job sérialisé est ensuite exécuté après
révocation ou reprise. Stockage local simulé et transport via la file synchrone :
aucun worker Redis d'exploitation validé ici. QA-002 reste ouvert pour les autres
CRUD/jobs et les limites REC-29/30/34/35/36.

## UCG-QA-002 — Neuvième lot observations statistiques (24 octobre 2026)

### Objectif et couverture

Compléter les frontières HTTP des observations, corrections et historiques, ainsi
que leur dépendance à l'audience des matchs. Huit cas ajoutés dans ObservationTest ;
scénario étranger préexistant ajouté au groupe critique. Les tests existants de
provenance d'import, droits distincts, concurrence et versions restent conservés.

- Acteur explicitement autorisé à créer/corriger dans Alpha et Beta, avec une
  observation lisible dans chaque tenant. Dans les deux sens, URL de détail,
  historique, version ou correction portant l'identifiant étranger refusée en
  404, comme la création utilisant une personne de l'autre tenant. Captures des
  observations/révisions et nombre d'audits identiques après refus.
- Révocation Alpha d'adhésion, de rôle ou de sports.statistics.read après lectures
  réussies : liste, détail, historique, version et choix de personnes refusés en
  404/403, ainsi que création/correction. Aucune donnée privée dans les réponses,
  aucune écriture ni audit de mutation. Observation Beta encore lisible et
  correction autorisée vers 12.125 ; valeur zéro et historique Alpha préservés.
- Retrait de sports.statistics.write : lectures conservées, création et choix
  de personnes refusés, correction encore autorisée. Une seule révision/audit
  supplémentaire pour la correction légitime ; version initiale zéro lisible.
- Retrait de sports.statistics.correct : correction interdite sans écriture,
  création d'une autre observation autorisée ; observation/version initiales
  intactes et lisibles. Les deux permissions ne s'accordent pas mutuellement.
- Identifiant de version d'une autre observation du même tenant : consultation
  sous le mauvais parent refusée en 404, utilisation pour corriger l'autre
  observation refusée en 409/OBSERVATION_STALE. Aucune révision ni audit ajouté.
- Match initialement partagé avec l'organisation puis restreint à privé par un
  changement contrôlé de fixture serveur : observation et choix de match retirés
  des listes, détail/historique/version et correction refusés en 404, comme une
  nouvelle observation sur ce match. Les observations/révisions restent intactes ;
  le créateur du match garde l'accès légitime aux valeurs et versions existantes.

### Validation

- Groupe critique complet sur SQLite en mémoire : **95 tests, 1 921 assertions**.
- Quatre suites : ObservationTest, StatisticalCalculationTest,
  StatisticalImportProcessingTest, MatchControllerTest.
  **112 tests, 1 459 assertions par moteur**, SQLite en mémoire et PostgreSQL 17 neuf.
  Rapports XML relus : zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot9-critical.xml`,
  `back/reports/phpunit-qa002-lot9-sqlite.xml`,
  `back/reports/phpunit-qa002-lot9-postgresql.xml`.
- Pint/PHPStan verts. Les quatre suites sont déjà incluses dans la sélection
  PostgreSQL du workflow backend et dans Feature ; aucun changement de workflow
  ou dépendance requis.
- Commandes SQLite successives, PostgreSQL avec stockage temporaire distinct.
  Cluster arrêté/supprimé, aucun répertoire du lanceur restant au contrôle final.
  Aucune base existante, donnée réelle, migration, `.env`, frontend ou logique
  métier modifiée.

### Limites et reprise

La CI de `a581415` a été annoncée en lancement, sans validation rapportée. Ce
neuvième lot est livré dans un commit dédié, sans push ; sa propre CI reste à
obtenir après livraison distante. Le changement d'audience
est préparé dans la fixture pour tester sa prise en compte par les routes
d'observation ; il ne rejoue pas le partage dans le navigateur. La concurrence
réelle d'ObservationConcurrencyTest n'est pas réexécutée par ces quatre suites.
QA-002 reste ouvert pour les autres CRUD/jobs et les limites REC-29/30/34/35/36.

## UCG-QA-002 — Dixième lot séances Académie et présences (24 octobre 2026)

### Objectif et couverture

Renforcer les frontières HTTP des séances et présences sans modifier le code
métier. Neuf cas ajoutés dans AcademySessionTest et un scénario étranger
préexistant intégré au groupe critique. Les assertions de versions et d'historique
existantes restent conservées.

- Un gestionnaire autorisé dans Alpha et Beta peut consulter les deux tenants,
  mais les identifiants de séance, activité, inscription et présence étrangers
  sont refusés sous Alpha. Les séances, présences, révisions et audits restent
  identiques après refus.
- Dans le même tenant, les séances restent liées à leur cycle/cohorte et les
  présences à leur séance ; une inscription d'un autre cycle est refusée.
- Après lectures réussies, révocation de l'adhésion, du rôle ou de academy.read :
  lectures et mutations Alpha refusées en 404/403, sans données privées ni
  écritures partielles. Les lectures et corrections Beta autorisées fonctionnent
  encore ; les données Alpha restent intactes.
- Retrait de academy.manage : séances non modifiables, lectures et correction
  des présences autorisées conservées. Retrait de academy.attendance.manage :
  présences non modifiables, correction du contenu de séance encore autorisée.
- Retrait de calendar.read ou activité devenue privée : références et horaires
  du calendrier masqués, choix retirés/refusés et mutations liées refusées.
  Le compte rendu et les présences restent lisibles avec academy.read ; le titre
  privé de l'activité n'est pas divulgué. Aucun audit de mutation ni révision
  supplémentaire après les refus.

### Validation

- Groupe critique complet sur SQLite en mémoire : **105 tests, 2 131 assertions**.
- Quatre suites : AcademySessionTest, AcademyScheduleTest, AcademyTest et
  CalendarControllerTest. **77 tests, 607 assertions par moteur**, SQLite en
  mémoire et PostgreSQL 17 neuf. Rapports XML relus : zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot10-critical.xml`,
  `back/reports/phpunit-qa002-lot10-sqlite.xml`,
  `back/reports/phpunit-qa002-lot10-postgresql.xml`.
- Pint/PHPStan verts. Ces suites sont déjà sélectionnées dans le workflow
  backend PostgreSQL et dans Feature ; aucune dépendance ni workflow modifié.
- Commandes SQLite successives, stockage PostgreSQL temporaire distinct.
  Cluster arrêté et supprimé ; aucune base existante, donnée réelle, migration,
  configuration d'environnement ou logique métier modifiée.

### Limites et reprise

La CI de `5172f83` est validée par l'utilisateur, sans nouvelle lecture distante.
Ce dixième lot est réuni dans un commit dédié demandé par l'utilisateur, sans
push ; sa CI reste à obtenir après livraison distante. Le passage du calendrier à privé est préparé dans une
fixture serveur : le parcours navigateur de partage n'est pas rejoué ici.
AcademyConcurrencyTest n'est pas réexécuté par ces quatre suites. QA-002 reste
ouvert pour les autres CRUD/jobs et les limites REC-29/30/34/35/36.

## UCG-QA-002 — Onzième lot suivi pédagogique Académie (24 octobre 2026)

### Objectif et couverture

Renforcer les accès HTTP aux objectifs, retours partagés et notes internes après
consultation ou révocation. Onze cas ajoutés dans AcademyCoachingVisibilityTest,
avec le scénario étranger existant intégré au groupe critique. Aucun test existant
supprimé ; aucun changement métier nécessaire.

- Gestionnaire autorisé dans Alpha et Beta : lectures Beta réussies, mais objectifs,
  retours, notes et mentor Beta refusés sous Alpha en 404. État des objectifs,
  retours, révisions et nombre d'audits inchangés après les tentatives.
- Dans le même tenant, objectif sous un autre cycle, retour/note sous un autre
  objectif et identifiant interne utilisé sur le chemin partagé (ou inversement)
  refusés en 404, sans mutation.
- Après lectures réussies des objectifs, retours, notes et historique, révocation
  de l'adhésion, du rôle ou de academy.read : lectures et créations/corrections
  Alpha refusées en 404/403. Les notes Beta restent lisibles et un retour Beta
  reste corrigeable ; les enregistrements et révisions Alpha restent intacts.
- Retrait de academy.notes.manage : notes déjà consultées devenues inaccessibles,
  leurs identifiants et leur nombre retirés de l'historique, écriture refusée ;
  correction du retour partagé toujours autorisée. Retrait de
  academy.feedback.manage : lecture partagée conservée, création/correction
  partagée refusée, correction interne encore autorisée. Les refus n'ajoutent
  aucune révision ni audit de mutation.
- Élève lié à une personne dans deux tenants : objectifs et retours initialement
  lisibles, objectif Beta refusé sous Alpha. Retrait d'inscription ou personne
  devenue ancienne : liste personnelle Alpha vide et ancien lien en 404.
  Retrait de academy.self.read ou d'adhésion : anciens liens Alpha refusés en
  403/404. Accès Beta conservé sans note interne ni version de gestion exposée ;
  objectifs, retours, révisions et audits inchangés.

### Validation

- Groupe critique complet sur SQLite en mémoire : **117 tests, 2 421 assertions**.
- Quatre suites : AcademyCoachingVisibilityTest, AcademyCoachingTest,
  AcademyCoachingSchemaTest et AcademySessionTest. **69 tests, 777 assertions
  par moteur**, SQLite en mémoire et PostgreSQL 17 neuf. Rapports XML relus :
  zéro échec, erreur ou exclusion.
- Rapports : `back/reports/phpunit-qa002-lot11-critical.xml`,
  `back/reports/phpunit-qa002-lot11-sqlite.xml`,
  `back/reports/phpunit-qa002-lot11-postgresql.xml`.
- Pint/PHPStan verts. Les quatre suites sont déjà sélectionnées dans le workflow
  backend PostgreSQL et dans Feature ; aucun changement de dépendance ou workflow.
- Commandes SQLite successives et stockage PostgreSQL temporaire distinct.
  Cluster arrêté et supprimé ; aucun répertoire du lanceur restant au contrôle
  final. Aucune base existante, donnée réelle, migration, configuration
  d'environnement, logique métier ou interface modifiée.

### Limites et reprise

La CI de `22ac7d5` est validée par l'utilisateur, sans nouvelle lecture distante.
Ce onzième lot est réuni dans un commit dédié demandé par l'utilisateur, sans
push ; sa CI reste à obtenir après livraison distante. Les révocations sont préparées dans les fixtures serveur,
sans rejouer leur parcours navigateur. AcademyConcurrencyTest n'est pas réexécuté
par ces quatre suites. QA-002 reste ouvert pour les autres CRUD/jobs et les
limites REC-29/30/34/35/36.

## UCG-QA-002 — Consolidation du périmètre local restant (24–25 octobre 2026)

### Mandat et périmètre

L'utilisateur demande de regrouper les lots restants en une seule tâche, puis
autorise le travail prudent. Les étapes et commits sont gérés sans nouvelle
autorisation par lot. `b7a6cba` et origin/staging concordent au démarrage ; arbre
propre. Sa CI n'est pas confirmée dans la conversation. Aucun résultat distant
n'est déduit de la seule présence du commit dans la référence locale.

Cette consolidation complète les preuves locales des modules livrés. Elle ne
crée pas un connecteur webhook absent, une politique de conservation ou un
outil de restauration, et ne remplace pas la qualification de l'environnement
hébergé. QA-002 reste ouvert tant que ses dépendances applicables ne sont pas
satisfaites ou explicitement arbitrées.

### Écarts traités

- TenantBusinessRevocationTest : **40 cas**, dix familles (partenaires, contacts,
  opportunités, accords, campagnes, médias, communications, activités calendrier,
  matchs et compétitions externes), chacune avec retrait d'adhésion, de rôle,
  de lecture ou d'écriture après consultation réussie. Même acteur dans Alpha
  et Beta ; lectures/historiques pertinents revérifiés, mutations Alpha refusées,
  captures des tables et compteurs d'audits inchangés. Beta reste lisible et sa
  correction réussit, avec relecture du modèle persisté, y compris les champs
  chiffrés. Le retrait de l'écriture conserve les lectures autorisées.
- SponsorshipAgreementControllerTest : **deux cas** après lecture de conditions
  financières. Retrait de financial.read ou financial.write : sauvegarde refusée,
  version/conditions/historiques/audits inchangés. Lecture financière refusée si
  son droit disparaît ; sinon conservée avec l'événement de consultation attendu.
  La fiche générale ne divulgue pas les conditions.
- MatchRealtimeTest : **trois cas** après signature de canal et lecture live
  réussies. Révocation d'adhésion, de rôle ou de lecture : nouvelle signature et
  récupération des scores refusées, aucune partie créée.
- **69 méthodes existantes**, soit **84 cas avec paramètres**, intégrées au
  groupe critique sans supprimer ni réduire leurs assertions. Elles couvrent
  identifiants étrangers, mauvais parents, audiences, notifications et workers
  calendrier, flux ICS et anciens tokens, scores/résultats/carnets/corrections,
  documents/résultats de compétition, rôles/support, quotas, exports et purge
  ciblés. Groupe final : **246 cas, 3 831 assertions** sur SQLite.
- ReverbDeliveryTest : signatures obtenues via les vraies routes de deux tenants,
  deux connexions WebSocket autorisées et une troisième refusée par la route et
  le transport (signature invalide). Publication avec le broadcaster et l'événement
  applicatifs réels ; chaque abonné reçoit son seul événement, le troisième
  aucun. Vérification du payload minimal à quatre clés, sans score ni contenu
  métier. Serveur de test en boucle locale, identifiants d'application aléatoires,
  ports libres, échange atomique des fichiers et arrêt des processus en finally.
  Node 24 natif, aucune dépendance npm ajoutée. Trois exécutions indépendantes
  réussies : **1 test, 26 assertions chacune**.
- Workflow backend : nouvelle matrice et test de cache ajoutés à la sélection
  PostgreSQL ; étape Reverb dédiée avec Node 24. Le test de transport reste
  opt-in dans la suite normale afin de ne pas imposer un serveur aux tests HTTP.

### Régression d'isolation des tests découverte

La première exécution complète PostgreSQL a été interrompue après propagation
d'erreurs de schéma ; elle n'est pas une validation réussie. Le cluster a été
arrêté et supprimé. La séquence DashboardIndicatorTest →
EffectiveGameCatalogueCacheTest reproduit une fonction PostgreSQL déjà existante :
`migrate:fresh` supprime les tables mais conserve les fonctions de triggers.
Le test de cache utilisait DatabaseMigrations dans la base commune aux suites.

Correction limitée à ce test : base PostgreSQL aléatoire dédiée, migration sans
transaction conservée, restauration de la connexion et de l'état du framework,
puis suppression de cette seule base dans les callbacks de nettoyage. Les
assertions du test, les migrations et le code métier sont inchangés. SQLite
conserve le fonctionnement existant. La séquence étendue aux contrôleurs de
compétitions passe ensuite : **40 tests, 256 assertions sur PostgreSQL**.
Rapports de reproduction : `phpunit-qa002-cache-isolation-sequence.xml` (échec
attendu avant correction) et `phpunit-qa002-cache-isolation-afterfix.xml`.

### Matrice REC-23 à REC-36 après consolidation

Les noms de suites désignent `back/tests/Feature/`, avec `Tenancy/` pour
`Modules/Tenancy/`. Une limite ci-dessous reste ouverte même si le groupe critique
est vert ; la matrice initiale du premier lot est conservée comme historique.

| Critère | Preuves locales mobilisées | Limite de clôture |
| --- | --- | --- |
| REC-23 | Lots 1–11, TenantBusinessRevocationTest, contrôleurs partenariats/sponsoring, médias, calendrier, communication et compétition : URL étrangères, mauvais parents, mutations refusées. | Couverture des modules livrés ; tout nouveau module doit ajouter ses frontières. |
| REC-24 | TenantSearchTest, PartnershipControllerTest, choix de références des modules et régression EffectiveGameCatalogueCacheTest. | Pas de moteur externe de recherche qualifié ici. |
| REC-25 | ActiveOrganizationControllerTest, ContextualRolesTest, jobs sérialisés des lots précédents et matrice Alpha/Beta après révocation. | Interface/navigateur suivi séparément par QA-003. |
| REC-26 | OrganizationSchemaTest, ContextualRolesTest et suites de schéma avec clés composites et unicités locales ; inspecteur de propriété des tables. | Vérifier les nouvelles migrations à chaque extension ; aucune extrapolation vers des identifiants d'intégration absente. |
| REC-27 | TenantCacheTest, TenantArtifactServiceTest, ReportExportTest, CalendarSubscriptionTest et export opérateur. | Redis réel dispose d'une étape CI dédiée ; il n'est pas démarré localement dans cette consolidation. |
| REC-28 | Imports personnes/statistiques, scans et rappels contractuels, rappels d'inscription, CalendarInvitationWorkerTest, CalendarChangeWorkerTest et export opérateur sérialisé. | Pas de qualification de la topologie Redis/Horizon hébergée ni de SMTP réel. |
| REC-29 | Autorisation et révocation HTTP/live, payload minimal, tests de file et transport Reverb local réel avec deux tenants. | Reverse proxy/TLS/worker hébergés non qualifiés ; pas de preuve d'éviction d'une socket déjà abonnée après révocation. Une invalidation minimale ne vaut pas autorisation de relire les scores. |
| REC-30 | Aucun endpoint webhook métier entrant identifié. Le 25 octobre 2026, l'utilisateur confirme : « Aucun webhook entrant prévu au P0 ». | **Non applicable au P0 actuel**, sur décision explicite de périmètre. API-002 reste ouvert pour une future intégration ; signature, mapping tenant et idempotence devront alors être testés. Aucune implémentation de webhook certifiée. |
| REC-32 | PlatformRoleManagementTest, ContextualRolesTest et séparation des rôles tenant/plateforme. | Conserver les audits et tests par nouvelle action privilégiée. |
| REC-33 | PlatformSupportAccessTest : tenant/opérateur liés, durée, révocation, audit et expiration contrôlée. | Pas de nouvelle recette d'exploitation ou de scheduler hébergé ici. |
| REC-34 | TenantTechnicalLimitsTest, quotas imports/exports/artefacts, budgets Redis et file isolée en CI. | QA-005 requis : charge asymétrique selon l'enveloppe du CDC, p95 et impact sur Beta mesurés. Des tests de bornes ne prouvent pas cette charge. |
| REC-35 | Catalogue de portabilité fermé aux tables/colonnes inconnues, export chiffré et fichiers ciblés, politiques et purge autorisée sur fixtures ; Beta préservé. | OPS-007 requis pour politique complète par catégorie et rétention réelle, y compris sauvegardes. Pas de déclaration de conformité d'une purge exhaustive. |
| REC-36 | OrganizationMaintenanceTest : retour d'archivé à suspendu sans réécriture ni impact Beta. | OPS-004/005 requis : restauration de sauvegarde et réconciliation base/fichiers sans écraser les mises à jour Beta ; retour de statut seul insuffisant. |

### Validation et livraison

| Vérification finale | Résultat |
| --- | --- |
| Backend complet SQLite en mémoire | 2 798 cas : **2 748 réussites**, 17 690 assertions, 50 exclusions prévues. |
| Backend complet PostgreSQL 17 neuf | 2 798 cas : **2 794 réussites**, 18 222 assertions, 4 exclusions prévues. Les tests de concurrence réelle sont inclus. |
| Groupe critique SQLite | **246 réussites**, 3 831 assertions, aucune exclusion. |
| Reverb réel local, trois démarrages indépendants | **1 réussite/26 assertions par exécution**, aucune exclusion. |
| Pint, PHPStan, syntaxe du client Node et parsing YAML du workflow | Réussis. |

Tous les rapports finaux ont été relus : zéro échec et zéro erreur. Les quatre
exclusions PostgreSQL sont ClamAV, les deux intégrations Redis et Reverb opt-in ;
ce dernier est exécuté séparément avec succès. SQLite exclut aussi les cas de
concurrence réservés à PostgreSQL et le contrôle de schéma qui exige ce moteur.
Redis dispose de son étape CI existante ; aucun Redis ou ClamAV réel démarré ici.
Rapports sous `back/reports/` : `phpunit-qa002-consolidated-sqlite.xml`,
`phpunit-qa002-consolidated-postgresql.xml`,
`phpunit-qa002-consolidated-critical.xml`, `phpunit-qa002-reverb-1.xml` à `-3.xml`.
La première tentative PostgreSQL interrompue est remplacée par la validation
complète réussie après correction ; elle n'est pas comptée comme preuve positive.

Cluster PostgreSQL et bases de concurrence supprimés, processus Reverb/Node
arrêtés ; aucun répertoire temporaire du lanceur ou de Reverb restant au contrôle
final. Livraison en trois commits dédiés demandés dans le cadre de la tâche
unique, sans push ; la CI distante des nouveaux commits reste à obtenir.
L'utilisateur confirme le 25 octobre 2026 qu'aucun webhook entrant n'est prévu
au P0 ; REC-30 n'est donc plus bloquant pour ce périmètre. Les informations sur
la recette de charge/restauration et la politique de rétention restent attendues.
Aucune base existante, donnée réelle, migration, dépendance applicative, `.env`
ou interface modifiée. Les preuves de transport local n'impliquent aucune mise
en production. Aucun push ni résultat de CI distante revendiqué pour ce travail.

## UCG-QA-003 — Revue de qualification sur b4dd21a (25 octobre 2026)

> État initial de cette revue, conservé pour traçabilité. La lecture authentifiée
> et la décision finale sont consignées dans la section suivante et remplacent
> l'attente d'autorisation et de qualification décrite ici.

### Objectif et état vérifié

L'utilisateur confirme la CI de `b4dd21a`, demande de vérifier la priorité entre
QA-002/003/005, puis autorise la revue prudente de QA-003. Cette étape examine les
preuves existantes et les critères d'acceptation ; elle ne démarre pas QA-005.
Au départ, arbre propre sur `staging`, HEAD et référence locale origin/staging
sur `b4dd21a7ec37f30fcf94f40e5590aa47d34b4164`.

Contrôle direct des API publiques GitHub Actions, sur ce SHA exact :

| Preuve distante | Résultat constaté |
| --- | --- |
| [Frontend quality — 40142869767](https://github.com/sloumach/ucg-plateform/actions/runs/40142869767) | Completed/success ; lint, tests unitaires et build réussis. |
| Job Playwright SQLite | Suite générale, garde-fous et parcours réels Laravel réussis. |
| Job Playwright PostgreSQL | Garde-fous, contrôle du cluster jetable et parcours réels réussis. |
| [Backend quality — 40142869759](https://github.com/sloumach/ucg-plateform/actions/runs/40142869759) | Completed/success ; formatage, analyse statique, tests, PostgreSQL, Reverb réel et isolation Redis réussis. |

Les étapes conditionnelles non sélectionnées dans chaque branche de la matrice
sont marquées skipped : ce sont des étapes de workflow, pas un décompte de tests
exclus. Les artefacts suivants existent et ne sont pas expirés au contrôle :
`playwright-reports-sqlite` (12579298608), `playwright-reports-postgresql`
(12579801387), `frontend-test-report` (12579621188) et `backend-test-report`
(12579927802). Métadonnées publiques sauvegardées localement dans
`front/reports/qa003-b4dd21a/ci-metadata.json` (répertoire de rapports ignoré).

### Rapports locaux et contrôle du périmètre

| Rapport local relu | Cas réels | Échecs / erreurs / exclusions | Portée |
| --- | --- | --- | --- |
| `front/reports/playwright-real.xml` | 8 | 0 / 0 / 0 | Exécution SQLite du 24 octobre, environ 239 s. |
| `front/reports/playwright-real-postgresql.xml` | 8 | 0 / 0 / 0 | Exécution PostgreSQL du 24 octobre, environ 369 s. |
| `front/reports/playwright.xml` | 0 | 0 / 0 / 0 | Rapport vide du 24 octobre ; aucune preuve positive de suite générale. |

Les compteurs ont été confrontés aux nœuds testcase et à l'absence de nœuds
failure/error/skipped. Les deux fichiers `.last-run.json` des parcours réels
indiquent passed, sans test échoué. Ces fichiers locaux ne sont pas les artefacts
CI de `b4dd21a` et n'attestent pas les décomptes de cette CI.

La comparaison Git depuis `c3fb614` ne montre aucun changement des parcours,
du lanceur, des configurations Playwright réelles, des fixtures navigateur,
de l'interface `front/src` ou du workflow frontend. Le client Reverb ajouté
depuis dans les fixtures backend relève de QA-002, pas de ces parcours.
Les huit sources contiennent chacune un scénario, sans skip/fixme/only, et des
contrôles de débordement aux largeurs 375/320 px. Les requêtes navigateur autorisées
continuent vers le frontend et l'API locaux ; les autres origines sont refusées.
Le lanceur réel conserve un worker unique, retries=0, des données jetables et
des garde-fous avant migrations. Aucun nouveau test ni service n'est lancé ici.

Les neuf thèmes de QA-003 sont représentés : identité/personne/changement de
tenant dans un scénario, puis roster, calendrier, match, contrat, export,
invitation et MFA dans les sept autres. Le bilan de couverture précédent reste
applicable, y compris ses limites sur les cas avancés. Le premier scénario ne
collecte pas explicitement les événements pageerror, contrairement aux sept
autres ; cette limite interdit de revendiquer un contrôle uniforme de toutes
les erreurs JavaScript, sans constituer une anomalie métier observée.

### Décision de qualification et suite

**Couverture principale confirmée ; qualification documentaire encore ouverte.**
Les critères de parcours, tailles d'écran et erreurs exploitables disposent des
preuves locales et des étapes CI réussies décrites ci-dessus. Il reste à lire
les rapports CI du SHA exact pour vérifier leurs cas, exclusions et éventuelles
relances, notamment la suite générale qui autorise des retries en CI. Aucun
nouveau décompte CI ni absence de flakiness n'est déduit du statut success.

Le téléchargement public d'un artefact renvoie 401. La revue automatique refuse
ensuite la commande proposant de lire les identifiants GitHub configurés via
`git credential fill`, car cet accès n'a pas été explicitement autorisé. Cette
commande n'a pas été exécutée et aucun identifiant n'a été lu. La lecture des
artefacts attend soit une autorisation explicite pour cet accès authentifié,
soit la fourniture des rapports par l'utilisateur. Aucun contournement tenté.

Les variantes avancées de la matrice QA-003 restent des suites à prioriser,
pas des anomalies bloquantes découvertes ni une extension automatique du ticket
à tous les critères du CDC. QA-002 conserve ses dépendances QA-005/REC-34,
OPS-007/REC-35, OPS-004/005/REC-36 et les limites hébergées REC-29. Aucun webhook
entrant n'est attendu au P0. Après décision de qualification QA-003, QA-005 reste
un prochain chantier proposé ; ni la clôture QA-002 ni le pilote ne sont acquis.

Cette revue modifie uniquement le suivi documentaire. Pas de modification
métier, migration, configuration, dépendance ou donnée réelle ; pas de commit
ni de push effectué pendant cette étape.

## UCG-QA-003 — Lecture des artefacts CI et qualification finale (25 octobre 2026)

### Autorisation et provenance

Après la demande explicite d'autorisation d'utiliser les identifiants GitHub
déjà configurés pour télécharger les rapports, l'utilisateur répond « ok on
avance ». La commande authentifiée est alors approuvée et exécutée. Les
identifiants sont utilisés uniquement en mémoire pour les lectures GitHub,
sans affichage ni enregistrement dans les rapports ou le dépôt.

Le run frontend `40142869767` est revérifié avant téléchargement : SHA exact
`b4dd21a7ec37f30fcf94f40e5590aa47d34b4164`, conclusion success. Les trois
artefacts sont retrouvés dans la liste de ce run, non expirés :

| Artefact | Identifiant | Archive téléchargée |
| --- | --- | --- |
| frontend-test-report | 12579621188 | 20 529 octets |
| playwright-reports-sqlite | 12579298608 | 761 439 octets |
| playwright-reports-postgresql | 12579801387 | 306 222 octets |

Archives conservées sous `front/reports/qa003-b4dd21a/`, ignoré par Git.
`qualification-summary.json` conserve les empreintes SHA-256 des archives,
les décomptes, noms des suites et résultats du contrôle des tentatives ;
`ci-metadata.json` conserve les références des runs, jobs et artefacts.
Les XML et les données JSON embarquées dans les HTML sont lus comme des
données, sans exécuter le contenu des rapports.

### Résultats CI effectivement relus

| Rapport | Cas | Échecs | Erreurs | Exclusions | Durée rapportée |
| --- | --- | --- | --- | --- | --- |
| Vitest `vitest.xml` | 493 | 0 | 0 | 0 | 70,60 s |
| Playwright général `playwright.xml` | 53 | 0 | 0 | 0 | 132,62 s |
| API réelle SQLite `playwright-real.xml` | 8 | 0 | 0 | 0 | 208,97 s |
| API réelle PostgreSQL `playwright-real-postgresql.xml` | 8 | 0 | 0 | 0 | 221,86 s |

Les compteurs JUnit correspondent au nombre réel de nœuds testcase ; aucun
nœud failure/error/skipped/flakyFailure/rerunFailure. Les rapports HTML des trois
suites Playwright confirment respectivement 53/8/8 résultats expected, zéro
unexpected, flaky, skipped et zéro erreur globale. Chacun des 69 cas possède
exactement une tentative dans les données du rapport : aucune réussite obtenue
après relance sur ce run. Le rapport général porte aussi le SHA et l'URL du run
attendus dans ses métadonnées. Les rapports réels n'embarquent pas ces métadonnées
Git ; leur rattachement repose sur les artefacts du run vérifié, pas sur une
attribution déduite du nom de fichier.

Les deux rapports réels contiennent les huit suites attendues : calendrier,
contrats, exports, identité/personnes/contexte, invitations, matchs, MFA et
équipes/roster. Les 53 cas généraux complètent ces parcours avec des API métier
simulées ; ils ne sont pas présentés comme 53 parcours avec API réelle.

### Décision et prochain chantier

**QA-003 est qualifié sur son périmètre convenu ; la revue est terminée.**
La couverture des neuf thèmes et des tailles 375/320 px, les contrôles d'erreurs
exploitables et la réussite des parcours réels sur les deux moteurs disposent
maintenant des preuves de sources, locales et CI relues. L'attente de lecture
des artefacts est levée. Les variantes avancées déjà documentées restent des
suites à prioriser ; aucun nouvel échec ou anomalie bloquante n'est observé ici.
Cette qualification concerne Chromium et ces parcours ciblés, sans élargir la
preuve à un audit exhaustif d'accessibilité, à tous les navigateurs ou à la
recette des services hébergés.

La prochaine étape proposée est **QA-005/REC-34**, pour mesurer les performances
et l'effet d'un tenant fortement actif sur un autre selon l'enveloppe du CDC.
QA-005 n'est pas exécuté dans cette revue. QA-002 reste ouvert pour cette preuve
de charge, OPS-007/REC-35, OPS-004/005/REC-36 et les limites hébergées REC-29.
QA-004, la reprise des données, le pilote et la mise en production ne sont pas
validés par cette qualification.

Seuls les deux documents de suivi sont modifiés ; les rapports téléchargés
restent locaux et ignorés. Aucun test applicatif relancé pour cette revue de
preuves existantes, aucune base ni donnée réelle modifiée. Pas de commit ni push.

## UCG-QA-005 — Premier lot de mesure locale isolée (25 octobre 2026)

### Objectif et protocole livré

L'utilisateur autorise le démarrage prudent après qualification de QA-003 et
commit `9dcbb4a`. Ce premier lot crée un outil reproductible de mesure ; il ne
prétend pas remplir l'enveloppe du CDC (500 comptes, 100 actifs, 100 000 parties,
2 000 visiteurs publics selon périmètre) ni qualifier REC-34 sur l'hébergement.
Arbre propre sur `staging` au départ, commit de base `9dcbb4a`.

Commandes depuis `front/` : `npm run test:load:harness` et
`npm run test:load:smoke`. Aucun paquet ajouté. Réutilisation des garde-fous
`isolatedEnvironment`, `TemporaryPostgres` et du bootstrap propriétaire déjà
éprouvés par QA-003. Profil strict `smoke`, aucune URL externe ou connexion DB
arbitraire acceptée ; PostgreSQL 17 neuf en boucle locale, schéma vide obligatoire,
stockage/cache/sessions privés au répertoire temporaire. Aucun `.env` de
développement transmis. Refus du second peuplement et absence de changement
des snapshots vérifiés avant le démarrage des phases.

Dataset réel de l'essai : trois comptes fictifs (propriétaire et deux acteurs),
trois organisations, 500 fiches Alpha, 120 Beta, 10 Gamma, aucune partie créée.
Deux sessions se connectent par HTTP avec CSRF, mot de passe, TOTP et confirmation
du tenant. Deux processus `php -S` partagent le cluster ; un worker database
traite les exports. Cache database, pas de Redis/Horizon ni serveur web hébergé.

Après cinq lectures de chauffe, Beta réalise 100 lectures de sa liste avant,
100 pendant et 100 après l'activité d'Alpha. Chaque phase alterne des pages
de 25 et 100 fiches (50 échantillons de chaque taille). Alpha lit ses pages
de 100 en boucle fermée et trois exports `active_people` de 500 lignes sont
mis en queue, puis réellement générés. Les 100 lectures sous charge doivent
se trouver dans la fenêtre d'activité HTTP d'Alpha ; l'occupation du worker
n'est pas supposée continue sur cette fenêtre.

Chaque réponse doit avoir le bon statut, les compteurs d'instrumentation,
les bons identifiants, le total et la taille paginée attendus, sans doublon.
Les erreurs ne sont pas exclues des latences ; une phase interrompue ne passe pas.
Les identifiants/versions/updated_at des fiches Beta et Gamma sont hachés et
comparés avant/après, avec leurs compteurs d'exports et d'artefacts. Le rapport
contrôle aussi les trois exports terminés à 500 lignes et zéro job échoué/en queue.
Il ne constitue pas une comparaison de toutes les colonnes de toutes les tables.

### Mesures et constats

Rapports locaux sous `front/reports/`, ignorés par Git :

| Exécution | Résultat et portée |
| --- | --- |
| `load-smoke-1792928551019.json` | PostgreSQL refusé par le jeton Windows restreint avant démarrage ; nettoyage confirmé, aucune mesure positive. |
| `load-smoke-1792928583792.json` | Premier protocole terminé : p95 Beta 329,35 / 539,87 / 655,73 ms ; seuil 500 ms non tenu, code de sortie 1 ; 398 lectures mesurées réussies, trois exports de 500 lignes, intégrité et nettoyage confirmés. Essai conservé, pas reclassé en réussite de performance. |
| `load-smoke-1792928900910.json` | Ajout du refus de second peuplement : contrôle en échec avant mesures, nettoyage confirmé. Un refus après bootstrap Laravel pouvait terminer avec code 0 ; le nouveau seeder émet désormais un statut explicite 2, et le lanceur reconnaît son message sûr. |
| `load-smoke-1792929067358.json` | Exécution finale instrumentée : protocole complet, schéma rempli refusé, cible locale respectée, intégrité et nettoyage confirmés, code de sortie 0. |

Résultats de l'exécution finale :

| Phase | Requêtes réussies / erreurs | p95 global | p95 page 25 / page 100 | Requêtes SQL par réponse |
| --- | --- | --- | --- | --- |
| Beta avant | 100 / 0 | 332,82 ms | 332,82 / 364,18 ms | 48 / 48 |
| Beta pendant Alpha | 100 / 0 | 378,47 ms | 409,00 / 378,47 ms | 48 / 48 |
| Beta après | 100 / 0 | 381,10 ms | 338,39 / 417,60 ms | 48–52 / 48 |
| Alpha pendant Beta | 100 / 0 | 382,54 ms | — / 382,54 ms | — / 48 |

Impact observé sur le p95 global Beta : +45,64 ms, ratio 1,137. Aucun seuil
supplémentaire de ratio n'est inventé. Le nombre de requêtes ne croît pas avec
le nombre de fiches retournées sur les pages mesurées ; les quatre requêtes
ponctuelles supplémentaires après charge restent une variation à profiler,
sans en déduire une cause ou certifier tous les accès contre les N+1.

Les p95 SQL Beta sont 202,27 / 218,02 / 208,21 ms ; bootstrap 167,07 / 132,71 /
170,70 ms et traitement HTTP 245,05 / 264,74 / 250,92 ms. Le SQL est inclus dans
le traitement ; ces quantiles ne s'additionnent pas. Les compteurs sont limités
à la période entre bootstrap et envoi de la réponse : preuve de propriété DB,
bootstrap et terminaison exclus. La latence client inclut leurs coûts avant
réponse ainsi que transport et décodage JSON. Aucun SQL ou binding n'est enregistré.

Poste commun aux services et au générateur : Windows 10, Intel i5-9600KF,
6 processeurs logiques, environ 25,7 Go de mémoire, Node 24.21.0, PHP 8.5,
Laravel installé 13.34.0. Les latences incluent les serveurs de développement
et les vérifications de propriété répétées. La différence entre les deux essais
complets illustre la variabilité de ce poste partagé ; aucune optimisation métier
n'a été appliquée et aucune amélioration de capacité n'est revendiquée.

### Validation, livraison et limites restantes

- Cinq tests Node du nouveau lanceur réussis : paramètres/cibles refusés,
  percentiles, erreurs conservées, pagination/tenant et garde PHP avant bootstrap
  (douze combinaisons sur les trois points d'entrée).
- Six tests existants du lanceur navigateur réutilisé réussis, sans exclusion.
- Lint frontend, syntaxe Node/PHP, Pint dirty et PHPStan réussis ; YAML du workflow
  analysé avec Symfony YAML. La CI est raccordée aux cinq garde-fous seulement,
  sans exécution automatique de seuils de latence sur un runner partagé.
- Nouvelle CI distante non exécutée ; aucun commit ni push pendant ce lot.

Les rapports comprennent les échantillons, statuts, p50/p95/max, p95 par taille,
temps SQL/bootstrap/traitement, environnement, commit de base, checkout modifié,
empreintes SHA-256 des sources du lanceur, contrôles d'intégrité et nettoyage.
`completed`, `interactiveTarget.metInAllPhases` et `cleanup` sont trois résultats
distincts. Une interruption est demandée après cinq minutes, puis les délais
bornés de fin des requêtes et d'arrêt peuvent s'ajouter. Les processus sont arrêtés
avant PostgreSQL ; suppression du seul répertoire canonique dont le propriétaire
est vérifié. En cas de doute, les ressources sont conservées avec un statut d'échec.
Les deux essais complets et les deux essais refusés ont tous confirmé le nettoyage ;
aucun répertoire `ucg-browser-*` restant au contrôle final. Le service PostgreSQL
préexistant n'est ni utilisé ni arrêté.

**QA-005 et REC-34 restent ouverts.** Restent la volumétrie complète, les
100 utilisateurs actifs avec profil de débit et distribution de rôles définis,
les imports lourds simultanés, les pics publics applicables, les files/cache
représentatifs, la mesure sur infrastructure de recette et une durée de charge
suffisante. Ce lot ne mesure ni rendu d'écran, ni propagation des scores, ni
disponibilité. La prochaine extension doit distinguer le profilage des 48 requêtes
du passage à la charge cible ; aucun index/cache n'est ajouté sans preuve.
QA-002 conserve aussi ses dépendances de rétention et de restauration.

Modifications limitées aux fixtures de test, lanceur/métriques/tests Node,
scripts npm, raccordement des garde-fous CI et documentation existante.
Aucun code métier, endpoint public, migration, dépendance, `.env` ou donnée
réelle modifié. Aucun SMTP, fournisseur externe, Redis ou Horizon sollicité.

## Correctif CI — Fenêtre UTC d'abonnement calendrier (25 octobre 2026)

Journal fourni par l'utilisateur : un échec dans
`CalendarSubscriptionTest::test_window_uses_utc_half_open_bounds_and_preserves_stable_uids`,
404 au premier téléchargement au lieu de 200 ; 2 747 réussites et 50 exclusions
dans cette exécution CI. Le SHA de cette exécution n'est pas fourni et n'est pas
déduit du seul journal.

L'échec est reproduit à l'identique sur SQLite en mémoire, avant correction.
Le scénario créait les droits à l'heure réelle, puis reculait au
25 octobre 2026 à 00 h 30. Les affectations générées avec un début relatif à
l'heure réelle pouvaient alors devenir futures ; le contrôle d'accès refusait
normalement le flux avec RESOURCE_NOT_FOUND. Ce test dépendait donc de la date
d'exécution et de l'ordre de création des fixtures.

Correction ciblée : déplacement du `travelTo` avant `scenario()` dans ce seul
test. Les droits et le flux sont créés dans le même contexte temporel que les
activités et leurs bornes UTC. Les assertions des bornes semi-ouvertes et de
stabilité des UID sont conservées ; aucune règle d'accès applicative modifiée.

Validation : fichier complet CalendarSubscriptionTest, **15 cas réussis,
114 assertions**, sans exclusion, sur SQLite en mémoire ; Pint dirty et PHPStan
réussis. Rapports locaux `back/reports/calendar-window-before.xml` et
`back/reports/calendar-subscriptions-fixed.xml`. La suite backend complète n'est
pas relancée pour cette modification d'ordre dans le test. Nouvelle CI distante
à exécuter après livraison ; aucun commit ni push pendant ce correctif.

## UCG-QA-005 — Consolidation locale des lots restants (25 octobre 2026)

### Mandat, périmètre et environnement

L'utilisateur valide la CI du correctif `9637ce9`, demande de réunir les quatre
lots restants dans une seule tâche et autorise le commit. À la question sur la
recette représentative, il confirme : « Toujours différé : poursuivre toute la
validation locale possible ». Au départ, arbre propre sur staging, HEAD et
référence locale origin/staging sur `9637ce9`. La CI est confirmée par l'utilisateur,
sans nouvelle lecture distante dans cette tâche.

Quatre profils fixes sont désormais disponibles : smoke, profile, volume et
pressure. Aucun ciblage distant ou de base existante n'est ajouté. Les garde-fous
du cluster, le schéma vide, le refus de second peuplement, le filtrage des variables
héritées, CSRF, MFA des acteurs privilégiés, autorisations et limites restent
actifs. Les serveurs, worker et PostgreSQL sont natifs sur le même poste Windows.
Le daemon Docker n'est pas joignable et aucun Redis/Horizon réel n'est qualifié.

### Lot 2 — Profilage mesuré

Le profil profile agrège les empreintes des requêtes, tables, emplacements des
appels, nombres et temps ; aucun SQL ni binding n'est conservé. Les compteurs
restent limités à la période après bootstrap et avant envoi. L'instrumentation
ajoutée a un coût propre ; ses latences ne sont pas une comparaison avant/après
d'une optimisation applicative.

Premier rapport `load-profile-1792931897026.json` : protocole terminé, aucune erreur,
nettoyage confirmé ; p95 Beta 382,51 / 306,15 / 318,45 ms. Sur 405 lectures (chauffe
et activité Alpha comprises), la première lecture users représente environ
22 677 ms cumulées ; organizations 4 050 exécutions/5 045 ms, memberships 4 050
exécutions/1 496 ms et vérifications de grants 2 430 exécutions/1 813 ms.
Les événements de première lecture peuvent inclure l'ouverture de connexion ;
ces temps ne sont pas assimilés au seul temps d'exécution du plan PostgreSQL.

Les lectures d'organisation/adhésion et de permissions sont répétées par les
différentes frontières d'accès. Le nombre reste stable avec la taille des pages
mesurées. Aucune preuve d'index manquant ou de N+1 proportionnel aux lignes n'est
établie ici. Aucun cache de droits ni suppression d'un contrôle n'est introduit
pour réduire artificiellement le compteur ; une optimisation ultérieure devra
préserver les révocations immédiates et être qualifiée sur la vraie topologie.

### Lots 3–4 — Volumétrie, concurrence et pression

Les grands profils créent **500 comptes, 1 000 matchs et 100 000 parties jouées**
dans les vraies tables PostgreSQL : répartition des matchs Alpha 800, Beta 199,
Gamma 1, chacun avec deux participants, des règles lobby et 100 parties. Les
relations, transitions et déclencheurs restent actifs. Les données sont insérées
par lots bornés, sans désactivation des contraintes. Les parties datées dans le
passé restent des fixtures synthétiques, pas des résultats approuvés via toute
la procédure métier. Cette limite n'est pas masquée par le terme historique.

Cent sessions HTTP distinctes sont ouvertes : 99 lecteurs Alpha/Beta et un acteur
bruyant privilégié. Les lecteurs ordinaires ont seulement sports.read. Les
sessions sont distribuées dans le même pool de quatre serveurs PHP pour les
deux tenants. La référence/récupération utilise 50 lecteurs Beta (150 lectures),
la phase de concurrence 99 lecteurs (297 lectures), avec l'acteur bruyant en plus.
Le pic de 100 requêtes en attente est enregistré ; il ne représente pas
100 requêtes PHP exécutées à la même microseconde sur quatre processus.

Les ressources mesurées incluent personnes, matchs et pages de parties d'un
match commun à chaque tenant, avec tailles 25/100 et vérification des identifiants,
totaux et doublons. Ce match commun constitue un point chaud explicite. Les
latences, erreurs, requêtes SQL et débit réalisé sont ventilés par tenant et
ressource. En pressure, les listes de personnes Alpha grandissent légitimement ;
ses lectures mesurées restent sur matchs/parties et le contrôle strict des listes
Beta est conservé. L'augmentation Alpha est vérifiée après la fin des imports.

Trois imports de 1 000 lignes et trois exports de 500 lignes sont confirmés via
les vraies API et traités par un worker database. Les exports précèdent les
imports dans la queue reports afin de garder leur source stable pendant leur
génération. Les chunks d'import de 25 lignes alternent ensuite dans cette queue.
Ce protocole mesure HTTP concurrent avec queue chargée, pas plusieurs workers
exécutant les deux types de traitement simultanément. Les exports générés avant
les imports ne sont pas déclarés encore téléchargeables après invalidation de
leur source. Le CSV de 1 001 lignes est explicitement refusé en 422.

### Résultats et écarts conservés

| Rapport local sous front/reports | Résultat |
| --- | --- |
| load-volume-1792932111265.json | Dataset complet et 100 sessions ; première référence interrompue après 131/150 requêtes, dont 17 refus 429. Nettoyage confirmé. Ce résultat a motivé l'observation complète des refus. |
| load-volume-1792932511216.json | Trois phases complètes avant ajout des lectures de parties : 150/297/150 requêtes, dont 24/26/17 refus 429. Intégrité ciblée, exports et nettoyage conformes ; performance refusée. |
| load-volume-1792932850363.json | Trois phases complètes avec pages de parties : 150/297/150 requêtes, dont 15/24/16 refus 429 ; p95 Beta 3 879,89 / 8 489,79 / 3 914 ms environ. Pic 100, exports conformes, zéro job échoué/en attente, intégrité ciblée et nettoyage conformes ; code 1 pour la performance. |
| load-pressure-1792933086407.json | Contrôle de CSV surdimensionné réussi et jobs en queue ; interruption à 242/297 lectures sous pression, car le validateur attendait encore une liste Alpha fixe malgré les créations normales. Nettoyage confirmé ; ce run n'est pas une preuve positive de pression complète. |
| load-pressure-1792933424102.json | Pression complète après correction de cette hypothèse du lanceur : 150/297/150 lectures, dont 16/29/11 refus 429 ; p95 Beta 3 474,87 / 8 688,84 / 3 582,15 ms. Pic 100, trois imports terminés et exactement 3 000 créations (Alpha passe de 500 à 3 500), trois exports terminés à 500 lignes, zéro job échoué/en attente. Intégrité ciblée et nettoyage conformes ; performance refusée, code 1. |

Le contrôle d'intégrité compare identifiants/versions/updated_at des personnes
Beta/Gamma, compte leurs matchs/parties et leurs exports/artefacts ; aucune
altération ciblée observée. Il ne prétend pas comparer chaque colonne de chaque
table ni tous les fichiers. Les requêtes 200 validées n'ont aucun identifiant
étranger dans leurs collections contrôlées.

La contention du verrou non bloquant de LimitTenantRequests peut renvoyer 429
avant épuisement du budget de 600 requêtes/minute. Les refus observés sous charge
ne sont pas transformés en succès. Chaque opération suivante respecte le
Retry-After borné à 1–60 s ; aucune requête refusée n'est remplacée. Les p95 HTTP
incluent les latences des refus, mais pas l'attente avant l'opération suivante ;
le débit réalisé inclut ces attentes. Une réponse 200 non conforme interrompt
immédiatement l'essai. Les limites de sécurité restent en place.

Sur volume, le p95 Beta est multiplié par environ 2,19 ; sur pressure, par 2,50.
Ces rapports ne satisfont pas la cible de 500 ms. Aucun seuil de ratio arbitraire
n'est ajouté, aucune capacité de production n'est déduite des quatre serveurs
de développement ni d'un poste partagé. Les runs interrompus et les versions
précédentes des protocoles restent conservés avec leurs empreintes propres.

### Lot 5 — Protocole de recette préparé, exécution différée

Le README frontend définit la recette finale : environnement et générateur
séparés, topologie/ressources/versions enregistrées, montée et charge soutenue,
mix de routes/rôles et débit convenus, files/cache/workers représentatifs,
mesures de Beta, profondeur/âge des queues, CPU/mémoire/IO et rapport de décision.
Le lanceur local n'est pas ouvert à une URL arbitraire pour contourner ces prérequis.

Les vitrines et scores publics sont P1 dans le backlog et aucun module public
n'est livré ici. La charge de 2 000 visiteurs n'est pas exercée sur une route de
santé et n'est pas déclarée satisfaite ; son applicabilité exacte doit être
confirmée avec les endpoints de recette. Redis/Horizon, TLS/reverse proxy,
longue durée, rendu d'écran et propagation temps réel restent hors preuve locale.

**QA-005 et REC-34 restent ouverts**, à la fois pour les écarts de performance
observés et pour la recette représentative différée par l'utilisateur. Les lots
locaux et le protocole sont livrables ; la validation finale n'est pas inventée.
QA-002 conserve ses dépendances de charge, rétention et restauration.

Les changements concernent uniquement les fixtures, le lanceur et ses tests,
les scripts npm et la documentation existante. Aucun service métier, route
publique, migration, dépendance, `.env` ou donnée réelle modifié. Commit autorisé
dans cette tâche, sans push ; nouvelle CI des changements à exécuter après livraison.

### Vérification finale et livraison

Dernière régression de profilage sur les sources finales :
`load-profile-1792933785770.json`, protocole complet, zéro erreur, objectif local
respecté et nettoyage confirmé ; p95 Beta 398,71 / 305,65 / 308,94 ms. Les
empreintes des sources de ce rapport et du dernier pressure ont été confrontées
aux fichiers livrés : concordance. Le rapport volume précédent conserve ses
empreintes de l'itération antérieure à l'extraction du sélecteur de ressources ;
le dernier pressure réexerce la même volumétrie sur les sources finales.

Validation finale : **15 tests Node réussis, sans exclusion** (neuf du protocole
de charge et six du lanceur isolé réutilisé), lint et syntaxe Node réussis.
Pint dirty et PHPStan réussis sur les sources PHP finales. Aucun code métier
n'ayant changé, la suite applicative complète n'est pas relancée pour ces fixtures.
Les garde-fous sont déjà raccordés à la CI frontend, mais cette nouvelle CI
distante n'est pas déclarée exécutée. Aucun répertoire ucg-browser-* restant
après les derniers essais et les tests. Aucun processus PHP de ces essais restant.

Deux corrections propres au lanceur sont incluses : poursuivre les phases avec
les refus 429 enregistrés, et ne pas confondre les créations Alpha attendues avec
une réponse de lecture invalide. Le contrôle strict de Beta reste actif.
La matrice de qualification distingue ainsi : outils/tests livrés, scénarios
locaux terminés, performance refusée sous forte concurrence, et recette
représentative différée. Un commit UCG-QA-005 est demandé pour ces changements ;
aucun push n'est autorisé ou effectué dans cette tâche.

## UCG-OPS-007 — Inventaire et préparation de la conservation (25 octobre 2026)

### Mandat et pays confirmé

Après la consolidation QA-005 livrée dans `f70cce6`, l'utilisateur autorise le
démarrage prudent de la préparation des règles, sans suppression de données.
Il confirme que l'établissement d'UCG est en **Tunisie**. Cette réponse est une
information métier de cadrage, pas une instruction de modifier les organisations
ou les fixtures actuellement configurées FR. Les pays des utilisateurs, les
mineurs éventuels, les responsables des traitements et l'hébergement restent
à préciser. QA-005 reste ouvert sur les écarts de performance et la recette différée.

Arbre propre sur staging au début. Travail limité à README.md, au backlog et à
ce suivi. Aucune connexion à une base, commande Artisan, migration, mise en queue,
expiration ou purge n'est lancée. Les constantes, configurations et procédures
sont examinées dans les fichiers ; leurs valeurs effectives sur une installation
utilisée ne sont pas déduites des seuls défauts du code.

### Livrable documentaire et constats techniques

La section « Conservation des données — cadrage UCG-OPS-007 » du README est le
support de travail. La matrice couvre exactement les **116 entrées** de
DatabasePortabilityRepository::TABLES, réparties en **18 familles sans doublon**.
Le classement est confronté aux noms du catalogue, sans table non classée.
Ce décompte de sources ne certifie pas le schéma d'une base réelle.

Chaque famille mentionne un événement de départ possible, les liens métier à
préserver et les décisions restantes. Les durées actives/archives demeurent
à valider, sans valeur juridique chiffrée inventée. Une fiche précise les champs
attendus : finalité, responsable, périmètre/pays, événement, durée active, archive
et habilitations, justification applicable, action finale, éventuel gel,
dépendances, version, date et validateur. Une durée non définie ne signifie ni
illimité ni suppression immédiate. Cette fiche n'est pas une configuration exécutée.

Les principales limites observées sont explicites :

- AuditRetentionPolicy et config/audit.php imposent unlimited sans purge, sur
  décision utilisateur IAM-006 antérieure. Ce choix technique reste intact et
  n'est pas présenté comme une conservation juridiquement validée.
- OrganizationPurgeService ne traite que six catégories opérateur ; les chemins
  supprimant des données concernent paramètres, objets privés et archives
  techniques. Les historiques d'accès/cycle de vie et la racine sont protégés ;
  les comptes globaux ne sont pas supprimés. Le service n'efface pas toutes les
  tables métier du catalogue. Les fichiers documentaires partagent le ledger
  avec des exports : leur durée devra dépendre de leur finalité, pas du seul disque.
- La liste livrée de politiques de purge est vide. Une politique opérateur
  complète, un tenant archivé, une confirmation et un export source sont exigés
  par le chemin existant, sans constituer une preuve de formalités remplies ou
  d'effacement exhaustif. Aucun réglage de ce chemin n'est modifié.
- Les liens de téléchargement, analyses antivirus, invitations, sessions et
  archives/export temporaires ont des échéances techniques distinctes. Le README
  consigne leurs valeurs par défaut et leurs limites, sans les transformer en
  durée de conservation des dossiers ni présumer un scheduler actif.
- Les copies hors application (stockage/versioning, téléchargements, prestataires,
  logs, sauvegardes/PITR et réplicas) nécessitent leurs propres responsables et
  preuves. Aucune stratégie réelle de sauvegarde ou d'effacement n'est inventée.

### Références et suite

Le cadrage pointe vers le [texte officiel tunisien de la loi n° 2004-63](https://www.mtc.gov.tn/fileadmin/texte_juridiques/L2004-0063.pdf),
consulté le 25 octobre 2026, pour la validation locale des délais et formalités.
La source INPDP indexée renvoie au même texte, mais son téléchargement direct
expire lors de la consultation ; aucune actualité ou réforme juridique non
vérifiée n'est supposée acquise. Les exemples CNIL du CDC ne sont pas convertis
en règles françaises imposées à UCG. L'applicabilité exacte et les références
sectorielles restent à confirmer avant une politique exécutable.

Prochaine étape : compléter la fiche par sous-catégorie avec les finalités,
responsables et durées applicables ; concevoir ensuite les évolutions nécessaires
pour les historiques protégés et l'effacement intermodules, puis les éprouver
en simulation sur fixtures. Une exécution réelle serait une opération distincte
avec autorisation explicite ; la préparation actuelle ne l'autorise pas.

**OPS-007, CAD-004 et REC-35 restent ouverts.** Vérification de cohérence des
comptages et du diff documentaire ; aucun test applicatif relancé pour ces trois
documents. Aucun code, configuration, dépendance ou donnée réelle modifié ;
aucun commit ni push effectué dans ce lot préparatoire.

## UCG-CAD-004 — Dossiers de personnes, mineurs et territoires (25 octobre 2026)

### Mandat et confirmations

Après le commit documentaire `83fa8e4`, l'utilisateur autorise le démarrage
prudent d'une fiche consacrée aux candidats, membres actifs et anciens membres.
Il confirme ensuite l'accueil de **mineurs**, des personnes en Tunisie et dans
d'autres pays, puis précise **zone MENA et Europe**. Ces zones restent à décliner
en pays ; elles ne déterminent ni l'hébergement ni le régime applicable à tous
les usages. Aucune modification des fixtures FR ou d'une organisation existante
n'est demandée ou réalisée.

### Fiche livrée et constats issus des sources

Le README contient la fiche « UCG-CAD-004 — Fiche de décision des dossiers de
personnes », rattachée au cadrage OPS-007. Huit sous-catégories PERS-01 à PERS-08
distinguent profil sportif, données administratives, lien au compte global,
profils/comptes de jeu, périodes, obligations de départ, événement de départ et
copies d'import/export. Les six tables du noyau restent celles de la matrice
précédente ; imports et artefacts sont des dépendances, pas des tables ajoutées
à ce décompte. Les finalités décrites sont proposées, à confirmer.

Sources examinées : migrations People et ajout de birth_date pour l'éligibilité,
PersonInput, PersonStatus, modèles/projections People, PersonService,
PersonDepartureService, TeamDepartureParticipant, TenantDepartureAccessService et
PersonImportService. Aucun accès à une base, migration, commande Artisan ou
processus de suppression n'est lancé.

Les champs administratifs chiffrés/projetés selon permission, la date de naissance
facultative, les statuts et les protections de suppression sont consignés sans
les assimiler à une prise en charge juridique complète des mineurs. Aucun
parcours dédié de représentant légal n'est identifié dans People examiné.
Le lien éventuel au compte global n'autorise pas sa suppression pour un tenant.

Les parcours couvrent les six statuts existants : candidate, trial, active,
unavailable, substitute et former. Une clôture de candidature ou d'essai doit
encore être définie comme événement vérifiable ; updated_at n'est pas choisi
comme substitut. L'opération de départ dédiée est distinguée du simple statut
former : sa date et ses effets d'accès ne sont pas supposés pour toute fiche
étiquetée former. Les périodes closes, obligations, preuves et références
intermodules nécessitent des décisions séparées.

### Validation et suite

Les durées, archives/habilitations, actions finales, seuils d'âge, représentants,
pays précis, responsables et modalités applicables restent à définir/valider.
La fiche ne réinterprète pas les références juridiques du cadrage précédent et
n'ajoute pas de durée légale ou d'obligation présentée comme validée. Le plan de
simulation décrit des fixtures futures Alpha/Beta, les refus sans date fiable,
comptes partagés, obligations et changements de situation ; il n'est pas exécuté.

Vérification documentaire : cohérence des identifiants PERS, six tables du noyau,
six statuts et diff. Aucun test applicatif relancé pour ces seuls documents.
Seuls README.md, le backlog et ce suivi sont modifiés ; aucun fichier applicatif,
configuration ou donnée réelle modifié. Aucun commit/push effectué dans ce lot.
**CAD-004, OPS-007 et REC-35 restent ouverts.** Prochaine étape : compléter les
décisions métier et pays précis, faire valider les règles applicables, puis
concevoir la simulation et les évolutions nécessaires.
