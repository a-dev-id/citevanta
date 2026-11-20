<x-layouts::app title="Competitors" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Competitors' => null]">
    <livewire:hotels.competitors-manager :hotel-id="$hotel->id" />
</x-layouts::app>
