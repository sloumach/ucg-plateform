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
