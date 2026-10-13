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

Le parcours Identity expose aussi :

- `POST /api/v1/auth/forgot-password` et `POST /api/v1/auth/reset-password` ;
- `POST /api/v1/auth/email/verification-notification` puis le lien signé
  `GET /api/v1/auth/email/verify/{id}/{hash}` ;
- `POST /api/v1/auth/sessions/revoke-others`, avec confirmation du mot de passe.

La demande de récupération renvoie le même succès pour une adresse connue ou
inconnue. Les mutations d’une SPA utilisent d’abord `/sanctum/csrf-cookie`, puis
envoient le cookie de session et `X-XSRF-TOKEN`. Une limitation renvoie `429`
avec `Retry-After` en plus de l’enveloppe d’erreur standard.

## MFA — UCG-IAM-002

Tous les endpoints suivants demandent une session SPA Sanctum ; les mutations
conservent CSRF. Les réponses MFA utilisent une Resource et
`Cache-Control: no-store, private`. Aucun secret ni code ne doit être journalisé.

| Endpoint | Entrée / sortie |
| --- | --- |
| `GET /api/v1/auth/mfa` | `required`, `enabled`, `verified` |
| `POST /api/v1/auth/mfa/enrollment` | Mot de passe courant ; clé, URI `otpauth`, expiration de 600 s |
| `POST /api/v1/auth/mfa/confirmation` | `code` TOTP à six chiffres ; dix `recovery_codes` affichés une fois |
| `POST /api/v1/auth/mfa/challenge` | Exactement un de `code` ou `recovery_code` ; état MFA |
| `POST /api/v1/auth/mfa/recovery-codes` | Mot de passe et session MFA validée ; nouveaux codes |
| `DELETE /api/v1/auth/mfa` | Mot de passe et session MFA validée ; comptes sans privilège imposant le MFA |

`POST /auth/login` et `GET /auth/me` exposent désormais `data.mfa`. Une connexion
par mot de passe peut fournir une session limitée : consulter son profil,
vérifier l’e-mail, activer le MFA, effectuer le challenge ou se déconnecter.
L’accès métier et la révocation des autres appareils demandent aussi le MFA
lorsque le compte est enrôlé ou privilégié.

Le blocage MFA précède le binding métier et le contrôle du contexte tenant :
`403 MFA_ENROLLMENT_REQUIRED` ou `403 MFA_CHALLENGE_REQUIRED`. Une version de
session révoquée renvoie `401 AUTHENTICATION_REQUIRED`. Une préparation absente,
périmée ou liée à un ancien mot de passe/facteur renvoie
`409 MFA_ENROLLMENT_EXPIRED`. Un propriétaire, administrateur effectif ou opérateur
Horizon ne peut pas désactiver le MFA :
`409 MFA_REQUIRED_FOR_PRIVILEGED_ACCOUNT`. L’adresse doit être vérifiée avant
enrôlement (`409 EMAIL_VERIFICATION_REQUIRED`).

Les opérations MFA partagent cinq tentatives par minute par compte, complétées
par trente par IP. `429` conserve `Retry-After`, exposé par CORS et par
`ApiClientError.retryAfter`. Les erreurs de code sont localisées. Chaque code TOTP
est consommé une seule fois par compte, avec une tolérance d’une période de part
et d’autre de l’heure serveur.

Exemple React avec le client CSRF partagé :

```ts
import { mfaRequest } from './api/mfa'

const activation = await mfaRequest<{ secret: string; provisioning_uri: string; expires_in: number }>(
  'enrollment', { password: currentPassword },
)
// Présenter la clé sans l’envoyer à un générateur QR distant.
const confirmation = await mfaRequest<{ recovery_codes: string[] }>(
  'confirmation', { code: authenticatorCode },
)
// Afficher les codes une fois et demander leur conservation hors de l’application.
await mfaRequest('challenge', { recovery_code: savedRecoveryCode })
```

Remplacer l’authentificateur reprend le couple enrollment/confirmation après un
challenge réussi, éventuellement avec un code de récupération. L’ancien facteur
reste actif jusqu’à confirmation, puis les anciennes sessions et les anciens
codes sont révoqués. Le reset de mot de passe ne retire jamais le MFA.


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

## Rôles et permissions contextuels — UCG-IAM-003

Ces routes exigent session Sanctum, e-mail vérifié, contexte tenant autorisé et
preuve MFA si le compte est privilégié. Les services revérifient les droits ;
`context.permissions` est un snapshot d’affichage. Les mutations exigent CSRF,
sélection confirmée et `X-Tenant-Revision`, selon le parcours tenant existant.

| Endpoint sous /api/v1/tenants/{tenant} | Autorisation et réponse |
| --- | --- |
| `GET permission-catalogue` | `roles.assign` ; catalogue fermé, scope_types disponibles, can_manage |
| `GET roles` | `roles.assign` ; liste paginée |
| `POST roles` | `roles.manage` (propriétaire) ; 201 et notification |
| `PUT roles/{uuid}` | `roles.manage` ; version obligatoire, 200 |
| `GET role-assignments` | `roles.assign` ; affectations du tenant paginées |
| `GET my-role-assignments` | `organization.view` ; uniquement celles du compte |
| `POST role-assignments` | `roles.assign`, limites de délégation ; 201 |
| `PUT role-assignments/{uuid}` | Même contrôle ; version obligatoire, 200 |

Les listes acceptent `per_page=1..100` et `page=1..100000`. Les UUID d’un autre
tenant donnent 404 ; les listes exposent les identifiants des comptes, sans profil
Identity privé. Une version périmée donne 409 `AUTHORIZATION_VERSION_STALE` :
relire et demander une nouvelle confirmation, sans rejouer automatiquement.

Définition : `key` (slug stable, 80 caractères maximum), `name` (120 maximum),
`permissions` (liste non vide, distincte, issue du catalogue), `active` (booléen),
`version` pour PUT. UUID, clé et tenant immuables. `privileged` est calculé par
le serveur et interdit en entrée. Le catalogue configurable est :

- `organization.view`, `organization.update`, `memberships.manage`,
  `invitations.manage`, `roles.assign` ;
- `sports.read`, `sports.write`, `sports.validate`, `sports.publish` ;
- `administrative.read`, `administrative.write`, `administrative.validate` ;
- `financial.read`, `financial.write`, `financial.validate`.

Seules `organization.view` et `sports.read` sont non privilégiées. La gouvernance
exige le périmètre organisation. `roles.manage` reste réservé au propriétaire ;
wildcards, clés système et permissions plateforme sont exclus.

Affectation : `role_id`, `user_id` (membre actif ou propriétaire), `scope_type`,
`scope_id`, `status` (`active/suspended/revoked`), `starts_at`, `ends_at` nullable,
`version` pour PUT. Dates ISO 8601 avec décalage, stockées en UTC : début inclus,
fin exclue et strictement postérieure au début. La période doit rester dans
l’adhésion cible et dans les droits du délégué. Identité cible, rôle et périmètre
immuables : révoquer puis créer pour les changer. La révocation est définitive.

Le délégué doit détenir `roles.assign` au niveau organisation et chacune des
permissions accordées sur le périmètre cible pour toute la période demandée.
Un droit organisation couvre un périmètre précis validé ; une équipe ne couvre
pas une autre équipe. Une suspension/révocation conservant exactement la période
exige les droits actuels, sans prolonger une autorité passée. Auto-attribution,
réactivation ou extension de ses propres droits sont refusées. Le propriétaire
ne peut élargir ou réactiver une définition qui lui est encore affectée ; un
délégué autorisé peut lui attribuer des permissions métier.

Aujourd’hui `scope_types` vaut `["organization"]` et `scope_id` égale le tenant.
Équipe/jeu/cohorte/événement restent fermés jusqu’au raccordement de leurs modules.
IAM-004 fournit le contrat de projection ci-dessous ; définir une permission
n’implémente pas un module métier absent.

Exemple React après connexion, MFA et sélection, avec le client partagé :

```ts
import { tenancyRequest } from './api/tenancy'
import type { ApiSuccessResponse } from './api/contracts'
import type { OrganizationRole } from './api/roles'

// Tenant/révision relus, action confirmée explicitement par la personne.
await tenancyRequest('active-organization/confirmation', {
  method: 'POST', body: { organization_id: organizationId, revision, confirm: true },
})
const created = await tenancyRequest<ApiSuccessResponse<OrganizationRole>>(
  'tenants/' + organizationId + '/roles', {
    method: 'POST', revision,
    body: { key: 'coach', name: 'Coach', permissions: ['sports.read'], active: true },
  },
)
await tenancyRequest('tenants/' + organizationId + '/role-assignments', {
  method: 'POST', revision,
  body: { role_id: created.data.id, user_id: memberAccountId,
    scope_type: 'organization', scope_id: organizationId, status: 'active',
    starts_at: new Date().toISOString().slice(0, 19) + '+00:00', ends_at: null },
})
// Relire la version et confirmer de nouveau avant une modification réelle.
await tenancyRequest('tenants/' + organizationId + '/roles/' + created.data.id, {
  method: 'PUT', revision,
  body: { key: created.data.key, name: 'Coach lecture', permissions: ['sports.read'],
    active: true, version: created.data.version },
})
// Le client prépare CSRF ; restituer notification et erreurs via les composants partagés.
```

