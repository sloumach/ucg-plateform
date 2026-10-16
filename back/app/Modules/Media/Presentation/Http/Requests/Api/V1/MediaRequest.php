<?php

namespace App\Modules\Media\Presentation\Http\Requests\Api\V1;

use App\Modules\Media\Application\Validation\MediaInput;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MediaRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->isMethod('GET') ? 'viewAny' : 'manage', [MediaItem::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return $this->isMethod('GET') ? MediaInput::pageRules($this->routeIs('api.v1.media.teams')) : MediaInput::saveRules($this->isMethod('POST'));
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('media.closed'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MediaInput::messages();
    }
}
