<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Services\Google\GoogleSelfCheck;
use App\Services\Hotels\CurrentHotel;
use App\Services\Reports\AiTrafficReport;
use App\Services\Reports\BookingDemandReport;
use App\Services\Reports\ReportAvailability;
use App\Services\Reports\ReportFindings;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SearchReport;
use App\Services\Reports\TableOptions;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for Private teams: AI traffic and bookings (GA4) and Google Search
 * (Search Console). Everything comes from our own synced tables; there are
 * no live Google calls.
 */
class ReportController extends Controller
{
    public function __construct(private readonly CurrentHotel $currentHotel, private readonly ReportFindings $findings) {}

    public function show(Request $request, Hotel $hotel): View
    {
        $this->authorizePrivate($hotel);
        $this->currentHotel->set($hotel);

        $tab = $request->query('report') === 'search' ? 'search' : 'ai';
        $period = $this->period($request, $hotel, $tab);
        $options = TableOptions::fromRequest($request, $this->pageSize($request));
        $link = $hotel->googleLink;
        $data = ['hotel' => $hotel, 'period' => $period, 'tab' => $tab, 'currency' => (string) $link?->ga4_currency, 'perPage' => $options->perPage];

        if ($tab === 'search') {
            $data += ['empty' => ReportAvailability::search($hotel, $period)];

            if ($data['empty'] === null) {
                $search = new SearchReport($hotel, $period);
                $data += [
                    'totals' => $search->totals(), 'previousTotals' => $search->previousTotals(), 'tables' => $search->tables($options),
                    'findings' => $this->findings->forSearch($hotel, $search), 'aiOverviewsNote' => SearchReport::AI_OVERVIEWS_NOTE,
                    'warnings' => ReportAvailability::warnings($hotel, ['search_console']),
                ];
            }

            return view('hotels.reports', $data);
        }

        $data += ['empty' => ReportAvailability::ga4($hotel, $period)];

        if ($data['empty'] === null) {
            $traffic = new AiTrafficReport($hotel, $period);
            $demand = new BookingDemandReport($hotel, $period);
            $data += [
                'summary' => $traffic->summary(), 'scriptSeen' => $traffic->scriptSeen(), 'charts' => $traffic->charts(), 'completeThrough' => $traffic->isIncomplete() ? $traffic->completeThrough() : null, 'dataWarning' => GoogleSelfCheck::warningFor($hotel->googleLink, (bool) $request->user()?->is_admin), 'tables' => $traffic->tables($options),
                'findings' => $this->findings->forAiTraffic($hotel, $traffic),
                'warnings' => ReportAvailability::warnings($hotel, ['receiving', 'purchases', 'cross_domain', 'ai_referrals']),
                'bookingSetup' => ReportAvailability::bookingDates($hotel),
            ];
            $data['demandTables'] = $data['bookingSetup'] === null ? $demand->tables($options) : [];
            $data['demandCalendar'] = $data['bookingSetup'] === null ? $demand->calendar() : null;
            $data['demandTop'] = $data['bookingSetup'] === null ? $demand->topDates() : [];
            $data['demandStays'] = $data['bookingSetup'] === null ? $demand->stayHistogram() : null;
            $data['demandDates'] = $data['bookingSetup'] === null ? $demand->byCheckin() : [];
            $data['demandDateCount'] = count($data['demandDates']);
            $data['countryBars'] = array_slice($traffic->byCountry(), 0, 5);
        }

        return view('hotels.reports', $data);
    }

    public function export(Request $request, Hotel $hotel, string $table): StreamedResponse
    {
        $this->authorizePrivate($hotel);

        $tab = str_starts_with($table, 'search') ? 'search' : 'ai';
        $period = $this->period($request, $hotel, $tab);
        // Every row of the range (not one page), in the order asked for, with the comparison columns when a comparison is on.
        $all = TableOptions::all((array) $request->query('sort', []));
        $tables = match (strstr($table, '-', true)) {
            'ai' => (new AiTrafficReport($hotel, $period))->tables($all),
            'demand' => (new BookingDemandReport($hotel, $period))->tables($all),
            'search' => (new SearchReport($hotel, $period))->tables($all),
            default => [],
        };
        abort_unless(isset($tables[$table]), 404);

        $report = $tables[$table];
        $name = $hotel->slug.'-'.$table.'-'.$period->from->format('Ymd').'-'.$period->to->format('Ymd')
            .($period->hasComparison() ? '-vs-'.$period->compareFrom->format('Ymd').'-'.$period->compareTo->format('Ymd') : '').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $report->csvHeader());

            foreach ($report->csvRows() as $row) {
                // A cell that starts with = + - @ would run as a formula in Excel: keep it text.
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@]/', $cell) ? "'".$cell : $cell, $row));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The range and comparison from the URL, kept inside the synced days (first synced day to last fully synced day).
     */
    private function period(Request $request, Hotel $hotel, string $tab): ReportPeriod
    {
        [$earliest, $latest] = $tab === 'search' ? ReportAvailability::searchBounds($hotel) : ReportAvailability::ga4Bounds($hotel);

        return ReportPeriod::resolve($request->query(), $earliest, $latest);
    }

    /**
     * Rows per page: the one in the URL (and remembered for the user), else the user's last choice, else 10.
     */
    private function pageSize(Request $request): int
    {
        $user = $request->user();
        $asked = TableOptions::validSize($request->query('size'));

        if ($asked !== null) {
            if ($user !== null && (int) $user->report_page_size !== $asked) {
                $user->forceFill(['report_page_size' => $asked])->save();
            }

            return $asked;
        }

        return TableOptions::validSize($user?->report_page_size) ?? TableOptions::DEFAULT_SIZE;
    }

    private function authorizePrivate(Hotel $hotel): void
    {
        abort_unless($hotel->team->isPrivate(), 403, 'Reports are part of the Private plan.');
    }
}