Conflits 409 : `ROLE_SELF_ESCALATION_FORBIDDEN`, `ROLE_DELEGATION_DENIED`,
`ROLE_PERIOD_OUTSIDE_MEMBERSHIP`, `ROLE_SCOPE_INCOMPATIBLE`,
`AUTHORIZATION_SCOPE_UNAVAILABLE`, `ROLE_SUBJECT_INVALID`, `ROLE_INACTIVE`,
`ROLE_ALREADY_EXISTS`, `ROLE_ASSIGNMENT_ALREADY_EXISTS`, `ROLE_ASSIGNMENT_REVOKED`,
`ROLE_ASSIGNMENT_IDENTITY_IMMUTABLE`, `ROLE_IDENTITY_IMMUTABLE`, `ROLE_LIMIT_REACHED`.
La validation donne 422 ; l’autorisation 403. Les mutations et révocations dues au
départ sont auditées dans leur transaction, avec référence contrainte au même tenant.

## Projection des champs — UCG-IAM-004

Les Policies et le Gate `tenant.permission` contrôlent l’action. Le contrat public
`TenantDataVisibility` contrôle ensuite chaque champ de sortie, à partir d’un
`TenantDataRecord` préparé par le module propriétaire et d’un schéma serveur
`FieldVisibility`. Le client ne choisit ni permission, ni schéma, ni tenant source.
Un champ absent du schéma ou interdit est omis ; sa valeur n’est pas remplacée
par `null`. Une valeur autorisée réellement nulle reste nulle.

| Données | Permission de lecture |
| --- | --- |
| Informations communes d’une organisation accessible | `organization.view` |
| Sportives | `sports.read` |
| Administratives confidentielles | `administrative.read` |
| Financières restreintes | `financial.read` |
| Configuration des rôles | `roles.assign` |

Les droits propriétaire/admin n’impliquent pas une lecture métier. Écriture,
validation et publication ne remplacent pas une permission de lecture. MFA,
dates d’effet, suspension et révocation sont vérifiés par `TenantAuthorization`.
Un tenant étranger provoque un 404 ; accès/périmètre refusé, un 403. Un schéma
inconnu ou une structure incompatible est une erreur de programmation serveur
et ne produit aucune réponse partiellement filtrée.

Exemple dans un service métier après lecture d’un enregistrement autorisé :

```php
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;

// $visibility est injecté via TenantDataVisibility ; contexte résolu côté serveur.
$data = $visibility->project($context,
    new TenantDataRecord($recordOrganizationId, $candidateData, $scopeType, $scopeId), [
        'name' => new FieldVisibility('organization.view'),
        'rank' => new FieldVisibility('sports.read'),
        'salary' => new FieldVisibility('financial.read'),
        'profile' => new FieldVisibility('organization.view', [
            'address' => new FieldVisibility('administrative.read'),
        ]),
    ]);
// La Resource, l’export ou la notification reçoit seulement $data.
```

Les objets et listes d’objets exigent un schéma explicite pour leurs enfants ;
une feuille peut contenir seulement un scalaire, null ou une liste de scalaires.
Les enfants partagent le tenant et le périmètre du record ; des ressources de
périmètres différents doivent être projetées comme records distincts. Le
repository reste responsable de la sélection des enregistrements autorisés.

`projectMany` accepte au plus 200 records par page/chunk et partage ses contrôles
de permission/périmètre pendant cet appel uniquement. Le prochain appel relit
les droits. Les sorties des rôles conservent leurs huit champs, leurs statuts
201/200, notifications Flasher, pagination et identifiant de requête.

Il n’existe pas encore de recherche ni d’export métier sportif/admin/finance.
Leur raccordement est obligatoire lors de leur implémentation : filtrer les
champs recherchables/triables et les enregistrements avant requête, puis projeter
les résultats, extraits, compteurs et fichiers avant diffusion. Les jobs doivent
restaurer le contexte et projeter au traitement, sans réutiliser les droits du
moment de la mise en queue. Le mail existant n’expose que le nom d’organisation
et le lien d’accès ; son worker exige désormais `invitations.manage` actuel.

Les exports complets de maintenance restent réservés à un opérateur autorisé sur
le serveur avec acteur vérifié, catalogue fermé et aucune URL publique. IAM-005
refuse les acteurs techniques actifs, avant génération et lecture. Ces sauvegardes
ne subissent pas une projection partielle incompatible avec leur restauration.

## Accès plateforme et support — UCG-IAM-005

Préfixe `/api/v1/platform` ; session Sanctum Identity valide, e-mail vérifié,
rôle global actif `superadmin`/`support`, MFA confirmé et preuve de session actuelle.
Quota de 30 requêtes/minute/opérateur. Les permissions tenant et les e-mails
Horizon ne donnent pas ce rôle. Pas de token API support ni d’impersonation.

| Route relative au préfixe | Résultat |
| --- | --- |
| `GET support-accesses` | Ses propres accès seulement, paginés |
| `POST tenants/{tenant}/support-accesses` | Ouverture explicite, 201 |
| `DELETE tenants/{tenant}/support-accesses/{access}` | Révocation définitive/idempotente, 200 |
| `GET tenants/{tenant}/support-accesses/{access}/organization` | Diagnostic `organization.inspect`, 200 |
| `GET tenants/{tenant}/support-accesses/{access}/access-summary` | Diagnostic `access.inspect`, 200 |

UUID tenant/accès dans l’URL. Corps d’ouverture : `reason` (10..500 caractères
après trim, sans secret), `duration_minutes` (entier 1..30), `actions` (liste
distincte de 1..2 valeurs parmi `organization.inspect`, `access.inspect`),
`confirm=true`. L’acteur, les dates, le statut et le rôle sont imposés par le
serveur ; `organization_id` dans le corps est interdit. Liste :
`per_page=1..100` (20 par défaut), `page=1..100000`.

La Resource contient seulement `id`, `organization_id`, `actor_user_id`, `reason`,
`actions`, `status`, `starts_at`, `expires_at`. Statut temporel `active/expired` ou
`revoked` ; il ne prouve pas une autorisation : chaque diagnostic vérifie aussi
le rôle et sa version actuelle. Début inclus, fin exclue, UTC. Aucune extension.
Cinq accès actifs maximum par couple tenant/opérateur, sinon 409
`SUPPORT_ACCESS_LIMIT`. La liste ne contient jamais les accès d’un autre opérateur.

Le diagnostic organisation expose `organization_id`, `status`, `timezone`,
`language`. Le diagnostic accès expose `organization_id`, `memberships`, `roles`,
`role_assignments` : des compteurs de lignes, sans noms, e-mails ni profils.
Aucune donnée sportive/admin/finance, aucune action de validation/publication.
Un tenant suspendu peut être diagnostiqué sans rendre ses opérations métier actives.

Validation : 422 ; MFA manquant : 403 avec le code Identity correspondant ;
session révoquée/changement de version MFA : 401 ; rôle/action/période refusés :
403 ; accès d’un autre tenant ou opérateur : 404. Après révocation ou changement
de version du rôle plateforme, un ancien accès ne peut pas être réutilisé.
Les réponses conservent `meta.request_id`/`X-Request-ID`, notifications compatibles
Flasher sur ouverture/révocation et pagination standard.

Exemple client avec une session opérateur déjà connectée et MFA vérifié ; le
client partagé prépare CSRF et transmet les cookies. Aucun tenant actif ou
confirmation de tenant métier n’est requis pour ce parcours explicite :

```ts
import { tenancyRequest } from './api/tenancy'
import type { ApiSuccessResponse } from './api/contracts'

type SupportAccess = { id: string; organization_id: string; expires_at: string }
const opened = await tenancyRequest<ApiSuccessResponse<SupportAccess>>(
  'platform/tenants/' + targetTenantId + '/support-accesses', {
    method: 'POST',
    body: { reason: 'Diagnostic incident de connexion', duration_minutes: 15,
      actions: ['organization.inspect'], confirm: true },
  },
)
const accessPath = 'platform/tenants/' + targetTenantId + '/support-accesses/' + opened.data.id
const diagnostic = await tenancyRequest<ApiSuccessResponse<{
  organization_id: string; status: string; timezone: string; language: string
}>>(accessPath + '/organization')
await tenancyRequest(accessPath, { method: 'DELETE' })
// Restituer notifications/erreurs via les composants partagés.
```

L’accès et son audit sont créés atomiquement. Une lecture réussie n’est diffusée
qu’après écriture de sa trace ; l’indisponibilité de l’audit fait échouer la requête.
Les refus de diagnostic de son propre accès sont conservés sans lire les données.
Le journal transverse IAM-006 ci-dessous couvre également les refus authentifiés
403/404 des routes tenant, dont ceux avant identification d’un accès support.
La conservation est désormais sans limite, sans purge.
Aucun écran React support ou endpoint d’attribution des rôles globaux dans ce lot.

## Journal d’audit — UCG-IAM-006

| Route GET | Autorisation | Sources |
| --- | --- | --- |
| `platform/audit-events` | superadmin actif | `identity.security`, `identity.platform_roles`, `identity.audit` |
| `tenants/{tenant}/audit-events` | propriétaire/admin ou grant effectif `audit.view` | `tenancy.lifecycle`, `tenancy.access`, `tenancy.support`, `tenancy.audit` |

Session valide, e-mail vérifié et preuve MFA actuelle sont requis. Un rôle support
ne donne pas accès au journal global. Un compte technique actif est refusé pour
le journal tenant. `audit.view` est privilégiée et limitée à l’organisation entière.
Un grant révoqué/expiré ne donne plus accès. Le contexte tenant est résolu côté
serveur avec les règles existantes de révision du tenant actif.

Filtres facultatifs : `source` (catalogue ci-dessus), `action` (valeur littérale),
`actor_user_id` (entier positif), `from`/`until` (ISO 8601 avec secondes et offset,
exemple `2026-10-11T12:00:00+00:00`), `per_page` (1..100, défaut 20), `cursor`.
`until` doit être postérieur ou égal à `from`. `page`, `sort`,
`organization_id` et `user_id` sont refusés. Encoder dates et curseurs.

