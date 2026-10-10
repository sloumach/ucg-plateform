<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class TenancyActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Organization::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
