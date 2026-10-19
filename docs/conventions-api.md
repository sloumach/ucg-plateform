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

## Références externes et instantanés — UCG-TEAM-006

Ces routes sont privées à l’organisation active, sous `/api/v1` :

| Méthode | Route | Usage |
| --- | --- | --- |
| GET | `tenants/{tenant}/external-opponents` | Recherche paginée |
| POST | `tenants/{tenant}/external-opponents` | Création d’une référence |
| GET | `tenants/{tenant}/external-opponents/{opponent}` | Lecture |
| PATCH | `tenants/{tenant}/external-opponents/{opponent}` | Modification partielle ou archivage |
| GET | `tenants/{tenant}/external-opponents/{opponent}/snapshots` | Historique paginé |
| POST | `tenants/{tenant}/external-opponents/{opponent}/snapshots` | Capture définitive des faits actuels |

Lecture : `sports.read` à l’échelle organisation. Écriture : aussi `teams.manage`,
MFA, CSRF, révision et confirmation du tenant actif. Aucun droit implicite pour le
propriétaire ou l’administrateur ; un périmètre équipe seul ne donne pas accès au
référentiel organisationnel. Identifiants étrangers : 404, même pour un membre des
deux tenants. Pas de DELETE, de portail adversaire ni de droit attribué à la saisie.

POST référence exige `key` (slug local ≤80), `kind` parmi organization/team/player,
`name` ≤120, `source_label` ≤160, `observed_at` au format
`Y-m-dTH:i:sP` avec fuseau, non futur, et `confirm_public_data` accepté.
Champs facultatifs : `game_id` nullable, `short_name` ≤24,
`organization_name` ≤120, `source_url` HTTPS ≤1000 et `status` active/archived.
Le nom d’organisation est un libellé public, jamais un lien vers un tenant réel.
Le jeu, s’il est renseigné, doit être disponible dans le catalogue du tenant.
Sans jeu associé, la référence peut être créée même sans activation de jeu.

PATCH exige `version` UUID courante et `confirm_public_data` accepté. Seulement
les champs réellement modifiés sont transmis. Identité `id/tenant/key/kind/game_id`
fixe ; les champs inconnus, comptes, permissions, coordonnées et rosters sont
refusés. Une date d’observation omise n’est pas rafraîchie par un renommage.
Une archive reste lisible et réserve sa clé, mais ne peut être réactivée, éditée
ou utilisée pour une nouvelle capture. L’archivage seul reste possible après le
retrait du jeu.

Les URL sont des citations saisies, jamais téléchargées, prévisualisées ni
interrogées par le serveur. HTTPS exigé ; identifiants de connexion, localhost,
domaines locaux évidents et adresses IP privées/réservées refusés. Aucune résolution
DNS ni vérification automatique du caractère public des faits ; leur provenance
et l’autorisation de les utiliser sont déclarées par l’utilisateur.
L’interface affiche les URL comme texte échappé.

POST instantané accepte exclusivement `version`, `context_label` ≤120 non vide
et `confirm_public_data`. Les noms, jeu, source et observation sont copiés côté
serveur, avec version de référence, auteur et date de capture. Aucune fiche Match
ou Competition fictive : le contexte est un libellé humain et ne vaut pas
inscription. Les futurs modules utiliseront le port `ExternalOpponents` puis leurs
propres relations et Policies. Renommage, archivage et modification du catalogue
ne réécrivent jamais un instantané déjà conservé.

Réponse référence : id, key, kind, name, short_name, organization_name,
game_id/key/name, source_label/url, observed_at, status, version,
publication_status=private. Réponse instantané : id, opponent_id/version,
context_label, kind, name, short_name, organization_name, game_key/name,
source_label/url, observed_at, captured_at, created_by, publication_status=private.
Pas d’organisation propriétaire, d’identité administrative ou de permission copiée.

GET références : q (préfixe littéral ≤80), kind, status, game_id, sort=name/newest
et page/per_page. GET instantanés : uniquement page/per_page.
Pages API 1..100, UI 10, 1000 références par tenant et 1000 instantanés par référence.
Limites et clés réservées incluent les archives. Recherche, relations catalogue et
historique filtrent le tenant ; pagination stable, tri secondaire UUID.

Conflits : `EXTERNAL_OPPONENT_VERSION_STALE`, `EXTERNAL_OPPONENT_ARCHIVED`,
`EXTERNAL_OPPONENT_KEY_ALREADY_EXISTS`, `EXTERNAL_OPPONENT_LIMIT_REACHED`,
`EXTERNAL_OPPONENT_SNAPSHOT_LIMIT_REACHED` ; jeu retiré :
`GAME_CONTEXT_UNAVAILABLE`. Après 409 : relire puis confirmer à nouveau,
sans rejeu automatique. Une capture est une nouvelle preuve : aucun rejeu
automatique ni déduplication de captures explicitement confirmées.
Audit atomique minimal (UUID, état, version, noms des champs modifiés),
sans contenu des noms, URL, source ou libellé de contexte.

```ts
// Après confirmation humaine de l’action et du tenant actif.
// tenancyRequest gère les cookies et CSRF ; activeRevision vient du serveur.
const created = await tenancyRequest<ApiSuccessResponse<ExternalOpponent>>(
  `tenants/${organizationId}/external-opponents`,
  { method: 'POST', revision: activeRevision, body: {
    key: 'adversaire-public', kind: 'team', name: 'Équipe adverse',
    source_label: 'Liste publique des participants',
    source_url: 'https://example.com/participants',
    observed_at: '2026-10-01T12:00:00+00:00', confirm_public_data: true,
  } },
)
// Nouvelle confirmation humaine pour conserver un instantané.
await tenancyRequest(
  `tenants/${organizationId}/external-opponents/${created.data.id}/snapshots`,
  { method: 'POST', revision: activeRevision, body: {
    version: created.data.version, context_label: 'Compétition suivie',
    confirm_public_data: true,
  } },
)
```

## Contrats administratifs — UCG-CTR-001

Routes privées sous /api/v1, organisation active :

| Méthode | Route | Usage |
| --- | --- | --- |
| GET/POST | `tenants/{tenant}/contracts` | Liste paginée / création |
| GET | `tenants/{tenant}/contracts/people` | Sélecteur administratif paginé |
| GET/PATCH | `tenants/{tenant}/contracts/{contract}` | Détail / édition partielle |
| GET/POST | `tenants/{tenant}/contracts/{contract}/transitions` | Historique / changement d’état |
| PATCH | `tenants/{tenant}/contracts/{contract}/obligations/{obligation}` | Régularisation |

Lecture administrative.read ; mutations aussi administrative.write ;
approved/signed/active/ended/terminated aussi administrative.validate.
MFA, cookies Sanctum, CSRF, révision et confirmation du tenant actif.
UUID étrangers : 404 ; aucun droit implicite propriétaire ou technique.
Pas de DELETE. Le responsable de suivi n’acquiert aucun droit IAM.

POST exige reference slug local ≤80, type parmi player/coach/manager/analyst/
contributor/sponsor, title ≤160, responsible_person_id et parties (1..10).
Chaque partie : kind person/external, name ≤160 et person_id requis pour person.
Personne et responsable doivent appartenir au tenant ; le nom du responsable
est copié côté serveur. Les fiches People conservées après départ sont référençables
sans réactivation. Organisation hôte implicite. Champs facultatifs starts_on/
ends_on nullables YYYY-MM-DD ≥1900, fin ≥début ; obligations ≤50 avec title ≤160
et due_on nullable. Champs racine et imbriqués inconnus refusés, aucun montant.

PATCH exige version UUID courante et seulement les champs modifiés.
Reference/type fixes. Termes et listes modifiables seulement draft/in_review ;
responsable réaffectable après approbation. Obligations déjà régularisées
conservées : remplacement de leur liste refusé. Archive terminale en lecture seule.

Graphe :
draft → in_review/archived ; in_review → draft/approved/archived ;
approved → in_review/sent ; sent → in_review/signed ;
signed → active/ended/terminated ; active → ended/terminated ;
ended/terminated → archived.
Chaque POST transition exige version et status. Approved exige un approbateur
distinct de prepared_by. Retour draft/in_review efface l’approbation.
Approved/sent/signed/active exigent starts_on. Active exige une période courante
UTC ; ended exige une fin dépassée (fin incluse). Archived exige zéro obligation
pending. Les statuts restent manuels ; in_force est recalculé à la lecture.

Signed exige signed_on non future ≥1900, signature_reference non vide ≤160
et confirm_signature_received accepté. Réception déclarée hors plateforme :
signature_evidence_status=declared_external, aucune preuve de fichier rattachée
dans cette transition. Rattachement d’une copie via CTR-002 ; téléchargement
réservé à CTR-003. Terminated exige terminated_on non future ≥signed_on.
Aucun champ de signature n’est accepté pour les autres transitions.

Obligation PATCH : version UUID obligation, contract_version UUID dossier,
status pending/completed/waived différent de l’état courant. Mise à jour des
deux versions, y compris après échéance ou résiliation, avant archivage.
GET historique et people acceptent seulement page/per_page. Liste contrats :
page/per_page, q préfixe de référence, type, status et responsible_person_id.
Pages ≤100, UI 10 ; 1000 dossiers par tenant et 200 transitions par dossier.

Réponse fermée : référence/type/titre, responsable id/nom copié, dates, état,
version, préparation/approbation, déclaration de signature, résiliation,
publication_status=private, in_force, sporting_eligibility=not_assessed,
comptages et available_transitions (graphe métier, permissions toujours contrôlées
au POST). Parties/obligations incluses seulement au détail ; historique paginé
avec from_status/to_status, acteur, date et version. Sélecteur People id/label,
sans coordonnées. Textes privés chiffrés en base ; audit minimal sans contenu.

Conflits : CONTRACT_REFERENCE_ALREADY_EXISTS, CONTRACT_VERSION_STALE,
CONTRACT_OBLIGATION_VERSION_STALE, CONTRACT_ARCHIVED, CONTRACT_TERMS_FROZEN,
CONTRACT_OBLIGATIONS_RETAINED, CONTRACT_TRANSITION_INVALID,
CONTRACT_DATES_REQUIRED, CONTRACT_NOT_EFFECTIVE, CONTRACT_TERM_NOT_ENDED,
CONTRACT_PENDING_OBLIGATIONS, CONTRACT_SELF_APPROVAL_FORBIDDEN,
CONTRACT_LIMIT_REACHED, CONTRACT_TRANSITION_LIMIT.
Après 409 : recharger puis confirmer à nouveau, aucun rejeu automatique.

```ts
// Après confirmation humaine de l’action et du tenant actif.
// tenancyRequest gère cookies et CSRF ; activeRevision vient du serveur.
const created = await tenancyRequest<ApiSuccessResponse<ContractRecord>>(
  `tenants/${organizationId}/contracts`,
  { method: 'POST', revision: activeRevision, body: {
    reference: 'contrat-joueur-2026', type: 'player', title: 'Contrat joueur',
    responsible_person_id: responsibleId, starts_on: '2026-10-01',
    ends_on: '2027-09-30',
    parties: [{ kind: 'person', person_id: playerId, name: 'Partie contractante' }],
    obligations: [{ title: 'Restituer le matériel', due_on: '2027-09-30' }],
  } },
)
// Nouvelle confirmation humaine pour chaque changement d’état.
await tenancyRequest(
  `tenants/${organizationId}/contracts/${created.data.id}/transitions`,
  { method: 'POST', revision: activeRevision,
    body: { version: created.data.version, status: 'in_review' } },
)
// Un autre utilisateur habilité approuve avec la nouvelle version reçue.
```

## Versions documentaires et avenants — UCG-CTR-002

Routes privées sous /api/v1 :

| Méthode | Route | Usage |
| --- | --- | --- |
| GET/POST | `tenants/{tenant}/contracts/{contract}/documents` | Liste / création |
| GET/POST | `tenants/{tenant}/contracts/{contract}/documents/{document}/versions` | Historique / dépôt de version |
| POST | `tenants/{tenant}/contracts/{contract}/documents/{document}/signature` | Identification définitive d’une copie signée |

Chaque route exige MFA et administrative.read. POST exige aussi
administrative.write ; dépôt purpose=signed_copy et identification de signature
exigent administrative.validate. Cookies, CSRF, confirmation et révision du
tenant actif ; tous les POST exigent contract_version UUID courante.
Le contrat, le document, l’original lié et la version choisie restent dans ce
tenant et ce contrat : référence étrangère 404, même pour un membre des deux
organisations. Aucun droit implicite propriétaire ou technique.

POST document JSON : reference slug local ≤80, kind original/amendment,
title non vide ≤160 et contract_version. Un original par contrat ;
original_document_id requis pour amendment, désignant l’original de ce contrat
ayant une copie signée identifiée. Avenant uniquement après déclaration de
signature du contrat, hors archive. effective_on nullable YYYY-MM-DD ≥1900,
réservée aux avenants et déclarative : ne modifie pas les termes du contrat.
Référence, titre, type, origine et date d’effet restent immuables.
101 documents/contrat : un original et jusqu’à 100 avenants.

POST version multipart : contract_version, purpose draft/signed_copy et file.
PDF uniquement, extension et MIME, en-tête PDF ; ≤5 Mo (5 242 880 octets).
Nom affiché ≤160, sans chemin, contrôle, barre inverse, deux-points ou pourcentage.
Le client ne fournit ni empreinte, taille, numéro, artefact, URL ou chemin.
Version numérotée consécutivement côté serveur (1..100), SHA-256 calculée sur
les octets reçus ; taille des octets clairs, acteur et date serveur conservés.
Les fichiers sont chiffrés et stockés sous un UUID neuf via TEN-005 ; nom de
stockage générique, nom d’origine chiffré en base. Quota sur les octets chiffrés.

Draft d’original seulement si contrat draft/in_review ; actualise prepared_by,
préservant l’auto-approbation interdite. Après approbation, nouvelle préparation
refusée ; revenir en revue via CTR-001. Signed_copy seulement si contrat
signed/active/ended/terminated. Préparation d’avenant sans changer le contrat.
Document ayant une version signée identifiée : aucun nouveau dépôt.

POST signature JSON : contract_version, document_version_id, signed_on date
non future ≥1900, confirm_signed_copy accepté. La version doit appartenir à ce
document et avoir purpose=signed_copy ; le serveur copie son numéro et son hash.
Original : signed_on égale à celle déjà déclarée dans le contrat. Une seule
liaison par document, définitive et immutable. Signature externe déclarée,
sans certification cryptographique/légale ni approbation financière implicite.

Toutes les versions ont scan_status=pending et download_available=false à ce
stade. Aucun aperçu, URL ou route de téléchargement. CTR-003 ajoutera l’analyse
de contenu et une nouvelle autorisation pour délivrer les octets. Déclaration
de signature et sécurité du fichier restent distinctes.
Archive consultable mais ne reçoit plus de document, version ou liaison.
Aucune version/backfill fictif pour un contrat signé avant CTR-002.

GET documents et versions acceptent uniquement page/per_page, pages ≤100/UI 10.
Documents triés date/id, versions numéro décroissant/id. Détail des versions
sur route séparée ; aucun chargement massif de fichiers ou liste non bornée.
Document : id/reference/kind/title/original_document_id/effective_on,
created_by/at, versions_count, signed_version null ou objet
{id, number, sha256, signed_on, recorded_by, recorded_at},
signature_evidence_status not_declared/declared_external, publication_status private.
Version : id/number/purpose/filename/sha256/bytes/created_by/at,
scan_status pending, download_available false, publication_status private.
Jamais organization_id, artifact_id, clé de disque ou chemin de stockage.

POST réussis : meta.contract_version indique la nouvelle version UUID du contrat,
avec meta.request_id et notification localisée. Le frontend recharge le parent
avant la mutation suivante. Création et dépôt : 201 ; identification : 200.
422 conserve la saisie/le fichier ; 409 impose relecture puis confirmation,
sans rejeu automatique. Audit atomique minimal UUID/état/version, sans titre,
nom, contenu ou empreinte. Rollback nettoie seulement le nouveau fichier ;
échec de nettoyage conserve la réservation pour réconciliation opérateur.