`data[]` contient `id`, `source`, `organization_id` (null pour Identity),
`actor_user_id` (null si acteur non établi), `action`, `target:{type,id}`,
`before`/`after` (objets), `reason_code`, `request_id`, `ip_address`
(null si absente de la source) et `occurred_at` UTC.
Les motifs catégoriels sont `authentication_event`, `governance_change`,
`support_operation`, `authorized_review`, `authorization_refused` ou `domain_change`.
Motifs libres, profils, e-mails, subject hashes et user agents sont omis.
Les états restituent seulement les clés/valeurs autorisées : statut, flags,
rôle/version, permissions, périmètre et dates techniques. Les anciennes clés
inconnues sont omises, les historiques d’origine ne sont pas réécrits. Les nouveaux
écrivains rejettent les clés/valeurs non autorisées avant persistance.

`meta` contient `request_id`, `per_page`, `next_cursor` (null en dernière page)
et `retention:{mode:"unlimited",purge_enabled:false}`. Aucun total/page/last_page :
ne pas utiliser le type client `Page<T>` fondé sur l’offset.
Ordre décroissant date/source/UUID, curseur chiffré lié au compte/tenant/filtres.
Curseur modifié ou réutilisé ailleurs : 422. Les consultations sont elles-mêmes
tracées avant diffusion ; une panne d’audit produit 500 sans données du journal.

Invité/session révoquée : 401 ; permission/MFA manquants : 403 ; tenant étranger :
404 ; filtre/curseur invalide : 422 ; quota de lecture 30/minute par compte : 429.
Les refus authentifiés 403/404 des routes tenant et du journal global ajoutent une
trace Identity minimale : acteur, nom de route serveur, code HTTP, request ID, IP.
Aucune URL, query, corps ou UUID du tenant demandé n’est enregistré dans cette trace.
Les invités et sessions déjà invalidées ne passent pas par ce middleware ; les routes
d’authentification conservent leur journal de sécurité IAM-001.

Exemple tenant sélectionné, session connectée et MFA validé via le client partagé :

~~~ts
import { tenancyRequest } from './api/tenancy'
type AuditEvent = {
  id: string; source: string; organization_id: string | null
  actor_user_id: number | null; action: string; target: { type: string; id: string | null }
  before: Record<string, unknown>; after: Record<string, unknown>
  reason_code: string; request_id: string; ip_address: string | null; occurred_at: string
}
type AuditPage = {
  data: AuditEvent[]
  meta: { request_id: string; per_page: number; next_cursor: string | null
    retention: { mode: 'unlimited'; purge_enabled: false } }
}
const filters = new URLSearchParams({ source: 'tenancy.access', per_page: '20' })
const path = 'tenants/' + activeTenantId + '/audit-events'
const first = await tenancyRequest<AuditPage>(path + '?' + filters, { revision: activeRevision })
if (first.meta.next_cursor) {
  filters.set('cursor', first.meta.next_cursor)
  const next = await tenancyRequest<AuditPage>(path + '?' + filters, { revision: activeRevision })
  // Afficher next.data et traiter les erreurs via les composants partagés.
}
~~~

Conservation sans limite décidée par l’utilisateur : aucune purge physique,
programmée ni exclusion automatique des événements anciens. `audit:retention`
affiche la politique sans écrire. Une politique bornée future nécessitera une
décision et une implémentation dédiées avec protection des événements de sécurité
et procédures en cours ; changer une configuration ne l’active pas.

## Personnes tenant — UCG-HUM-001

| Méthode | Route relative à `/api/v1` | Accès |
| --- | --- | --- |
| POST | `tenants/{tenant}/people` | `sports.write`, plus `administrative.write` si un champ administratif est fourni |
| GET | `tenants/{tenant}/people/{person}` | `sports.read` ou `administrative.read`, puis projection par champ |
| PATCH | `tenants/{tenant}/people/{person}` | droit d’écriture de chaque catégorie fournie ; `version` actuelle requise |

Sanctum/session/MFA, tenant explicite et budget TEN-005 existants. Les mutations
conservent CSRF et `tenant.confirmed`/`X-Tenant-Revision`. Une UUID de personne
étrangère ou absente renvoie 404 ; les permissions ne sont jamais implicites
pour un propriétaire/administrateur. Les comptes techniques actifs sont refusés.

POST exige `pseudonym` (80 caractères max), `language` (catalogue tenant configuré),
`timezone` IANA et `status` (`candidate`, `trial`, `active`, `unavailable`,
`substitute`, `former`). `biography_public` est facultative/null, 4 000 caractères.
Champs administratifs facultatifs/null : `user_id` entier positif, `first_name`
et `last_name` (120 caractères), `contact_email` (254), `contact_phone` (32),
`biography_internal` (4 000). Le lien vise un compte vérifié déjà effectif dans
ce tenant ; il ne donne aucun droit. Aucun rapprochement par pseudo/e-mail.

PATCH ne modifie que les champs présents et exige au moins un champ métier.
Un null explicite efface un champ nullable avec son droit d’écriture ; un champ
omis est conservé. `version` UUID évite d’écraser une modification concurrente.
`id`, `organization_id`, permissions et toutes les clés inconnues sont refusés.
Le statut ne lance aucune révocation des accès, à traiter dans HUM-004.

Réponse : `data.id`/`data.version`, champs généraux avec `sports.read`, champs
administratifs avec `administrative.read`. Les clés non autorisées sont absentes,
y compris après écriture ; écrire ne donne pas le droit de lire. Le nom « public »
de la biographie ne crée pas de route publique. Rendre ces textes avec échappement.
`meta.request_id` et notifications de succès/erreur suivent le contrat commun.

| Statut/code | Situation |
| --- | --- |
| 409 `PERSON_VERSION_STALE` | version concurrente |
| 409 `PERSON_ACCOUNT_UNAVAILABLE` | compte absent, non vérifié, étranger ou sans adhésion effective ; message identique |
| 409 `PERSON_ACCOUNT_ALREADY_LINKED` | compte déjà lié à une fiche du même tenant |
| 422 `VALIDATION_FAILED` | champ invalide/inconnu, version absente ou patch vide |
| 500 `INTERNAL_ERROR` | panne de persistance/audit, mutation annulée |

Le client existant gère cookies, CSRF et révision ; exemple après connexion/MFA
et confirmation de l’organisation :

```ts
import { createPerson, getPerson, updatePerson } from '../api/people'

const created = await createPerson(organizationId, {
  pseudonym: 'Camille', language: 'fr', timezone: 'Europe/Paris', status: 'active',
}, activeRevision)
const current = await getPerson(organizationId, created.data.id, activeRevision)
await updatePerson(organizationId, current.data.id, {
  pseudonym: 'Camille UCG', version: current.data.version,
}, activeRevision)
```

Les méthodes publiques du Service valident aussi leurs DTO et revérifient
les droits. `PeopleDirectory` fournit le snapshot filtré aux modules autorisés.
Les listes/recherche/écrans restent HUM-003, les profils de jeu HUM-002 et les
affectations/départs HUM-004. Aucun DELETE, import, photo ou export métier ajouté.

## Profils de jeu et comptes externes — UCG-HUM-002

Base profils : `tenants/{tenant}/people/{person}/game-profiles`.
Base comptes : base profils + `/{profile}/external-accounts`.

| Méthode | Route | Permission |
| --- | --- | --- |
| GET | `tenants/{tenant}/game-profile-catalogue` | `sports.read` |
| GET | base profils ou comptes | `sports.read`, pagination `per_page` 1..100, `page` 1..100000 |
| GET | base profils + `/{profile}` ou base comptes + `/{account}` | `sports.read` |
| POST | base profils ou comptes | `sports.write`, confirmation tenant |
| PATCH | ressource profile/compte | `sports.write`, confirmation et `version` requise |

Auth/session/MFA, CSRF, révision et quotas tenant existants. Toutes les actions
emploient une Form Request et une Policy, puis revérifient dans les Services.
Une personne/profil/compte d’un autre tenant ou parent renvoie 404. Les comptes
techniques actifs et les droits métiers implicites restent refusés.

Création profil : `game_key` (64 caractères), `platform_key`, `region_key` (32),
clés minuscules alphanumériques avec tirets, validées contre le catalogue serveur.
Facultatifs : `rank` et `game_role` (texte/null, 80), `observed_at` (date/null),
`status` (`active` par défaut, ou `archived`). Une seule fiche par personne/contexte.
Ces libellés ne valent ni permission, ni éligibilité, ni mesure comparable entre jeux.

Création compte : `provider_key` configuré pour le jeu/plateforme (32),
`external_id` facultatif/null (191, ASCII imprimable sans espaces, casse conservée),
`display_name` facultatif/null (120), `observed_at`, `status`. Au moins un identifiant
stable ou un nom est nécessaire. Le serveur copie le contexte du profil, jamais
un payload client. Même nom sans identifiant possible plusieurs fois ; aucune fusion.
Unicité de l’identifiant stable sur tenant/jeu/plateforme/région/fournisseur, y compris
après archivage. Aucune recherche globale ni retour de la fiche en conflit.

PATCH conserve les champs omis et refuse toute clé d’identité/contexte, même null.
Un identifiant externe absent peut être renseigné une fois ; un identifiant établi
ne peut être remplacé/effacé. Le fournisseur et le profil parent restent immuables.
L’archivage seul reste autorisé après retrait d’une référence ou archivage du parent.
Réactivation et autres éditions nécessitent le contexte toujours disponible.
Pas de DELETE ou réaffectation automatique ; conservation de l’historique.

