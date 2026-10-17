<?php

namespace App\Modules\Scheduling\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class CalendarInvitationInput
{
    /** @return array<string,array<mixed>> */
    public static function rules(string $operation): array
    {
        return match ($operation) {
            'issue' => ['activity_version' => ['required', 'uuid'], 'response_deadline' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
                'reminder_offsets' => ['present', 'array', 'list', 'max:3'],
                'reminder_offsets.*' => ['required', 'integer:strict', 'between:1,10080', 'distinct:strict'], 'confirm_invitations' => ['required', 'accepted']],
            'respond' => ['version' => ['required', 'uuid'], 'response' => ['required', 'string', 'in:pending,present,absent']],
            default => ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']],
        };
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        $rules = self::rules($operation);
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['record' => __('calendar.closed')]);
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['required' => __('calendar.required'), 'present' => __('calendar.required'), 'uuid' => __('calendar.uuid'),
            'date_format' => __('calendar.date'), 'accepted' => __('calendar.invitation_confirm'),
            'array' => __('calendar.invitation_offsets'), 'list' => __('calendar.invitation_offsets'),
            'max' => __('calendar.invitation_offsets'), 'between' => __('calendar.invitation_bounds'),
            'integer' => __('calendar.integer'), 'distinct' => __('calendar.distinct'), 'in' => __('calendar.choice'), 'string' => __('calendar.string')];
    }
}
