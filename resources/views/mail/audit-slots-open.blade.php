<x-mail::message>
# {{ __('audit.mail.slots_subject') }}

{{ __('audit.mail.slots_body') }}

<x-mail::button :url="$url">
{{ __('audit.mail.slots_button') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
