<x-layouts::app title="Prompts" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Prompts' => null]">
    <livewire:hotels.prompts-manager :hotel-id="$hotel->id" />
</x-layouts::app>
