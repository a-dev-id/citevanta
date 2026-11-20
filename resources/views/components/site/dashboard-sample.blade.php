{{--
    Sample dashboard for the home page, using the demo hotel's data (DemoSeeder:
    visibility 25% -> 45% -> 65% over three scans, its three competitors).
    A real screenshot can replace it: put a 1600x1000 image at
    public/images/dashboard-sample.webp and it is shown instead (lazy-loaded).
--}}
@php
    $demoHotel = 'Kirana Hills Resort Ubud';
    $image = public_path('images/dashboard-sample.webp');
    $share = [
        ['name' => $demoHotel, 'pct' => 34, 'you' => true],
        ['name' => 'Bamboo Valley Retreat', 'pct' => 27, 'you' => false],
        ['name' => 'Lotus Terrace Ubud', 'pct' => 22, 'you' => false],
        ['name' => 'Sayan Ridge Villas', 'pct' => 17, 'you' => false],
    ];
    $trend = [25, 45, 65];
@endphp

@if (is_file($image))
    <img src="{{ asset('images/dashboard-sample.webp') }}" width="1600" height="1000" loading="lazy" decoding="async"
         class="img-fluid cv-sample" alt="{{ __('site.sample.alt', ['app' => config('app.name')]) }}">
@else
    <figure class="cv-sample mb-0" aria-label="{{ __('site.sample.alt', ['app' => config('app.name')]) }}">
        <div class="cv-sample__bar">
            <span class="dot"></span><span class="dot"></span><span class="dot"></span>
            <span class="ms-2">{{ $demoHotel }}</span>
            <span class="ms-auto badge text-bg-secondary">{{ __('site.sample.label') }}</span>
        </div>
        <div class="cv-sample__body">
            <div class="row g-2 mb-2">
                @foreach ([
                    ['label' => __('site.sample.visibility'), 'value' => '65%', 'change' => '+20 pts'],
                    ['label' => __('site.sample.position'), 'value' => '#2.1', 'change' => '0.6 better'],
                    ['label' => __('site.sample.positive'), 'value' => '78%', 'change' => '+9 pts'],
                    ['label' => __('site.sample.sources'), 'value' => '24', 'change' => '+5'],
                ] as $widget)
                    <div class="col-6 col-lg-3">
                        <div class="cv-sample__widget">
                            <div class="label">{{ $widget['label'] }}</div>
                            <div class="value">{{ $widget['value'] }}</div>
                            <div class="change">{{ $widget['change'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="row g-2">
                <div class="col-md-6">
                    <div class="cv-sample__panel">
                        <h3>{{ __('site.sample.trend') }}</h3>
                        <svg viewBox="0 0 300 120" role="img" aria-label="{{ __('site.sample.trend') }}: {{ implode('%, ', $trend) }}%">
                            <line x1="20" y1="100" x2="290" y2="100" stroke="#e5e7eb" />
                            <line x1="20" y1="60" x2="290" y2="60" stroke="#f1f2f4" />
                            <line x1="20" y1="20" x2="290" y2="20" stroke="#f1f2f4" />
                            @php($points = collect($trend)->map(fn ($v, $i) => (40 + $i * 110).','.(100 - $v * 0.8))->implode(' '))
                            <polyline points="{{ $points }}" fill="none" stroke="#0088cc" stroke-width="3" />
                            @foreach ($trend as $i => $value)
                                <circle cx="{{ 40 + $i * 110 }}" cy="{{ 100 - $value * 0.8 }}" r="5" fill="#0088cc" stroke="#fff" stroke-width="2" />
                                <text x="{{ 40 + $i * 110 }}" y="{{ 92 - $value * 0.8 }}" font-size="11" text-anchor="middle" fill="#33353f">{{ $value }}%</text>
                            @endforeach
                        </svg>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="cv-sample__panel">
                        <h3>{{ __('site.sample.share') }}</h3>
                        @foreach ($share as $row)
                            <div @class(['cv-sample__row', 'is-you' => $row['you']])>
                                <span class="name">{{ $row['you'] ? __('site.sample.you') : $row['name'] }}</span>
                                <span class="bar"><span style="width: {{ $row['pct'] * 2 }}%"></span></span>
                                <span class="pct">{{ $row['pct'] }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </figure>
@endif