Conflits : CONTRACT_VERSION_STALE, CONTRACT_ARCHIVED,
CONTRACT_DOCUMENT_REFERENCE_ALREADY_EXISTS, CONTRACT_DOCUMENT_ORIGIN_REQUIRED,
CONTRACT_DOCUMENT_LIMIT, CONTRACT_DOCUMENT_VERSION_LIMIT,
CONTRACT_DOCUMENT_SIGNED, CONTRACT_DOCUMENT_DRAFT_FROZEN,
CONTRACT_DOCUMENT_SIGNATURE_REQUIRED, CONTRACT_DOCUMENT_SIGNED_COPY_REQUIRED.
Quota : TENANT_LIMIT_EXCEEDED selon le contrat transversal TEN-005.
Aucun PATCH ou DELETE documentaire ; les corrections passent par une nouvelle
version avant identification signée, ou par un avenant après celle-ci.

```ts
type VersionResult<T> = ApiSuccessResponse<T> & {
  meta: { request_id: string; contract_version: string }
}
// Après confirmation humaine de l’action et du tenant actif.
// tenancyRequest gère cookies et CSRF ; révision issue du serveur.
const original = await tenancyRequest<VersionResult<ContractDocument>>(
  `tenants/${organizationId}/contracts/${contract.id}/documents`,
  { method: 'POST', revision: activeRevision, body: {
    contract_version: contract.version, reference: 'original',
    kind: 'original', title: 'Contrat original',
  } },
)
// Après une nouvelle confirmation humaine, au brouillon/en revue.
const body = new FormData()
body.set('contract_version', original.meta.contract_version)
body.set('purpose', 'draft')
body.set('file', selectedPdf)
const deposited = await tenancyRequest<VersionResult<ContractDocumentVersion>>(
  `tenants/${organizationId}/contracts/${contract.id}/documents/${original.data.id}/versions`,
  { method: 'POST', revision: activeRevision, body },
)
// deposited.data.sha256 vient du serveur ; chaque dépôt conserve une nouvelle version.
// Une fois la signature déclarée via CTR-001, relire le dossier courant.
const current = await tenancyRequest<ApiSuccessResponse<ContractRecord>>(
  `tenants/${organizationId}/contracts/${contract.id}`,
  { revision: activeRevision },
)
// Après nouvelle confirmation humaine, un validateur dépose la copie signée.
const signedBody = new FormData()
signedBody.set('contract_version', current.data.version)
signedBody.set('purpose', 'signed_copy')
signedBody.set('file', selectedSignedPdf)
const signedCopy = await tenancyRequest<VersionResult<ContractDocumentVersion>>(
  `tenants/${organizationId}/contracts/${contract.id}/documents/${original.data.id}/versions`,
  { method: 'POST', revision: activeRevision, body: signedBody },
)
// Après une nouvelle confirmation, identifier précisément cette version :
await tenancyRequest(
  `tenants/${organizationId}/contracts/${contract.id}/documents/${original.data.id}/signature`,
  { method: 'POST', revision: activeRevision, body: {
    contract_version: signedCopy.meta.contract_version,
    document_version_id: signedCopy.data.id, signed_on: current.data.signed_on,
    confirm_signed_copy: true,
  } },
)
// Un avenant est ensuite créé avec kind=amendment et original_document_id=original.data.id.
// Il n’est jamais un remplacement silencieux de l’original.
```


## Analyse et téléchargement privé — UCG-CTR-003

Préfixe de version documentaire :
`tenants/{tenant}/contracts/{contract}/documents/{document}/versions/{version}`,
sous /api/v1. UUID du contrat, document et version toujours résolus dans ce tenant.

| Méthode | Suffixe | Usage / données admises |
| --- | --- | --- |
| GET | /scans | Historique : page/per_page, maximum 100 |
| POST | /scans | Relance : expected_scan_id présent, UUID ou null |
| POST | /download-link | Lien neuf : expected_scan_id UUID obligatoire |
| GET | /download | Lien signé serveur : actor, scan, expires, signature |

Toutes les actions exigent administrative.read, session MFA et révision tenant.
Relance exige aussi administrative.write, confirmation du tenant actif et CSRF.
Émission de lien utilise CSRF/session mais ne nécessite pas de droit d’écriture.
Aucun champ inattendu, état clean, hash, chemin ou moteur fourni par le client
n’est accepté. Aucun changement de la version métier du contrat pour un scan.
Le dépôt CTR-002 demande automatiquement une analyse de sa nouvelle version.

POST /scans retourne 200 et Resource de tentative : id/attempt/status/reason,
policy/engine/requested_by/requested_at/started_at/finished_at/invalidated_at ;
meta.request_id et notification. Une tentative active retourne le même id.
Après une tentative terminale, expected_scan_id doit correspondre à la dernière
analyse ; ancien affichage → 409. Une tentative dépassant 300 s peut être relancée.

La Resource version enrichit scan_status (pending/queued/processing/clean/rejected/
failed/stale), scan_id, scan_reason, scan_finished_at et download_available.
Sans scan = pending ; clean expiré, ancienne politique ou lease de traitement
expirée (≥300 s) = stale, afin de proposer une relance explicite ; invalidé =
rejected/intégrité. Le nom et le contenu restent privés. Refus/panne ne supprime
pas la version ni le suivi du résultat ; seuls les octets autorisés sont délivrés.

POST /download-link retourne data {url, expires_at, filename} avec URL relative
signée cinq minutes et notification. GET ne fonctionne qu’avec la même session
utilisateur et des droits toujours valides, la même dernière analyse clean <24 h
et une intégrité vérifiée à nouveau. Expiration au timestamp exact refusée.
Déclencher une nouvelle analyse invalide les liens de l’ancienne tentative.

Réponse de téléchargement : octets PDF exacts, attachment, application/pdf,
private/no-store, Pragma no-cache, nosniff, CSP sandbox et X-Frame-Options DENY.
Aucun redirection de stockage ou jeton permettant de contourner la session.
Aucun artefact_id, chemin tenant ou URL de disque dans les Resources.

Conflits 409 : CONTRACT_DOCUMENT_SCAN_STALE, CONTRACT_DOCUMENT_SCAN_LIMIT,
CONTRACT_DOCUMENT_NOT_READY, CONTRACT_DOCUMENT_LINK_INVALID,
CONTRACT_DOCUMENT_INTEGRITY_FAILURE. Droits insuffisants 403, invité 401,
ressource étrangère 404, validation 422, throttle 429.
État failed avec scanner_unavailable/scanner_response/scanner_database_stale/
dispatch_failed/worker_failed/timed_out ; rejected avec malware/invalid_pdf/integrity.
Réponse failed est un résultat de suivi, jamais une autorisation de fichier.

```ts
const path = documentsPath(organizationId, contract.id, document.id)
  + '/versions/' + version.id
// Après confirmation de la relance et du tenant, si aucune analyse active.
await tenancyRequest<ApiSuccessResponse<ContractDocumentScan>>(path + '/scans', {
  method: 'POST', revision: activeRevision,
  body: { expected_scan_id: version.scan_id ?? null },
})
// Relire les versions ; attendre download_available=true et scan_status=clean.
const link = await tenancyRequest<ApiSuccessResponse<{
  url: string; filename: string; expires_at: string
}>>(path + '/download-link', {
  method: 'POST', revision: activeRevision, body: { expected_scan_id: version.scan_id },
})
// Vérifier le chemin, le compte et le scan comme DocumentSecurityControls.
const signed = new URL(link.data.url, 'https://document.invalid')
if (signed.origin !== 'https://document.invalid'
  || signed.pathname !== '/api/v1/' + path + '/download'
  || signed.searchParams.get('scan') !== version.scan_id
  || signed.searchParams.get('actor') !== String(active.context?.actor_user_id)) {
  throw new Error('Lien documentaire invalide')
}
const blob = await tenancyRequest<Blob>(path + '/download' + signed.search, {
  download: 'pdf', revision: activeRevision, signal: controller.signal,
})
// Déclencher le téléchargement local du Blob puis libérer son URL.
// 401/403/404/409 : relecture et nouvelle action explicite, aucun rejeu silencieux.
```

ClamAV désactivé par défaut tant que l’opérateur n’a pas préparé son endpoint
privé, ses signatures et la queue reports. Versions antérieures sans backfill :
une relance explicite est nécessaire. Voir README, mise en service CTR-003.

## Conditions financières et approbations — UCG-CTR-004

Base : /api/v1/tenants/{tenant}/contracts/{contract}. Session, MFA et contexte serveur
requis ; écritures protégées par CSRF, confirmation tenant et X-Tenant-Revision.

| Route relative | Méthode | Usage |
| --- | --- | --- |
| /financial-terms | GET / PATCH | Lire/remplacer le bloc financier original |
| /documents/{document}/financial-terms | GET / PATCH | Bloc d’avenant ; écriture interdite sur un original |
| /documents/{document}/approvals | GET | Historique paginé page/per_page ≤100, UI 10 |
| /documents/{document}/approvals | POST | Approuver la dernière préparation d’avenant |

Lecture financière : administrative.read + financial.read ; écriture : ces lectures
+ administrative.write + financial.write. GET renvoie data=null si aucun bloc.
GET/PATCH financiers : private, no-store ; PATCH renvoie 200, Resource financière
et meta.contract_version. POST approbation renvoie 201 et meta.contract_version.

Exemple, après confirmation tenant et lecture des versions :

~~~bash
curl 'https://api.example.test/api/v1/tenants/<tenant>/contracts/<contract>/financial-terms' \
  -X PATCH -b cookies.txt \
  -H 'Content-Type: application/json' \
  -H 'X-XSRF-TOKEN: <jeton-csrf>' \
  -H 'X-Tenant-Revision: <revision-confirmee>' \
  --data '{"contract_version":"<version-dossier>","expected_version":null,"terms":{"currency":"EUR","remuneration":"1500.00","remuneration_period":"monthly","bonus":null,"prize_share_percent":"12.50","payment_terms":"Versement selon les conditions approuvées"}}'
~~~

expected_version est présent : null pour créer, UUID de la Resource lue pour
remplacer. Montants en chaînes positives ou nulles, exactement deux décimales,
douze chiffres maximum avant le point ; ni float, négatif, notation exponentielle
ou conversion. currency : trois lettres majuscules ; prize_share_percent : 0.00
à 100.00. remuneration_period : once/monthly/yearly, obligatoire avec rémunération,
vide sinon. payment_terms nullable ≤2000 caractères. Clés inconnues refusées.
Aucune valeur financière dans les Resources administratives, personnes,
transitions ou audit.

Un bloc financier rend tous les documents du dossier soumis à financial.read,
y compris par lien déjà émis. Leur préparation exige financial.write ; analyses
et téléchargements conservent leurs autres droits. Un bloc de valeurs nulles ne
retire pas cette restriction. Les archives de maintenance gardent les valeurs
physiques chiffrées et ne sont pas un endpoint navigateur.

La transition approved d’un dossier financier exige financial.read/validate et
les droits administratifs existants, avec approbateur distinct des préparateurs
administratif et financier. Les conditions originales sont ensuite figées, sauf
retour en revue autorisé.

POST approbation d’avenant :

~~~json
{
  "contract_version": "<version-dossier>",
  "document_version_id": "<derniere-preparation-pdf>",
  "confirm_approval": true
}
~~~

Approbateur distinct du créateur documentaire et des préparateurs PDF/financier.
financial.validate est requis dès que le dossier est financier, même sans bloc
propre à l’avenant. Resource décision : id, number, document_version_id, approved_by,
approved_at, sans valeurs financières. Nouvelle préparation ou version financière :
décision conservée mais invalidée, nouvelle approbation puis nouvelle copie signée
requises. Un avenant signé est figé.

Les documents ajoutent latest_draft_id, latest_prepared_by, financial_terms_present,
financial_prepared_by et approval courante ou null. Le contrat ajoute
financial_terms_present, financial_documents_restricted, signature_separation_required,
signature_recorded_by. L’option de séparation est un booléen strict modifiable
en préparation, figé après approbation. Elle impose un déclarant distinct des
préparateurs et de l’approbateur pour déclaration/dépôt/identification de copie
signée. Réception hors plateforme uniquement, aucune certification externe.

Erreurs 403 droits, 404 référence hors tenant/dossier, 422 validation ;
409 CONTRACT_FINANCIAL_VERSION_STALE, CONTRACT_VERSION_STALE, CONTRACT_TERMS_FROZEN,
CONTRACT_SELF_APPROVAL_FORBIDDEN, CONTRACT_SIGNATURE_SEPARATION_REQUIRED,
CONTRACT_AMENDMENT_DRAFT_REQUIRED, CONTRACT_AMENDMENT_APPROVAL_REQUIRED,
CONTRACT_AMENDMENT_ALREADY_APPROVED, CONTRACT_AMENDMENT_COPY_STALE.
Après conflit, relire et reconfirmer sans rejeu.


## Échéances et rappels — UCG-CTR-005

Toutes les routes sont sous /api/v1/tenants/{tenant}/contracts, privées no-store,
authentifiées avec MFA, contexte tenant et administrative.read. Un dossier
contenant des conditions financières exige aussi financial.read. Les écritures
exigent administrative.write, confirmation tenant, CSRF et X-Tenant-Revision.

| Méthode et suffixe | Usage |
| --- | --- |
| GET /reminders | Boîte du compte connecté, responsable actuel et droits recontrôlés |
| GET /{contract}/reminders | Réglage ou data=null ; meta.scheduler_enabled/email_ready |
| PATCH /{contract}/reminders | Réglage avec contract_version et expected_version nullable |
| GET /{contract}/reminders/history | Historique paginé par dossier |
| POST /{contract}/reminders/{delivery}/resolution | Résoudre un e-mail uncertain après vérification |

Pagination page >=1, per_page <=100 (défaut 10) ; les dates sont YYYY-MM-DD,
offsets une liste de 1 à 10 entiers distincts de 0 à 365, channels une liste
distincte contenant in_app et/ou email. Les champs inconnus sont rejetés. Un
PATCH est intégral : enabled, notice_on, renewal_on, offsets et channels requis.
expected_version est null à la création puis l'UUID retourné ; le contrat est
également versionné dans meta.contract_version, sans modifier son approbation.

Exemple avec le client tenancyRequest déjà configuré pour CSRF :

```ts
const path = 'tenants/' + organizationId + '/contracts/' + contractId + '/reminders'
await tenancyRequest(path, {
  method: 'PATCH', revision,
  body: {
    contract_version: contract.version,
    expected_version: plan?.version ?? null,
    enabled: true, notice_on: '2026-11-01', renewal_on: null,
    offsets: [90, 30, 7], channels: ['in_app', 'email'],
  },
})
```

L'activation concerne les statuts approved/sent/signed/active. La date de fin
provient du contrat. Le responsable lié à un compte vérifié est le destinataire ;
l'adresse ne peut pas être fournie par le client. Le rattrapage ne génère qu'un
seul seuil atteint par date ; les dates passées sont exclues. Les nouveaux
seuils/date/responsable/compte créent des occurrences distinctes.

Livraison : id, contract_id, version, kind (deadline/notice/renewal), due_on,
offset_days, in_app_status (sent/blocked/not_requested), email_status
(pending/sending/sent/uncertain/blocked/not_requested), reason, attempts,
created_at et email_sent_at. Aucun compte destinataire, adresse, nom ou contenu
documentaire dans ces réponses. La boîte contient uniquement les occurrences
in-app sent encore consultables par leur responsable actuel. Le contrôle
financier s'applique également aux rappels créés avant une restriction du dossier.

reason : recipient_unavailable, smtp_unavailable, obsolete ou delivery_uncertain.
Un SMTP désactivé laisse pending sans tentative ; une indisponibilité pendant
l'appel est ambiguë et devient uncertain. Un sending abandonné depuis cinq
minutes est aussi uncertain. La décision POST exige expected_version et action
confirm_sent ou retry ; retry exige confirm_possible_duplicate=true, puis la
tâche recontrôle l'éligibilité avant envoi. Le serveur plafonne les tentatives à 20.
Une confirmation manuelle renseigne email_sent_at au moment de la vérification.

```ts
await tenancyRequest(path + '/' + delivery.id + '/resolution', {
  method: 'POST', revision,
  body: { expected_version: delivery.version, action: 'retry', confirm_possible_duplicate: true },
})
```

Écritures 200 avec notification Flasher success. Erreurs : 403 droits, 404 tenant
ou dossier étranger, 422 champs invalides/acceptation manquante, 409
CONTRACT_VERSION_STALE, CONTRACT_REMINDER_VERSION_STALE ou CONTRACT_ARCHIVED.
Relire et reconfirmer après conflit, sans rejeu automatique. Un indicateur activé
reflète la configuration, jamais une garantie de worker actif ou de livraison SMTP.


