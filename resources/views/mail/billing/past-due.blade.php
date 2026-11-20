<x-mail::message>
# Your payment is overdue

We have not received the payment for your {{ $team->plan->label() }} plan. Your scans keep running until **{{ $graceEndsAt }}**.

After that date the account becomes read-only until the invoice is paid. Paying restores everything immediately.

<x-mail::button :url="$billingUrl">
Pay now
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
