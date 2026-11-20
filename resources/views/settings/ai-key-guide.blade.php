@php
    $steps = [
        ['Create an OpenAI account', 'Go to platform.openai.com and sign up (or sign in). Use your company email so your team can manage billing later.'],
        ['Buy credit', 'Open Settings > Billing and add credit, for example $20. OpenAI uses prepaid credit: calls stop when it runs out, so you never get an unexpected bill.'],
        ['Turn OFF auto-reload', 'In Settings > Billing, make sure "Auto recharge" / auto-reload is OFF. Then your cost can never go above the credit you added.'],
        ['Set a monthly spend limit', 'In Settings > Limits, set a monthly budget (for example $30). OpenAI stops the key when it is reached; we pause your scans and email you.'],
        ['Create a project', 'In the top-left project menu, create a project named "'.config('app.name').'". A separate project lets you see its cost and limit it on its own.'],
        ['Create an API key', 'In the project, open API keys > Create new secret key. Name it "'.config('app.name').'", permissions "All". Copy the key: OpenAI shows it only once.'],
        ['Paste it here', 'Go back to Settings > AI key, paste the key and click "Test and save". We check it with one free request and store it encrypted.'],
    ];
@endphp
<x-layouts::app title="How to create your OpenAI key" :breadcrumbs="['Settings' => null, 'AI key' => route('settings.ai-key'), 'Guide' => null]">
    <x-card title="Your own OpenAI key in 7 steps" subtitle="About 10 minutes. You pay OpenAI directly for your scans; {{ config('app.name') }} never sees your card." :collapsible="false">
        <ol class="list-unstyled mb-0">
            @foreach ($steps as $i => [$title, $text])
                <li class="d-flex gap-3 mb-4">
                    <span class="badge rounded-pill text-bg-primary align-self-start fs-6">{{ $i + 1 }}</span>
                    <div class="flex-grow-1">
                        <h2 class="h6 mb-1">{{ $title }}</h2>
                        <p class="mb-2">{{ $text }}</p>
                        {{-- Screenshot placeholder: add public/images/guides/openai-step-{{ $i + 1 }}.webp --}}
                        @if (file_exists(public_path('images/guides/openai-step-'.($i + 1).'.webp')))
                            <img src="{{ asset('images/guides/openai-step-'.($i + 1).'.webp') }}" alt="Screenshot of step {{ $i + 1 }}: {{ $title }}" class="img-fluid border rounded" loading="lazy">
                        @else
                            <div class="border rounded bg-light text-muted small p-4 text-center">Screenshot of step {{ $i + 1 }} coming soon</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
        <x-slot:footer>
            <a href="{{ route('settings.ai-key') }}" class="btn btn-primary">Paste my key</a>
        </x-slot:footer>
    </x-card>
</x-layouts::app>