`observed_at` utilise `YYYY-MM-DDTHH:mm:ss±HH:mm`, non future, convertie en UTC.
`freshness` vaut `unknown`, `recent` ou `stale` selon la date et le seuil serveur
(30 jours par défaut, configurable). Changer rang/rôle ou identifiant/nom sans date
met l’observation à null. `data_source=manual` et `ownership_verified=false`
sur les comptes indiquent que la plateforme n’a vérifié ni propriété ni informations
chez un éditeur. Aucun mot de passe, token ou secret n’est accepté comme champ.

La réponse ne contient que `id`/`version` et les champs autorisés par `sports.read` ;
écrire n’accorde pas le droit de lire. Les comptes ont leur propre pagination.
Le nombre de comptes d’un profil comprend les archives, sans tableau imbriqué massif.
`meta.request_id`, pagination et notifications suivent les conventions communes.
Les limites techniques initiales sont 50 profils/personne et 20 comptes/profil,
archives incluses ; pas de plan commercial implicite.

Le catalogue renvoie au maximum 100 jeux, leurs plateformes/fournisseurs et zones
actifs, filtrés par activation du tenant. Les clés restent des strings extensibles ;
aucun enum fixe ne bloque les ajouts. Les régions initiales `global`, `eu`, `na`,
`apac` sont des zones applicatives, pas des shards d’éditeur. Depuis TEAM-001,
les références et activations proviennent de la base ; config/game_profiles.php
ne fournit que la source initiale de migration et le seuil de fraîcheur.

| Statut/code | Situation |
| --- | --- |
| 409 `GAME_CONTEXT_UNAVAILABLE` | référence absente/désactivée ou non activée pour le tenant |
| 409 `GAME_CATALOGUE_INVALID` | catalogue stocké incohérent ou indisponible |
| 409 `GAME_PROFILE_ALREADY_EXISTS` | contexte déjà présent pour cette personne |
| 409 `EXTERNAL_ACCOUNT_ALREADY_LINKED` | identifiant déjà réservé dans le même contexte tenant |
| 409 `GAME_PROFILE_VERSION_STALE` / `EXTERNAL_ACCOUNT_VERSION_STALE` | version obsolète |
| 409 `EXTERNAL_ACCOUNT_IDENTITY_IMMUTABLE` | remplacement/effacement d’identifiant établi |
| 409 `GAME_PROFILE_ARCHIVED` | modification de compte dans un profil archivé |
| 409 `GAME_PROFILE_LIMIT_REACHED` / `EXTERNAL_ACCOUNT_LIMIT_REACHED` | limite technique atteinte |
| 422 `VALIDATION_FAILED` | entrée, pagination, version ou patch vide invalide |
| 500 `INTERNAL_ERROR` | panne de persistance/audit, transaction annulée |

Exemple après connexion, MFA et confirmation tenant ; le client gère CSRF et cookies :

```ts
import { getGameProfileCatalogue, createGameProfile, createExternalGameAccount, listGameProfiles } from '../api/gameProfiles'

const catalogue = await getGameProfileCatalogue(organizationId, activeRevision)
// Choisir des clés disponibles dans catalogue.data.games.
const profile = await createGameProfile(organizationId, personId, {
  game_key: 'valorant', platform_key: 'pc', region_key: 'eu',
}, activeRevision)
await createExternalGameAccount(organizationId, personId, profile.data.id, {
  provider_key: 'riot', display_name: 'Camille#EU',
}, activeRevision)
const page = await listGameProfiles(organizationId, personId, { perPage: 20, page: 1, revision: activeRevision })
// Afficher les textes avec échappement et gérer les erreurs via les composants partagés.
```

Aucun écran, OAuth, synchronisation éditeur, import ou publication publique dans
ce lot. Les interfaces et recherche restent HUM-003 ; catalogue complet TEAM-001.

## Liste et recherche des personnes — UCG-HUM-003

GET `tenants/{tenant}/people` exige sports.read ou administrative.read au périmètre
organisation courant ; auth/session/MFA, révision et quotas existants s’appliquent.
La Resource renvoie `data` (résumés), `meta.request_id`, métadonnées de pagination
et liens conservant les filtres validés. Aucune donnée d’une autre organisation
n’entre dans les résultats, compteurs ou tris.

| Paramètre | Valeurs / comportement | Droit supplémentaire |
| --- | --- | --- |
| page | 1..10000, défaut 1 | — |
| per_page | 1..100, défaut 20 | — |
| view | general (défaut), sports, administrative | droit de lecture de la vue ciblée |
| sort | id (défaut), pseudonym, status ; départage UUID | sports.read pour pseudonym/status |
| direction | asc (défaut), desc | — |
| q | préfixe de pseudonyme, 2..80 caractères, %/_/! littéraux | sports.read |
| status | candidate, trial, active, unavailable, substitute, former | sports.read |
| language | langue prise en charge par tenancy.languages | sports.read |
| game_key / platform_key | clés valides, 64/32 caractères ; même profil actif | sports.read |
| account_linked | linked ou unlinked | administrative.read |

Les paramètres inconnus/vides/invalides donnent 422 `VALIDATION_FAILED` avec
`errors` localisés. Un filtre/tri/vue sans permission donne 403 même sur liste vide,
afin de ne pas révéler une valeur masquée par un nombre de résultats. Aucun filtre
first_name/last_name/contact_email ou autre identité administrative chiffrée n’est
accepté ; aucune recherche sur les alias/identifiants externes. La casse est traitée
par LOWER natif ; accents non normalisés. Des clés de jeu historiques peuvent être
filtrées, mais seules les occurrences de profil actif correspondent.

Résumé : id/version, puis pseudonym/language/timezone/status/game_profiles_count
si sports.read et vue générale/sportive ; user_id/first_name/last_name/contact_email
si administrative.read et vue générale/administrative. Biographies et téléphone
restent réservés au détail. game_profiles_count comprend les archives. Aucun tableau
de profils/comptes n’est imbriqué ; utiliser leurs endpoints paginés HUM-002.

Les routes POST, GET unitaire et PATCH de HUM-001/HUM-002 conservent leurs contrats.
Le frontend utilise les valeurs modifiées seulement et la version de détail,
confirme l’organisation avant écriture, conserve les brouillons sur 422, recharge
sur conflit sans rejeu automatique et efface les données lors d’un changement de
tenant/révision/droits. Les vues de détail affichent les champs permis reçus du
serveur ; masquer un champ dans l’interface ne remplace jamais IAM-004.

Exemple après connexion et sélection de l’organisation :

```ts
import { listPeople, getPerson, updatePerson } from '../api/people'
import { tenancyRequest } from '../api/tenancy'

const page = await listPeople(organizationId, {
  view: 'sports', q: 'Ca', status: 'active', sort: 'pseudonym', direction: 'asc',
}, { page: 1, perPage: 10, revision: activeRevision })
const first = page.data[0]
if (first) {
  const detail = await getPerson(organizationId, first.id, activeRevision)
  // Dans le gestionnaire du bouton de confirmation explicite de l’organisation.
  await tenancyRequest('active-organization/confirmation', {
    method: 'POST', body: { organization_id: organizationId, revision: activeRevision, confirm: true },
  })
  await updatePerson(organizationId, detail.data.id, {
    pseudonym: 'Camille', version: detail.data.version,
  }, activeRevision)
}
```

Les protections d’historique
restent en place : pas de DELETE. Le statut former ne révoque aucun accès ; départ
effectif dans HUM-004. Aucune migration, export métier ou import dans HUM-003.

## HUM-004 — Périodes, checklist et départ des personnes

Base : `/api/v1/tenants/{tenant}/people/{person}`. UUID tenant/personne/record,
session authentifiée MFA, tenant actif et autorisation métier restent obligatoires.
Les mutations exigent également CSRF, `X-Tenant-Revision` et confirmation tenant,
avec limitation de fréquence existante. Aucune suppression physique.

| Route | Méthode | Permission |
| --- | --- | --- |
| `/periods` | GET | sports.read |
| `/periods` | POST | sports.write |
| `/periods/{record}` | PATCH | sports.write |
| `/departure-items` | GET | administrative.read |
| `/departure-items` | POST | administrative.write |
| `/departure-items/{record}` | PATCH | administrative.write |
| `/departure` | GET | sports.read ou administrative.read |
| `/departure` | POST | sports.write + administrative.write + memberships.manage + invitations.manage |

Lecture paginée `page=1..10000`, `per_page=1..100` (client 10). Résultats bornés
au tenant et à la personne ; ressources projetées IAM-004, aucune lecture accordée
par le seul droit d’écriture. Auteur sans lecture reçoit les métadonnées id/version
du résultat. GET departure expose departed/person_version et, si départ enregistré,
id/person_id/departed_at ; reason_key/pending_items_count uniquement aux lecteurs
administratifs. Aucun identifiant du compte historique ni acteur dans cette vue.

Période : kind `assignment|unavailability`, starts_at ISO8601 secondes + offset,
ends_at nullable, role_key obligatoire pour assignment (`player|substitute|captain|
coach|analyst|staff`), absent/null pour unavailability. Aucun motif médical.
Intervalle `[début, fin)` avec fin strictement après début ; chevauchement des périodes
non annulées de même type/rôle refusé, adjacence autorisée. `status=active|cancelled`
à la création/modification ; closed réservé à la clôture serveur. PATCH exige
version UUID et accepte starts_at/ends_at/status uniquement. Une période close ou
annulée reste consultable mais ne se modifie plus. 1000 périodes maximum par personne,
historique inclus. GET ajoute meta.unavailable_now et effective sur chaque période.

