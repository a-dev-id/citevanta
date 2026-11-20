<?php

namespace App\Services\Dashboard;

use App\Enums\DomainType;
use App\Enums\Persona;
use App\Enums\ScanStatus;
use App\Models\Citation;
use App\Models\CompetitorScore;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\VisibilityScore;
use App\Services\Reporting\VisibilityReportBuilder;
use Illuminate\Support\Collection;

/**
 * Data for the dashboard trend charts, one point per completed scan (oldest
 * first, up to and including the scan being viewed):
 *  - visibility: overall line, plus one line per persona (toggle)
 *  - share of voice: the hotel and each competitor
 *  - sources: citations per domain type (stacked bars)
 *
 * Chart arrays are plain data; colours are tokens ("series-1", "domain-ota")
 * that resources/js/charts.js resolves from CSS variables set in Sass.
 *
 * @phpstan-type Series array{key: string, label: string, color: string, data: list<float|int|null>, group: string, highlight: bool}
 * @phpstan-type Chart array{labels: list<string>, titles: list<string>, series: list<Series>, unit: string}
 */
final class TrendService
{
    /** Line colours in fixed order: slot 1 is the hotel / overall line. */
    public const SERIES_COLOURS = 8;

    public function __construct(
        private readonly DomainClassifier $classifier,
        private readonly VisibilityReportBuilder $reports,
    ) {}

    /**
     * Completed scans for the trend, oldest first.
     *
     * @return Collection<int, ScanRun>
     */
    public function scans(Hotel $hotel, ?ScanRun $upTo = null): Collection
    {
        return $hotel->scanRuns()
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->when($upTo?->finished_at, fn ($query) => $query->where(fn ($q) => $q
                ->where('finished_at', '<', $upTo->finished_at)
                ->orWhere(fn ($same) => $same->where('finished_at', $upTo->finished_at)->where('id', '<=', $upTo->id))))
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->limit((int) config('citevanta.trend_scans', 12))
            ->get()
            ->each(fn (ScanRun $scan) => $scan->setRelation('hotel', $hotel))
            ->reverse()->values();
    }

    /**
     * @param  Collection<int, ScanRun>  $scans
     * @return Chart
     */
    public function visibility(Collection $scans, ?string $engine = null, ?string $language = null): array
    {
        $scores = VisibilityScore::query()
            ->whereIn('scan_run_id', $scans->pluck('id'))
            ->when($engine, fn ($q) => $q->where('engine', $engine))
            ->get()
            ->groupBy(fn (VisibilityScore $score) => $score->scan_run_id.'|'.($score->persona ?? ''));

        // Scores have no language split: with a language filter, read each scan's answers.
        $reports = $language === null ? collect()
            : $scans->mapWithKeys(fn (ScanRun $scan) => [$scan->id => $this->reports->build($scan, [], $engine, $language)]);

        $point = function (ScanRun $scan, string $persona) use ($scores, $reports, $language): ?float {
            if ($language !== null) {
                $report = $reports[$scan->id];
                $row = $persona === '' ? ['answers' => $report->totalAnswers, 'visibility' => $report->visibility] : ($report->perPersona[$persona] ?? null);

                return $row === null || $row['answers'] === 0 ? null : $row['visibility'];
            }

            $rows = $scores->get($scan->id.'|'.$persona);

            return $rows === null || (int) $rows->sum('answers') === 0
                ? null
                : VisibilityReportBuilder::percentage((int) $rows->sum('mentions'), (int) $rows->sum('answers'));
        };

        $series = [self::series('overall', 'All prompts', 'series-1', $scans->map(fn ($scan) => $point($scan, ''))->all(), 'overall')];

        foreach (Persona::cases() as $i => $persona) {
            $data = $scans->map(fn ($scan) => $point($scan, $persona->value))->all();

            if (array_filter($data, fn ($value) => $value !== null) !== []) {
                $series[] = self::series($persona->value, ucfirst($persona->value), 'series-'.($i + 2), $data, 'personas');
            }
        }

        return $this->chart($scans, $series, '%');
    }

