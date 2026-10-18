<?php

namespace App\Modules\Competition\Application\Validation;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class ExternalCompetitionInput
{
    public const MAX_COMPETITIONS = 10000;

    public const MAX_REVISIONS = 1000;

    public const MAX_DOCUMENTS = 100;

    public const MAX_DOCUMENT_REVISIONS = 100;

    /**
     * @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        $pages = ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,25']];
        if ($operation === 'index') {
            return [...$pages, 'status' => ['sometimes', 'in:active,archived'], 'game_id' => ['sometimes', 'uuid'], 'season' => ['sometimes', 'string', 'max:100'], 'region' => ['sometimes', 'string', 'max:80']];
        }
        if ($operation === 'games') {
            return $pages;
        }
        if (in_array($operation, ['history', 'documents', 'documentHistory'], true)) {
            return ['page' => $pages['page'], 'per_page' => ['sometimes', 'integer', 'between:1,10']];
        }
        if (in_array($operation, ['show', 'download'], true)) {
            return [];
        }
        $reason = ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'];
        if (in_array($operation, ['documentStore', 'documentUpdate'], true)) {
            return ['competition_version' => ['required', 'uuid'], ...($operation === 'documentStore' ? ['kind' => ['required', 'in:rules,note']] : ['version' => ['required', 'uuid']]),
                'title' => [$operation === 'documentStore' ? 'required' : 'sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
                'body' => [$operation === 'documentStore' ? 'required' : 'sometimes', 'required', 'string', 'max:20000', 'regex:/\S/u'],
                'source_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'url:https', self::publicUrl()], 'reason' => $reason];
        }

        return [...($operation === 'store' ? ['reference' => ['required', 'string', 'min:2', 'max:64', 'regex:/\A[A-Z0-9][A-Z0-9_-]+\z/'], 'game_id' => ['required', 'uuid']] : ['version' => ['required', 'uuid'], 'archive' => ['sometimes', 'accepted']]),
            'name' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
            'organizer' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
            'region' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:80', 'regex:/\S/u'],
            'season' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:100', 'regex:/\S/u'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'url:https', self::publicUrl()],
            'contacts' => ['sometimes', 'array', 'list', 'max:10'], 'contacts.*' => ['array:name,role,email,phone'],
            'contacts.*.name' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'contacts.*.role' => ['present', 'nullable', 'string', 'max:120'], 'contacts.*.email' => ['present', 'nullable', 'email:rfc', 'max:254'],
            'contacts.*.phone' => ['present', 'nullable', 'string', 'max:40', 'regex:/\A[+0-9(). \-]+\z/'], 'reason' => $reason];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        $unknown = array_diff(array_keys($input), array_filter(array_keys(self::rules($operation)), fn (string $key): bool => ! str_contains($key, '.')));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('external_competitions.closed')));
        }

        return $factory->make($input, self::rules($operation), self::messages())->validate();
    }

    /**
     * @return array<string,string> */
    public static function messages(): array
    {
        return ['required' => __('external_competitions.required'), 'present' => __('external_competitions.required'), 'uuid' => __('external_competitions.uuid'), 'url' => __('external_competitions.url'), 'email' => __('external_competitions.email'), 'regex' => __('external_competitions.invalid'), 'array' => __('external_competitions.invalid'), 'in' => __('external_competitions.invalid'), 'accepted' => __('external_competitions.confirm'), 'min' => __('external_competitions.short'), 'max' => __('external_competitions.limit'), 'between' => __('external_competitions.limit')];
    }

    /** URLs are citations, never fetched by the server. */
    private static function publicUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : false;
            $host = is_array($parts) ? strtolower(trim($parts['host'] ?? '', '[]')) : '';
            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || $host === '' || ! str_contains($host, '.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
                || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                $fail(__('external_competitions.url'));
            }
        };
    }
}
