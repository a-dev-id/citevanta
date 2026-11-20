<x-mail::message>
# {{ $percent }}% of your monthly AI budget used

Your own OpenAI key has used **${{ number_format($spent, 2) }}** of the **${{ number_format($budget, 2) }}** monthly budget you set in {{ config('app.name') }}.

@if ($percent >= 100)
Scans and content are paused until the 1st, or until you raise the budget.
@else
Nothing is paused yet.
@endif

<x-mail::button :url="$url">
Open Settings > AI key
</x-mail::button>
</x-mail::message>