Checklist : kind `equipment|obligation`, title non blanc ≤120, notes nullable ≤4000,
due_at ISO8601 nullable, status `open|resolved|waived`. Kind immuable. PATCH exige
version ; title/notes/due_at/status seuls modifiables. Date completed_at calculée
par le serveur, remise à null en réouverture ; waived exige une note non blanche.
200 éléments maximum, historique inclus. GET ajoute meta.pending_items_count,
nombre total d’éléments ouverts de cette personne. Title/notes chiffrés au repos ;
jamais dans l’audit ni projection sportive. Régularisation autorisée après départ.

POST departure exige version de personne, reason_key
`resignation|end_of_contract|decision|other`, confirm accepté et
acknowledge_pending=true si des éléments sont ouverts. Pas de date caller : départ
immédiat. Champs inconnus refusés. Transaction unique ferme/annule les périodes,
inscrit le départ, marque former, retire adhésion/rôles/invitations en attente du
compte actuellement lié dans ce tenant. Aucune révocation dans un autre tenant.
Le propriétaire doit d’abord transférer la propriété ; la délégation des rôles
administratifs reste réservée au propriétaire. Auto-départ permis si autorisé.
Réponse d’action : id/person_id/person_version/departed, sans nouvelle lecture après
révocation. Rejeu autorisé avec même version source/motif renvoie le même départ ;
aucun rejeu si l’acteur a perdu ses droits. Une seule clôture par personne.

Statut descriptif seul ou indisponibilité n’altèrent pas IAM. Après départ formel,
status/user_id scellés, périodes et nouveaux profils/comptes externes refusés ;
archivage des profils existants permis. Réadmission métier contrôlée future.
Le départ générique Tenancy ne crée pas automatiquement un registre People.

Erreurs : 422 payload invalide/inconnu ; 403 droits/MFA insuffisants ;
404 tenant/personne/record absent du contexte ; 409 confirmation tenant manquante,
révision obsolète, conflit de version,
chevauchement, limite atteinte, propriétaire protégé, éléments non reconnus ou
personne déjà clôturée. Ne jamais rejouer automatiquement un conflit.

Exemple à intégrer au gestionnaire de confirmation explicite, après sélection
tenant et confirmation serveur via le client tenancy existant :

```ts
import { createPersonPeriod, createDepartureItem, departPerson } from '../api/personLifecycle'

await createPersonPeriod(organizationId, personId, {
  kind: 'assignment', role_key: 'player',
  starts_at: '2026-10-12T09:00:00+02:00', ends_at: null,
}, activeRevision)
await createDepartureItem(organizationId, personId, {
  kind: 'equipment', title: 'Maillot à restituer', status: 'open',
}, activeRevision)
// Dans le bouton « Confirmer le départ », après affichage du compte concerné
// et reconnaissance humaine des éléments ouverts ; version de fiche courante.
await departPerson(organizationId, personId, {
  version: personVersion, reason_key: 'resignation',
  confirm: true, acknowledge_pending: true,
}, activeRevision)
// Recharger l’organisation active et ses droits après la révocation.
```

Ces helpers assurent CSRF/révision et enveloppes typées ; la confirmation tenant
reste celle du hook de mutation. Aucun appel automatique à un fournisseur externe,
aucune pièce jointe ou opération financière ajoutée par HUM-004.

## HUM-005 — Import privé de personnes par CSV

Base : `/api/v1/tenants/{tenant}/people-imports`. Tous les endpoints exigent les
quatre grants explicites sports.read/write et administrative.read/write dans le
tenant actif. Le fichier peut contenir une identité privée avant tout mapping ;
les seules permissions d’écriture ou de lecture ne suffisent pas. Auth/session,
MFA, tenant résolu, CSRF et révision habituels ; mutations confirmées dans le tenant.

| Méthode / route | Entrée | Résultat |
| --- | --- | --- |
| GET `/` | page 1..10000 | 10 imports, counts regroupés et pagination |
| POST `/` | multipart : file, delimiter | registre uploaded, aucune fiche créée |
| GET `/{import}` | page 1..10000 | registre + 10 lignes projetées + pagination |
| POST `/{import}/preview` | version, mapping, defaults | lignes ready/invalid/duplicate, aucune fiche créée |
| POST `/{import}/confirm` | version, confirm, acknowledge_rejected | queued ou résultat courant, puis lots asynchrones |
| GET `/{import}/report` | aucun payload nécessaire | CSV privé sans valeurs source |

Identifiants UUID, champs inconnus refusés. L’enveloppe JSON reste Resource/success,
avec notification interactive sur mutations. Le CSV est une réponse attachment,
Content-Type text/csv UTF-8, private/no-store et nosniff. Rapport indisponible pendant
queued/processing (409) ; actualiser pour voir la fin ou reprendre après interruption.

Upload : extension .csv, contenu MIME texte/CSV validé puis parser UTF-8 strict,
BOM facultatif, virgule ou point-virgule explicite. Quotes doublées et cellules
multilignes acceptées, en-têtes non vides/uniques ≤80 caractères ; lignes vides
ignorées. Max 1 Mio, 1000 records de données, 32 colonnes, 32768 octets/cellule ;
les quotas import/storage/artifact tenant peuvent être plus stricts. Source chiffrée
au repos ; ni nom utilisateur, URL publique ou téléchargement de source brut exposés.
L’upload identique (mêmes octets/séparateur dans ce tenant) retrouve le même registre.

Mapping : objet champ → index entier 0..31 réellement présent, sans réutiliser une
colonne ; pseudonym obligatoire. Champs permis : pseudonym, language, timezone,
status, biography_public, first_name, last_name, contact_email, contact_phone,
biography_internal. Pas de user_id, rôle, profil de jeu, période ou donnée de départ.
defaults doit contenir language/timezone/status valides selon PersonInput ; il
s’applique aux champs obligatoires non mappés. Une valeur vide dans une colonne
obligatoire mappée reste invalide, elle ne reprend pas silencieusement le défaut.
Le mapping et staging peuvent être remplacés avant confirmation, avec version UUID.
Après confirmation ils sont figés. Les numéros désignent le début physique du record
dans le fichier, y compris lorsqu’une cellule contient plusieurs lignes.

Validation PersonInput par ligne. Pseudonyme répété dans le fichier :
DUPLICATE_IN_FILE ; candidat existant dans le tenant : EXISTING_PERSON ; aucune
modification ou réactivation d’un ancien membre. Comparaison native LOWER/TRIM
pour l’existant, trim/mb_strtolower dans le fichier ; pas de rapprochement par
identité administrative chiffrée ni normalisation des accents. Les noms identiques
et e-mails ne sont pas des clés d’identité fiables dans cet import de création.

Confirm exige confirm accepté et acknowledge_rejected=true s’il existe des lignes
invalides/doublons. Un preview sans ligne prête est refusé (409). Traitement par
lots transactionnels de 25 sur reports ; droits/tenant revérifiés avant chaque lot.
Un candidat créé depuis le preview devient skipped avec
EXISTING_PERSON_AT_EXECUTION. Une ligne créée porte person_id et n’est plus rejouée.
Les lots précédents restent conservés si un lot suivant échoue. Une reprise POST
confirm avec version courante et confirmation explicite remet uniquement les lignes
restantes en queue. Rejeu avec version de confirmation initiale admis sous droits
actuels ; completed reste un résultat sans nouvelles créations. Aucune invitation,
liaison de compte, attribution de rôle ou mise à jour de fiche existante.

États registre : uploaded, previewed, queued, processing, completed, failed.
États ligne : ready, invalid, duplicate, created, skipped. Erreurs de ligne fermées :
COLUMN_COUNT, DUPLICATE_IN_FILE, EXISTING_PERSON, EXISTING_PERSON_AT_EXECUTION et
INVALID_<CHAMP>. failure_code PROCESSING_INTERRUPTED n’expose aucun texte d’exception.
Rapport : line,status,errors,person_id, codes séparés par | ; aucune cellule source,
nom, e-mail ou formule tableur. Seuls les lecteurs autorisés de ce tenant peuvent
le télécharger. Pas de purge arbitraire des sources ; leur quota reste comptabilisé.

Erreurs HTTP : 422 fichier/mapping/confirmation métier/pagination invalide,
403 droits ou MFA manquants, 404 registre hors tenant, 409 version obsolète,
mapping figé, confirmation tenant absente, exclusions non reconnues ou rien à créer.
429 quotas tenant/rate limiting selon les conventions TEN existantes. Ne pas
rejouer automatiquement une erreur de version ou d’autorisation.

Exemple dans le gestionnaire de confirmation humaine, après connexion/sélection
et confirmation serveur du tenant via le hook habituel :

```ts
import { tenancyRequest } from '../api/tenancy'
import { personImportsPath, type PersonImport } from '../api/personImports'
import type { ApiSuccessResponse } from '../api/contracts'

const root = personImportsPath(organizationId)
const body = new FormData()
body.set('file', selectedCsv)
body.set('delimiter', ';')
const uploaded = await tenancyRequest<ApiSuccessResponse<PersonImport>>(root, {
  method: 'POST', body, revision: activeRevision,
})
const preview = await tenancyRequest<ApiSuccessResponse<PersonImport>>(`${root}/${uploaded.data.id}/preview`, {
  method: 'POST', revision: activeRevision,
  body: { version: uploaded.data.version, mapping: { pseudonym: 0, contact_email: 1 },
    defaults: { language: 'fr', timezone: 'Europe/Paris', status: 'active' } },
})
// Afficher preview.data.rows/counts ; attendre confirmation humaine et reconnaissance
// des lignes exclues. Aucun appel de création automatique après le preview.
await tenancyRequest<ApiSuccessResponse<PersonImport>>(`${root}/${uploaded.data.id}/confirm`, {
  method: 'POST', revision: activeRevision,
  body: { version: preview.data.version, confirm: true, acknowledge_rejected: exclusionsAcknowledged },
})
```

