<x-mail::message>
# We could not confirm your payment

We could not find the payment for invoice {{ $invoice->number }} ({{ $amount }}).

@if ($invoice->rejected_reason)
<x-mail::panel>
{{ $invoice->rejected_reason }}
</x-mail::panel>
@endif

@include('mail.billing._how-to-pay')

<x-mail::button :url="$url">
Open invoice
</x-mail::button>

Reply to this email if you think this is a mistake.

{{ config('app.name') }}
</x-mail::message>
