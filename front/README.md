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