Le helper retire Content-Type pour FormData, conserve CSRF/session/révision et
laisse le navigateur fournir sa boundary. Téléchargement typé via
downloadPersonImportReport(organizationId, importId, activeRevision), qui retourne
un Blob. Environnement asynchrone : un worker doit écouter reports.

## TEAM-001 — Catalogue commun et réglages des jeux

Toutes les routes suivent auth Sanctum/session, vérification de compte, MFA et
enveloppes Resource/pagination/request_id. Mutations : CSRF, notification française,
versions et aucune clé inconnue. DELETE absent : désactivation conservant l’historique.

| Méthode / route sous /api/v1 | Autorisation et entrée |
| --- | --- |
| GET game-catalogue | compte vérifié ; kind, game_id pour mode/role/map/release, pagination |
| GET game-catalogue/{entry} | compte vérifié ; référence globale UUID |
| POST game-catalogue | superadmin actif vérifié/MFA ; kind/key/name/active, game_id si enfant, definition si jeu, catalogue_version, confirm_global |
| PATCH game-catalogue/{entry} | même droit ; name/active, definition si jeu, version, catalogue_version, confirm_global |
| GET tenants/{tenant}/games | sports.read organisation ; pagination |
| GET tenants/{tenant}/games/{game} | sports.read ; réglage + options disponibles/effectives, catalogue_version |
| PATCH tenants/{tenant}/games/{game} | sports.read + games.manage organisation ; confirmation/révision tenant, enabled/restrictions/version/catalogue_version |

Catégories fermées : game/platform/provider/region/mode/role/map/release. Les quatre
dernières exigent un game_id de type game ; les premières ont scope global.
Clé stable : minuscules/chiffres/tirets, 64 caractères, 32 pour plateforme/fournisseur/
région. Nom non vide ≤120 ; active booléen. Une clé retirée reste réservée.
Modes, rôles de jeu et versions sont des références, aucune permission IAM implicite.

Definition d’un jeu : objet platforms (clé plateforme → liste de clés fournisseurs)
et regions (liste de clés région). Références existantes seulement, fournisseurs
distincts dans chaque plateforme ; un même fournisseur peut servir plusieurs
plateformes. Une association vide n’autorise aucun nouveau contexte correspondant.
Les fournisseurs désignent des références de compte, aucun OAuth ou secret.
Les régions initiales demeurent des contextes applicatifs et non des shards officiels.

Réglage local : enabled booléen ; restrictions objet fermé à platform/provider/
region/mode/role/map/release, valeurs listes de clés disponibles de ce jeu.
Une catégorie omise suit le catalogue ; [] bloque tous ses choix. Les choix retirés
déjà enregistrés peuvent être conservés ou supprimés, jamais redevenir effectifs
par une écriture locale. Un jeu global indisponible ne peut être réactivé localement.
Version explicitement null au premier réglage, UUID courant ensuite. Le détail
renvoie configured_enabled, enabled effectif, available, restrictions brutes,
options et effective_options. La liste n’embarque pas tous les enfants d’un jeu.

Le catalogue commun n’exige aucun tenant actif. Meta.can_manage sert seulement
à afficher les commandes ; le serveur revérifie les droits dans la transaction.
confirm_global accepté est distinct de la confirmation tenant et requiert une
action humaine sur l’écran commun. Support et propriétaire tenant n’ont aucun
droit d’édition globale implicite. Le superadmin n’a aucun droit métier local implicite.

Limites : 100 jeux, 32 références par catégorie globale, 200 par catégorie de jeu,
archives comprises ; pagination per_page 1..100 et page 1..100000. Les 4 jeux et
leurs références configurés sont repris une fois à la migration ; les nouveaux
jeux nécessitent une activation locale. Le catalogue des profils conserve son
contrat existant et applique les choix effectifs à toute nouvelle utilisation.

| Statut/code | Situation |
| --- | --- |
| 403 | droit, vérification ou MFA manquant |
| 404 | organisation inaccessible ou référence UUID inexistante |
| 409 GAME_CATALOGUE_VERSION_STALE | catalogue ou référence changé |
| 409 ORGANIZATION_GAME_VERSION_STALE | réglage local changé ou création concurrente |
| 409 GAME_CATALOGUE_ALREADY_EXISTS | clé déjà réservée dans son scope |
| 409 GAME_CATALOGUE_LIMIT_REACHED | limite incluant références désactivées atteinte |
| 409 GAME_CONTEXT_UNAVAILABLE | jeu/référence indisponible pour l’organisation |
| 422 VALIDATION_FAILED | champs inconnus, mauvais scope, référence, version ou confirmation |
| 500 INTERNAL_ERROR | persistance/audit indisponible, transaction annulée |

Exemple TypeScript après connexion/MFA, sous un superadmin, à déclencher seulement
après la confirmation humaine globale :

```ts
import { tenancyRequest } from '../api/tenancy'
import type { ApiSuccessResponse } from '../api/contracts'
import type { CatalogueEntry, CataloguePage, OrganizationGameDetail } from '../api/gameCatalogue'

const common = await tenancyRequest<CataloguePage>('game-catalogue?kind=game&per_page=10&page=1')
await tenancyRequest<ApiSuccessResponse<CatalogueEntry>>('game-catalogue', {
  method: 'POST',
  body: { kind: 'map', game_id: selectedGlobalGameId, key: 'map-example', name: 'Carte exemple',
    active: true, catalogue_version: common.meta.catalogue_version, confirm_global: globalHumanConfirmation },
})

// Session d’un gestionnaire métier disposant de sports.read + games.manage,
// tenant sélectionné et confirmé humainement via active-organization/confirmation.
const detail = await tenancyRequest<ApiSuccessResponse<OrganizationGameDetail>>(
  `tenants/${organizationId}/games/${selectedGlobalGameId}`, { revision: activeRevision })
await tenancyRequest<ApiSuccessResponse<OrganizationGameDetail>>(
  `tenants/${organizationId}/games/${selectedGlobalGameId}`, {
    method: 'PATCH', revision: activeRevision,
    body: { enabled: true, restrictions: { platform: ['pc'], region: ['eu'] },
      version: detail.data.version, catalogue_version: detail.data.catalogue_version },
  })
```

Ne pas rejouer automatiquement un conflit. Recharger les versions puis demander
une nouvelle confirmation. Les observations rang/game_role des profils restent
des textes déclaratifs ; elles ne sont pas converties en références de catalogue.

## TEAM-002 — Équipes d’une organisation

Routes privées sous /api/v1, session Sanctum, compte vérifié/MFA et contexte
tenant actif. Lecture sports.read à portée organisation ; mutations exigent
aussi teams.manage, CSRF, révision tenant et confirmation humaine. Enveloppes
Resource/pagination/request_id et notifications françaises habituelles.

| Méthode / route | Entrée |
| --- | --- |
| GET tenants/{tenant}/teams | q, game_id, kind, status, season, sort, page, per_page |
| GET tenants/{tenant}/teams/{team} | UUID d’équipe du tenant |
| POST tenants/{tenant}/teams | game_id, slug, season, name, kind ; identité et statut facultatifs |
| PATCH tenants/{tenant}/teams/{team} | version UUID courante et au moins un champ mutable |

game_id doit référencer un jeu global actif et activé pour cette organisation.
Les choix de création viennent de GET tenants/{tenant}/games ; aucune activation
arbitraire. slug : minuscules/chiffres/tirets, ≤80, unique par organisation même
après archivage. season : texte non vide ≤64, descriptif de cycle, sans calendrier
de saison séparé. Jeu, slug, saison et organisation ne peuvent pas être modifiés.
Un nouveau cycle se crée avec un nouveau slug. name non vide ≤120 ; kind parmi
first/academy/reserve/event/custom. custom_category non vide ≤80 obligatoire
pour custom, null pour les autres catégories.

Champs facultatifs et effaçables avec null : short_name ≤16, public_description
≤1000 et color au format #RRGGBB. custom_category est également nullable selon
kind ; pour quitter custom, envoyer ensemble kind et custom_category:null.
Les champs omis d’un PATCH sont conservés ; champs inconnus/immuables refusés.
status parmi draft/active/archived, draft par défaut. archived est terminal :
aucune édition, réactivation ou suppression. Pour archiver après retrait global
ou local du jeu, envoyer uniquement version et status:archived.

Resource : id, game_id, game_key, game_name, game_available, slug, season, name,
kind, custom_category, short_name, public_description, color, status, version,
publication_status. game_available indique seulement l’activité globale du jeu ;
ses réglages locaux sont consultables via tenants/{tenant}/games. L’écriture
revérifie la disponibilité effective sous verrou. publication_status vaut private :
les champs d’identité publique ne publient pas l’équipe. organization_id absent.

Filtres q (préfixe littéral du nom, ≤80), season (égalité exacte), game_id UUID,
kind/status fermés ; sort=name par défaut ou newest, départage par UUID.
per_page 1..100, page 1..100000, défaut 10/1. Limite technique 1000 équipes par
tenant, archives incluses. Pas de endpoint roster, appartenance ou DELETE ici.

| Statut/code | Situation |
| --- | --- |
| 403 | droit organisation, vérification ou MFA manquant |
| 404 | organisation inaccessible, UUID d’équipe absent ou appartenant à un autre tenant |
| 409 TEAM_VERSION_STALE | version de l’équipe changée |
| 409 TEAM_SLUG_ALREADY_EXISTS | slug déjà réservé dans cette organisation |
| 409 TEAM_ARCHIVED | modification d’une archive terminale |
| 409 TEAM_LIMIT_REACHED | 1000 équipes, archives comprises |
| 409 GAME_CONTEXT_UNAVAILABLE | jeu global retiré ou désactivé localement |
| 422 VALIDATION_FAILED | entrée inconnue/invalide, catégorie incohérente, PATCH vide |
| 500 INTERNAL_ERROR | persistance/audit indisponible, transaction annulée |

