<x-mail::message>
{{ __('audit.mail.intro', ['hotel' => $audit->hotel_name, 'city' => $audit->city]) }}

**{{ __('audit.mail.score', ['count' => $count, 'total' => $total]) }}**

<x-mail::button :url="$url">
{{ __('audit.mail.button') }}
</x-mail::button>

{{ __('audit.mail.outro') }}

{{ config('app.name') }}
</x-mail::message>
