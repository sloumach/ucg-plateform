<?php

namespace App\Modules\Competition\Presentation\Http\Requests\Api\V1;

use App\Modules\Competition\Application\Validation\ExternalCompetitionInput;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ExternalCompetitionRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(in_array($this->operation(), ['store', 'update', 'games', 'documentStore', 'documentUpdate'], true) ? 'manage' : 'viewAny', [ExternalCompetition::class, $context]) ?? false;
    }

    public function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
    }

    /**
     * @return array<string,mixed> */
    public function rules(): array
    {
        return ExternalCompetitionInput::rules($this->operation());
    }

    /**
     * @return array<string,string> */
    public function messages(): array
    {
        return ExternalCompetitionInput::messages();
    }

    /**
     * @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), static fn (string $field): bool => ! str_contains($field, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, __('external_competitions.closed'));
            }
        }];
    }
}