## Consultations et exports sensibles — UCG-CTR-006

Les routes existantes de lecture de contrats, conditions financières, documents,
versions, approbations, analyses et rappels sont journalisées après succès.
La création POST d'un lien et le GET qui prépare le PDF sont deux actions.
Les contrôles métier, MFA, tenant et financiers existants sont conservés.
Les refus ne sont pas présentés comme des consultations réussies ; leur audit
IAM distinct reste en place.

En-tête facultatif de consultation :

```http
GET /api/v1/tenants/{tenant}/contracts/{contract}
Accept: application/json
X-Tenant-Revision: {revision}
X-Access-Purpose: internal_control
```

Valeurs : operational (défaut), internal_control ou legal_review. Une valeur
libre, inconnue ou opérateur envoyée par le navigateur reçoit 422
VALIDATION_FAILED, champ access_purpose, message français. La catégorie n'entre
ni dans le payload métier validé ni dans une URL signée. Sans cet en-tête,
les appels existants restent valides. La catégorie ne prouve pas le motif réel.

Le journal se consulte par l'API IAM-006 existante :

```bash
curl "$API_BASE/tenants/$TENANT/audit-events?source=tenancy.sensitive&per_page=10&action=contract.document_downloaded" \
  --cookie "$SESSION_COOKIE" -H "Accept: application/json"
```

audit.view, compte vérifié, MFA confirmée et accès tenant effectif requis ;
aucun accès tenant par le journal plateforme. Les filtres actor_user_id, action,
from/until et cursor de l'API IAM restent applicables. per_page <=100.
Le curseur est lié à l'auditeur, au tenant et à tous les filtres ; un changement
requiert une première page sans curseur. Les consultations du journal lui-même
restent tracées par journal.viewed dans tenancy.audit, sans boucle dans la source
tenancy.sensitive.

Format AuditEntryResource inchangé : id, source, organization_id, actor_user_id,
action, target {type,id}, before/after vides, reason_code, request_id, ip_address
facultative et occurred_at. meta.next_cursor et meta.per_page pour la pagination.
Cibles : contract_collection (UUID organisation), contract, contract_document,
contract_document_version ou organization_export. Aucun titre, montant, nom,
nom de fichier, contenu documentaire, empreinte ni URL signée dans ces entrées.

Actions contractuelles : contract.listed, contract.viewed, contract.history_viewed,
contract.financial_viewed, contract.amendment_financial_viewed,
contract.documents_viewed, contract.versions_viewed, contract.approvals_viewed,
contract.scan_history_viewed, contract.download_link_created,
contract.document_downloaded, contract.reminders_viewed,
contract.reminder_history_viewed et contract.reminder_inbox_viewed.

Exports opérateur existants : tenant.export_requested, tenant.export_retried,
tenant.export_generated, tenant.export_read ; motif operator_portability imposé
côté serveur. Le motif libre de la commande n'est pas recopié. Il ne s'agit pas
d'un nouvel endpoint d'export de contrats. Le snapshot d'archive ne contient pas
ses propres événements futurs de fin de génération et de lecture.

Les réponses contractuelles et le journal tenant sont private, no-store.
Si l'audit d'une réponse contractuelle ne peut pas être enregistré : 503
ACCESS_AUDIT_UNAVAILABLE, message français, Retry-After: 30, aucun corps métier.
Une trace de téléchargement atteste une préparation autorisée pour servir le
PDF, pas sa réception finale. Un export ne sort pas du service sans journalisation
de sa lecture et nouveau contrôle de l'opérateur après stockage.

## Prospects et contacts — UCG-SPON-001

Base : `/api/v1/tenants/{tenant}/sponsors`. Sanctum, session, MFA, contexte tenant
et Policies s’appliquent à chaque route ; confirmation/révision tenant requises
pour POST/PATCH. Les permissions `partnerships.read` et `partnerships.write` sont
explicites et distinctes des droits RH, sportifs et financiers.

| Méthode | Chemin relatif | Contrat |
| --- | --- | --- |
| GET / POST | `/` | Recherche paginée / création de société |
| GET / PATCH | `/{sponsor}` | Lecture / modification avec version |
| GET / POST | `/{sponsor}/contacts` | Contacts paginés / création |
| PATCH | `/{sponsor}/contacts/{record}` | Modification avec version |
| GET / POST | `/{sponsor}/opportunities` | Opportunités paginées / création |
| PATCH | `/{sponsor}/opportunities/{record}` | Modification avec version |
| GET / POST | `/{sponsor}/interactions` | Historique paginé / ajout manuel |

Société : `name`, `industry` nullable, `status` prospect/partner/archived.
Contact : `name`, `position`, `email`, `phone` nullables, `status` active/archived.
Opportunité : `title`, `description` nullable, `status`
open/contacted/negotiating/won/lost/archived. Échange : `channel`
note/email/phone/meeting/other, `summary` (4 000 caractères maximum),
`occurred_at` ISO 8601 avec fuseau, non futur ; `contact_id` et `opportunity_id`
facultatifs, rattachés au même dossier et tenant. Auteur et date de saisie sont
établis par le serveur. Aucun champ RH, lien de compte, montant ou pièce jointe.

Listes : `page` (1 à 100 000), `per_page` (1 à 100, défaut 10), `q` (préfixe
littéral du nom/titre/compte rendu, maximum 80 caractères), statut selon la
ressource. Historique : canal et UUID contact/opportunité optionnels, tri par
date de réalisation décroissante puis date de saisie et UUID. Les caractères
% et _ sont littéraux ; filtres inconnus rejetés. Enveloppe `data`, `links`,
`meta` et `meta.request_id` conservée.

Création : 201, modification : 200, notification Flasher française. Resources
à liste fermée et `publication_status=private`, sans `organization_id`.
UUID hors tenant/dossier : 404 ; entrée inconnue/invalide : 422 ; version
obsolète : 409 `PARTNERSHIP_VERSION_STALE` ; archive : 409 `PARTNERSHIP_ARCHIVED`.
Archivage irréversible via l’API actuelle ; aucun DELETE ni édition d’échange.
Réponses privées no-store. La consignation d’un échange ne déclenche aucun envoi.

## Accords partenaires — UCG-SPON-002

Base : `/api/v1/tenants/{tenant}/sponsors/{sponsor}/agreements`.
Sanctum, session, MFA, tenant, Policies et validation fermée s’appliquent partout.
POST/PATCH exigent confirmation et révision tenant ; les mutations utilisent
une version optimiste de l’accord, plus celle de la ressource enfant si nécessaire.

| Méthode | Chemin relatif | Usage |
| --- | --- | --- |
| GET / POST | `/` | Recherche paginée / préparation d’un accord |
| GET / PATCH | `/{agreement}` | Lecture / révision qui annule l’approbation |
| POST | `/{agreement}/transitions` | Approbation, clôture, archivage |
| GET | `/{agreement}/history` | Journal paginé, sans texte ou valeur financière |
| GET / POST | `/{agreement}/exposures` | Périmètres paginés / préparation |
| PATCH | `/{agreement}/exposures/{exposure}` | Révision ou archivage d’un périmètre |
| GET / PATCH | `/{agreement}/financial` | Consultation auditée / préparation d’apports |

Références : `GET /api/v1/tenants/{tenant}/sponsorship-references/{kind}`,
avec kind = members/teams/players/contracts, `page` et `per_page` (1–100).
Réponse de référence strictement `id` et `label`. Le catalogue contrats exige
`administrative.read` et ne retourne que référence/id de contrats sponsor.
Les sélecteurs de membres ne montrent pas leurs rôles/e-mails ; les références
People ne montrent ni identité RH, coordonnées, compte ou biographie interne.
Les références devenues indisponibles sont refusées à l’écriture/approbation.

Accord : `reference` immuable (slug, 80), `title` (160), `starts_on`, `ends_on`
(AAAA-MM-JJ, fin >= début), `responsible_user_id` ; `opportunity_id` facultatif
et immuable, limité au dossier sponsor. `contract_id` facultatif et protégé par
le droit administratif ; son `contract_version` est établi par le serveur.
La réponse inclut le nom de compte `responsible_label` courant, ou null si
indisponible. Elle n’inclut jamais conditions financières ou montant. Les champs
contract_id/contract_version sont projetés par droit administratif serveur.

États : draft → approved/archived, approved → ended, ended → archived.
Transition : `version`, `target_status` ; approbation avec
`confirm_authorizations=true`, `partnerships.validate`, droits lecture/validation
financiers, préparateurs distincts, apport et périmètres vérifiés. Toute révision
d’accord, d’apport ou de périmètre remet en draft avec nouvelle version.

Périmètre : `agreement_version`, `target_type` organization/team/player,
`target_id` UUID, `channel` jersey/social/stream/event/website/other,
`authorization_reference` obligatoire (160), `description` facultative (2000),
`exclusivity_key` facultative (slug, 80). Modifier exige aussi `version` ; cible
immuable, `status=archived` disponible. Maximum 100 périmètres conservés par accord.
Les cibles event/content sont préparées dans la validation mais refusées en 409
SPONSORSHIP_TARGET_UNAVAILABLE jusqu’à vérification par leurs modules réels.
L’autorisation n’est jamais implicite : `exposure_authorized` est recalculé selon
statut, période, références et droits courants. `publication_status` reste private.

Finances : `agreement_version`, `expected_version` nullable obligatoire,
`terms` avec cinq clés fermées : kind cash/in_kind/mixed, currency nullable,
amount nullable (chaîne décimale positive à deux décimales, 12 chiffres entiers
maximum), in_kind nullable (2000), payment_terms nullable (2000). Cash/mixed
exigent amount et devise ASCII majuscule à trois lettres ; in_kind/mixed exigent
la description d’apport. Un apport en nature seul interdit montant/devise.
Aucun nombre flottant JSON n’est accepté. `financial.read/write/validate` sont
indépendants des droits partenariats et n’accordent aucun accès RH. La réponse
financière seule contient terms, sa version et agreement_version.

Réponses privées no-store ; 201 pour les créations, 200 pour les révisions,
Flasher français. Hors tenant/dossier : 404 ; champs inconnus/invalides : 422 ;
conflit de version : 409 SPONSORSHIP_VERSION_STALE / SPONSORSHIP_FINANCIAL_STALE /
SPONSORSHIP_EXPOSURE_STALE ; préparateur approbateur identique : 409
SPONSORSHIP_SELF_APPROVAL_FORBIDDEN ; exclusivité chevauchante : 409
SPONSORSHIP_EXCLUSIVITY_CONFLICT. Une panne d’audit financier renvoie 503
ACCESS_AUDIT_UNAVAILABLE sans données métier. Les pièces utilisent les routes
Contrats existantes et conservent leurs contrôles d’autorisation et antivirus.

## Campagnes et livrables partenaires — UCG-SPON-003

Base : /api/v1/tenants/{tenant}/sponsors/{sponsor}/agreements/{agreement}/campaigns.
Sanctum, MFA, Policies, confirmation/révision tenant, validation fermée et réponses
privées no-store s’appliquent comme dans SPON-002. Aucun DELETE ni envoi externe.

| Méthode | Chemin relatif | Usage |
| --- | --- | --- |
| GET / POST | / | Liste paginée / préparation de campagne |
| GET / PATCH | /{campaign} | Lecture / révision ou archivage |
| GET | /{campaign}/history | Historique paginé sans capture de preuve |
| GET / POST | /{campaign}/deliverables | Liste filtrée / nouvelle promesse |
| PATCH | /{campaign}/deliverables/{deliverable} | Modifier une promesse |
| POST | /{campaign}/deliverables/{deliverable}/deliveries | Déclarer une livraison |
| POST | /{campaign}/deliverables/{deliverable}/transitions | Valider, rouvrir ou annuler |

Tableau : GET /api/v1/tenants/{tenant}/sponsorship-dashboard. Enveloppe paginée
standard data/links/meta pour les retards, plus summary avec promised, delivered,
validated, cancelled et overdue. Tous ces compteurs portent sur les campagnes
actives. Les lignes ne contiennent ni preuve ni montant ; seulement contexte et
métadonnées de suivi, sans promesse d’autorisation d’exposition.

Pagination page 1–100000, per_page 1–100, défaut 10. Filtre status sur campagnes
(active/archived) ou livrables (promised/delivered/validated/cancelled) ; aucun
filtre inconnu accepté. Les historiques sont ordonnés par date/UUID, les retards
et livrables par échéance/UUID.

Campagne : agreement_version requis, title (160), starts_on/ends_on au format
AAAA-MM-JJ et responsible_user_id entier habilité. PATCH exige version ; status
archived s’envoie seul avec les versions. Période dans l’accord et couvrant les
échéances conservées. Aucun changement de rattachement possible.

Promesse : agreement_version, campaign_version, exposure_id actif du même accord,
title (160), kind publication/video/event_presence/brand_placement,
responsible_user_id habilité et due_at ISO 8601 avec fuseau. PATCH exige aussi
version, reste limité à l’état promised et ne permet pas de changer le périmètre.
Une mutation enfant retourne les nouvelles version/campaign_version et la version
inchangée de l’accord ; les requêtes suivantes doivent reprendre ces versions.

Livraison : les trois versions, delivered_at ISO 8601 non futur et
proof_reference textuelle obligatoire (2000). La date et l’échéance sont dans la
période de campagne selon le fuseau tenant. La réponse passe à delivered,
conserve delivered_by et distingue delivered_late ; aucune validation implicite.
La preuve n’est ni un téléversement ni une URL ouverte automatiquement.

Transition : les trois versions et target_status validated/promised/cancelled.
Validated exige delivered, confirm_evidence=true, droits partnerships.read/write/
validate, acteur distinct du préparateur/responsable/livreur et accord/périmètre
actuellement autorisés. Les droits financiers ne sont pas requis pour cette
revue opérationnelle. Promised exige delivered/validated et partnerships.validate
pour rouvrir, retire preuve/validation courantes et conserve l’ancienne capture
chiffrée. Cancelled exige promised/delivered et write ; cet état est définitif.
Les campagnes restent archivables une fois leurs livrables validés ou annulés.

La lecture des livrables inclut exposure_authorized selon SPON-002,
validation_matches_agreement, les dates et acteurs de livraison/validation,
overdue et versions. Une validation historique reste visible si les références
changent ; son indicateur de correspondance devient faux. publication_status
reste private partout.

201 pour créations, 200 pour révisions/actions, notification Flasher française.
401 sans session ; 403 sans habilitation ; 404 pour UUID hors tenant/accord/
campagne ; 422 pour forme/clé inconnue/confirmation ; 409 pour conflit métier.
Codes de conflit : SPONSORSHIP_CAMPAIGN_STALE, PERIOD, DUE, RESPONSIBLE, SCOPE,
TRANSITION, INDEPENDENT, AUTHORIZATION, REOPEN, PENDING, FROZEN et ACTIVE_CAMPAIGNS
(chaque suffixe est précédé de SPONSORSHIP_CAMPAIGN_). Les conflits de version
parent conservent SPONSORSHIP_VERSION_STALE. Aucun texte de preuve n’est recopié
dans le journal transversal ni dans les événements HTTP.

## Médias d’équipe — UCG-MEDIA-001

Base authentifiée : `/api/v1/tenants/{tenant}/media`.
Middleware de session/MFA/tenant ; écritures : confirmation tenant, révision,
verrou de session et droits revérifiés sous verrou organisation.

| Méthode / suffixe | Droit organisation | Résultat |
| --- | --- | --- |
| GET / | media.read | Page de médias autorisés |
| GET /teams | media.read | Choix id/label d’équipes actives, paginés |
| GET /{media} | media.read | Détail visible, sinon 404 |
| POST / | media.read + media.write | Création privée par défaut |
| PATCH /{media} | media.read + media.write | Modification avec version |
| POST/PATCH avec partage maintenu | ajouter media.publish | Confirmation explicite obligatoire |

Filtres fermés : page 1–100000, per_page 1–100, q (préfixe littéral, 80),
kind, status, team_id, archived (booléen ; true exige media.write).
/teams accepte seulement page/per_page. Tri created_at descendant puis id.
Un lecteur ne reçoit ni média privé, ni archive, ni partage dont publish_at
est futur, y compris lors d’un accès direct. Les réponses sont private/no-store.

