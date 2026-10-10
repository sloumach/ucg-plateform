# Architecture modulaire UCG

## Objectif

Le backend reste un monolithe Laravel déployé comme une seule application, mais son code est
divisé en modules métier autonomes. Chaque module possède sa logique, ses interfaces de
persistance, ses adaptateurs Laravel et ses points d’entrée HTTP. Cette séparation prépare une
évolution indépendante des domaines sans ajouter la complexité opérationnelle de microservices.

## Carte des modules

La carte exécutable se trouve dans `App\Architecture\Modules\ModuleDependencyMap`. Une
dépendance signifie que le module de gauche peut consommer l’API publique du module de droite.

| Module | Dépendances autorisées |
| --- | --- |
| `Identity` | aucune |
| `Tenancy` | `Identity` |
| `People` | `Identity`, `Tenancy` |
| `Teams` | `Tenancy`, `People` |
| `Academy` | `Tenancy`, `People`, `Teams` |
| `Scheduling` | `Tenancy`, `People`, `Teams` |
| `Competition` | `Tenancy`, `Teams`, `Scheduling` |
| `Performance` | `Tenancy`, `People`, `Teams`, `Competition` |
| `Contracts` | `Tenancy`, `People` |
| `Partnerships` | `Tenancy` |
| `Finance` | `Tenancy`, `Contracts`, `Partnerships` |
| `Media` | `Tenancy`, `People`, `Teams` |
| `Reporting` | tous les modules métier nécessaires à ses projections de lecture |

`Reporting` est un consommateur terminal : aucun autre module ne doit dépendre de lui.

## Structure d’un module

```text
app/Modules/<Module>/
├── Application/
│   ├── Contracts/       API synchrone consommable par les autres modules
│   ├── Data/            DTO immuables
│   └── Services/        orchestration des cas d’usage
├── Domain/
│   ├── Contracts/       ports de persistance du module
│   ├── Events/          événements publics immuables
│   ├── Exceptions/      erreurs métier ciblées
│   └── Models/          modèles et règles appartenant au domaine
├── Infrastructure/
│   └── Persistence/     implémentations des repositories
├── Presentation/
│   ├── Http/            Controllers, Form Requests et Resources
│   ├── Policies/        autorisations du module
│   └── Routes/          routes chargées par le provider du module
├── Providers/           composition Laravel du module
└── Module.php           frontière physique déclarée
```

Les dossiers ne sont créés que lorsqu’ils portent une responsabilité réelle. `Module.php`
matérialise la frontière même avant l’arrivée des premières fonctionnalités.

## Règles de dépendance

1. Un module ne peut importer que les modules déclarés dans `ModuleDependencyMap`.
2. Une communication inter-module passe uniquement par `Application\Contracts` pour un appel
   synchrone ou par `Domain\Events` pour une réaction découplée.
3. Il est interdit d’importer directement le modèle, le repository, le controller ou
   l’infrastructure d’un autre module.
4. À l’intérieur d’un module, les dépendances pointent vers le cœur :
   `Presentation → Application → Domain` et `Infrastructure → Application/Domain`.
5. Le provider du module est la racine de composition autorisée à relier les couches, charger
   les routes, policies et bindings.
6. Les migrations restent centralisées dans `database/migrations` conformément à la convention
   Laravel. Chaque table conserve toutefois un module propriétaire. Une relation inter-module
   stocke l’identifiant nécessaire, mais ne crée pas un accès implicite au modèle Eloquent voisin.
7. Les écritures cohérentes sont orchestrées dans un Service et protégées par une transaction.
   Les événements externes sont publiés après validation de la transaction.
8. Les controllers restent minces : validation par Form Request, autorisation par Policy,
   orchestration par Service et sérialisation par Resource.

Les tests de `tests/Architecture/ModularArchitectureTest.php` bloquent les modules manquants,
les cycles, les dépendances non déclarées, le contournement des APIs publiques et les
dépendances de couche dirigées vers l’extérieur.

## Exemple vertical Identity

Le parcours de connexion sert de référence exécutable :

