<x-mail::message>
@if ($wasTrial)
# Your trial has ended
@else
# Your subscription has expired
@endif

Your account is now read-only. You can still log in and see all past results, but no new scans or content are created.

Your data is kept. Choose a plan to start again: everything is restored immediately after payment.

<x-mail::button :url="$billingUrl">
Choose a plan
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
