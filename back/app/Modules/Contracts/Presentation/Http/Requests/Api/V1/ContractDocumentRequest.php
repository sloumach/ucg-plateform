<?php

namespace App\Modules\Contracts\Presentation\Http\Requests\Api\V1;

use App\Modules\Contracts\Application\Validation\ContractDocumentInput;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContractDocumentRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        if ($this->isMethod('GET')) {
            return $this->user()?->can('viewAny', [ContractRecord::class, $context]) ?? false;
        }
        $validating = $this->routeIs('api.v1.contract-documents.sign')
            || ($this->routeIs('api.v1.contract-documents.upload') && $this->input('purpose') === 'signed_copy');

        return $validating ? ($this->user()?->can('transition', [ContractRecord::class, $context, 'signed']) ?? false)
            : ($this->user()?->can('manage', [ContractRecord::class, $context]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('api.v1.contract-documents.store') => ContractDocumentInput::createRules(),
            $this->routeIs('api.v1.contract-documents.upload') => ContractDocumentInput::uploadRules(),
            $this->routeIs('api.v1.contract-documents.sign') => ContractDocumentInput::signatureRules(),
            default => ContractInput::pageRules(true),
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ContractDocumentInput::messages();
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('managed_contracts.unknown'));
            }
        }];
    }
}