Création : title (160), team_id (UUID tenant actif), kind stream/vod/clip,
provider twitch/youtube, url (500). Champs facultatifs : status,
status_observed_at (ISO 8601 avec fuseau, non futur), is_scrim (booléen),
visibility private/organization/public, publish_at nullable, confirm_sharing.
Privé par défaut ; statut par défaut scheduled pour stream, ended sinon.
VOD/clip : seulement ended/unavailable. Toute source/statut transmis au PATCH
exige status_observed_at, au moins égal à la dernière observation. Le client
omet les champs inchangés.

Le partage exige confirm_sharing accepté, y compris pour modifier un média
déjà partagé. Le service conserve l’acteur et la date d’autorisation. Pour un
scrim, publish_at est obligatoire et futur à l’autorisation, à sa reclassification
en scrim ou au changement de date. Le retrait du partage efface ces données.
public désigne une autorisation externe : aucun endpoint anonyme ou changement
de visibilité chez Twitch/YouTube. Le contexte scrim est déclaratif, sans match
créé. Seul team_id est disponible ; match_id/event_id/score et les champs serveur
sont refusés.

PATCH exige version ; une archive est demandée uniquement avec version + archive
accepté. Pas de DELETE, pas de réouverture. Codes 409 MEDIA_VERSION_STALE et
MEDIA_ARCHIVED, 422 de validation et 404 inter-tenant. Audit atomique minimal,
sans titre/URL. Aucun accès sportif/RH/finance/partenariats induit.

Resource explicite : id, title, team_id, team_label nullable, kind, provider, url,
status, status_source=manual, status_observed_at, is_scrim, visibility,
effective_visibility, publish_at, archived_at, version. Aucun score ou mesure
d’audience. Les liens sont normalisés par MediaLink, sans accès réseau :
HTTPS, hôtes exacts, chemins compatibles type/fournisseur, aucun paramètre de
suivi ni identifiant secret. Un lien accepté n’est pas une vérification d’existence.

## Annonces, commentaires et notifications internes — socle UCG-COM-001

Préfixe privé authentifié `/api/v1/tenants/{tenant}/communication`. MFA, session et
tenant actifs ; toutes les mutations exigent la confirmation/révision tenant et
le verrou de session existants. Permission organisation communication.read pour
toutes les routes, write pour gestion/références, publish pour publication/retrait,
comment pour participation. Aucun accès implicite propriétaire/plateforme.

| Méthode | Suffixe | Fonction |
| --- | --- | --- |
| GET | /announcements | Fil ciblé ; mode gestion explicite |
| POST | /announcements | Créer un brouillon |
| GET | /announcements/{id} | Détail autorisé |
| PATCH | /announcements/{id} | Éditer le brouillon ou une action isolée |
| GET | /targets?type=team\|game\|role | Références actives id/label paginées, write requis |
| GET/POST | /announcements/{id}/comments | Lire/ajouter une réponse |
| GET | /inbox | Remise des notifications visibles au compte courant |
| POST | /inbox/{event}/read | Lecture idempotente d’un événement encore accessible |
| GET/PATCH | /preferences | Préférences du compte et du tenant courants |

Listes : page 1–100000, per_page 1–100, défaut 10, tri stable. Annonces :
management booléen facultatif (write requis), status draft/published/withdrawn
seulement en gestion. Détail/commentaires : management facultatif à la lecture.
Inbox : unread booléen facultatif. Cache-Control private,no-store partout.
Aucun champ de tri, recherche de texte chiffré, user_id ou organization_id accepté.

POST brouillon : title (160), body (12000), target_type organization/team/game/role,
target_id UUID nullable ; null pour organization, obligatoire sinon.
comments_enabled booléen facultatif, vrai par défaut. expires_at ISO avec fuseau,
nullable et futur. Choix équipe/jeu actifs, rôle actif du tenant. Mise à jour :
version obligatoire et au moins un champ de brouillon. Changer le type impose
la transmission de la cible cohérente ; target_id peut seul changer dans le même type.

PATCH d’action exige seulement les champs suivants :

- Publication : version, action=publish, confirm_publication accepté ;
  notify_members booléen facultatif, faux par défaut.
- Retrait définitif : version, action=withdraw.
- Ouvrir/fermer les commentaires d’une annonce publiée : version, action=comments,
  comments_enabled booléen obligatoire.

Aucun mélange d’action et de contenu ; la cible est recontrôlée à la publication.
Contenu/cible/expiration publiés figés. Éditer une annonce publiée exige son retrait
puis un nouveau brouillon. POST commentaire : version actuelle du parent, body
(4000), notify_members facultatif faux par défaut. Autorisation de commenter
rejouée sur l’audience actuelle, sans dérogation de gestion. Historique append-only,
sans modification/suppression API ni modification automatique de version du parent.

Resource annonce : id/title/body/target_type/target_id/status/comments_enabled/
can_comment/published_at/expires_at/withdrawn_at/version. Resource commentaire :
id/announcement_id/body/is_mine/created_at ; pas d’identité globale ou de donnée RH.
Resource inbox : id/announcement_id/comment_id/title/kind announcement|comment/
read/created_at ; aucun texte de réponse, acteur ou liste de destinataires.
Resource préférences : announcements_enabled (défaut vrai), comments_enabled
(défaut faux), version nullable (GET initial sans écriture). PATCH exige ces trois
champs, version null au premier enregistrement, UUID courant ensuite.
POST read retourne data.read=true. Mutations : notification succès standard.

La remise dans la boîte vérifie les droits et les cibles à cet instant, même
pour une ancienne notification déjà lue ; aucun snapshot d’autorisation. La
préférence réponses cible l’auteur de l’annonce et les participants déjà présents,
sans notifier l’auteur de la réponse lui-même. Il s’agit d’une consultation
interne, sans e-mail, webhook ou Discord ; aucun travailleur requis.

409 : COMMUNICATION_VERSION_STALE, COMMUNICATION_CONTENT_FROZEN,
COMMUNICATION_WITHDRAWN, COMMUNICATION_TRANSITION_INVALID,
COMMUNICATION_COMMENTS_CLOSED. 404 pour ressource hors tenant/hors audience ou
cible inactive ; 403 pour permission absente ; 422 pour payload fermé/incohérent.
Une annonce expirée ou retirée disparaît des lectures ordinaires et de l’inbox.
Pas de DELETE. event_id/task_id/match_id/session_id libres refusés : intégrations
aux futurs référentiels encore à livrer, sans prétendre achever ces volets.

Exemple de commentaire après lecture de la version courante :

```ts
await tenancyRequest('tenants/' + organizationId + '/communication/announcements/' + announcement.id + '/comments', {
  method: 'POST', revision,
  body: { version: announcement.version, body: 'Bien reçu.', notify_members: false },
})
```

Utiliser le client SPA existant avec CSRF et confirmation tenant. Après 409,
recharger les données et refaire une décision humaine, sans réessayer en boucle.

## Activités du calendrier — UCG-CAL-001

Préfixe authentifié : /api/v1/tenants/{tenant}/calendar. Session, MFA et tenant
actifs ; mutations confirmées avec révision tenant et verrou de session.
Permissions au niveau organisation : calendar.read ; read + write pour mutations
et références ; publish supplémentaire pour tout maintien/création d’un partage.

| Méthode | Suffixe | Objet |
| --- | --- | --- |
| GET | /activities | Agenda filtré avant pagination |
| GET | /activities/{id} | Détail visible ; management=1 pour gestionnaire |
| POST | /activities | Création privée par défaut |
| PATCH | /activities/{id} | Modification versionnée ou archive |
| GET | /teams | Équipes actives id/label paginées, write requis |
| GET | /people | Personnes non parties id/label paginées, write requis |

GET agenda : from_date et to_date obligatoires (Y-m-d, fin exclue), période
de 1 à 93 dates locales valides. timezone IANA facultatif, défaut personne liée
au compte puis organisation. Filtres kind, team_id UUID, management booléen
et archived booléen ; archives seulement en mode gestion. page 1–100000 et
per_page 1–100, défaut 10. Tri starts_at/id ; chevauchement starts_at < borne
haute ET ends_at > borne basse. La pagination compte uniquement les activités
autorisées. Références : page/per_page seulement. Détail : management seulement.
Toutes les réponses portent Cache-Control private,no-store.

POST : title (160), kind training/scrim/match/meeting/academy/bootcamp/sponsor/travel,
starts_at, ends_at, origin_timezone obligatoires. Instants au format
Y-m-dTH:i:sP (secondes et offset numérique obligatoires) ; offset et heure
locale doivent correspondre au fuseau IANA d’origine. Exemple à Paris :
2026-10-25T02:30:00+02:00 et 2026-10-25T02:30:00+01:00 sont deux occurrences
distinctes autorisées. 2026-03-29T02:30:00+01:00 est refusé. Stockage UTC.
Fin > début, durée réelle maximale 31 jours. Au PATCH, transmettre ensemble les
deux instants et le fuseau dès qu’un des trois change.

Facultatifs : description nullable (4000), location nullable (200), url nullable
(2000), team_id UUID nullable, participant_ids liste de 0–50 UUID distincts,
visibility private/team/organization. Personnes du même tenant, non parties ;
équipe active dans un jeu activé. Un partage team exige team_id. Tout partage
non privé, même lors d’une autre modification, exige calendar.publish et
confirm_sharing accepté. Retirer le partage efface l’acteur/date d’autorisation.
URL HTTPS, sans identifiants, port explicite ou contrôles ; paramètres
d’invitation conservés, aucun accès réseau.

PATCH exige version courante. Champs absents conservés, null explicite pour
vider une valeur nullable. Liste participants transmise = remplacement complet,
liste absente = conservation ; aucune convocation ni permission créée.
Archive : seulement version + archive accepté. Terminale, pas de DELETE.
Un 409 impose une relecture et une nouvelle décision humaine.

Resource : id, title, description, kind, starts_at/ends_at UTC, origin_timezone,
starts_at_origin/ends_at_origin (offset d’origine), team_id/team_label nullable,
location/url nullable, visibility, participants [{id: person_id, label nullable}],
archived_at, version. Aucun organization_id, compte global, champ RH ou état
d’invitation. Une ancienne personne/équipe indisponible garde son identifiant
historique avec label nullable. L’enveloppe paginée ajoute calendar :
viewer_timezone, display_timezone, from_utc, to_utc.

Visibilité : read requis pour toutes les voies. Privé = créateur ou participant
actuellement lié à une personne non partie ; équipe ajoute effectif actuel ;
organisation ajoute tous les lecteurs tenant habilités. management=1 requiert
write et inclut les activités privées/archives. Une inscription directe est
distincte d’une appartenance à l’équipe, et peut conserver l’accès après fin
d’effectif si la personne et les permissions restent actives.

404 pour objet hors tenant/hors audience ou référence étrangère/inactive ;
403 pour permission manquante ; 409 CALENDAR_VERSION_STALE ou CALENDAR_ARCHIVED ;
422 pour période, offset, partage ou payload invalide. Liste fermée, y compris
refus des clés littérales contenant un point : match_id/event_id/cohort_id,
recurrence, organization_id et champs serveur non acceptés. Aucune liaison
fictive à un futur dossier métier ; aucun endpoint anonyme, ICS ou notification.

Exemple de lecture avec le client SPA existant :

```ts
await tenancyRequest<CalendarPage>(
  'tenants/' + organizationId + '/calendar/activities?' +
  new URLSearchParams({
    from_date: '2026-10-25', to_date: '2026-10-26',
    timezone: 'Europe/Paris', per_page: '10',
  }),
  { revision },
)
```

Le type CalendarPage vient de api/calendar. Réutiliser le transport SPA pour
CSRF, authentification, confirmation et révision ; ne pas fabriquer les UUID.

## Disponibilités et absences — UCG-CAL-002

Préfixe authentifié `/api/v1/tenants/{tenant}/calendar`, UUID tenant et objets,
session SPA/MFA, contrôle tenant, confirmation et X-Tenant-Revision en écriture.
Toutes les réponses sont private,no-store.

| Méthode | Suffixe | Usage |
| --- | --- | --- |
| GET | /availabilities | Vue personnelle ou collective, paginée |
| POST | /availabilities | Déclarer un créneau |
| GET | /availabilities/{id} | État courant accessible |
| PATCH | /availabilities/{id} | Modifier ou annuler avec version |
| GET | /availabilities/{id}/history | Instantanés paginés, auteur concerné ou gestionnaire |
| GET | /availability-people | Personnes actuelles id/label, read requis |

Droits organisationnels explicites : availability.self permet sa propre personne
actuelle liée ; availability.read permet le collectif ; read+manage permettent
la gestion des autres personnes. Ni calendar.read/write, ni le rôle propriétaire
ou technique ne remplacent ces droits. MFA requis. L’historique et le commentaire
exigent self pour sa personne ou read+manage pour les autres. Un lecteur collectif
sans ces droits reçoit les autres champs, sans clé comment et sans historique.

GET liste : from_date/to_date obligatoires Y-m-d, fin exclue, 1–93 dates locales.
timezone IANA facultatif, défaut personne liée puis tenant. collective booléen,
défaut false (self requis) ; true exige read. person_id UUID uniquement en mode
collectif. status available/unavailable/uncertain facultatif ; cancelled booléen,
défaut false, true sélectionne les annulés. page 1–100000, per_page 1–100,
défaut 10. Tri starts_at/id, chevauchement starts_at < fin ET ends_at > début.
Le scope personnel est appliqué avant comptage/pagination ; sans personne liée,
la liste personnelle est vide. Aucun créneau ne signifie pas « disponible ».

POST : status, starts_at, ends_at, origin_timezone obligatoires. Instants ISO
Y-m-dTH:i:sP, secondes et offset numérique exigés ; heure/offset cohérents avec
le fuseau d’origine, stockage UTC. Heure inexistante refusée ; heure répétée
acceptée avec un offset valide explicite. Fin strictement supérieure au début,
31 jours réels maximum. comment nullable facultatif, 1000 caractères, pas de
motif médical requis. person_id UUID facultatif à la création, déduit de la
personne actuelle liée sinon ; choisir un autre id exige read+manage. Références
actuelles du même tenant seulement ; aucun nouvel accès/compte créé.

PATCH : version UUID courant obligatoire ; status/comment modifiables, absence
de champ = conservation, comment:null = effacement. Tout changement horaire
exige ensemble starts_at,ends_at,origin_timezone. person_id reste immuable.
Annulation : seulement version + cancel accepté ; terminale et sans DELETE.
Un chevauchement avec un autre créneau actif de la même personne est refusé,
quel que soit le statut ; l’adjacence et les autres personnes restent permises.

Resource Availability : id,person_id,person_label nullable,status,
comment seulement si autorisé, starts_at/ends_at UTC, origin_timezone,
starts_at_origin/ends_at_origin, cancelled_at, version, number,
can_edit,can_cancel,can_history. Aucun identifiant de compte/organisation,
donnée RH ou acteur global. Enveloppe AvailabilityPage avec availability :
viewer_person_id nullable, viewer_timezone, display_timezone, from_utc,to_utc,
can_self,can_manage. Une personne partie conserve ses anciennes références
avec label nullable ; aucun accès personnel après départ.

Historique : page/per_page seulement, défaut 20, maximum 100 ; tri number
décroissant. Chaque élément : id,number,action created/updated/cancelled,status,
comment,starts_at,ends_at,origin_timezone,cancelled_at,version,created_at.
Les états précédents restent immuables. Pas d’acteur global dans la réponse.
Détail : aucun filtre. Références : page/per_page seulement.

404 pour objet hors tenant/hors scope personnel, personne étrangère/partie en
écriture ordinaire ; 403 pour droit manquant ; 422 pour payload fermé, dates,
offsets, période, commentaire ou absence de personne liée. Les clés serveur,
les clés littérales avec point et tout champ médical inconnu sont refusés.
409 AVAILABILITY_VERSION_STALE, AVAILABILITY_CANCELLED ou AVAILABILITY_OVERLAP :
relire puis décider explicitement, sans rejeu automatique.

Exemple de lecture personnelle avec le client SPA existant :

```ts
await tenancyRequest<AvailabilityPage>(
  'tenants/' + organizationId + '/calendar/availabilities?' +
  new URLSearchParams({
    from_date: '2026-10-25', to_date: '2026-10-26',
    timezone: 'Europe/Paris', per_page: '10',
  }),
  { revision },
)
```

