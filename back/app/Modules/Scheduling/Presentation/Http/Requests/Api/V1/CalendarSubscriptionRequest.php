<?php

namespace App\Modules\Scheduling\Presentation\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CalendarSubscriptionRequest extends FormRequest
{
    /** The controller authorizes the actor resolved from the validated capability. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        return ['token' => $this->attributes->get('calendar_subscription_token')];
    }

    /** @return array<string,array<string>> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }
}
