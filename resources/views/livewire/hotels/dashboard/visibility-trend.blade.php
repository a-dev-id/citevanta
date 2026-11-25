@php($trends = $d->trends)

<x-card title="Visibility trend" :subtitle="$trends ? 'Share of answers that mention your hotel, last '.$trends['scans'].' scans' : null" :collapsible="false" data-tour="trend">
    @if ($trends)
        <x-slot:actions>
            <div class="btn-group cv-chart-toggle me-1" role="group" aria-label="Lines shown">
                <button type="button" class="btn btn-outline-primary active" aria-pressed="true"
                        data-cv-chart-target="cv-chart-visibility" data-cv-chart-group="overall">Overall</button>
                <button type="button" class="btn btn-outline-primary" aria-pressed="false"
                        data-cv-chart-target="cv-chart-visibility" data-cv-chart-group="personas">By persona</button>
            </div>
        </x-slot:actions>

        <x-chart id="cv-chart-visibility" :chart="$trends['visibility']" group="overall" label="Visibility per scan, overall and per persona" />
    @else
        <x-empty-state icon="fa-chart-line" text="Trends appear after your second scan." />
    @endif
</x-card>
