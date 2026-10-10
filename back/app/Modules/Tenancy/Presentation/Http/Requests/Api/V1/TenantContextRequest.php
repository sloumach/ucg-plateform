<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class TenantContextRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('view', $context) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
