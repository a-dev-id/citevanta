<x-mail::message>
# OpenAI credit or spend limit reached

OpenAI refused an AI call with **{{ $code }}**. All queued scans and audits are paused (not failed) and no further AI calls are made.

1. Top up the OpenAI credit or raise the spend limit.
2. Open Admin > System and click **Resume paused jobs**.

<x-mail::button :url="$url">
Open Admin > System
</x-mail::button>
</x-mail::message>