AvailabilityPage vient de api/availability. Utiliser le transport existant pour
CSRF/session/révision. Une annulation après départ reste permise au gestionnaire ;
la réécriture du créneau d’une personne partie est refusée. Aucun changement RH,
envoi de message, convocation, conflit d’activité ou récurrence dans ce ticket.

## Réservations et exceptions de conflit — UCG-CAL-003

Préfixe privé /api/v1/tenants/{tenant}/calendar ; session/MFA/tenant et CSRF.
Les mutations persistantes exigent confirmation du tenant et X-Tenant-Revision.
L’aperçu est un POST de lecture sans confirmation préalable : les champs privés
ne sont pas placés dans une URL. Toutes les réponses sont private,no-store.

| Méthode | Suffixe | Autorisation |
| --- | --- | --- |
| GET | /resources | calendar.read + calendar.write |
| POST | /resources | read/write + calendar.resources.manage |
| PATCH | /resources/{resource} | read/write + calendar.resources.manage |
| POST | /activity-conflicts | calendar.read + calendar.write |
| GET | /activities/{activity}/conflict-overrides | read/write + availability.read |

Ressource : création key (80, minuscules/chiffres séparés par tirets, unique
tenant), label (120), kind room/equipment/other. Réponse id,key,label,kind,status,
version. PATCH exige version ; label modifiable, ou archive accepté seul avec
version. key/kind restent fixes, archivage terminal, aucun DELETE.
Liste : page 1–100000, per_page 1–100 (défaut 10), archived booléen (défaut false),
tri key/id. Le lecteur ordinaire ne peut pas énumérer le référentiel.

Activités POST/PATCH : resource_ids liste facultative de 0–20 UUID distincts,
même tenant et ressources actives lors d’une affectation ou d’un changement de
période. Absence au PATCH = conservation ; [] = retrait complet. Une activité
renvoie resources [{id,label,archived}], y compris aux lecteurs de son audience.
Une archive d’activité conserve ses réservations mais les exclut des conflits.
Un nom de lieu ne réserve pas automatiquement une ressource.

POST aperçu : même payload métier que POST activité, sans archive ni champs
d’exception ; à l’édition, activity_id UUID et version courante, puis seulement
les champs modifiés. Le préparateur complète les références et horaires depuis
l’activité. Les règles horaires/partage CAL-001 restent intégralement appliquées.

Réponse CalendarConflictReport : conflicts (0–100), truncated, availability_checked,
can_override, review_token, expires_in=300. Chaque conflit : type activity,
unavailable ou uncertain ; id,version,title nullable, starts_at/ends_at UTC,
causes [person,team,resource,availability], person_ids et resource_ids communs.
L’aperçu est réservé aux writers, déjà habilités au mode gestion des activités
privées. Aucun commentaire de disponibilité ou description/URL/lieu projeté.

Le périmètre est constitué des participants explicitement inscrits, de l’identité
de l’équipe associée et des ressources sélectionnées. Aucun roster développé
automatiquement. Activités archivées et disponibilités annulées exclues ;
adjacence permise. Les disponibilités unavailable/uncertain sont incluses
seulement avec availability.read, sinon availability_checked=false. Pas de
déclaration ne signifie pas disponible ; un créneau available ne bloque pas.

Enregistrement : conflict_review facultatif s’il n’y a aucun conflit, mais vérifié
s’il est transmis. Si conflit, aperçu exact et courant obligatoire, puis permission
calendar.conflicts.override, confirm_conflicts accepté et conflict_reason (10–1000
caractères après trim). Tout nouveau PATCH restant en conflit exige une nouvelle
exception ; l’ancienne ne vaut pas dérogation permanente. Pas de motif privé dans
l’audit générique. Plus de 100 conflits = refus de toute exception.

Le jeton authentifié expire après cinq minutes et lie acteur/tenant,
activité/version, instants, participants, équipe, ressources et résultats.
Changement de portée, de conflit ou d’habilitation à la lecture de disponibilité :
nouvel aperçu nécessaire. La sauvegarde réévalue dans sa transaction sous verrou
organisation, y compris la création sans activité préexistante.

Historique : page/per_page seulement, défaut 10, maximum 100, tri created_at/id
décroissant. Éléments id,activity_version,reason,conflict_count,conflicts,
availability_checked,created_at ; pas d’acteur global, tenant ou jeton dans la
réponse. Motif et instantané sont immuables et chiffrés en base.

404 : activité/ressource hors tenant ou ressource inactive affectée.
403 : droit manquant. 422 : payload fermé, référence/période/partage invalide,
motif/confirmation manquant ou exception sans conflit.
409 : CALENDAR_RESOURCE_STALE, CALENDAR_RESOURCE_ARCHIVED,
CALENDAR_RESOURCE_KEY_TAKEN, CALENDAR_CONFLICT_REVIEW_REQUIRED,
CALENDAR_CONFLICTS_CHANGED ou CALENDAR_CONFLICT_LIMIT ; les codes de version et
d’archive CAL-001 restent valables. Relire et confirmer à nouveau sans rejeu.

Exemple : voir le client SPA et le déroulement aperçu/décision/confirmation dans
README. Ajouter activity_id/version seulement pour l’aperçu d’une édition ;
l’URL cible et version identifient ensuite le PATCH.

## Convocations et rappels internes — UCG-CAL-004

Préfixe privé `/api/v1/tenants/{tenant}/calendar`, session SPA/MFA, tenant actif,
CSRF et autorisations explicites. Confirmation tenant, révision et verrou de
session pour POST/PATCH. Réponses `Cache-Control: private,no-store`.

| Méthode | Suffixe | Autorisation |
| --- | --- | --- |
| GET | /invitations | calendar.read ; boîte personnelle filtrée avant pagination |
| GET | /activities/{activity}/invitations | read + write + invitations.manage |
| POST | /activities/{activity}/invitations | read + write + invitations.manage |
| PATCH | /invitations/{invitation} | read + invitations.respond ; destinataire actuel uniquement |
| GET | /invitations/{invitation}/deliveries | read ; destinataire actuel ou gestionnaire habilité |

Les permissions courtes du tableau ont le préfixe `calendar.`.
Les listes/historiques acceptent uniquement page (1–100000) et per_page
(1–100, défaut 10), avec tri stable. Aucun filtre libre par compte/personne
ou organisation. Boîte : personne actuelle liée, recipient_id du compte courant,
convocation remise, activité non archivée et version courante, participant
encore inscrit. Ces conditions précèdent le comptage et la pagination.
Le gestionnaire consulte aussi les anciennes versions ; leur état current=false
ne permet aucune nouvelle réponse.

POST : activity_version UUID, response_deadline ISO `Y-m-dTH:i:sP` (secondes
et offset numérique requis), reminder_offsets liste présente de 0 à 3 entiers
stricts distincts entre 1 et 10080, confirm_invitations accepté.
Échéance future et <= début de l’activité. Cibles = tous les participants
actuels explicitement inscrits (1 à 50), jamais un roster développé ni des
adresses libres. Paramètres et identité figés après émission. Une seule émission
par version d’activité ; une configuration différente exige une nouvelle version.
Réponse 201 : collection paginée de suivi, avec toast succès standard.

PATCH : version UUID de la convocation et response pending/present/absent.
Aucune identité/cible ou réponse d’un autre participant acceptée. Contrôle du
lien actuel, remise effectuée, activité/version/participant et échéance en
transaction. La même réponse avec version courante est idempotente ; sinon
nouvelle version et audit minimal atomique. Aucun effet sur la disponibilité RH.

Resource convocation : id, activity_id, activity_version, title (titre actuel
de l’activité), starts_at UTC, origin_timezone, person_id, person_label nullable,
response, response_deadline UTC, reminder_offsets, responded_at, sent_at,
current, expired, blocked_reason nullable, delivery_count, can_respond, version.
blocked_reason : issuer_unavailable/recipient_unavailable. Pas de compte global,
tenant, e-mail, description, URL privée, acteur ou donnée RH. Anciennes activités :
titre actuel et référence de version, pas de copie documentaire historique.
Les collections de convocations ajoutent
calendar_invitations.delivery_enabled : tâche configurée, sans promesse de santé
du worker/scheduler. Ce statut ne change pas les autorisations.

Resource remise : id, kind invitation/reminder, offset_minutes (0 pour la
convocation), created_at UTC. Historique append-only sans run_id, acteur,
destinataire global ou corps de message. Ces lignes constituent les notifications
internes ; aucune copie par e-mail, Discord ou webhook.

La remise et les rappels sont asynchrones via la tâche tenant calendar-invitations,
désactivée par défaut (activation serveur CALENDAR_INVITATIONS_ENABLED).
Seules les réponses pending avant échéance sont traitées, en lots de 25.
Droits émetteur/destinataire, MFA, personne et lien actuels revalidés à chaque
passage. Une absence de compte ou de droit bloque sans créer d’accès.
Unicité SQL et transaction commune remise/état empêchent les doubles remises,
y compris lors d’une nouvelle tentative. Rattrapage du seul seuil le plus urgent ;
aucun seuil déjà dépassé lors de la première remise n’est rattrapé ensuite.

404 : objet étranger/hors destinataire ou personne non liée en réponse ordinaire.
403 : droit absent. 422 : payload non fermé, échéance, seuils ou confirmation
invalides, aucun participant actuel.
409 : CALENDAR_VERSION_STALE, CALENDAR_ARCHIVED,
CALENDAR_INVITATIONS_ALREADY_ISSUED, CALENDAR_INVITATION_STALE,
CALENDAR_INVITATION_OBSOLETE, CALENDAR_INVITATION_EXPIRED.
Relire puis décider, sans réessai automatique. Toute modification de l’activité
rend les convocations précédentes obsolètes ; notification automatique de
report/annulation CAL-006 et ICS CAL-005 restent distincts.
Exemples SPA et activation opérationnelle : README, section CAL-004.
## Calendrier ICS privé — UCG-CAL-005

Gestion personnelle, sous le contexte tenant habituel, MFA et `calendar.read` +
`calendar.export` explicites :

| Méthode | Route sous `/api/v1` | Résultat |
| --- | --- | --- |
| GET | `/tenants/{tenant}/calendar/feed` | `data: null` ou métadonnées du lien du compte courant |
| POST | `/tenants/{tenant}/calendar/feed` | 201, `data: {feed, url}` ; secret à usage d’affichage unique |
| PATCH | `/tenants/{tenant}/calendar/feed` | métadonnées du lien révoqué |
| GET | `/calendar-subscriptions/{tenant}/{feed}.ics?token=…` | capacité sans session, `text/calendar; charset=UTF-8` |

POST exige confirmation du tenant, `X-Tenant-Revision` et JSON fermé :

```json
{"expected_version":null,"before_days":7,"after_days":60,"expires_in_days":30,"confirm_export":true}
```

`expected_version` présent/null pour la première création, sinon version UUID
courante pour renouveler. Bornes entières strictes : before_days 0–30,
after_days 1–90, cumul ≤ 93 ; expires_in_days 1–90. PATCH exige la même confirmation
et `{ "version": "UUID_COURANT", "confirm_revocation": true }`. Une version
obsolète retourne 409 `CALENDAR_FEED_STALE`, sans rejeu. Une seconde révocation
avec la version déjà révoquée est idempotente. GET ne restitue jamais le secret
ni son hash. Métadonnées : id, before_days, after_days, expires_at, revoked_at,
status active/expired/revoked, version. Notifications succès du client existant.

Téléchargement : UUID tenant/lien et seul query parameter `token`, 64 caractères
hexadécimaux minuscules. Ni session ni sélection tenant interactive requise ;
le secret résout son propre acteur. Compte vérifié, MFA, organisation et adhésion
actives, rôles et audience courants sont vérifiés à chaque lecture. Un token ne
peut servir un autre tenant/lien. Jeton invalide, expiré, révoqué ou droits perdus :
404 générique. Les erreurs inattendues donnent une réponse indisponible sans
corps ICS partiel. Limite de 12/min par empreinte et 120/min par IP ; 429 avec
Retry-After. Dépassement 500 activités/512 Kio : 409
`CALENDAR_FEED_LIMIT_EXCEEDED` ; quota tenant d’export : 429.

Projection UTC minimale : titre SUMMARY échappé, UID opaque, DTSTAMP,
DTSTART/DTEND, CLASS PRIVATE et TRANSP OPAQUE. Lignes RFC 5545/UTF-8 repliées,
CRLF et pas d’injection de propriétés via les titres. Fenêtre glissante en jours
UTC, intersection `[from,to[` avec les activités non archivées accessibles en
mode personnel. Aucun champ privé additionnel ou relation dans le flux.

Cache-Control `private, no-store` ; pas d’ETag ni de 304, y compris requête
conditionnelle après révocation. Referrer-Policy no-referrer, nosniff et noindex
sur le téléchargement. Masquer token dans les logs du proxy/APM et ne pas mettre
le flux en cache partagé. La révocation bloque les synchronisations suivantes ;
les données déjà copiées restent sous contrôle du lecteur externe. Voir README
pour exemple SPA, activation et limites opérationnelles.
## Reports, annulations et historique — UCG-CAL-006

Sous session Sanctum, MFA et contexte tenant habituel :

| Méthode | Route sous /api/v1 | Droits et résultat |
| --- | --- | --- |
| GET | /tenants/{tenant}/calendar/changes | calendar.read ; ses messages remis et encore accessibles |
| GET | /tenants/{tenant}/calendar/activities/{activity}/changes | calendar.read + calendar.write ; historique de gestion, y compris annulation |
| PATCH | /tenants/{tenant}/calendar/activities/{activity} | calendar.read + calendar.write ; report ou annulation via le contrat existant |

Les GET n’acceptent que page entier 1–100000 et per_page entier 1–100
(défauts 1/10). Les paramètres inconnus sont rejetés. Pagination standard,
calendar_changes.delivery_enabled indique la configuration de remise, sans
garantir la disponibilité du scheduler. Réponses private, no-store.

Tout changement effectif de starts_at, ends_at ou origin_timezone d’une activité
existante exige change_reason : chaîne non blanche, 10–1000 caractères après
nettoyage utile. La période complète et le fuseau restent obligatoires ensemble.
Le contrôle POST activity-conflicts reçoit aussi change_reason ; les règles de
conflit/review_token/exception CAL-003 s’appliquent. Pas d’événement pour un simple
renommage ou les mêmes instants et fuseau. Les créations n’acceptent pas ce motif.

L’annulation utilise exclusivement :
```json
{"version":"UUID_COURANT","archive":true,"change_reason":"Annulation opérationnelle du créneau."}
```
Aucun autre changement dans cette requête. Confirmation tenant et
X-Tenant-Revision restent obligatoires. archived_at et cancelled_at sont fixés
au même instant, les relations sont conservées et l’activité devient terminale.
Le JSON CalendarActivity ajoute cancelled_at nullable ; null pour les anciennes
archives. Les prochaines lectures ICS ne contiennent plus l’activité annulée.

Historique : id, activity_id, activity_version, kind rescheduled/cancelled,
title, reason, before/after {starts_at, ends_at, origin_timezone}, occurred_at,
recipients_count, delivered_count et blocked_count. UTC explicite pour les
instants ; fuseaux IANA conservés. Le titre est celui du changement enregistré.

Boîte personnelle : id de remise, change (mêmes champs de snapshot sans les
compteurs), delivered_at, current. current compare la version du changement à
celle de l’activité actuelle ; false signale un changement historique. Filtrage
compte/personne et audience actuelle avant pagination, même pour une annulation.
Aucun email, acteur, compte cible, autre destinataire, description, lieu ou lien
de réunion n’est exposé. Aucun marquage de lecture dans ce lot.

Motif manquant/invalide ou mélange annulation/autres attributs : 422 localisé.
Version obsolète : 409 CALENDAR_VERSION_STALE. Activité déjà terminale avec sa
version courante : 409 CALENDAR_ARCHIVED. Activité étrangère/invisible : 404 ;
droits insuffisants : 403. Les conflits CAL-003 conservent leurs codes.
Aucun rejeu automatique du client. Succès PATCH : toast Flasher adapté à
l’annulation ou à l’enregistrement ; exemples SPA et activation dans README.

## Dossiers Match et changements de statut — UCG-MATCH-001

Session Sanctum, MFA, tenant actif ; sports.read obligatoire. sports.write
supplémentaire pour gestion et sélection de références. Les mutations exigent
confirmation du tenant et X-Tenant-Revision, avec les protections CSRF usuelles.

