<x-card title="Top recommendations" :subtitle="$d->recommendations['open'].' open '.Str::plural('item', $d->recommendations['open'])" :flush="true">
    @if ($d->recommendations['top']->isEmpty())
        <x-empty-state icon="fa-lightbulb" text="No open recommendations. New ones appear after each scan." />
    @else
        <ul class="list-unstyled mb-0 cv-issue-list">
            @foreach ($d->recommendations['top'] as $item)
                <li class="cv-issue" wire:key="dash-rec-{{ $item->id }}">
                    <a href="{{ route('hotels.recommendations', $d->hotel) }}#recommendation-{{ $item->id }}" class="fw-semibold">{{ $item->title }}</a>
                    <span class="d-flex flex-wrap gap-1 mt-1">
                        <x-badge-status :label="$item->impact->label().' impact'" :variant="$item->impact->variant()" />
                        <x-badge-status :label="$item->effort->label()" variant="neutral" />
                        <x-badge-status :label="$item->category->label()" variant="primary" />
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    <x-slot:footer>
        <a href="{{ route('hotels.recommendations', $d->hotel) }}">All recommendations</a>
    </x-slot:footer>
</x-card>