Exemple TypeScript après connexion/MFA, attribution explicite de sports.read +
teams.manage, sélection et confirmation humaine du tenant :

```ts
import { tenancyRequest } from '../api/tenancy'
import type { ApiSuccessResponse } from '../api/contracts'
import type { Team } from '../api/teams'

const created = await tenancyRequest<ApiSuccessResponse<Team>>(
  `tenants/${organizationId}/teams`, {
    method: 'POST', revision: activeRevision,
    body: { game_id: selectedEnabledGameId, slug: 'academy-2026', season: '2026',
      name: 'Académie', kind: 'academy', short_name: 'ACAD', status: 'draft' },
  })
const renamed = await tenancyRequest<ApiSuccessResponse<Team>>(
  `tenants/${organizationId}/teams/${created.data.id}`, {
    method: 'PATCH', revision: activeRevision,
    body: { version: created.data.version, name: 'Académie A' },
  })
// À déclencher après une action humaine d’archivage et confirmation du tenant.
await tenancyRequest<ApiSuccessResponse<Team>>(
  `tenants/${organizationId}/teams/${renamed.data.id}`, {
    method: 'PATCH', revision: activeRevision,
    body: { version: renamed.data.version, status: 'archived' },
  })
```

Un conflit exige un rechargement et une nouvelle action humaine ; aucun rejeu
automatique. Les archives restent consultables dans les listes et le détail privé.

## TEAM-003 — Appartenances datées et mouvements sportifs

Routes privées sous /api/v1/tenants/{tenant}/teams/{team}. Auth/session,
vérification/MFA, contexte tenant et Resources habituels. Lecture sports.read
organisation ; mutations exigent aussi teams.manage organisation, CSRF,
confirmation humaine et révision du tenant. Aucun droit automatique du capitaine,
coach, propriétaire, support ou superadmin.

| Méthode / suffixe | Entrée |
| --- | --- |
| GET memberships | page, per_page, person_id, role_key, status, at |
| GET movements | page, per_page |
| POST memberships | person_id, role_key, starts_at, ends_at facultative, reason_key |
| PATCH memberships/{membership} | version, action, reason_key ; paramètres de l’action |

person_id : UUID d’une personne de la même organisation, sans départ enregistré
ni statut former. Aucun compte de connexion obligatoire pour une fonction sportive.
role_key fermé : player (titulaire), substitute, captain, coach, analyst. Dates
avec secondes et offset, format Y-m-dTH:i:sP, normalisées UTC. ends_at nullable,
strictement après starts_at. Intervalles [début, fin) : périodes adjacentes admises.
Même personne/équipe : fonctions identiques sans chevauchement, player/substitute
mutuellement exclusifs ; captain peut compléter une autre fonction. Aucune
interdiction globale d’appartenir à plusieurs équipes ajoutée dans ce lot.
Création : reason_key initial/sporting_decision/other. Jeu disponible globalement
et activé localement, équipe non archivée. Un ancien membre n’est pas réaffectable.

Identité de période et début immuables ; aucune édition de role_key, person_id,
team_id, starts_at ou organization_id. Les changements créent une nouvelle période.
Action PATCH parmi close/cancel/transfer/promotion ; reason_key sporting_decision/
promotion/transfer/end_of_period/other. Version UUID obligatoire.

| Action | Règles |
| --- | --- |
| close | effective_at obligatoire, après le début, ne prolonge pas la fin existante ; clôture future possible |
| cancel | période non commencée seulement ; omettre effective_at, horodatage serveur |
| transfer | destination_team_id et destination_version, role_key ; même jeu et autre équipe non archivée |
| promotion | mêmes champs, source academy et destination first du même jeu |

Pour transfer/promotion, effective_at facultative : omise = instant serveur à la
confirmation, fournie = date présente/future dans la période source. La destination
crée une période ouverte. Toutes les fonctions sources qui dépassent cette date
sont raccourcies ; celles qui commencent à partir de cette date sont annulées.
Les historiques déjà finis restent intacts. Aucune copie des autres fonctions ou
grants IAM ; ajouter explicitement les fonctions complémentaires dans la destination.
UUID de destination externe au tenant : 404. Mauvaise catégorie/jeu/version : 409.
Période cancelled/closed ou déjà expirée : terminale. Une fin future conserve
status=active jusqu’à son échéance ; effective dépend des dates à chaque lecture.
Pour terminer plus tôt, clôturer avec la version courante et une date plus proche.
Clôture/annulation restent possibles après retrait du jeu. Pas de DELETE.

Liste : id, team_id, person_id, pseudonym sportif, role_key, starts_at, ends_at,
status, effective, version ; aucune identité administrative ou organization_id.
at filtre les périodes effectives à cette date ; sans at, toutes les périodes,
avec effective calculé à la lecture. meta.evaluated_at précise cette date.
Filtres person_id UUID, role_key, status active/closed/cancelled. Tri début
décroissant puis UUID ; per_page 1..100, page 1..100000, défaut 10/1.

Mouvements paginés, y compris ceux vers cette équipe : UUID, source/destination
et leurs noms actuels, références des périodes, action, reason_key, actor_user_id,
effective_at et recorded_at. Journal append-only : les noms courants ne sont pas
des snapshots de match. La promotion laisse un mouvement par fonction source.
Limites techniques : 1000 périodes par personne/organisation, archives comprises,
et 1000 liaisons IAM par période. Départ HUM-004 ferme les périodes en cours,
annule les futures et trace person_departure dans la transaction du départ.
Archivage TEAM-002 désormais refusé si une appartenance en cours/future subsiste.

Portée IAM team maintenant disponible dans permission-catalogue. L’attribution
role-assignments existante exige une personne liée au compte, une appartenance
couvrant toute la période du grant et les contrôles de délégation IAM-003.
Le grant reste lié à cette période : expiration, clôture, annulation, révocation
d’adhésion, rôle inactif ou compte délié sont revérifiés ; un retour ne réactive
aucun ancien grant. La fonction sportive seule ne donne aucune permission.
Après un retour, révoquer l’ancien grant puis créer une nouvelle attribution
explicitement liée à la nouvelle appartenance ; ne pas réactiver ou relier l’ancienne.
GET teams/{team} accepte aussi sports.read équipe effectif ; les listes Teams,
People et le roster restent réservés à sports.read organisation. Les mutations
restent sous teams.manage organisation. Les grants organisation explicites
couvrent leurs équipes sans imposer une fonction sportive à leurs gestionnaires.

| Statut/code | Situation |
| --- | --- |
| 403 | droit, MFA, accès organisation ou grant équipe effectif manquant |
| 404 | personne/équipe/période absente ou étrangère au tenant/à l’équipe |
| 409 TEAM_MEMBERSHIP_VERSION_STALE | période modifiée depuis sa lecture |
| 409 TEAM_MEMBERSHIP_IMMUTABLE | période terminale ou déjà expirée |
| 409 TEAM_MEMBERSHIP_OVERLAP | chevauchement d’une fonction incompatible |
| 409 TEAM_MOVEMENT_DESTINATION_INVALID | destination incompatible ou version changée |
| 409 TEAM_PERSON_UNAVAILABLE | personne ancienne ou déjà partie |
| 409 TEAM_ARCHIVED / GAME_CONTEXT_UNAVAILABLE | nouvelle utilisation interdite |
| 409 TEAM_HAS_LIVE_MEMBERSHIPS | archivage avant clôture/annulation des appartenances |
| 409 TEAM_MEMBERSHIP_LIMIT_REACHED / TEAM_SCOPE_GRANT_LIMIT_REACHED | limite historique atteinte |
| 409 ROLE_PERIOD_OUTSIDE_TEAM_MEMBERSHIP | grant hors de la période liée ou tentative de reliaison |
| 422 VALIDATION_FAILED | date, champ immuable/inconnu ou paramètres incohérents |
| 500 INTERNAL_ERROR | persistance/audit indisponible ; mouvement entier annulé |

Exemple TypeScript après connexion/MFA, sélection et confirmation humaine du
tenant, sous sports.read + teams.manage organisation :

```ts
import { tenancyRequest } from '../api/tenancy'
import type { ApiSuccessResponse } from '../api/contracts'
import type { TeamMembership } from '../api/teamMemberships'

const path = `tenants/${organizationId}/teams/${academyTeamId}/memberships`
const membership = await tenancyRequest<ApiSuccessResponse<TeamMembership>>(path, {
  method: 'POST', revision: activeRevision,
  body: { person_id: selectedPersonId, role_key: 'player',
    starts_at: '2026-10-13T12:00:00+02:00', ends_at: null, reason_key: 'initial' },
})
// Nouvelle action humaine confirmée ; destination lue via la liste des équipes.
await tenancyRequest<ApiSuccessResponse<TeamMembership>>(`${path}/${membership.data.id}`, {
  method: 'PATCH', revision: activeRevision,
  body: { version: membership.data.version, action: 'promotion', reason_key: 'promotion',
    destination_team_id: firstTeam.id, destination_version: firstTeam.version,
    role_key: 'player' }, // Date omise : effet immédiat à la confirmation.
})
```

Ne pas rejouer automatiquement un conflit ; recharger les références/versions
et confirmer une nouvelle action. Les compositions de TEAM-004 sont décrites ci-dessous.

## Compositions et instantanés — UCG-TEAM-004