| Méthode | Route sous /api/v1/tenants/{tenant} | Résultat |
| --- | --- | --- |
| GET | /matches | liste paginée de dossiers accessibles |
| GET | /matches/{match} | un dossier sous son audience |
| POST | /matches | création proposed, 201 et notification Flasher |
| PATCH | /matches/{match} | modification partielle d’un dossier proposed |
| POST | /matches/{match}/transitions | transition motivée avec version courante |
| GET | /matches/{match}/history | historique paginé sous la même audience |
| GET | /matches/references/{type} | références minimales paginées pour l’éditeur |

Listes : page 1–100000, per_page 1–100, défauts 1/10. GET matches accepte aussi
game_id UUID, kind scrim/official, status parmi les huit états et management
booléen. Show/history acceptent management ; true exige sports.write et inclut
les dossiers privés du tenant. Historique et références acceptent la pagination ;
références accepte game_id facultatif pour filtrer équipes/instantanés. Types :
game, person, team, opponent_snapshot. Chaque choix expose id/label seulement.

POST : title non blanc ≤160, game_id actif et disponible, kind scrim/official,
format bo1/bo3/bo5/lobby/free_for_all, starts_at/ends_at avec secondes et offset,
origin_timezone IANA, responsible_person_id actif du tenant, participants de
2 à 100 éléments {kind, source_id}. Kind = person/team/opponent_snapshot, source
UUID issu de la lecture autorisée ; aucun nom libre, score ou clé additionnelle.
Doublons de même nature/source refusés. BO1/BO3/BO5 imposent deux participants.
Début/fin cohérents avec le fuseau, durée positive ≤31 jours. Les heures répétées
exigent un offset valide ; les heures inexistantes sont rejetées.

visibility est facultative, défaut private ; team ajoute les membres actuels
des équipes participantes (au moins une équipe interne), organization ajoute
ses lecteurs sportifs. sports.publish + confirm_sharing:true sont exigés à
l’élargissement et lors d’un remplacement des participants déjà partagés.
Les créateurs et personnes inscrites/responsables lisent sous leurs droits
courants ; les références externes n’acquièrent aucun accès.

PATCH accepte les mêmes champs hors game_id, plus version UUID obligatoire.
Un seul champ temporel modifié exige la période et le fuseau complets ensemble.
Les participants sont remplacés ensemble, uniquement en proposed. Le noyau
d’un confirmed est gelé : réouverture explicite par transition avant correction.
Un payload vide ou limité à version/consentement est refusé.

Transition POST : {version, status, reason}, motif non blanc 10–1000 caractères.
Parcours proposé → confirmé → en cours → terminé à valider ; confirmed → proposed
pour correction, annulation depuis proposed/confirmed/in_progress. Validation
et démarrage relisent jeu, responsable et participants actuels. Le modèle inclut
validated/disputed/forfeited mais le workflow de résultats est requis avant
de les commander ; aucune entrée de score n’est acceptée par ce lot.

MatchResource : id/title/kind/format, game_id/key/name conservés, status/version,
visibility, starts_at/ends_at UTC et variantes _origin, origin_timezone,
responsible_person_id/label conservé, participants ordonnés {id,kind,source_id,
label,reference_version,slot}, editable, available_transitions opérationnelles.
Aucun created_by, email, score, contact externe ou donnée administrative.
Historique : id, from_status nullable à création, to_status, match_version,
reason nullable à création, occurred_at. Événements et motifs conservés.

403 pour droits insuffisants ; 404 pour objet ou référence invisible/étranger ;
422 pour saisie fermée, période, participant/motif/consentement invalides.
409 MATCH_VERSION_STALE pour une version périmée, MATCH_NOT_EDITABLE hors
proposed, MATCH_TRANSITION_INVALID pour un saut interdit ou un terminal,
MATCH_RESULT_WORKFLOW_REQUIRED pour une transition liée aux résultats.
Un jeu indisponible conserve GAME_CONTEXT_UNAVAILABLE. Échec transactionnel :
aucune écriture partielle. Cache-Control private,no-store sur les réponses.

L’horaire est une donnée du dossier, sans réservation calendrier ni garantie
d’absence de conflit. Lineups, parties, scores, preuves, résultats publiés et
notifications restent leurs workflows dédiés. Voir README pour exemple SPA.

## Compositions et remplacements du match — UCG-MATCH-002

Routes sous `/api/v1/tenants/{tenant}/matches/{match}/participants/{participant}/lineups`.
Le participant doit être une équipe interne de ce dossier ; autres natures ou
références étrangères : 404. Session/MFA, audience du match, tenant confirmé et
révision de session suivent les conventions Match existantes.

| Méthode | Suffixe | Contrat |
| --- | --- | --- |
| GET | aucun | historique paginé, sports.read, même audience que le dossier |
| GET | /choices | compositions finalisées de purpose match de cette équipe, sports.read/write |
| POST | aucun | affectation d’une composition finalisée, sports.read/write, 201 |
| POST | /substitutions | échange titulaire/remplaçant du pool retenu, sports.read/write, 201 |

Pagination : page 1–100000, per_page 1–100, défauts 1/10. L’historique accepte
management booléen ; true exige sports.write et permet les dossiers privés en
gestion. Les choix exposent uniquement id/label. Cache-Control private,no-store.
Les corps et paramètres sont fermés ; aucun nom, rôle libre, auteur, date ou
rapport d’éligibilité ne peut être fourni par le client.

Affectation : `{version, lineup_id, reason}`. version est la version courante du
dossier Match, pas celle de la composition Teams. Motif non blanc 10–1000 caractères.
Si le dossier est partagé : sports.publish et `confirm_sharing:true` obligatoires.
Source finalisée, purpose match, équipe/jeu/tenant correspondants ; affectation
possible uniquement proposed/confirmed. Une réaffectation identique sans changement
est refusée. Après la première affectation, PATCH du dossier ne remplace plus ses
participants ; les autres champs proposed restent éditables selon leur contrat.

Remplacement : `{version, out_person_id, in_person_id, reason}`. Le sortant doit
être titulaire et l’entrant remplaçant de la dernière version retenue ; personnes
distinctes, aucune entrée extérieure au pool. Possible proposed/confirmed/in_progress.
Les rôles sont échangés, la source finalisée Teams reste intacte. Tout changement
est refusé en awaiting_validation, validated, disputed, cancelled ou forfeited.

Éligibilité : présence au roster, disponibilité des personnes/équipe/jeu et règles
retenues sont recontrôlées à l’affectation, au remplacement et au démarrage. La date
contrôlée est starts_at avant le match, puis max(date serveur, starts_at) en cours.
Un match d’équipes ne démarre pas sans composition par équipe interne ; une personne
ne participe pas à plusieurs camps. Les individus et instantanés externes ne
demandent pas de composition. Sans règlement : unassessed et contrôle du roster.
Avec règlement : eligible requis, aucun défaut de données ou de permission sur
les faits privés n’est assimilé à une preuve positive. L’admissibilité historique
de la source ne suffit pas à autoriser une nouvelle opération.

MatchLineupResource : id, participant_id, match_version, number, action assign/substitute,
phase, reason, actor_user_id (référence interne du compte), occurred_at serveur,
snapshot sportif conservé et assessment {status, checks, evaluated_at, effective_at}.
Le snapshot comprend source id/version, équipe/jeu/saison/libellés, finalisation,
références du règlement et du rapport initial, participants avec pseudonymes,
références de période du roster, rôles sportifs et positions. Aucun email, contact,
date de naissance ni valeur privée utilisée pour l’évaluation n’est exposé.
L’historique est trié par numéro décroissant. Le POST ajoute notification Flasher
et impose de reprendre match_version comme version du dossier pour la prochaine
commande. Aucun score, résultat, réservation ou notification externe n’est créé.

409 : MATCH_VERSION_STALE, MATCH_LINEUP_FROZEN, MATCH_LINEUP_REQUIRED,
MATCH_LINEUP_NOT_ELIGIBLE, MATCH_LINEUP_SOURCE_INVALID, MATCH_LINEUP_UNCHANGED,
MATCH_LINEUP_PARTICIPANTS_RETAINED, MATCH_LINEUP_DUPLICATE_PERSON, MATCH_LINEUP_LIMIT.
Une source non finalisée conserve TEAM_LINEUP_NOT_FINALIZED ; un jeu indisponible
conserve GAME_CONTEXT_UNAVAILABLE. Référence absente/étrangère/invisible : 404 ;
droits insuffisants : 403 ; saisie invalide/motif/consentement : 422 localisé.
500 révisions au plus par dossier. Un échec d’audit annule aussi version et révision ;
les conflits ne sont jamais rejoués automatiquement par le client.


## Parties et score de série — UCG-MATCH-003

Routes sous `/api/v1/tenants/{tenant}/matches/{match}` ; session Sanctum, MFA,
tenant actif et audience Match existants. Les mutations imposent tenant confirmé,
révision de session et version optimiste **commune** au dossier/compositions/scores.

| Méthode | Suffixe | Contrat |
| --- | --- | --- |
| GET | /games | historique paginé, sports.read ; management=1 exige aussi sports.write |
| POST | /score-rules | règles définitives, sports.read/write, 201 |
| POST | /games | ajout immuable d’une tentative, sports.read/write, 201 |

Les mutations sont permises en in_progress et awaiting_validation uniquement.
Sur un dossier partagé : sports.publish et confirm_sharing:true sont requis à
chaque mutation. Aucun droit propriétaire implicite, élargissement automatique
d’audience, partage public, notification externe ou approbation de résultat.

Configuration obligatoire avant la première partie : version UUID, reason
10–1000 caractères non blancs, allow_draw booléen explicite, game_limit entier
1–100. BO1/BO3/BO5 exigent respectivement 1/3/5. Les règles sont immuables dès
confirmation, même sans partie. Aucun endpoint de correction n’est fourni ici.

Partie : version UUID, reason 10–1000, outcome played/draw/forfeit/abandon/remake,
map ou mode non blanc obligatoire (160 caractères chacun, nullable), game_patch
non blanc obligatoire (80), results liste de 2–100 objets fermés
{participant_id,score}. Les identifiants sont ceux de MatchResource.participants,
pas les identifiants source People/Teams ; chaque participant du dossier doit
figurer une fois, sans intrus ni doublon même avec casse différente. score entier
0–1000000 pour toute partie comptée ; score:null pour **tous** dans un remake.
Aucun score agrégé, vainqueur, nom, auteur, date ou composition n’est accepté.

Calculs : BO = nombre de victoires, arrêt à la majorité ou au nombre prévu ;
un nul autorisé consomme une partie sans victoire, puis le plus de victoires
à la borne détermine le résultat (égalité possible si autorisée). Lobby/individuel
= somme des points déclarés sur game_limit parties, total le plus élevé en tête.
Les remakes sont des tentatives abandonnées **sans résultat**, conservées et non
comptées ; ils ne permettent pas d’effacer rétroactivement une partie saisie.
Une série terminée refuse toute tentative supplémentaire, même un remake.
Les nuls de partie doivent correspondre à une égalité en tête ; les autres
outcomes exigent un premier unique. Les nuls et égalités finales sont refusés
si allow_draw=false. Les forfaits/abandons de partie reçoivent des scores explicites
selon le règlement retenu, expliqués dans reason ; aucun barème de jeu n’est
inventé par le serveur et le statut du dossier Match n’est pas changé en forfeited.

GET : data tableau de MatchGameResource, links/meta de pagination et scorecard
{match_version,rules,series}. Pagination page 1–100000, per_page 1–100, défaut 1/10 ;
ordre number décroissant. rules:null et series:null avant configuration.
series = {complete,played,winner_ids,standings:[{participant_id,score}],draw}.
winner_ids reste vide tant que la série est incomplète ; en cas d’égalité finale,
il contient tous les premiers et draw=true. Ces résultats sont **non approuvés**.
POST : data {match_version,rules,series,game}, game:null pour la configuration,
notification Flasher. Reprendre match_version pour toute prochaine commande.

MatchGameResource : id, number, match_version, outcome, map, mode, game_patch,
reason, actor_user_id interne, occurred_at serveur et results contenant
participant_id, label conservé, score et lineup_revision_id nullable. La référence
de composition est celle en vigueur **à la saisie**, sans prétendre reconstruire
la composition d’une partie antérieure saisie tardivement. Aucune donnée
administrative ni coordonnées du compte/adversaire n’est exposée.

409 : MATCH_VERSION_STALE, MATCH_SCORE_FROZEN, MATCH_SCORE_RULES_FROZEN,
MATCH_SCORE_RULES_REQUIRED, MATCH_SERIES_COMPLETE, MATCH_GAME_LIMIT.
422 : saisie fermée, format incohérent, participants/scores/nul/motif/partage
invalides. 403 : permission/MFA insuffisants ; 404 : dossier invisible/étranger.
Cache-Control private,no-store. Échec d’audit : rollback du score et de la version.
Le client garde la saisie après 422 et ne rejoue jamais un 409.

Limites : 100 tentatives par dossier, remakes inclus ; 100 participants par
partie, tous issus du dossier. Lectures du score bornées à 100 tentatives ;
relations et compositions chargées en bloc. Le GET matérialise résumé/page sous
le verrou du match pour une version cohérente. Pas de cache d’autorisation ou
de contenu privé partagé. Corrections motivées/recalculs : MATCH-006 ; preuves,
soumission et approbation distincte : MATCH-004 ; diffusion : MATCH-007.


## Soumission, preuve référencée et validation — UCG-MATCH-004

Routes privées sous `/api/v1/tenants/{tenant}/matches/{match}/results` ; Sanctum,
MFA et audience Match existants. Aucune attribution automatique ni accès adverse.
La preuve est une **référence textuelle déclarée** conservée chiffrée : aucun
fichier n’est déposé, archivé, téléchargé ou vérifié automatiquement. L’auteur
conserve la pièce dans son espace privé et garantit sa consultation par le validateur.
La référence ne prouve ni l’intégrité ni la disponibilité future de cette pièce.

| Méthode | Suffixe | Permission et résultat |
| --- | --- | --- |
| GET | aucun | sports.read, audience du dossier, historique paginé |
| POST | aucun | sports.read/write, soumission, 201 |
| POST | /approvals | sports.read/validate, approbation indépendante, 200 |
| POST | /disputes | sports.read et sports.write ou sports.validate, contestation, 200 |

Toutes les mutations exigent tenant confirmé, révision de session, version UUID
courante du dossier et reason de 10–1000 caractères non blancs. Un match partagé
exige en plus sports.publish et confirm_sharing:true **sur chaque action**.
Le validateur n’a pas besoin de sports.write, mais reste soumis à l’audience du
dossier ; aucun accès de gestion aux matchs privés ne vient de sports.validate.
GET management=1 conserve l’exigence sports.write. Paramètres et corps fermés.
Pagination page 1–100000, per_page 1–100, défauts 1/10, tri number décroissant.

Soumission : outcome joué = played, sinon forfeit/abandon ; proof_reference
10–2000 caractères et confirm_proof:true obligatoires. played exige une série
MATCH-003 complète ; ni score agrégé, snapshot, vainqueur ni auteur fournis par
le client. forfeit/abandon exigent winner_participant_id appartenant au dossier,
sans inventer de parties ni changer les scores enregistrés. Ces décisions de
rencontre sont distinctes des forfaits/abandons de **partie** MATCH-003.

played : soumission en in_progress/awaiting_validation ou après contestation.
forfeit/abandon : également possibles en confirmed avant le démarrage. Toute
soumission passe en awaiting_validation et fige règles/parties/compositions ;
une nouvelle soumission nécessite d’abord une contestation. Le serveur scelle
règles, série calculée ou partielle/null, participants/libellés, bénéficiaire(s),
IDs des parties immuables et auteurs des règles/parties. Les contributeurs restent
privés, ne figurent dans aucune Resource, et servent au contrôle d’indépendance.

Approbation : submission_id de la proposition courante et confirm_evidence:true
obligatoires. L’approbateur doit être distinct du soumissionnaire **et** de tous
les auteurs des règles/parties, même si une autre personne a soumis leurs scores.
Il déclare avoir consulté la preuve et vérifié le résultat. played/abandon passe
en validated ; forfeit en forfeited. Aucune publication ou notification automatique.

