<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Application\Data\GameProfileData;
use App\Modules\People\Application\Validation\GameProfileInput;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SaveGameProfileRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', [GameProfile::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...GameProfileInput::profileRules($this->isMethod('POST')),
            'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'uuid']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('game_profiles.validation.immutable'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return GameProfileInput::messages();
    }

    public function toData(): GameProfileData
    {
        return new GameProfileData($this->safe()->except(['version']), $this->filled('version') ? $this->string('version')->toString() : null);
    }
}
