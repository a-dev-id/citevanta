<x-mail::message>
# Scan #{{ $scanRunId }} was stopped

It reached {{ $calls }} AI calls, the most its plan allows ({{ $max }}). The scan is marked as failed so it makes no further calls. This usually means a job is looping; please check the logs.

<x-mail::button :url="$url">
Open scan runs
</x-mail::button>
</x-mail::message>
