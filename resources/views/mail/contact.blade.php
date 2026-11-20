<x-mail::message>
# New message from the website

**From:** {{ $senderName }} ({{ $senderEmail }})
@if ($company !== '')
**Hotel or company:** {{ $company }}
@endif

<x-mail::panel>
{{ $body }}
</x-mail::panel>

Reply to this email to answer {{ $senderName }} directly.
</x-mail::message>