Contestation : submission_id courant et motif obligatoire ; disponible pour une
proposition en attente ou approuvée, y compris un forfait approuvé. Le dossier
passe en disputed et perd son approbation courante ; les décisions antérieures
et références de preuve restent immuables. Une nouvelle soumission, puis une
nouvelle approbation indépendante, sont nécessaires. Les parties restent figées ;
la correction motivée des scores et le recalcul relèvent de MATCH-006.

GET : data MatchResultEventResource[], links/meta et result
{match_version,status,locked,current,submission,allowed_actions}. current est le
dernier événement ; submission la dernière proposition associée. allowed_actions
est calculé côté serveur pour le compte courant, sans dispenser des contrôles à
l’exécution. POST : data de même forme, sans historique, et notification Flasher.
Toujours reprendre match_version et submission.id d’une lecture fraîche ; aucun
rejeu après 409. GET /games expose désormais scorecard.result_locked, qui fige
également ses formulaires ; les snapshots et scores n’affirment pas une approbation.

Événement : id, number, action submit/approve/dispute, outcome, from_status,
to_status, match_version, submission_id nullable à la soumission, proof_reference
et snapshot présents uniquement à la soumission, reason, actor_user_id interne,
occurred_at serveur. Aucun contributeur, donnée administrative ou coordonnées.
La référence est rendue en texte échappé, jamais ouverte automatiquement.

409 : MATCH_VERSION_STALE, MATCH_RESULT_SUBMISSION_STALE,
MATCH_RESULT_TRANSITION_INVALID, MATCH_RESULT_INDEPENDENT_REQUIRED,
MATCH_RESULT_INCOMPLETE, MATCH_RESULT_LIMIT ; écritures de scores après soumission :
MATCH_RESULT_LOCKED. 422 : saisie fermée, pièce/motif/confirmations/bénéficiaire
invalides. 403 : droits/MFA insuffisants ; 404 : dossier hors audience/tenant.
Cache-Control private,no-store ; transaction événement → dossier → historique
statut → audit, rollback complet en cas d’échec.

200 événements au plus par match, lectures paginées et snapshots bornés aux
100 participants/tentatives MATCH-003. Les transitions de résultat exigent un
événement SQL de même version/from/to ; les scores restent aussi gelés en SQL.
Aucun endpoint opérationnel générique ne valide ou conteste un résultat.


### MATCH-005 — Préparation, VOD, débrief et suivi

Base : `/api/v1/tenants/{tenant}/matches/{match}/notebook`.

- GET : page/per_page (1–100), management, audience internal/strategy/shared,
  kind objective/note/vod/debrief/action, state open/done facultatifs.
- POST : kind, audience, title (1–200), body (1–10000), vod_url HTTPS sans
  credentials (obligatoire pour VOD), timestamp_seconds nullable (0–604800),
  state open/done (action seulement, open par défaut), due_at nullable (action
  seulement, format ISO `Y-m-dTH:i:sP` avec décalage), confirm_sharing si shared.
- PATCH /{entry} : version courante obligatoire et champs éditables ci-dessus,
  sans kind ni audience ; confirmation renouvelée pour shared.
- GET /{entry}/history : page/per_page et management ; versions descendantes,
  mêmes droits de lecture que le contenu courant.

Read : sports.read, audience du match et sports.notes.read pour internal ou
sports.strategies.read pour strategy. management exige sports.write et ne donne
aucun accès sensible supplémentaire. Write : sports.read/write plus read/write
de l’espace ; shared exige sports.publish et confirm_sharing à chaque version.
MFA, organisation active/revision courante et confirmation pour les mutations.
Identifiants masqués/hors tenant : 404 ; filtre d’espace interdit : 403. Les
comptes sans délégation ne récupèrent ni métadonnées ni totaux des espaces privés.

GET retourne data MatchNotebookResource[], links/meta et writable_audiences
calculé côté serveur. Resource : entry_id, version (identifiant de révision),
number, kind, audience, title, body, vod_url, timestamp_seconds, state, due_at,
actor_user_id, occurred_at serveur. POST 201/PATCH 200 : data Resource et
notification Flasher. Champ inconnu/type/espace modifié : 422 ; conflit
MATCH_NOTEBOOK_VERSION_STALE, limite MATCH_NOTEBOOK_LIMIT : 409. Aucun rejeu.
Réponses Cache-Control private,no-store. Audit sans texte ni URL.

Exemples cURL pour un client déjà authentifié (cookie et CSRF courants), UUIDs
remplacés par ceux du tenant actif ; ne jamais inclure de credentials dans la VOD :

```bash
curl -b cookies.txt -H "Accept: application/json" \
  -H "X-Tenant-Revision: {revision-session}" \
  "{api}/api/v1/tenants/{tenant}/matches/{match}/notebook?audience=internal&per_page=10"

curl -b cookies.txt -X POST -H "Content-Type: application/json" \
  -H "Accept: application/json" -H "X-XSRF-TOKEN: {csrf-courant}" \
  -H "X-Tenant-Revision: {revision-session}" \
  "{api}/api/v1/tenants/{tenant}/matches/{match}/notebook" \
  --data '{"kind":"vod","audience":"strategy","title":"Rotation à revoir","body":"Analyser le déplacement collectif.","vod_url":"https://example.com/vod/42","timestamp_seconds":123}'

curl -b cookies.txt -X PATCH -H "Content-Type: application/json" \
  -H "Accept: application/json" -H "X-XSRF-TOKEN: {csrf-courant}" \
  -H "X-Tenant-Revision: {revision-session}" \
  "{api}/api/v1/tenants/{tenant}/matches/{match}/notebook/{entry}" \
  --data '{"version":"{version-contenu}","body":"Annotation corrigée après analyse."}'
```

Shared reste dans l’audience authentifiée du match ; aucun endpoint public,
iframe ou téléchargement. Lien ouvert explicitement avec protection du référent.
500 contenus/match et 100 révisions/contenu, dont versions historiques conservées.
La version du contenu ne remplace jamais match_version pour les opérations sportives.


### MATCH-006 — Correction idempotente et recalcul

Base : `/api/v1/tenants/{tenant}/matches/{match}/corrections`.

- GET : page (1–100000), per_page (1–10, défaut 10), management facultatif.
  Réponse : data MatchCorrectionResource[], pagination, corrections
  {match_version,score_revision,pending,can_correct}.
- POST : version sportive, score_revision présente et nullable au premier essai,
  idempotency_key UUID, reason de 10–1000 caractères, confirm_correction accepté,
  confirm_sharing sur match partagé, games (1–100 entrées distinctes).
  Chaque entrée : game_id existant, outcome played/draw/forfeit/abandon/remake,
  results complet {participant_id,score} pour les participants du dossier.
  Scores entiers 0–1000000, null uniquement pour remake. Le caractère remake reste
  fixe ; pas de nouveau game_id, carte, règle, composition ou agrégat imposé.
- POST /{correction}/retries : corps vide, remise en file de la demande inchangée
  et actuellement pending. Une demande déjà appliquée retourne son état sans effet.

Lecture : sports.read et audience du match ; management exige sports.write.
Écriture/reprise : sports.read/write/correct, MFA, contexte tenant courant confirmé.
Demande partagée : sports.publish et confirmation ; traitement/reprise revérifient
les droits courants. Aucun droit de correction implicite pour un validateur.
La tâche ne modifie pas le choix déjà confirmé ; une personne habilitée peut
relancer sans devenir l’auteur du contenu, son exécution étant tracée séparément.

POST retourne 202 et data : id, number, match_version, state pending/applied,
before/after (parties modifiées : game_id,number,outcome,results avec libellés et
composition historique), reason, actor_user_id, occurred_at, applied_at nullable,
notification Flasher. La même clé, le même auteur et le même payload validé
renvoient la même correction sans tâche/audit supplémentaire. Ne pas générer une
nouvelle clé pour rejouer une réponse perdue ; un nouveau contenu exige une
nouvelle clé et une nouvelle lecture des deux versions.

Les /games exposent scorecard.score_revision et recalculation_pending. Pendant
pending, series=null ; les parties affichées restent celles de la révision
précédemment appliquée. Une partie projetée porte correction_id, ses métadonnées
source restant historiques. Après complétion, toutes les pages et le calcul de
série utilisent la même correction. /results expose aussi score_revision et
recalculation_pending ; aucune action disponible pendant le traitement.
Une soumission après correction doit transmettre score_revision courante ; la
nouvelle preuve et l’approbation indépendante restent celles du workflow MATCH-004.

409 : MATCH_RECALCULATION_PENDING, MATCH_VERSION_STALE,
MATCH_SCORE_REVISION_STALE, MATCH_CORRECTION_KEY_REUSED, MATCH_CORRECTION_LIMIT,
MATCH_CORRECTION_PHASE, MATCH_CORRECTION_ASYNC_REQUIRED. 422 : input fermé,
score/participant/partie/motif/confirmation ou cohérence invalides. 403 : droits ;
404 : match ou correction hors tenant/audience. Réponses private,no-store.

Exemple pour client authentifié, cookie/CSRF et révision de sélection courants :

```bash
curl -b cookies.txt -X POST -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: {csrf}" \
  -H "X-Tenant-Revision: {revision-session}" \
  "{api}/api/v1/tenants/{tenant}/matches/{match}/corrections" \
  --data '{"version":"{version-match}","score_revision":null,"idempotency_key":"{uuid-demande}","reason":"Correction selon le rapport source vérifié.","confirm_correction":true,"games":[{"game_id":"{partie}","outcome":"played","results":[{"participant_id":"{participant-A}","score":0},{"participant_id":"{participant-B}","score":13}]}]}'
```

Configurer une file persistante et un worker default avant usage. Les clés de
cache privé incluent tenant/match/version/correction et sont invalidées avant
correction et application ; pending SQL masque toute ancienne série. Les preuves
et scores sources restent immuables. Aucune republication automatique ni événement
public ; seul un nouveau résultat soumis/approuvé fournit la validation courante.


### MATCH-007 — Abonnement privé et projection de scores

- `POST /api/v1/tenants/{tenant}/matches/{match}/realtime/auth`
  Body strict : `{"socket_id":"123.456","channel_name":"private-tenants.{tenant}.matches.{match}"}`.
  Réponse protocole Pusher/Reverb : `{"auth":"app-key:signature"}`, sans enveloppe Resource.
  Form Request, policy sports.read, MFA/session courante, contexte confirmé/révision,
  audience du match ; droits de gestion sports.write identiques aux autres API.
  Route globale broadcasting/auth interdite pour ce canal. Entrées inconnues : 422 ;
  mauvaise audience ou match étranger : 404, permission insuffisante : 403,
  contexte non confirmé/périmé : 409, non authentifié : 401.
- `GET /api/v1/tenants/{tenant}/matches/{match}/live` sans paramètres.
  Resource `data` : `match_version`, `score_revision`, `recalculation_pending`,
  `outcome` (approuvée uniquement), `winner_ids` (approuvés uniquement), `status`,
  `series` (null si recalcul en attente), `observed_at`. Sans preuve, motif, carnet,
  acteur ou composition. Les lignes series portent les identifiants des participants.
  Lecture cohérente sous verrou du match jusqu’à matérialisation des deux projections.
  Les deux réponses : `Cache-Control: private, no-store`.
- Événement `.match.score.changed`, canal Echo privé
  `tenants.{tenant}.matches.{match}` ; clés exactes `event_id` (UUID),
  `organization_id`, `match_id`, `changed_at` (UTC ISO 8601).
  Relecture systématique : aucune valeur ni permission transportée par l’événement.
  Anciens événements/événements réordonnés ne restaurent jamais d’ancien score.
  Un abonné dont les droits sont retirés peut encore recevoir des invalidations
  sans contenu tant que le socket est ouvert ; aucune nouvelle lecture autorisée.
- File broadcasts après commit : 3 tentatives, timeout 30 s, backoff 1/5/15 s.
  Échec de dispatch après commit journalisé sans rejouer l’action métier.
  Absence d’outbox, secours par relecture. Ne jamais déduire une validation d’un signal.

Exemple Axios avec session SPA, CSRF initialisé et contexte actif :

```ts
const response = await axios.get(`/api/v1/tenants/${tenant}/matches/${match}/live`, {
  withCredentials: true, headers: { 'X-Tenant-Revision': revision },
});
const live = response.data.data;
// Utiliser la signature du endpoint /realtime/auth pour le socket courant.
echo.private(`tenants.${tenant}.matches.${match}`).listen('.match.score.changed', () => reloadLive());
// À la fermeture/changement d’organisation : echo.leave(`tenants.${tenant}.matches.${match}`).
```

Reverb est optionnel pour les lectures ; le client relit au retour en ligne et
sur page visible toutes les 30 s. Sans confirmation d’organisation, seul ce secours
HTTP est utilisé. Le délai affiché inclut transport et relecture et dépend des
horloges des deux systèmes. Aucun nouveau droit ni migration pour MATCH-007.

Vérification des contrats : `back/tests/Feature/MatchRealtimeTest.php` (20 cas,
SQLite et PostgreSQL), hook/client et vue front, navigateur
`front/tests/e2e/match-realtime.spec.ts` avec protocole Pusher simulé.
Le serveur Vite de Playwright reçoit une clé `test-key` locale à la recette,
sans changement de .env ni secret réel. Les suites existantes sportives restent
vertes. Une connexion null n’enfile aucun job de broadcast ; une panne de transport
après commit ne provoque pas de nouvelle écriture sportive ni de réponse trompeuse.


### COMP-001 — Dossiers privés de compétitions externes

Préfixe `/api/v1/tenants/{tenant}/external-competitions`, authentification session
Sanctum, tenant courant et MFA. Lecture : sports.read + sports.competitions.read.
Écriture et choix des jeux : sports.competitions.write en supplément. Les écritures
exigent CSRF, `X-Tenant-Revision` et confirmation explicite du tenant courant.
Aucun grant implicite au propriétaire ou administrateur.

| Méthode | Suffixe | Fonction |
| --- | --- | --- |
| GET | vide | Dossiers paginés |
| GET | `/games` | Jeux disponibles paginés, écriture autorisée |
| POST | vide | Création motivée |
| GET | `/{competition}` | Détail privé |
| PATCH | `/{competition}` | Édition motivée ou archivage seul |
| GET | `/{competition}/history` | Instantanés et motifs paginés |
| GET / POST | `/{competition}/documents` | Dernières versions privées / nouveau document |
| PATCH | `/{competition}/documents/{document}` | Nouvelle version du même document |
| GET | `/{competition}/documents/{document}/history` | Versions paginées |
| GET | `/{competition}/documents/{document}/versions/{version}/download` | Version texte authentifiée |

JSON via Resources `{data, meta}` ; création 201, édition/lecture 200, toast de
succès aux mutations. Réponses `Cache-Control: private, no-store`. Liste : page
1–100000, per_page 1–25 (défaut 10), status active par défaut ou archived,
filtres exacts game_id, region, season. Historiques/documents : per_page 1–10.
Champs inconnus, même ceux ressemblant à des règles à jokers, refusés en 422.

Création : reference majuscule `[A-Z0-9][A-Z0-9_-]+` (2–64), game_id UUID actif,
name/organizer (1–200), region (1–80), season (1–100), website_url HTTPS facultatif
ou null, contacts liste facultative de dix éléments, reason (10–1000 après trim).
Chaque contact accepte exclusivement name (1–160), role (nullable, 120), email
(nullable, RFC, 254), phone (nullable, 40) ; les quatre clés sont présentes.
Référence et jeu sont immuables ; les coordonnées et textes privés sont chiffrés.
Le PATCH exige version UUID et ne contient que les métadonnées modifiées avec reason.
L’archivage est exclusivement `{version, archive:true, reason}` et reste définitif.

Document : kind rules ou note à la création, titre 1–200, body texte brut 1–20000,
source_url HTTPS facultatif/null, reason et competition_version UUID du dossier.
Le PATCH ajoute version UUID documentaire, sans kind ; les champs inchangés peuvent
être omis. Le parent ne change pas de version lors d’une édition documentaire.
Un seul règlement logique, au plus 100 documents et 100 versions/document.
Dossier : au plus 1000 révisions et 10 000 dossiers/tenant. Pas d’upload binaire.

409 : EXTERNAL_COMPETITION_VERSION_STALE, EXTERNAL_COMPETITION_DOCUMENT_STALE,
EXTERNAL_COMPETITION_REFERENCE_EXISTS, EXTERNAL_COMPETITION_RULES_EXISTS,
EXTERNAL_COMPETITION_ARCHIVED ou limite de volume ; 422 : validation/absence de
changement. Relire avant toute nouvelle confirmation, sans retry automatique.
Ressource ou version hors tenant/parent : 404 ; permission révoquée : 403.
Téléchargement `text/plain; charset=UTF-8`, attachment au nom fixe contenant l’UUID,
nosniff et CSP sandbox, aucune URL publique ni accès durable par lien signé.
Les anciens textes restent lisibles après archivage pour les habilités actuels.

