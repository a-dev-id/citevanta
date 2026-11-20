<x-card title="Scan #{{ $scan->id }} failed" :collapsible="false" class="border-start border-4 border-danger">
    <x-empty-state icon="fa-triangle-exclamation"
                   text="This scan collected no answers{{ $scan->finished_at ? ' ('.$fmt($scan->finished_at).')' : '' }}. Your earlier results are not affected." />
    @if (! empty($scan->errors))
        <details class="small mb-3">
            <summary>What went wrong ({{ count($scan->errors) }} {{ Str::plural('error', count($scan->errors)) }})</summary>
            <ul class="mb-0 mt-2">
                @foreach (array_slice($scan->errors, 0, 5) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </details>
    @endif
    @if (auth()->user()->is_admin)
        <div class="text-center">
            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="retryFailed({{ $scan->id }})">
                <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i> Retry failed calls (admin)
            </button>
        </div>
    @endif
</x-card>
