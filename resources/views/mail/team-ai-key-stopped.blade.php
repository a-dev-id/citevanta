<x-mail::message>
# Your OpenAI key needs attention

OpenAI refused a request made with your API key (**{{ $code }}**). Your scans and content are paused; nothing is lost and no other key is used.

{{ $advice }}

<x-mail::button :url="$url">
Open Settings > AI key
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
