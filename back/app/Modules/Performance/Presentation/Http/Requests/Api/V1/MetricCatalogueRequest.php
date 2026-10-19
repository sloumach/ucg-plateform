<?php

namespace App\Modules\Performance\Presentation\Http\Requests\Api\V1;

use App\Modules\Performance\Application\Validation\MetricCatalogueInput;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MetricCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(in_array($this->route()?->getActionMethod(), ['store', 'update'], true) ? 'manage' : 'viewAny', [MetricDefinition::class]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return MetricCatalogueInput::rules($this->route()?->getActionMethod() ?? 'index');
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $roots = array_filter(array_keys($this->rules()), fn (string $field): bool => ! str_contains($field, '.'));
            foreach (array_diff(array_keys($this->all()), $roots) as $field) {
                $validator->errors()->add($field, __('metric_catalogue.closed'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MetricCatalogueInput::messages();
    }
}
