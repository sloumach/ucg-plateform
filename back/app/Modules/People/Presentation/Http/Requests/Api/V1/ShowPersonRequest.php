<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class ShowPersonRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('view', [Person::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
