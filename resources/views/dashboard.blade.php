<x-layouts::app title="Dashboard" :breadcrumbs="['Dashboard' => null]">
    @if ($hotels->isEmpty())
        <x-card :title="'Welcome to '.config('app.name')" :collapsible="false">
            <x-empty-state icon="fa-hotel"
                           text="Add your hotel to see how ChatGPT recommends it to travellers."
                           action-label="Add your hotel"
                           :action-url="Route::has('hotels.create') ? route('hotels.create') : null" />
        </x-card>
    @else
        <x-card title="Your hotels" :flush="true" :collapsible="false">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr><th scope="col">Hotel</th><th scope="col">City</th><th scope="col">Setup</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($hotels as $hotel)
                            <tr>
                                <td class="fw-semibold">
                                    <a href="{{ $hotel->isSetUp() ? route('hotels.show', $hotel) : route('hotels.setup', $hotel) }}">{{ $hotel->name }}</a>
                                </td>
                                <td>{{ $hotel->city }}, {{ $hotel->country }}</td>
                                <td>
                                    @if ($hotel->isSetUp())
                                        <x-badge-status label="Ready" variant="success" icon="fa-circle-check" />
                                    @else
                                        <a href="{{ route('hotels.setup', $hotel) }}" class="text-decoration-none">
                                            <x-badge-status label="Finish setup (step {{ $hotel->setup_step }} of {{ \App\Models\Hotel::SETUP_STEPS }})" variant="warning" icon="fa-pen" />
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts::app>