```text
Presentation/Routes/api.php
  → LoginRequest
  → AuthController
  → AuthenticationService + LoginData
  → UserRepositoryInterface
  → EloquentUserRepository
  → User
```

`IdentityServiceProvider` lie le contrat au repository Eloquent, enregistre la Policy, le rate
limiter et les routes. Aucun de ces détails n’est exposé aux futurs modules.

## Ajouter une fonctionnalité

### Contexte métier — UCG-TEN-002

Les modules autorisés à dépendre de Tenancy consomment
`Tenancy\Application\Contracts\TenantContext`, un DTO public `readonly`.
Le middleware `tenant` le résout depuis un UUID de route ou un hôte exact
approuvé, vérifie le propriétaire ou une adhésion active datée et le statut actif, puis l’attache à la
requête. Il s’exécute après l’authentification et avant les bindings de ressources.
Les adhésions, rôles et permissions minimales sont pris en compte depuis `TEN-003`.

Injecter le contexte dans la méthode d’action, pas dans le constructeur du
controller : Laravel peut instancier celui-ci avant l’exécution du middleware.
Passer ensuite le contexte explicitement aux Services. Ceux-ci transmettent
son UUID aux ports de persistance du Domain, sans créer de dépendance
`Domain → Application`. Toute recherche de ressource combine son identifiant
avec cet UUID ; `assertOrganization()` permet de refuser un identifiant
d’organisation différent, même si l’acteur possède les deux organisations.
Cette garde ne remplace ni les Policies ni les contraintes de base.

Le binding du contexte relit la requête courante et vérifie son acteur et sa
corrélation. Il ne mémorise pas le contexte dans le conteneur ; le middleware
nettoie ses attributs et le contexte de journalisation tenant dans un `finally`.
Ne jamais utiliser le contexte Laravel de journalisation comme preuve
d’autorisation. Les callbacks après réponse, workers et commandes ne disposent
pas automatiquement d’un contexte HTTP. Capturer les identifiants explicitement
avant la fin de la requête ; `toJobContext()` fournit le transport existant.
La restauration contrôlée des jobs sera réalisée dans `TEN-006`.

### Flux d’ajout

La sélection `TEN-003` est un marqueur de session avec révision, pas un DTO
persisté ni une preuve d’autorisation. Les mutations tenant exigent cette
révision et une confirmation après changement de sélection. Les routes
globales d’invitation/départ conservent leur autorisation propre et un contrôle
de révision lorsqu’une sélection existe. Les verrous de session empêchent
qu’un changement de sélection s’intercale pendant une requête sensible.
Ne pas injecter `TenantContext` dans un constructeur de middleware : Laravel
peut réinstancier ce middleware à la terminaison, une fois le contexte nettoyé.

Les admissions et réactivations verrouillent d’abord le compte vérifié via le
contrat public Identity, puis l’organisation, puis l’adhésion/invitation.
Les audits d’accès sont append-only et transactionnels ; l’e-mail est différé
après commit. Aucun modèle Identity n’est importé directement dans Tenancy.
Les tables d’accès et leurs migrations restent centrales, détenues par Tenancy.

La politique d’adhésion de `TEN-003` doit être isolée de la résolution du contexte :
multi-organisations par défaut, restriction d’exclusivité activable explicitement.
Vérifier les périodes côté serveur et sérialiser les admissions d’un même compte
pour éviter deux acceptations concurrentes. La suspension n’est pas un départ.
La restriction ne fusionne ni identités ni historiques et ne remplace pas les Policies.

1. Confirmer le module propriétaire et ses dépendances avant de coder.
2. Ajouter ou modifier le contrat public seulement si un autre module doit réellement appeler
   le cas d’usage.
3. Construire le flux vertical complet avec Request, Policy, Controller, Service, Repository et
   Resource lorsque ces couches sont pertinentes.
4. Ajouter migrations réversibles, contraintes, index et tests d’isolation nécessaires.
5. Exécuter les tests d’architecture, PHPUnit, Pint et les vérifications frontend.
6. Refuser toute nouvelle dépendance circulaire ; extraire un contrat ou publier un événement.
