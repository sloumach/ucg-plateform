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