    /**
     * Share of voice per scan; the hotel is highlighted. Competitors keep the
     * same colour across scans (by competitor id); past the palette they fold
     * into "Other competitors".
     *
     * @param  Collection<int, ScanRun>  $scans
     * @return Chart
     */
    public function shareOfVoice(Hotel $hotel, Collection $scans): array
    {
        $scores = CompetitorScore::query()->with('competitor')->whereIn('scan_run_id', $scans->pluck('id'))->get();
        $competitorIds = $scores->pluck('competitor_id')->filter()->unique()->sort()->values();
        $named = $competitorIds->take(self::SERIES_COLOURS - 2);
        $folded = $competitorIds->slice(self::SERIES_COLOURS - 2)->all();

        $sumFor = fn (ScanRun $scan, callable $filter): ?float => ($rows = $scores->where('scan_run_id', $scan->id))->isEmpty()
            ? null
            : round((float) $rows->filter($filter)->sum('share_of_voice_pct'), 1);

        $series = [self::series('hotel', $hotel->name, 'series-1', $scans->map(fn ($scan) => $sumFor($scan, fn ($s) => $s->competitor_id === null))->all(), 'all', true)];

        foreach ($named->values() as $i => $id) {
            $name = $scores->firstWhere('competitor_id', $id)?->competitor?->name ?? 'Removed competitor';
            $series[] = self::series('competitor-'.$id, $name, 'series-'.($i + 2), $scans->map(fn ($scan) => $sumFor($scan, fn ($s) => $s->competitor_id === $id))->all(), 'all');
        }

        if ($folded !== []) {
            $series[] = self::series('others', 'Other competitors', 'series-'.self::SERIES_COLOURS, $scans->map(fn ($scan) => $sumFor($scan, fn ($s) => in_array($s->competitor_id, $folded, true)))->all(), 'all');
        }

        return $this->chart($scans, $series, '%');
    }

    /**
     * Citations per domain type per scan (stacked). Read from the citations
     * table rather than citation_summary, which keeps only the top 20 domains
     * and is not split by engine.
     *
     * @param  Collection<int, ScanRun>  $scans
     * @return Chart
     */
    public function sources(Hotel $hotel, Collection $scans, ?string $engine = null): array
    {
        $context = DomainContext::forHotel($hotel);
        $counts = [];

        Citation::query()
            ->join('responses', 'responses.id', '=', 'citations.response_id')
            ->whereIn('responses.scan_run_id', $scans->pluck('id'))
            ->when($engine, fn ($q) => $q->where('responses.engine', $engine))
            ->selectRaw('responses.scan_run_id, citations.domain, count(*) as citations')
            ->groupBy('responses.scan_run_id', 'citations.domain')
            ->get()
            ->each(function ($row) use (&$counts, $context): void {
                $type = $this->classifier->classify((string) $row->domain, $context)->value;
                $counts[$row->scan_run_id][$type] = ($counts[$row->scan_run_id][$type] ?? 0) + (int) $row->citations;
            });

        $series = [];
        foreach (DomainType::cases() as $type) {
            $data = $scans->map(fn ($scan) => $counts[$scan->id][$type->value] ?? 0)->all();

            if (array_sum($data) > 0) {
                $series[] = self::series($type->value, $type->label(), 'domain-'.$type->value, $data, 'all');
            }
        }

        return $this->chart($scans, $series, '');
    }

    /**
     * @param  Collection<int, ScanRun>  $scans
     * @param  list<Series>  $series
     * @return Chart
     */
    private function chart(Collection $scans, array $series, string $unit): array
    {
        $tz = $scans->first()?->hotel?->team?->timezoneName() ?? config('citevanta.default_timezone');

        return [
            'labels' => $scans->map(fn (ScanRun $scan) => $scan->finished_at->copy()->setTimezone($tz)->format('j M'))->values()->all(),
            'titles' => $scans->map(fn (ScanRun $scan) => 'Scan #'.$scan->id.' · '.$scan->finished_at->copy()->setTimezone($tz)->format('j M Y'))->values()->all(),
            'series' => $series,
            'unit' => $unit,
        ];
    }

    /**
     * @param  list<float|int|null>  $data
     * @return Series
     */
    private static function series(string $key, string $label, string $color, array $data, string $group, bool $highlight = false): array
    {
        return ['key' => $key, 'label' => $label, 'color' => $color, 'data' => array_values($data), 'group' => $group, 'highlight' => $highlight];
    }
}
