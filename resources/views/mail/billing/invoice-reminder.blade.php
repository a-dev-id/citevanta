<x-mail::message>
@if ($dueToday)
# Invoice {{ $invoice->number }} is due today
@else
# Invoice {{ $invoice->number }} is due on {{ $due }}
@endif

{{ $invoice->description }}.

**Amount due: {{ $amount }}**

@include('mail.billing._how-to-pay')

<x-mail::button :url="$url">
View and pay invoice
</x-mail::button>

If you have already paid, thank you. You can ignore this email or click "I have paid" on the invoice.

{{ config('app.name') }}
</x-mail::message>
