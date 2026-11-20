<x-layouts::app :title="'Edit '.$hotel->name" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Edit' => null]">
    <div class="row gx-3">
        <div class="col-xl-6">
            <livewire:hotels.details-form :hotel-id="$hotel->id" />
        </div>
        <div class="col-xl-6">
            <livewire:hotels.facts-form :hotel-id="$hotel->id" />
        </div>
    </div>
</x-layouts::app>
