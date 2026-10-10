<x-mail::message>
# {{ __('tenancy.mail.subject') }}

{{ __('tenancy.mail.invited', ['organization' => $organizationName]) }}

{{ __('tenancy.mail.account') }}

<x-mail::button :url="$frontendUrl">
{{ __('tenancy.mail.open') }}
</x-mail::button>

{{ __('tenancy.mail.access') }}
</x-mail::message>
