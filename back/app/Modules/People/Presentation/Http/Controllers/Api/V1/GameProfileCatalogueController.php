<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Presentation\Http\Requests\Api\V1\GameProfileReadRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\GameProfileCatalogueResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;

final class GameProfileCatalogueController extends Controller
{
    public function __construct(private readonly GameProfileCatalogue $catalogue, private readonly Gate $gate) {}

    public function index(GameProfileReadRequest $request, TenantContext $context): GameProfileCatalogueResource
    {
        $this->gate->authorize('viewAny', [GameProfile::class, $context]);

        return GameProfileCatalogueResource::make(['games' => $this->catalogue->entries($context->organizationId)]);
    }
}
