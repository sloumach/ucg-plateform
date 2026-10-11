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
