<?php

namespace App\Services\Google;

use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only comparison of what we stored from GA4 for a hotel with what GA4 returns now.
 * Used by citevanta:google-diagnose and by Admin > Integrations > Google.
 *
 * The output is plain text. Every line passes through safe(): no key block, access token or
 * bearer value can get into it, whatever Google answers.
 */
final class GoogleDiagnostics
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * @return list<string>
     */
    public function run(Hotel $hotel, ?string $day = null, string $source = 'chatgpt.com', int $days = 28): array
    {
        $this->lines = [];
        $link = $hotel->googleLink;

        if ($link === null || $link->ga4_property === null) {
            return ['No GA4 property is linked for this hotel.'];
        }

        $to = Carbon::yesterday();
        $from = $to->copy()->subDays(max(1, $days) - 1);
        $day = $day ?: now()->subDays(10)->toDateString();
        $fake = (bool) config('services.google.fake');

        $this->add("{$hotel->name} (hotel {$hotel->id}), {$link->ga4_property}".($fake ? ' - FAKE Google data, not the real GA4' : ''));
        $this->stored($hotel->id, $link);
        $this->jobs($link->id);
        $this->requests($from, $to);

        if ($fake) {
            $this->add('');
            $this->add('Live comparison skipped: no Google key is set on this machine.');

            return $this->lines;
        }

        try {
            $this->singleDay($hotel->id, (string) $link->ga4_property, $day, $source);
            $this->period($hotel->id, (string) $link->ga4_property, $from, $to);
        } catch (GoogleApiException $e) {
            $this->add('');
            $this->add('Google refused the live request: '.$e->getMessage());
        }

        return $this->lines;
    }

    private function add(string $line): void
    {
        $this->lines[] = self::safe($line);
    }

    /** Whatever the text is, no key block, token or bearer value stays in it. */
    public static function safe(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[private key removed]', $text);
        $text = (string) preg_replace('/\bya29\.[A-Za-z0-9._-]+/', '[token removed]', $text);
        $text = (string) preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/', '[token removed]', $text);
        $text = (string) preg_replace('/\b(Bearer|access_token|refresh_token|private_key)\b["\':= ]+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [removed]', $text);

        return mb_substr($text, 0, 2000);
    }

    private function stored(int $hotelId, HotelGoogleLink $link): void
    {
        $this->add('');
        $this->add('== 1. What is stored');
        $this->add('first_synced_at: '.($link->first_synced_at ?? 'never').' | last_synced_at: '.($link->last_synced_at ?? 'never').' | last_sync_error: '.($link->last_sync_error ?? 'none'));

        foreach (['ga4_traffic_daily' => 'sessions', 'ga4_events_daily' => 'event_count', 'ga4_booking_dates_daily' => 'events'] as $table => $metric) {
            $row = DB::table($table)->where('hotel_id', $hotelId)->selectRaw("min(date) as first, max(date) as last, count(distinct date) as days, count(*) as `rows`, coalesce(sum({$metric}),0) as total")->first();
            $this->add(sprintf('%-24s %s to %s, %d days with rows, %d rows, %s total', $table, $row->first ?? '-', $row->last ?? '-', $row->days, $row->rows, $row->total));
        }

        $have = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->distinct()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $missing = [];
        for ($d = Carbon::yesterday()->subDays(40); $d->lte(Carbon::yesterday()); $d->addDay()) {
            if (! $have->has($d->toDateString())) {
                $missing[] = $d->toDateString();
            }
        }
        $this->add('Days without traffic rows in the last 41 days: '.($missing === [] ? 'none' : implode(', ', $missing)));
    }

    private function jobs(int $linkId): void
    {
        $this->add('');
        $this->add('== 2. Jobs');

        if (! Schema::hasTable('jobs')) {
            $this->add('No jobs table.');

            return;
        }

        $pending = DB::table('jobs')->where('queue', 'google')->get(['payload', 'attempts'])
            ->map(fn ($job) => [(string) (json_decode((string) $job->payload, true)['data']['command'] ?? ''), $job->attempts])
            ->filter(fn ($row) => str_contains($row[0], '"linkId";i:'.$linkId.';'))
            ->map(function ($row) {
                $get = fn (string $name) => preg_match('/"'.$name.'";(?:s:\d+:"([^"]*)"|i:(\d+))/', $row[0], $m) ? ($m[2] ?? $m[1]) : '?';

                return "{$get('type')} {$get('from')} to {$get('to')} offset {$get('offset')} (attempts {$row[1]})";
            });

        $this->add('Waiting google jobs for this link: '.$pending->count().($pending->isEmpty() ? ' - the sync is NOT running (nothing is waiting).' : ' - the sync is still running.'));
        $pending->take(8)->each(fn ($line) => $this->add('  '.$line));

        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('queue', 'google')->orderByDesc('id')->limit(5)->get(['failed_at', 'exception']) : collect();
        $this->add('Failed google jobs (latest 5): '.($failed->isEmpty() ? 'none' : ''));
        $failed->each(fn ($job) => $this->add("  {$job->failed_at}: ".mb_substr((string) strtok((string) $job->exception, "\n"), 0, 200)));
    }

    private function requests(Carbon $from, Carbon $to): void
    {
        $limit = (int) config('citevanta.google.ga4_page_size', 10000);

        $this->add('');
        $this->add('== 3. The exact request the traffic sync sends (POST properties/{id}:runReport)');
        $this->add('One window of '.GoogleSync::WINDOW_DAYS." days, pages of {$limit} rows, no dimensionFilter:");
        foreach (explode("\n", (string) json_encode(GoogleSync::trafficRequest($from->toDateString(), GoogleSync::windowEnd($from->toDateString(), $to->toDateString()), $limit), JSON_PRETTY_PRINT)) as $line) {
            $this->add('  '.$line);
        }
        $this->add('"purchases" is the GA4 metric ecommercePurchases; "revenue" is purchaseRevenue. AI rows are picked afterwards by session source (config citevanta.ai_sources), any medium.');
    }

    private function singleDay(int $hotelId, string $property, string $day, string $source): void
    {
        $this->add('');
        $this->add("== 4. One day live: {$day}, sessionSource = {$source}");
        $data = $this->api->runReport($property, [
            'dateRanges' => [['startDate' => $day, 'endDate' => $day]],
            'dimensions' => [['name' => 'sessionSource'], ['name' => 'sessionMedium'], ['name' => 'country']],
            'metrics' => [['name' => 'sessions'], ['name' => 'ecommercePurchases'], ['name' => 'purchaseRevenue']],
            'dimensionFilter' => ['filter' => ['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'EXACT', 'value' => $source, 'caseSensitive' => false]]],
            'limit' => '1000',
        ]);

        $live = ['sessions' => 0, 'purchases' => 0, 'revenue' => 0.0];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $live['sessions'] += (int) $row['metricValues'][0]['value'];
            $live['purchases'] += (int) $row['metricValues'][1]['value'];
            $live['revenue'] += (float) $row['metricValues'][2]['value'];
            $this->add(sprintf('  GA4: %s / %s / %s: %s sessions', $row['dimensionValues'][0]['value'], $row['dimensionValues'][1]['value'], $row['dimensionValues'][2]['value'], $row['metricValues'][0]['value']));
        }

        $stored = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->where('date', $day)->where('source', $source)
            ->selectRaw('coalesce(sum(sessions),0) s, coalesce(sum(purchases),0) p, coalesce(sum(revenue),0) r')->first();
        $this->add(sprintf('GA4 now: %d sessions, %d purchases, %.2f revenue | Stored: %d sessions, %d purchases, %.2f revenue | %s', $live['sessions'], $live['purchases'], $live['revenue'], $stored->s, $stored->p, $stored->r, $live['sessions'] === (int) $stored->s ? 'MATCH' : 'DIFFERENT'));
    }

    private function period(int $hotelId, string $property, Carbon $from, Carbon $to): void
    {
        $a = $from->toDateString();
        $b = $to->toDateString();
        $this->add('');
        $this->add("== 5. Period {$a} to {$b}: GA4 now against stored");
        $range = [['startDate' => $a, 'endDate' => $b]];

        $bySource = $this->api->runReport($property, ['dateRanges' => $range, 'dimensions' => [['name' => 'sessionSource']], 'metrics' => [['name' => 'sessions'], ['name' => 'ecommercePurchases'], ['name' => 'transactions']], 'limit' => '10000']);
        $ai = ['sessions' => 0, 'ecommercePurchases' => 0, 'transactions' => 0];
        $other = 0;
        foreach ((array) ($bySource['rows'] ?? []) as $row) {
            $name = (string) $row['dimensionValues'][0]['value'];
            $values = array_map(fn ($v) => (int) $v['value'], (array) $row['metricValues']);
            if ($name === '(other)') {
                $other += $values[0];
            }
            if (AiSources::platform($name) !== null) {
                $ai['sessions'] += $values[0];
                $ai['ecommercePurchases'] += $values[1];
                $ai['transactions'] += $values[2];
                $this->add("  GA4 AI source {$name}: {$values[0]} sessions, {$values[1]} ecommercePurchases, {$values[2]} transactions");
            }
        }
        $this->add('  "(other)" check: '.$other.' sessions grouped as "(other)"; GA4 reports data loss from "(other)": '.(! empty($bySource['metadata']['dataLossFromOtherRow']) ? 'YES' : 'no'));

        $metrics = ['sessions', 'ecommercePurchases', 'transactions', 'keyEvents', 'purchaseRevenue', 'totalRevenue'];
        $totals = $this->api->runReport($property, ['dateRanges' => $range, 'metrics' => array_map(fn ($m) => ['name' => $m], $metrics)]);
        $all = array_column((array) ($totals['rows'][0]['metricValues'] ?? []), 'value');
        $all += array_fill(0, 6, '?');

        $events = $this->api->runReport($property, ['dateRanges' => $range, 'dimensions' => [['name' => 'sessionSource']], 'metrics' => [['name' => 'eventCount']], 'limit' => '10000',
            'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'purchase']]]]);
        $eventAll = 0;
        $eventAi = 0;
        foreach ((array) ($events['rows'] ?? []) as $row) {
            $count = (int) $row['metricValues'][0]['value'];
            $eventAll += $count;
            $eventAi += AiSources::platform((string) $row['dimensionValues'][0]['value']) !== null ? $count : 0;
        }

        $stored = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$a, $b])->selectRaw('coalesce(sum(sessions),0) s, coalesce(sum(purchases),0) p, coalesce(sum(revenue),0) r')->first();
        $aiSources = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$a, $b])->distinct()->pluck('source')
            ->filter(fn ($name) => AiSources::platform((string) $name) !== null)->all();
        $storedAi = $aiSources === [] ? (object) ['s' => 0, 'p' => 0] : DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$a, $b])->whereIn('source', $aiSources)->selectRaw('sum(sessions) s, sum(purchases) p')->first();
        $storedEvent = (int) DB::table('ga4_events_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$a, $b])->where('event_name', 'purchase')->sum('event_count');

        $this->add('');
        $this->add('Purchases side by side (which number is a "purchase"?):');
        $this->add(sprintf('  %-22s %10s %20s %14s %18s %18s %14s', '', 'sessions', 'ecommercePurchases', 'transactions', 'purchase events', 'purchaseRevenue', 'totalRevenue'));
        $this->add(sprintf('  %-22s %10s %20s %14s %18s %18s %14s', 'GA4 now, all traffic', $all[0], $all[1], $all[2], $eventAll, $all[4], $all[5]));
        $this->add(sprintf('  %-22s %10d %20d %14d %18d %18s %14s', 'GA4 now, AI sources', $ai['sessions'], $ai['ecommercePurchases'], $ai['transactions'], $eventAi, '-', '-'));
        $this->add(sprintf('  %-22s %10d %20d %14s %18d %18.2f %14s', 'Stored, all traffic', $stored->s, $stored->p, '-', $storedEvent, $stored->r, '-'));
        $this->add(sprintf('  %-22s %10d %20d %14s %18s %18s %14s', 'Stored, AI sources', $storedAi->s, $storedAi->p, '-', '-', '-', '-'));
        $this->add('  (GA4 keyEvents, all traffic: '.$all[3].')');
    }
}
