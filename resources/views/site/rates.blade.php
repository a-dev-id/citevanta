@php
    /** @var \App\Models\Hotel $hotel */
    $money = fn ($amount) => $currency.' '.number_format((float) $amount, 0);
    $maxNights = (int) config('citevanta.rates.max_nights');
    $isStay = $checkin !== null;
@endphp

<x-layouts::public :title="$title" :description="$description ?? ($hotel->name.' in '.$hotel->city.': rates entered by the hotel, with a direct booking link.')" :json-ld="$json_ld">
    <section class="cv-section">
        <div class="container" style="max-width: 860px">
            <p class="text-muted small mb-1">{{ $hotel->city }}@if ($hotel->country), {{ $hotel->country }}@endif</p>
            <h1 class="cv-section-title">{{ $hotel->name }}: @if ($isStay) rates for {{ $nights }} {{ $nights === 1 ? 'night' : 'nights' }} from {{ \Illuminate\Support\Carbon::parse($checkin)->format('l j F Y') }} @else direct rates @endif</h1>

            @if ($description)
                <p class="cv-section-lead">{{ $description }}</p>
            @endif

            <p id="last-updated" class="small text-muted">
                @if ($updated_label)
                    Rates last updated by the hotel: <time datetime="{{ $updated->toIso8601String() }}">{{ $updated_label }}</time>.
                @else
                    The hotel has not entered rates for {{ $isStay ? 'these dates' : 'upcoming dates' }} yet.
                @endif
            </p>

            <form method="GET" action="{{ route('rates.show', $hotel->slug) }}" class="row g-2 align-items-end mb-4" aria-label="Choose your dates">
                <div class="col-sm-5">
                    <label for="rates-checkin" class="form-label">Check-in</label>
                    <input id="rates-checkin" name="checkin" type="date" class="form-control" value="{{ $checkin }}" min="{{ now()->toDateString() }}" required>
                </div>
                <div class="col-sm-4">
                    <label for="rates-nights" class="form-label">Nights</label>
                    <select id="rates-nights" name="nights" class="form-select">
                        @foreach (range(1, min(14, $maxNights)) as $n)
                            <option value="{{ $n }}" @selected($n === $nights)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3"><button class="btn btn-outline-primary w-100" type="submit">Show rates</button></div>
            </form>

            @if ($rooms === [])
                <p>The hotel has not listed its room types yet. Rates are on request.</p>
            @endif

            <div class="row g-3">
                @foreach ($rooms as $room)
                    <div class="col-12">
                        <article class="card h-100" id="room-{{ $room['id'] }}">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <h2 class="h5 mb-1">{{ $room['name'] }}</h2>
                                        @if ($room['description'])<p class="text-muted mb-2">{{ $room['description'] }}</p>@endif
                                    </div>
                                    <div class="text-md-end">
                                        @switch($room['status'])
                                            @case('available')
                                                <div class="fs-4 fw-semibold">{{ $money($room['total']) }}</div>
                                                <div class="small text-muted">total for {{ $nights }} {{ $nights === 1 ? 'night' : 'nights' }}@if ($nights > 1), about {{ $money($room['average']) }} per night @endif</div>
                                                @break
                                            @case('from')
                                                <div class="small text-muted">from</div>
                                                <div class="fs-4 fw-semibold">{{ $money($room['price']) }}</div>
                                                <div class="small text-muted">per night, lowest rate the hotel entered (e.g. {{ \Illuminate\Support\Carbon::parse($room['price_date'])->format('j M Y') }})</div>
                                                @break
                                            @case('unavailable')
                                                <div class="fw-semibold">Not available on these dates</div>
                                                @break
                                            @case('min_stay')
                                                <div class="fw-semibold">Minimum stay {{ $room['min_stay'] }} nights</div>
                                                @break
                                            @default
                                                <div class="fw-semibold">Rates on request</div>
                                                <div class="small text-muted">The hotel has not entered a rate for {{ $isStay ? 'these dates' : 'upcoming dates' }}.</div>
                                        @endswitch
                                    </div>
                                </div>

                                @if ($room['status'] === 'available' && $nights > 1)
                                    <ul class="small text-muted mt-2 mb-0">
                                        @foreach ($room['nightly'] as $date => $price)
                                            <li>{{ \Illuminate\Support\Carbon::parse($date)->format('D j M') }}: {{ $money($price) }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @foreach ($room['notes'] ?? [] as $note)
                                    <p class="small mb-0 mt-2"><i class="fa-regular fa-note-sticky me-1" aria-hidden="true"></i>{{ $note }}</p>
                                @endforeach
                                @if (($room['note'] ?? null) && $room['status'] === 'from')
                                    <p class="small mb-0 mt-2">{{ $room['note'] }}</p>
                                @endif
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>

            @if ($inclusions !== [])
                <h2 class="h5 mt-4">What is included</h2>
                <ul>
                    @foreach ($inclusions as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($book_url)
                <p class="mt-4">
                    <a href="{{ $book_url }}" class="btn btn-primary btn-lg" rel="nofollow">Book direct</a>
                    <span class="small text-muted ms-2">You book on {{ $hotel->name }}'s own booking engine.</span>
                </p>
            @endif

            <p class="small text-muted mt-4 border-top pt-3">
                These rates are entered by {{ $hotel->name }} itself and shown without changes; prices and availability are confirmed when you book.
                @if ($hotel->website) <a href="{{ $hotel->website }}" rel="nofollow">Hotel website</a>. @endif
            </p>
        </div>
    </section>
</x-layouts::public>
