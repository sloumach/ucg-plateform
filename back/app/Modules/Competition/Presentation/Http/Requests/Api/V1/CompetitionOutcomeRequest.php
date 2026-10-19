<?php

namespace App\Modules\Competition\Presentation\Http\Requests\Api\V1;

use App\Modules\Competition\Application\Validation\CompetitionOutcomeInput as Input;
use App\Modules\Competition\Domain\Models\CompetitionOutcomeRevision;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CompetitionOutcomeRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(in_array($this->route()?->getActionMethod(), ['save', 'choices'], true) ? 'manage' : 'viewAny', [CompetitionOutcomeRevision::class, $context, $this->route('stream')]) ?? false;
    }

    /**
     * @return array<string,mixed> */
    public function rules(): array
    {
        return Input::rules($this->route()?->getActionMethod() ?? 'show', (string) $this->route('stream'));
    }

    /**
     * @return array<string,string> */
    public function messages(): array
    {
        return Input::messages();
    }

    /**
     * @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $k): bool => ! str_contains($k, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, __('external_competitions.closed'));
            }
        }];
    }
}
