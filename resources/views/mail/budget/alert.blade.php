<x-mail::message>
# {{ $bucket }}: {{ $percent }}% used

${{ number_format($spent, 2) }} of ${{ number_format($cap, 2) }} this month.

@if ($percent >= 100)
New AI calls for this budget are paused until next month or until the cap is raised. Paused work resumes from Admin > System.
@else
Nothing is paused yet.
@endif

<x-mail::button :url="$url">
Open Admin > System
</x-mail::button>
</x-mail::message>