Exemple avec le client Axios de session configuré pour CSRF et cookies Sanctum
(baseURL `/api/v1`, withCredentials et withXSRFToken), après confirmation du tenant :

```js
const root = `tenants/${tenantId}/external-competitions`;
const headers = { 'X-Tenant-Revision': tenantRevision };
const { data: created } = await api.post(root, {
  reference: 'CIRCUIT_2026', game_id: gameId,
  name: 'Circuit externe', organizer: 'Organisateur externe',
  region: 'Europe', season: '2026', website_url: 'https://organisateur.example/competition',
  contacts: [{ name: 'Contact sportif', role: 'Accueil', email: null, phone: null }],
  reason: 'Participation consignée après consultation du règlement.'
}, { headers });
const competition = created.data;
const { data: written } = await api.post(`${root}/${competition.id}/documents`, {
  competition_version: competition.version, kind: 'rules', title: 'Règlement reçu',
  body: 'Texte du règlement communiqué par l’organisateur.', source_url: null,
  reason: 'Conservation de la première version communiquée.'
}, { headers });
const doc = written.data;
const file = await api.get(`${root}/${competition.id}/documents/${doc.document_id}/versions/${doc.version}/download`,
  { headers, responseType: 'blob' });
```

L’inscription effective et le check-in restent chez l’organisateur ; suivi des
pièces, échéances et tâches en COMP-002, rattachements résultats/dotation en COMP-003.


### COMP-002 — Suivi interne de participation externe

Préfixe `/api/v1/tenants/{tenant}/external-competitions/{competition}/registration`.
Session Sanctum, MFA, tenant courant ; lecture : sports.read,
sports.competitions.read et sports.registrations.read. Écriture et choix des
personnes : sports.registrations.write supplémentaire. Aucun droit implicite du
propriétaire/administrateur. Écriture : CSRF, X-Tenant-Revision et tenant confirmé.

| Méthode | Suffixe | Fonction |
| --- | --- | --- |
| GET | vide | Dernier suivi ou data:null avant initialisation |
| PATCH | vide | Initialise ou ajoute une révision motivée, réponse 200 |
| GET | `/people` | Personnes actives/substitutes du tenant, choix paginés |
| GET | `/history` | Révisions complètes, auteurs, dates et motifs paginés |
| GET | `/reminders` | Historique paginé des rappels émis pour le compte courant |

Resources JSON et Cache-Control private/no-store ; notification Flasher au PATCH.
Pagination page 1–100000, per_page 1–10 (défaut 10). Le suivi complet contient
au plus 20 joueurs et 50 éléments ; l’historique au plus 1000 révisions/compétition.
Chaque PATCH envoie l’état complet, pas un diff imbriqué. Champs inconnus et clés
à jokers refusés, y compris dans les tableaux. Aucun fichier binaire accepté.

Contrôle optimiste : competition_version UUID du parent et expected_version UUID
de la dernière révision (null à l’initialisation). Ces versions sont indépendantes :
modifier le suivi ne change pas le parent. 409 COMPETITION_REGISTRATION_STALE,
COMPETITION_REGISTRATION_ARCHIVED ou COMPETITION_REGISTRATION_LIMIT : relire avant
une nouvelle confirmation, jamais de retry automatique. 422 : validation, aucun
changement ou roster approuvé modifié sans réévaluation. Référence étrangère au
tenant/parent (personne, document ou compétition) : 404 ; droit manquant : 403.

Payload : registration_status preparing/submitted/accepted/rejected/withdrawn,
payment_status not_required/pending/paid/refunded, roster_status
draft/submitted/approved/rejected, checkin_status
not_open/required/completed/missed/not_required. Aucun de ces états n’est déduit
par le serveur d’un contrat, d’une checklist complète ou d’une règle juridique.
external_reference nullable (160), source_url nullable HTTPS sans credentials,
notes nullable (2000), requirements avec clés age/residence/rank nullable (1000 chacune).
Motif reason obligatoire (10–1000 après trim). Un roster déclaré soumis/approuvé
ne peut être vide ; un roster approuvé modifié ne peut rester approuvé dans la
même commande. Les étiquettes historiques restent lisibles après un départ.

roster : liste de `{person_id, role:starter|substitute}`, sans doublon de personne.
Les labels sont reconstruits côté serveur. items : liste de clés fermées
`{id,kind,title,status,due_at,responsible_person_id,note,document_version_id}`.
id UUID unique stable ; kind registration/payment/document/roster/checkin/task,
title 1–160, status pending/done/waived, due_at nullable au format
`YYYY-MM-DDTHH:mm:ss+HH:mm`, responsable nullable dans le tenant, note nullable
(1000), document_version_id nullable vers une version texte privée de la même
compétition et réservé au kind document. Les dates sont normalisées en UTC,
les noms des responsables sont fournis par le serveur.

La réponse contient version, number, payload, reason, actor_user_id, occurred_at
et summary (pending, due_soon, overdue), recalculé au moment de la lecture.
Les rappels historiques émis contiennent seulement id/item_id/stage/payload/
occurred_at, sans roster, exigences ou contacts. Ils ne remplacent pas les tâches
courantes ; leur consultation revérifie les droits du compte courant.

Exemple avec le client Axios de session CSRF configuré (baseURL /api/v1,
withCredentials/withXSRFToken), après confirmation du tenant :

```js
const path = `tenants/${tenantId}/external-competitions/${competition.id}/registration`;
const headers = { 'X-Tenant-Revision': tenantRevision };
const { data: response } = await api.patch(path, {
  competition_version: competition.version, expected_version: current?.version ?? null,
  registration_status: 'preparing', payment_status: 'pending',
  roster_status: 'draft', checkin_status: 'not_open',
  external_reference: null, source_url: 'https://organisateur.example/competition', notes: null,
  requirements: { age: 'Exigence indiquée dans le règlement source', residence: null, rank: null },
  roster: [{ person_id: playerId, role: 'starter' }],
  items: [{ id: crypto.randomUUID(), kind: 'document', title: 'Pièce demandée par la source',
    status: 'pending', due_at: '2026-10-28T17:30:15+00:00',
    responsible_person_id: managerPersonId, note: null, document_version_id: null }],
  reason: 'Préparation du dossier selon les consignes externes.'
}, { headers });
// Conserver response.data.version pour la prochaine commande confirmée.
```

Rappels internes asynchrones via TEN-006, flag opérateur désactivé par défaut.
L’inscription, la remise des originaux et le check-in effectifs restent chez
l’organisateur externe. Aucune transmission ou notification externe automatique.


### COMP-003 — Matchs, classement et dotations externes

Préfixe `/api/v1/tenants/{tenant}/external-competitions/{competition}/outcomes/{stream}`,
avec `stream=sport|awards`. Toutes les réponses sont `private, no-store`.
Authentification de session et MFA ; PATCH exige CSRF et tenant confirmé.

| Méthode | Suffixe | Réponse / droit supplémentaire |
| --- | --- | --- |
| GET | vide | `data: null` ou dernier état ; awards exige financial.read |
| PATCH | vide | nouvel état motivé ; sport exige sports.competitions.write, awards financial.read + financial.write |
| GET | `/history?page=1&per_page=5` | historique paginé ; mêmes droits de lecture |
| GET | `/choices?page=1&per_page=10` | sport uniquement, matchs accessibles du même jeu ; sports.competitions.write |
| GET | `/matches/{reference}/result` | sport uniquement, lien courant et audience du match revérifiés |

Le socle sports.read + sports.competitions.read est toujours requis. Un droit
sports.write seul ne donne pas accès au dossier. L’audience des matchs est le
mode ordinaire : aucun accès de gestion n’est déduit du droit de modifier le dossier.
Les query strings et objets sont fermés, UUID insensibles à la casse aux écritures.
Pagination : page 1..100000, per_page 1..10.

Exemple via le client SPA existant `tenancyRequest`, qui transmet CSRF et la
révision du contexte tenant :

```ts
const path = `tenants/${tenant}/external-competitions/${competition}/outcomes`
await tenancyRequest(`${path}/sport`, {
  method: 'PATCH', revision: tenantRevision,
  body: {
    competition_version: competitionVersion, expected_version: currentVersion ?? null,
    source: 'Rapport officiel de l’organisateur, référence R-42',
    source_url: 'https://organizer.example/results', observed_on: '2026-10-18',
    notes: null, place: 2, field_size: 16,
    matches: [{ reference_id: null, match_id: matchId, match_version: matchVersion }],
    reason: 'Classement communiqué et match du dossier vérifiés.'
  }
})
await tenancyRequest(`${path}/awards`, {
  method: 'PATCH', revision: tenantRevision,
  body: {
    competition_version: competitionVersion, expected_version: awardVersion ?? null,
    source: 'Annonce organisateur et relevé de réception R-43', source_url: null,
    observed_on: '2026-10-18', notes: 'Réception partielle constatée.',
    currency: 'EUR', announced_minor: 100000, received_minor: 12345,
    received_on: '2026-10-18', reason: 'Réception vérifiée selon le relevé privé.'
  }
})
```

`expected_version=null` initialise un flux ; ensuite l’UUID de sa dernière
révision est obligatoire. `competition_version` reste la version du dossier
COMP-001 ; ces écritures ne modifient pas cette version. Un lien conservé s’envoie
avec `{reference_id, match_id:null, match_version:null}` ; la référence appartient
à l’état courant du même dossier. L’omettre du nouvel état le retire, sans effacer
les anciennes révisions. Un nouveau lien envoie l’UUID/version du match visible.
20 liens maximum et aucune répétition. Une référence inaccessible ne révèle ni
UUID métier, ni titre, ni score : `{reference_id, match:null}`. L’historique
résout également les titres avec les droits actuels, sans copier leur ancien titre.

Chaque état renvoie `version`, `number`, `stream`, `payload`, `matches`, `reason`,
`occurred_at`. Les flux awards ne sont jamais fusionnés aux listes sportives.
Le résultat renvoie `reference_id`, `status`, `approved`, `outcome`, `series`,
`winners`, `observed_at` ; `outcome/series=null` si non approuvé ou recalcul en attente.
Les standings approuvés incluent un label échappé par React et le score du moteur
MATCH ; un forfait/abandon approuvé conserve sa décision et ses vainqueurs déclarés, avec series=null. Aucune preuve, contribution privée ou stratégie ne figure dans cette projection.

Sources textuelles requises (500 caractères), motif 10..1000 caractères après trim,
notes nullable jusqu’à 2000 ; URL HTTPS publique facultative, jamais récupérée par
le serveur. Dates strictes AAAA-MM-JJ non futures dans le fuseau du tenant. Rang
nullable entier 1..1000000, ne dépassant pas le nombre de participants s’il est
renseigné. `announced_minor/received_minor` sont des entiers JSON 0..1000000000000
ou null ; chaînes numériques et flottants refusés. Devise parmi
EUR/USD/GBP/CAD/CHF/JPY/AUD, sans conversion. Montant reçu et received_on sont
renseignés ensemble ; zéro est distinct de null. Le montant reçu est un cumul
constaté dans la devise de cet état, pas une nouvelle transaction à additionner.

Conflits 409 `COMPETITION_OUTCOME_STALE`, `COMPETITION_OUTCOME_ARCHIVED`,
`COMPETITION_OUTCOME_LIMIT` ; recharger, sans rejeu automatique. Inconnu/hors
périmètre/match inaccessible : 404 ; droits insuffisants : 403 ; données ou
non-changement : 422. Succès : notification Flasher, sans contenu financier dans
le journal général. Migration additive `2026_10_19_005858`, sans activation de worker.


### STAT-001 — Dictionnaire commun des métriques

Préfixe `/api/v1/metric-catalogue`, hors tenant. Session authentifiée et compte
vérifié ; MFA exigée pour le superadmin plateforme. Aucun droit tenant ne donne
l’écriture globale. Toutes les réponses sont `private, no-store` ; écritures sous
CSRF, confirmation globale et session bloquée pendant l’opération.

| Méthode | Suffixe | Comportement |
| --- | --- | --- |
| GET | vide | liste paginée, meta.catalogue_version/game_catalogue_version/can_manage |
| POST | vide | nouvelle identité et première révision, 201, superadmin uniquement |
| GET | `/{metric}` | état courant de cette définition |
| PATCH | `/{metric}` | nouvelle révision motivée, 200, superadmin uniquement |
| GET | `/{metric}/history` | historique paginé, plus récent d’abord |
| GET | `/{metric}/versions/{version}` | version immuable exacte du même identifiant |

Filtres GET : game_id, code exact, status active/retired, availability,
aggregation, mode_id, role_id, release_id. Page 1..100000, per_page 1..10 ;
aucun filtre de texte libre ni valeur privée. Sans status, la liste inclut actifs
et retirés. Le frontend commence sur active. Références de contexte via
`GET /api/v1/game-catalogue?kind=game|mode|role|release&game_id=...&page=1&per_page=10`
(game_id uniquement pour les références rattachées au jeu).

Exemple avec le client SPA existant :

```ts
await tenancyRequest('metric-catalogue', {
  method: 'POST',
  body: {
    catalogue_version: dictionaryVersion, game_catalogue_version: gamesVersion,
    confirm_global: true, game_id: gameId, code: 'KD_RATIO',
    mode_id: null, role_id: null, release_id: null,
    title: 'Ratio éliminations / morts', definition: 'Composantes comparables dans le même contexte.',
    unit: 'ratio', aggregation_level: 'period', aggregation: 'ratio',
    formula: 'sum(kills) / sum(deaths)', zero_denominator: 'unavailable',
    measurement_kind: 'objective', availability: 'source_required',
    availability_note: 'Source et accès à confirmer avant acquisition.',
    status: 'active', reason: 'Définition commune vérifiée avant utilisation.'
  }
})
```

PATCH envoie le même état documentaire complet, remplace game_id/code par
`version` (UUID de la dernière révision) et conserve les deux versions de catalogue.
Jeu et code ne peuvent pas être changés. Le niveau vaut game à la création s’il
est omis ; une correction qui l’omet conserve le niveau précédent. Le formulaire
l’envoie explicitement. Il est conservé dans scope.aggregation_level et projeté
aussi au niveau de la définition. Une révision ultérieure ne modifie jamais les
anciens niveaux/formules. Les futurs faits privés devront référencer l’UUID exact
de définition ; aucune écriture de faits ni recalcul n’est livré par STAT-001.

Champs : code ASCII `[A-Z][A-Z0-9_]{1,63}`, titre 160 caractères, définition 4000,
unité 40, formule 2000, note de disponibilité 2000, motif 10..1000 après trim.
Méthodes : sum/mean/min/max/last/ratio/manual ; niveaux : round/game/match/session/
period. Un ratio exige zero_denominator unavailable ou zero ; les autres méthodes
exigent not_applicable. Nature : objective/subjective ; disponibilité : manual/
source_required/unavailable. Les formules sont du texte descriptif, jamais du PHP,
JavaScript ou SQL à exécuter. Aucune disponibilité d’API fournisseur n’est déduite.

Mode, rôle et release sont nullable, du même jeu, et actifs pour une définition
active. Le retrait peut conserver des références devenues inactives. Les libellés
sont des instantanés de la révision. Réactivation et retrait créent une version ;
le retrait ne supprime ni code ni historique. 10 000 métriques maximum, 1000 révisions
par métrique. Aucun contenu organization_id/person_id/value/import n’est accepté
et les objets/query strings sont fermés. Les définitions globales sont exclues
des exports de valeurs privées des organisations.

409 METRIC_CATALOGUE_STALE/LIMIT/DUPLICATE ; un catalogue de jeux périmé renvoie
GAME_CATALOGUE_VERSION_STALE. Recharger sans rejeu automatique. 422 pour données
invalides/non-changement, 403 pour droits insuffisants, 404 pour référence ou version
inconnue/hors parent. Succès avec notification Flasher ; auteur, request_id, motif
et date inscrits atomiquement dans la révision. Aucune table tenant ni permission
métier réelle modifiée par ce lot. Migration `2026_10_19_094551` à appliquer après
sauvegarde et validation CI.
