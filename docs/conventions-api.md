# Conventions API UCG

## Version et format

Les endpoints applicatifs sont exposés sous `/api/v1`. Les clients envoient
`Accept: application/json`. Les clés JSON utilisent le `snake_case`, tandis que le client
TypeScript adapte les données à ses conventions internes lorsque nécessaire.

## Réponse réussie

Une Resource ou `ApiResponseFactory` produit toujours une enveloppe contenant `data` et `meta`.

```json
{
  "data": {
    "id": 42,
    "name": "UCG Admin"
  },
  "meta": {
    "request_id": "80b754c4-09dc-4ef2-9240-7bf741101105"
  }
}
```

Une action interactive peut ajouter une notification typée :

```json
{
  "data": null,
  "meta": {
    "request_id": "80b754c4-09dc-4ef2-9240-7bf741101105"
  },
  "notification": {
    "type": "success",
    "message": "Déconnexion effectuée."
  }
}
```

Les Resources API héritent de `App\Http\Api\ApiResource`. Les réponses sans Resource passent
par `App\Http\Api\ApiResponseFactory`.

## Réponse en erreur

```json
{
  "message": "Les données transmises sont invalides.",
  "code": "VALIDATION_FAILED",
  "errors": {
    "email": ["L’adresse e-mail est obligatoire."]
  },
  "meta": {
    "request_id": "80b754c4-09dc-4ef2-9240-7bf741101105"
  }
}
```

`errors` est un objet vide lorsqu’aucune erreur de champ n’existe. Le message reste destiné à
l’utilisateur ; `code` est le contrat stable utilisé par les clients.

| Statut | Code standard | Usage |
| --- | --- | --- |
| `401` | `AUTHENTICATION_REQUIRED` | session absente ou invalide |
| `403` | `AUTHORIZATION_DENIED` | Policy ou Gate refuse l’action |
| `404` | `RESOURCE_NOT_FOUND` | route ou modèle introuvable |
| `409` | code métier ou `RESOURCE_CONFLICT` | état concurrent ou transition impossible |
| `422` | `VALIDATION_FAILED` ou code métier | entrée invalide ou règle métier refusée |
| `429` | `RATE_LIMIT_EXCEEDED` | limite de requêtes atteinte |
| `500` | `INTERNAL_ERROR` | erreur inattendue masquée au client |

Les erreurs `500` ne contiennent jamais de classe d’exception, fichier, trace ou message
technique, même lorsque l’environnement local active le mode debug.

## Corrélation

`AssignRequestId` génère un UUID pour chaque requête. Il est renvoyé à deux endroits :

- en-tête HTTP `X-Request-ID` ;
- `meta.request_id` dans le JSON.

Les deux valeurs sont identiques. Cet identifiant est ajouté au contexte de journalisation
Laravel afin de relier une erreur frontend et une requête HTTP. Les futurs jobs devront le
transporter explicitement lorsqu’ils poursuivent le même traitement de façon asynchrone.

## DTO et exceptions métier

- Un DTO applicatif est `readonly` et étend `App\Support\Data\DataTransferObject`.
- Un DTO ne dépend jamais d’une Request HTTP ; la couche Presentation construit le DTO après
  validation.
- Les secrets comme les mots de passe ne sont jamais sérialisés ni journalisés.
- Une exception métier étend `App\Exceptions\DomainException` et expose un code stable.
- Un conflit métier étend `DomainConflictException` pour obtenir une réponse `409`.
- Le renderer central convertit les exceptions Laravel et métier ; les controllers ne dupliquent
  pas la construction des erreurs JSON.

## Client TypeScript

Les contrats partagés du frontend se trouvent dans `front/src/api/contracts.ts` :
`ApiSuccessResponse<T>`, `ApiErrorResponse`, `ApiMeta` et `ApiNotification`. Le client doit
afficher le message fonctionnel et conserver `meta.request_id` pour le support, sans dépendre du
texte comme identifiant logique.

## Exemples

Vérifier l’état de l’API :

```powershell
curl.exe --header "Accept: application/json" http://localhost:8000/api/v1/system/status
```

Appeler une route protégée sans session pour vérifier le contrat `401` :

```powershell
curl.exe --header "Accept: application/json" http://localhost:8000/api/v1/auth/me
```

## Organisations — UCG-TEN-001

Sous une session SPA Sanctum avec protection CSRF :

- `GET /api/v1/organizations?page=1&per_page=20` : organisations possédées
  uniquement, pagination bornée à 100 éléments et ordre stable par UUID.
- `GET /api/v1/organizations/{uuid}` : détail de l’organisation possédée.
- `PUT /api/v1/organizations/{uuid}` : remplacement complet des paramètres
  ci-dessous ; réservé au propriétaire d’une organisation active.

Exemple dans le frontend, après connexion et initialisation du cookie CSRF ;
`apiBaseUrl` est l’URL configurée de l’API et `organizationId` l’UUID autorisé :

```ts
const xsrfCookie = document.cookie.split('; ')
  .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
if (!xsrfCookie) throw new Error('Cookie CSRF absent')

const response = await fetch(apiBaseUrl + '/organizations/' + organizationId, {
  method: 'PUT',
  credentials: 'include',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.slice('XSRF-TOKEN='.length)),
  },
  body: JSON.stringify({
    name: 'Ultra Cyber Game',
    timezone: 'UTC',
    language: 'fr',
    country: 'FR',
    settings: { week_starts_on: 1, date_format: 'd/m/Y' },
  }),
})
// Traiter response.ok, les erreurs structurées et la notification via
// les conventions partagées avant d’afficher un succès.
```

Une organisation d’un autre propriétaire ou inexistante renvoie le même `404`,
sans charger ses paramètres. Les champs `id`, `slug`, `owner_user_id`,
`organization_id` et `status` sont interdits dans le payload de modification.
Les paramètres inconnus dans `settings` sont refusés.
Le succès inclut une notification française de type `success`.

Les codes métier supplémentaires sont `ORGANIZATION_INACTIVE`,
`ORGANIZATION_TRANSITION_DENIED`, `ORGANIZATION_SLUG_TAKEN`,
`ORGANIZATION_IDENTITY_IMMUTABLE`, `ORGANIZATION_AUDIT_IMMUTABLE`,
`ORGANIZATION_ACCOUNT_INVALID` et `ORGANIZATION_PILOT_OWNER_CONFLICT`.
Les créations et transitions ne disposent pas d’endpoint HTTP : elles passent
par les commandes opérateur documentées dans le README en attendant les
autorisations plateforme.
