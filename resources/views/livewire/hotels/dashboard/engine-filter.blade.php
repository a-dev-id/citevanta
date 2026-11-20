@if (count($d->engines) > 0 || count($d->languages) > 1)
    <div class="d-flex flex-wrap gap-3 mb-3">
        @if (count($d->engines) > 0)
            <nav class="cv-engine-filter" aria-label="Filter by AI assistant">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <button type="button" @class(['nav-link', 'active' => $d->engine === null]) wire:click="setEngine('')"
                                @if ($d->engine === null) aria-current="true" @endif>All engines</button>
                    </li>
                    @foreach ($d->engines as $engine)
                        <li class="nav-item">
                            <button type="button" @class(['nav-link', 'active' => $d->engine === $engine]) wire:click="setEngine('{{ $engine }}')"
                                    @if ($d->engine === $engine) aria-current="true" @endif>
                                {{ \App\Services\Dashboard\DashboardService::engineLabel($engine) }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if (count($d->languages) > 1)
            <nav class="cv-engine-filter" aria-label="Filter by prompt language">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <button type="button" @class(['nav-link', 'active' => $d->language === null]) wire:click="setLanguage('')"
                                @if ($d->language === null) aria-current="true" @endif>All languages</button>
                    </li>
                    @foreach ($d->languages as $code)
                        <li class="nav-item">
                            <button type="button" @class(['nav-link', 'active' => $d->language === $code]) wire:click="setLanguage('{{ $code }}')"
                                    @if ($d->language === $code) aria-current="true" @endif>
                                {{ config("citevanta.languages.{$code}", strtoupper($code)) }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
@endif
