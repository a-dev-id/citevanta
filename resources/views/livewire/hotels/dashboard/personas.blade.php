@php($hasLanguages = $d->byLanguage !== [])

<x-card :title="$hasLanguages ? 'Visibility by persona and language' : 'Visibility by persona'" subtitle="Share of answers that mention your hotel, per type of traveller" :flush="true">
    @if ($hasLanguages)
        <ul class="nav nav-tabs px-3 pt-2" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-personas" data-bs-toggle="tab" data-bs-target="#pane-personas" type="button" role="tab" aria-controls="pane-personas" aria-selected="true">By persona</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-languages" data-bs-toggle="tab" data-bs-target="#pane-languages" type="button" role="tab" aria-controls="pane-languages" aria-selected="false">By language</button>
            </li>
        </ul>
    @endif

    <div @class(['tab-content' => $hasLanguages])>
        <div @class(['tab-pane fade show active' => $hasLanguages]) id="pane-personas" role="tabpanel" aria-labelledby="tab-personas">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th scope="col">Persona</th>
                            <th scope="col" class="cv-num">Answers</th>
                            <th scope="col" class="cv-num">Mentions</th>
                            <th scope="col" class="cv-num">Visibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($d->personas as $row)
                            <tr>
                                <td><x-badge-status :label="ucfirst($row['persona'])" variant="primary" /></td>
                                <td class="cv-num">{{ $row['answers'] }}</td>
                                <td class="cv-num">{{ $row['mentions'] }}</td>
                                <td class="cv-num">
                                    <div class="cv-progress-cell">
                                        <div class="progress" role="progressbar" aria-label="{{ ucfirst($row['persona']) }} visibility"
                                             aria-valuenow="{{ $row['visibility'] }}" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar" style="width: {{ $row['visibility'] }}%"></div>
                                        </div>
                                        <span>{{ \App\Services\Dashboard\DashboardService::pct($row['visibility']) }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($hasLanguages)
            <div class="tab-pane fade" id="pane-languages" role="tabpanel" aria-labelledby="tab-languages">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th scope="col">Language</th>
                                <th scope="col" class="cv-num">Answers</th>
                                <th scope="col" class="cv-num">Mentions</th>
                                <th scope="col" class="cv-num">Visibility</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d->byLanguage as $row)
                                <tr>
                                    <td><x-badge-status :label="$row['name']" variant="info" /></td>
                                    <td class="cv-num">{{ $row['answers'] }}</td>
                                    <td class="cv-num">{{ $row['mentions'] }}</td>
                                    <td class="cv-num">
                                        <div class="cv-progress-cell">
                                            <div class="progress" role="progressbar" aria-label="{{ $row['name'] }} visibility"
                                                 aria-valuenow="{{ $row['visibility'] }}" aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar" style="width: {{ $row['visibility'] }}%"></div>
                                            </div>
                                            <span>{{ \App\Services\Dashboard\DashboardService::pct($row['visibility']) }}</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-card>
