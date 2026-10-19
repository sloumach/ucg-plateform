<?php

namespace App\Modules\Competition\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class CompetitionRegistrationInput
{
    public const MAX_REVISIONS = 1000;

    /**
     * @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        if (in_array($operation, ['show'], true)) {
            return [];
        }
        if ($operation !== 'save') {
            return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,10']];
        }

        return [
            'competition_version' => ['required', 'uuid'], 'expected_version' => ['present', 'nullable', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'],
            'registration_status' => ['required', 'in:preparing,submitted,accepted,rejected,withdrawn'], 'payment_status' => ['required', 'in:not_required,pending,paid,refunded'],
            'roster_status' => ['required', 'in:draft,submitted,approved,rejected'], 'checkin_status' => ['required', 'in:not_open,required,completed,missed,not_required'],
            'source_url' => ExternalCompetitionInput::rules('store')['website_url'], 'external_reference' => ['present', 'nullable', 'string', 'max:160'], 'notes' => ['present', 'nullable', 'string', 'max:2000'],
            'requirements' => ['required', 'array:age,residence,rank'], 'requirements.age' => ['present', 'nullable', 'string', 'max:1000'], 'requirements.residence' => ['present', 'nullable', 'string', 'max:1000'], 'requirements.rank' => ['present', 'nullable', 'string', 'max:1000'],
            'roster' => ['present', 'array', 'list', 'max:20'], 'roster.*' => ['array:person_id,role'], 'roster.*.person_id' => ['required', 'uuid', 'distinct:ignore_case'], 'roster.*.role' => ['required', 'in:starter,substitute'],
            'items' => ['present', 'array', 'list', 'max:50'], 'items.*' => ['array:id,kind,title,status,due_at,responsible_person_id,note,document_version_id'],
            'items.*.id' => ['required', 'uuid', 'distinct:ignore_case'], 'items.*.kind' => ['required', 'in:registration,payment,document,roster,checkin,task'],
            'items.*.title' => ['required', 'string', 'max:160', 'regex:/\S/u'], 'items.*.status' => ['required', 'in:pending,done,waived'],
            'items.*.due_at' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'items.*.responsible_person_id' => ['present', 'nullable', 'uuid'], 'items.*.document_version_id' => ['present', 'nullable', 'uuid'], 'items.*.note' => ['present', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        $rules = self::rules($operation);
        $allowed = array_filter(array_keys($rules), fn (string $k): bool => ! str_contains($k, '.'));
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('external_competitions.closed')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /**
     * @return array<string,string> */
    public static function messages(): array
    {
        return [...ExternalCompetitionInput::messages(), 'list' => __('competition_registrations.invalid'), 'distinct' => __('competition_registrations.duplicate'), 'date_format' => __('competition_registrations.date')];
    }
}
