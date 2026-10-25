<?php

namespace App\Modules\Contracts\Application\Validation;

use App\Modules\Contracts\Domain\DocumentSecurity;
use Illuminate\Validation\Rule;

final class ContractDocumentInput
{
    public const int MAX_DOCUMENTS = 101;

    public const int MAX_VERSIONS = 100;

    public const int MAX_BYTES = DocumentSecurity::MAX_BYTES;

    public const array SIGNED_STATES = ['signed', 'active', 'ended', 'terminated'];

    /** @return array<string, array<mixed>> */
    public static function createRules(): array
    {
        return ['contract_version' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'kind' => ['required', 'string', Rule::in(['original', 'amendment'])],
            'title' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'original_document_id' => ['required_if:kind,amendment', 'nullable', 'uuid'],
            'effective_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01']];
    }

    /** @return array<string, array<mixed>> */
    public static function uploadRules(): array
    {
        return ['contract_version' => ['required', 'uuid'], 'purpose' => ['required', 'string', Rule::in(['draft', 'signed_copy'])],
            'file' => ['required', 'file', 'extensions:pdf', 'mimetypes:application/pdf', 'max:5120']];
    }

    /** @return array<string, array<mixed>> */
    public static function signatureRules(): array
    {
        return ['contract_version' => ['required', 'uuid'], 'document_version_id' => ['required', 'uuid'],
            'signed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'confirm_signed_copy' => ['required', 'accepted']];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [...ContractInput::messages(), 'file' => __('contract_documents.file'),
            'extensions' => __('contract_documents.file'), 'mimetypes' => __('contract_documents.file'),
            'file.max' => __('contract_documents.file'), 'required_if' => __('contract_documents.origin'),
            'accepted' => __('contract_documents.confirm')];
    }
}
