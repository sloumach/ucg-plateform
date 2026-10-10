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
| `429` | `TENANT_LIMIT_EXCEEDED` | borne technique d’import, export, fichier ou stockage atteinte |
| `500` | `INTERNAL_ERROR` | erreur inattendue masquée au client |

Les erreurs `500` ne contiennent jamais de classe d’exception, fichier, trace ou message
technique, même lorsque l’environnement local active le mode debug.

TEN-005 conserve cette enveloppe et le feedback partagé ARC-006. Le débit des
routes tenant est compté après authentification/résolution d’une organisation
autorisée, dans un budget propre à son UUID canonique. Les réponses de débit
exposent `X-RateLimit-Limit` et `X-RateLimit-Remaining` ; un refus inclut
`Retry-After` en secondes. Un quota de volume n’invente pas de délai de reprise :
il faut libérer des ressources ou modifier la borne côté opérateur.
`TENANT_RESOURCE_BUSY` est un conflit `409` localisé de verrou, sans détail
sur un autre tenant. Aucune trace, clé de cache ou URL d’objet n’est exposée.

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
  ci-dessous ; réservé au propriétaire ou administrateur d’une organisation active.

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

## Contexte tenant — UCG-TEN-002

Les deux endpoints de lecture du contexte sont protégés par Sanctum, le
middleware tenant et une Policy :

- `GET /api/v1/tenants/{uuid}/context`, source route ;
- `GET /api/v1/tenant/context`, source domaine approuvé exact.

Ils renvoient `data.organization_id`, `data.actor_user_id`, `data.slug`,
`data.timezone`, `data.language` et `meta.request_id`. Le contexte ne contient
pas de modèle Eloquent mutable et n’est pas persisté en session.
`TEN-003` ajoute une sélection distincte, avec révision et confirmation,
sans modifier la forme JSON de ces deux endpoints.

Exemple après authentification SPA, avec l’UUID autorisé :

```ts
const response = await fetch(
  apiBaseUrl + '/tenants/' + encodeURIComponent(organizationId) + '/context',
  { credentials: 'include', headers: { Accept: 'application/json' } },
)
// Vérifier response.ok avant de lire data ; traiter les erreurs structurées
// avec le système de feedback partagé et conserver meta.request_id.
```

| Situation | Réponse |
| --- | --- |
| session absente | `401 AUTHENTICATION_REQUIRED` |
| aucune source tenant, même avec un seul tenant possédé | `422 TENANT_CONTEXT_REQUIRED` |
| tenant inconnu, sans adhésion effective ni propriété, approbation invalide ou route/domaine divergents | `404 RESOURCE_NOT_FOUND` |
| tenant suspendu, en clôture ou archivé | `409 ORGANIZATION_INACTIVE` |
| Policy refusant une action dans un contexte résolu | `403 AUTHORIZATION_DENIED` |

Le message `TENANT_CONTEXT_REQUIRED` est : « Un contexte d’organisation valide
est requis pour cette opération. » Les erreurs conservent l’enveloppe centrale
`message/code/errors/meta`, consommée par le feedback frontend ; aucun nouveau
format de toast n’est introduit.

Les en-têtes `X-Tenant-ID`/`X-Organization-ID`, la query string, les cookies et
les champs du payload ne constituent jamais une source de contexte.
Un domaine ne contourne pas l’autorisation d’accès. Si route et domaine
approuvé existent, ils doivent désigner la même organisation.
`PUT /organizations/{uuid}` utilise maintenant ce contexte ; son Service ne
prend plus un UUID tenant libre pour choisir l’organisation à modifier et
revérifie rôle d’administration et statut sous verrou avant l’écriture.
Les lectures de gestion `GET /organizations` et `GET /organizations/{uuid}`
restent accessibles au propriétaire sans contexte métier actif.

## Adhésions, invitations et sélection — UCG-TEN-003

Tous les endpoints sont sous Sanctum. Les mutations SPA requièrent le cookie
CSRF et `X-XSRF-TOKEN`, comme la connexion.
Les collections sont paginées : `page=1..100000`, `per_page=1..100`.

