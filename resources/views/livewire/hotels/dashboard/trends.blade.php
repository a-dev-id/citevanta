@if ($d->trends)
    <div class="row gx-3">
        <div class="col-xl-6">
            <x-card title="Share of voice over time" subtitle="Your hotel against tracked competitors (all engines)" :collapsible="false">
                <x-chart id="cv-chart-sov" :chart="$d->trends['share_of_voice']" label="Share of voice per scan for your hotel and each competitor" />
            </x-card>
        </div>
        <div class="col-xl-6">
            <x-card title="Where AI gets its information over time" subtitle="Citations per scan by type of website" :collapsible="false">
                <x-chart id="cv-chart-sources" type="bar" :stacked="true" :chart="$d->trends['sources']" label="Citations per scan by domain type" />
            </x-card>
        </div>
    </div>
@endif
