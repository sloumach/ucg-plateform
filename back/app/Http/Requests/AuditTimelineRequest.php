<?php

namespace App\Http\Requests;

use App\Support\Audit\AuditFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AuditTimelineRequest extends FormRequest
{
    /** @return list<string> */
    abstract protected function sources(): array;

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:4096'],
            'source' => ['sometimes', 'string', Rule::in($this->sources())], 'action' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.]*$/D'],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'], 'from' => ['sometimes', 'date_format:Y-m-d\TH:i:sP'],
            'until' => ['sometimes', 'date_format:Y-m-d\TH:i:sP', Rule::when($this->has('from'), 'after_or_equal:from')],
            'organization_id' => ['prohibited'], 'user_id' => ['prohibited'], 'page' => ['prohibited'], 'sort' => ['prohibited']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['*.*' => __('audit.invalid_filter')];
    }

    public function toFilter(): AuditFilter
    {
        $input = $this->validated();

        return new AuditFilter((int) $this->user()?->getAuthIdentifier(), (int) ($input['per_page'] ?? 20),
            $input['cursor'] ?? null, $input['source'] ?? null, $input['action'] ?? null,
            isset($input['actor_user_id']) ? (int) $input['actor_user_id'] : null,
            isset($input['from']) ? CarbonImmutable::parse($input['from'])->utc()->format('Y-m-d H:i:s') : null,
            isset($input['until']) ? CarbonImmutable::parse($input['until'])->utc()->format('Y-m-d H:i:s') : null);
    }
}