Routes sous `/api/v1/tenants/{tenant}/teams/{team}/lineups` : GET liste paginée,
GET `/{lineup}` détail, POST création de brouillon, PATCH `/{lineup}` action
replace/finalize/cancel. Lecture : sports.read organisation ; mutations : aussi
teams.manage organisation, MFA, CSRF, révision et confirmation tenant. Identifiants
hors tenant/hors équipe : 404. Aucun UUID de match ou inscription reçu ici : ces
fiches arrivent dans leurs modules ; un libellé humain identifie le contexte préparé.

GET accepte page 1..100000, per_page 1..100 (défaut 10), purpose match/registration
et status draft/finalized/cancelled. Ordre date d’effet décroissante puis UUID.
POST exige purpose, label (1..120 caractères), effective_at ISO avec secondes et
offset explicite, team_version UUID, entries (liste de 1..50 éléments). Chaque
élément exige membership_id, membership_version UUID et slot_key starter/substitute.
Aucun person_id, pseudonyme, position, auteur ou état fourni par le client.
Ordre enregistré : ordre de la liste, positions 1..50 ; une seule place par personne,
au moins un titulaire. Appartenance player/substitute du tenant/de l’équipe,
couvrant la date retenue ; aucune déduction de droits ou d’éligibilité de compétition.

PATCH exige version UUID et action. replace exige label, effective_at, team_version
et entries comme POST ; usage/équipe/jeu restent fixes. finalize exige team_version
et utilise les participants du brouillon avec leurs versions conservées. cancel
n’exige que version/action. Tout champ inattendu, y compris imbriqué, est refusé.
Une composition figée ou annulée ne change plus et ne se supprime pas.

Réponse : id, team_id, game_id, purpose, label, effective_at, status, version,
team_name/game_key/game_name/season conservés, created_by, finalized_by/finalized_at
et entries bornés. Les entrées portent id, membership_id, membership_version,
person_id, pseudonym, membership_role_key, slot_key et position. Ces noms sont
rafraîchis au figement, puis restent indépendants des fiches courantes ; ils
décrivent l’identité connue à la confirmation, sans reconstitution rétroactive.
Aucune identité administrative, coordonnée privée ou attribution IAM copiée.
Notifications françaises et request_id suivent le socle API.

Conflits : TEAM_LINEUP_VERSION_STALE, TEAM_LINEUP_TEAM_STALE,
TEAM_LINEUP_MEMBERSHIP_STALE, TEAM_LINEUP_IMMUTABLE, TEAM_LINEUP_LIMIT_REACHED ;
TEAM_ARCHIVED/GAME_CONTEXT_UNAVAILABLE pour les nouvelles utilisations. Le port
HistoricalLineups refuse un brouillon/annulé avec TEAM_LINEUP_NOT_FINALIZED.
Recharger les références après 409, corriger le brouillon si nécessaire et demander
une nouvelle confirmation humaine ; ne pas rejouer automatiquement.

```ts
import { tenancyRequest } from '../api/tenancy'
import type { ApiSuccessResponse } from '../api/contracts'
import type { TeamLineup } from '../api/teamLineups'

// Après connexion/MFA, sélection et confirmation humaine du tenant.
const path = `tenants/${organizationId}/teams/${team.id}/lineups`
const draft = await tenancyRequest<ApiSuccessResponse<TeamLineup>>(path, {
  method: 'POST', revision: activeRevision,
  body: { purpose: 'match', label: 'Scrim du mardi',
    effective_at: '2026-10-13T11:00:00+00:00', team_version: team.version,
    entries: [{ membership_id: selectedPeriod.id,
      membership_version: selectedPeriod.version, slot_key: 'starter' }] },
})
// Nouvelle action humaine confirmée : conserver définitivement cette composition.
await tenancyRequest<ApiSuccessResponse<TeamLineup>>(`${path}/${draft.data.id}`, {
  method: 'PATCH', revision: activeRevision,
  body: { action: 'finalize', version: draft.data.version, team_version: team.version },
})
```

## Éligibilité sportive — UCG-TEAM-005

Routes sous `/api/v1/tenants/{tenant}` :

- GET/POST `eligibility-rulesets` : liste paginée et création.
- PATCH `eligibility-rulesets/{ruleset}` : archivage uniquement.
- POST `teams/{team}/lineups/{lineup}/ruleset-binding` : liaison durable.
- GET/POST `teams/{team}/lineups/{lineup}/eligibility` : rapports paginés et pré-évaluation.

Lecture : sports.read organisation ; toute écriture exige aussi teams.manage
organisation, MFA, CSRF, révision et confirmation tenant. UUID hors tenant/équipe :
404. Champs inconnus refusés. Réponses typed Resources et notifications françaises.

Créer exige name 1..120 (contexte de compétition), game_id disponible, min_starters
1..50, max_starters >= minimum, max_substitutes 0..49 ; maximum total <=50.
Champs présents mais éventuellement null : platform_key, min_age, max_age, lock_at.
Âges 0..120 cohérents, observation_max_days 1..3650. lock_at ISO avec secondes et
offset explicite, normalisé UTC. allowed_regions et allowed_ranks : listes présentes
de 0..32 valeurs ; clé région du catalogue, rang déclaré de 1..80 caractères.
Plateforme du catalogue obligatoire si régions/rangs définis ; liste vide = critère
non imposé. Rangs comparés après trim et sans distinction de casse, sans inventer
une hiérarchie commune aux jeux. name identifie aujourd’hui le contexte préparé :
aucune FK de compétition fictive. Compétitions consommera ces références ensuite.

Un règlement reste immuable. PATCH accepte seulement version UUID et action
archive ; sa version de contenu ne change pas. Pour changer les critères, créer un
nouveau règlement. Les anciennes références et rapports restent lisibles.

Lier exige version du brouillon, ruleset_id et ruleset_version exactes. Liaison
possible seulement pour un brouillon non lié, même jeu, équipe/jeu disponibles,
règlement actif, avant échéance. Nouvelle version de composition retournée.
POST création de composition peut aussi recevoir ces deux références ensemble.
Lien non remplaçable/supprimable ; nouveau règlement => nouvelle composition.

Pré-évaluer exige uniquement version UUID du brouillon lié ; l’évaluation écrit
un rapport mais ne change pas cette version. Une pré-évaluation après échéance
explique le refus. GET accepte page/per_page du socle (API <=100, UI 10). Rapport :
id, lineup_id/version, ruleset_id/version, stage preview/final, status
eligible/ineligible/incomplete, evaluated_at, actor_user_id et checks bornés.
Chaque check porte code, status passed/failed/unknown, person_id nullable et
message localisé. Date de naissance, âge numérique, coordonnées, comptes externes,
noms de rang et région source ne sont pas copiés dans ces rapports.

Toute règle refusée domine une donnée inconnue : ineligible ; sinon une donnée
inconnue donne incomplete ; sinon eligible. Profil actif du jeu/plateforme demandé :
aucun = absent, plusieurs = ambigu. Aucun profil choisi arbitrairement. Observation
absente, après la composition ou l’évaluation, ou antérieure à
effective_at - observation_max_days => incomplete. La borne ancienne est inclusive.
Âge en années civiles à la date UTC de composition ; anniversaire du 29 février
atteint le 1er mars en année non bissextile. Lecture administrative requise pour
ce contrôle ; absence/invalidité/refus d’accès donne le même motif unknown.

PUT n’existe pas. PATCH finalize d’une composition liée refait l’évaluation après
vérification des versions d’appartenances, sous la même transaction ; exige eligible
et conserve un rapport final pour la nouvelle version de composition. Échéance
exclusive : à lock_at, modification et figement refusés. Annuler reste possible.
Une ancienne composition sans règlement reste figée avec eligibility_status
unassessed ; elle ne constitue aucune preuve d’admissibilité de compétition.

Réponse composition enrichie de ruleset_id/version/name, lock_at,
eligibility_status/report_id. HistoricalLineups contient les références du
rapport final et son statut ; aucun recalcul lors des lectures historiques.
Conflits : TEAM_RULESET_VERSION_STALE, TEAM_RULESET_IMMUTABLE,
TEAM_RULESET_UNAVAILABLE, TEAM_LINEUP_RULE_BINDING_RETAINED,
TEAM_LINEUP_RULESET_REQUIRED, TEAM_LINEUP_LOCKED,
TEAM_LINEUP_ELIGIBILITY_INCOMPLETE, TEAM_LINEUP_INELIGIBLE, TEAM_ELIGIBILITY_LIMIT.
Après 409 : recharger/corriger puis nouvelle confirmation, aucun rejeu automatique.
Limites : 1000 règlements/organisation, 99 pré-évaluations + 1 rapport final/composition.

birth_date facultative YYYY-MM-DD, 1900..aujourd’hui, disponible via les routes
People existantes : administrative.write pour saisir, administrative.read pour
lire. Stockage chiffré comme les autres champs privés ; hors projection sportive.
Mapping CSV conservé : date saisie dans la fiche administrative.

```ts
// Après confirmation humaine dans le tenant actif.
const bound = await tenancyRequest<ApiSuccessResponse<TeamLineup>>(
  `tenants/${organizationId}/teams/${team.id}/lineups/${draft.id}/ruleset-binding`,
  { method: 'POST', revision: activeRevision,
    body: { version: draft.version, ruleset_id: rules.id, ruleset_version: rules.version } },
)
// Nouvelle confirmation pour conserver une pré-évaluation explicable.
await tenancyRequest(
  `tenants/${organizationId}/teams/${team.id}/lineups/${draft.id}/eligibility`,
  { method: 'POST', revision: activeRevision, body: { version: bound.data.version } },
)
// Le figement, confirmé séparément, refait le contrôle ; ce rapport n’est pas une autorisation.
```
