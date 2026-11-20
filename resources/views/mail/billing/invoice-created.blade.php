<x-mail::message>
# Invoice {{ $invoice->number }}

{{ $invoice->description }}.

**Amount due: {{ $amount }}**<br>
Due date: {{ $due }}

@include('mail.billing._how-to-pay')

<x-mail::button :url="$url">
View and pay invoice
</x-mail::button>

Thank you,<br>
{{ config('app.name') }}
</x-mail::message>
