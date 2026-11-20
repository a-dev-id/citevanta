<x-mail::message>
# Your trial ends on {{ $endsAt }}

Choose a plan to keep your scans running. Your hotels, prompts and results stay as they are.

<x-mail::button :url="$billingUrl">
Choose a plan
</x-mail::button>

If you do not choose a plan, your account becomes read-only: you can still log in and see your results, but no new scans run.

{{ config('app.name') }}
</x-mail::message>
