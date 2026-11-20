<x-mail::message>
# Payment received

Thank you. We received {{ $amount }} for invoice {{ $invoice->number }}.

@if ($paidUntil)
Your {{ $plan }} plan is active until **{{ $paidUntil }}**.
@else
Your {{ $plan }} plan is active.
@endif

<x-mail::button :url="$url">
View invoice
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
