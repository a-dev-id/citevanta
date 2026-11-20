<x-mail::message>
# Your weekly AI visibility report

Hi {{ $user->name }}, here is what changed for {{ $team->name }} since the last report.

@foreach ($hotels as $hotel)
## {{ $hotel['hotel'] }}

**AI visibility: {{ $percent($hotel['visibility']) }}**@if ($hotel['change'] !== null) ({{ $signed($hotel['change']) }} since the previous scan)@endif

<x-mail::table>
| | |
|:--|:--|
@if ($hotel['best_persona'])
| Best for | {{ $hotel['best_persona']['name'] }} ({{ $percent($hotel['best_persona']['visibility']) }}) |
| Weakest for | {{ $hotel['worst_persona']['name'] }} ({{ $percent($hotel['worst_persona']['visibility']) }}) |
@endif
| New accuracy issues | {{ $hotel['new_issues'] }} |
| Resolved accuracy issues | {{ $hotel['resolved_issues'] }} |
@if ($hotel['competitor'])
| Competitor {{ $hotel['competitor']['change'] > 0 ? 'gaining' : 'losing' }} | {{ $hotel['competitor']['name'] }} ({{ $signed($hotel['competitor']['change']) }}) |
@endif
</x-mail::table>

@if ($hotel['recommendations'] !== [])
**Top recommendations**

@foreach ($hotel['recommendations'] as $title)
{{ $loop->iteration }}. {{ $title }}
@endforeach
@endif

[Open {{ $hotel['hotel'] }}]({{ $hotel['url'] }})

@endforeach

<x-mail::button :url="$dashboardUrl">
Open the dashboard
</x-mail::button>

{{ config('app.name') }}

<x-slot:subcopy>
You get this email because weekly reports are on for your account. [Unsubscribe]({{ $unsubscribeUrl }}) or change it in Settings > Profile.
</x-slot:subcopy>
</x-mail::message>
