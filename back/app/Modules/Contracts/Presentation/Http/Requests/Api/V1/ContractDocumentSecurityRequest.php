<?php

namespace App\Modules\Contracts\Presentation\Http\Requests\Api\V1;

use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContractDocumentSecurityRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->routeIs('api.v1.contract-document-security.scan') ? 'manage' : 'viewAny', [ContractRecord::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('api.v1.contract-document-security.scan') => ['expected_scan_id' => ['present', 'nullable', 'uuid']],
            $this->routeIs('api.v1.contract-document-security.link') => ['expected_scan_id' => ['required', 'uuid']],
            $this->routeIs('api.v1.contract-document-security.download') => ['actor' => ['required', 'integer', 'min:1'],
                'scan' => ['required', 'uuid'], 'expires' => ['required', 'integer', 'min:1'], 'signature' => ['required', 'string', 'size:64']],
            default => ContractInput::pageRules(true),
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ContractInput::messages();
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