| Endpoint sous /api/v1 | Usage et autorisation |
| --- | --- |
| `GET accessible-organizations` | Organisations actives accessibles ; rôles propres à chacune et membership_id |
| `GET active-organization` | Sélection actuelle, droits fraîchement résolus ; contexte nul si accès perdu |
| `POST active-organization` | Sélectionner organization_id autorisé ; nouvelle révision, confirmed=false |
| `POST active-organization/confirmation` | organization_id, revision, confirm=true ; confirme la sélection courante |
| `GET my-invitations` | Historique filtré par l’adresse vérifiée du compte, noms chargés sans N+1 |
| `PUT my-invitations/{uuid}` | Destinataire uniquement ; decision=accepted/declined, confirm=true |
| `DELETE my-memberships/{uuid}` | Départ du compte lui-même ; confirm=true, historique conservé |
| `GET tenants/{tenant}/memberships` | Administration de ce tenant |
| `PUT tenants/{tenant}/memberships/{uuid}` | Administration ; roles, status, starts_at, ends_at |
| `GET tenants/{tenant}/invitations` | Administration de ce tenant |
| `POST tenants/{tenant}/invitations` | Administration ; email, roles, starts_at facultatif, ends_at nullable |
| `DELETE tenants/{tenant}/invitations/{uuid}` | Révoquer une invitation en attente de ce tenant |

Les roles acceptés sont `member` et `administrator`. Seul le propriétaire
peut déléguer/modifier/révoquer une affectation d’administration.
Le statut propriétaire `owner` est retourné mais jamais accepté dans un payload.
Les périodes utilisent un ISO 8601 précis avec décalage,
par exemple `2026-10-10T14:30:00+02:00`, fin strictement postérieure au début.
Les instants sont persistés/retournés en UTC ; leur décalage d’entrée est respecté.
Les listes ne révèlent aucun profil global privé ; les membres exposent leur
identifiant de compte, pas les données d’une autre organisation.
Les identifiants d’organisation/compte, l’émetteur et la durée d’expiration ne
peuvent pas être modifiés depuis les payloads d’invitation/adhésion.
Un UUID d’accès situé dans un autre tenant donne le même 404 qu’un UUID inconnu.
La création d’invitations est limitée à 10/minute par acteur et tenant.

Une sélection retourne `data.context` (identité, rôles, permissions,
membership_id, is_owner), `data.revision` et `data.confirmed`.
Après sélection, envoyer `X-Tenant-Revision` sur les routes tenant, y compris
les lectures, et sur les réponses aux invitations/départs.
Ce header ne choisit pas le tenant : il empêche d’agir avec un ancien onglet.
Une révision absente/périmée produit `409 TENANT_CONTEXT_CHANGED` ;
une mutation tenant avant confirmation produit `409 TENANT_CONFIRMATION_REQUIRED`.
Les parcours globaux acceptation/refus/départ demandent toujours confirm=true
pour leur cible propre ; ils ne choisissent pas le contexte métier depuis le payload.
Un client API explicite sans sélection de session conserve le parcours route
TEN-002 : autorisation serveur obligatoire, sans changement antérieur à confirmer.

Exemple TypeScript avec le client partagé (session/CSRF/erreurs structurées) :

```ts
const selection = await tenancyRequest<ApiSuccessResponse<ActiveOrganization>>(
  'active-organization',
  { method: 'POST', body: { organization_id: organizationId } },
)
const revision = selection.data.revision
if (!revision) throw new Error('Sélection non établie')
// Demander à la personne de confirmer explicitement l’organisation affichée.
await tenancyRequest('active-organization/confirmation', {
  method: 'POST',
  body: { organization_id: organizationId, revision, confirm: true },
})
await tenancyRequest('tenants/' + organizationId + '/invitations', {
  method: 'POST', revision,
  body: { email: 'membre@example.test', roles: ['member'],
    starts_at: '2026-10-10T14:30:00+02:00', ends_at: null },
})
// Consommer notification via le provider partagé ; afficher errors près des champs.
```

Codes 409 supplémentaires : `INVITATION_ALREADY_PENDING`,
`INVITATION_EXPIRED`, `INVITATION_ALREADY_CLOSED`, `INVITATION_PERIOD_ENDED`,
`MEMBERSHIP_ALREADY_EXISTS`, `OWNER_MEMBERSHIP_PROTECTED` et
`MULTIPLE_ORGANIZATIONS_FORBIDDEN`. L’exclusivité globale, facultative et
multi-organisations par défaut, est documentée dans le README et ORG-03.
Elle ne supprime ni compte, ni appartenance, ni historique automatiquement.
L’acceptation demande un compte existant à adresse vérifiée ; la création
et la vérification Identity restent hors de TEN-003.
