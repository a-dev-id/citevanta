<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Services\Hotels\CurrentHotel;
use App\Services\Reports\AiTrafficReport;
use App\Services\Reports\BookingDemandReport;
use App\Services\Reports\ReportAvailability;
use App\Services\Reports\ReportFindings;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SearchReport;
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

        $period = ReportPeriod::make($request->query('range'));
        $tab = $request->query('report') === 'search' ? 'search' : 'ai';
        $link = $hotel->googleLink;
        $data = ['hotel' => $hotel, 'period' => $period, 'tab' => $tab, 'currency' => (string) ($link?->ga4_currency ?: $hotel->currency)];

        if ($tab === 'search') {
            $data += ['empty' => ReportAvailability::search($hotel, $period)];

            if ($data['empty'] === null) {
                $search = new SearchReport($hotel, $period);
                $data += [
                    'totals' => $search->totals(), 'tables' => $search->tables(),
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
                'summary' => $traffic->summary(), 'charts' => $traffic->charts(), 'tables' => $traffic->tables(),
                'findings' => $this->findings->forAiTraffic($hotel, $traffic, $demand),
                'warnings' => ReportAvailability::warnings($hotel, ['receiving', 'purchases', 'cross_domain', 'ai_referrals']),
                'bookingSetup' => ReportAvailability::bookingDates($hotel),
            ];
            $data['demandTables'] = $data['bookingSetup'] === null ? $demand->tables() : [];
            $data['demandChart'] = $data['bookingSetup'] === null ? $demand->chart() : null;
            $data['demandTop'] = $data['bookingSetup'] === null ? $demand->topDates() : [];
        }

        return view('hotels.reports', $data);
    }

    public function export(Request $request, Hotel $hotel, string $table): StreamedResponse
    {
        $this->authorizePrivate($hotel);

        $period = ReportPeriod::make($request->query('range'));
        $tables = match (strstr($table, '-', true)) {
            'ai' => (new AiTrafficReport($hotel, $period))->tables(),
            'demand' => (new BookingDemandReport($hotel, $period))->tables(),
            'search' => (new SearchReport($hotel, $period))->tables(),
            default => [],
        };
        abort_unless(isset($tables[$table]), 404);

        $report = $tables[$table];
        $name = $hotel->slug.'-'.$table.'-'.$period->from->format('Ymd').'-'.$period->to->format('Ymd').'.csv';

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

    private function authorizePrivate(Hotel $hotel): void
    {
        abort_unless($hotel->team->isPrivate(), 403, 'Reports are part of the Private plan.');
    }
}
