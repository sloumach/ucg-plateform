<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\People\Application\Services\ExternalGameAccountService;
use App\Modules\People\Application\Services\GameProfileProjectionService;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Presentation\Http\Requests\Api\V1\GameProfileReadRequest;
use App\Modules\People\Presentation\Http\Requests\Api\V1\SaveExternalGameAccountRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\ExternalGameAccountResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class ExternalGameAccountController extends Controller
{
    public function __construct(private readonly ExternalGameAccountService $accounts, private readonly GameProfileProjectionService $projection,
        private readonly Gate $gate) {}

    public function index(GameProfileReadRequest $request, TenantContext $context, string $tenant, string $person, string $profile): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ExternalGameAccount::class, $context]);
        $page = $this->accounts->accounts($context, $person, $profile, $request->integer('per_page', 20), $request->integer('page', 1));
        $data = $this->projection->accounts($context, array_values($page->items()));

        return ExternalGameAccountResource::collection(new LengthAwarePaginator($data, $page->total(), $page->perPage(), $page->currentPage(),
            ['path' => $request->url(), 'query' => $request->query()]))->additional(['meta' => ['request_id' => RequestId::for($request)]]);
    }

    public function show(GameProfileReadRequest $request, TenantContext $context, string $tenant, string $person, string $profile, string $account): ExternalGameAccountResource
    {
        $this->gate->authorize('viewAny', [ExternalGameAccount::class, $context]);

        return ExternalGameAccountResource::make($this->projection->accounts($context, [$this->accounts->find($context, $person, $profile, $account)])[0]);
    }

    public function store(SaveExternalGameAccountRequest $request, TenantContext $context, string $tenant, string $person, string $profile): JsonResponse
    {
        $this->gate->authorize('manage', [ExternalGameAccount::class, $context]);
        $account = $this->accounts->save($context, $person, $profile, $request->toData(), ipAddress: $request->ip());

        return $this->result($context, $account)->response()->setStatusCode(201);
    }

    public function update(SaveExternalGameAccountRequest $request, TenantContext $context, string $tenant, string $person, string $profile, string $account): ExternalGameAccountResource
    {
        $this->gate->authorize('manage', [ExternalGameAccount::class, $context]);

        return $this->result($context, $this->accounts->save($context, $person, $profile, $request->toData(), $account, $request->ip()));
    }

    private function result(TenantContext $context, ExternalGameAccount $account): ExternalGameAccountResource
    {
        return ExternalGameAccountResource::make($this->projection->accounts($context, [$account])[0])
            ->additional(['notification' => ['type' => 'success', 'message' => __('game_profiles.account_saved')]]);
    }
}
