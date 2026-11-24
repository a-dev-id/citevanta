<?php

namespace App\Http\Controllers;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Services\Dashboard\CitationReport;
use App\Services\Dashboard\DashboardService;
use App\Services\Hotels\CurrentHotel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The hotel pages around the dashboard (the dashboard itself is the
 * HotelDashboard Livewire component).
 */
class HotelController extends Controller
{
    /**
     * Pages the hotel switcher keeps you on: route name => section.
     */
    public const SECTIONS = [
        'show' => 'hotels.show',
        'edit' => 'hotels.edit',
        'prompts' => 'hotels.prompts',
        'competitors' => 'hotels.competitors',
        'scans' => 'hotels.scans',
        'citations' => 'hotels.citations',
        'accuracy' => 'hotels.accuracy',
        'recommendations' => 'hotels.recommendations',
        'reports' => 'hotels.reports',
        'rates' => 'hotels.rates',
    ];

    public function __construct(private CurrentHotel $currentHotel) {}

    public function index(Request $request, DashboardService $dashboards): View
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('viewAny', [Hotel::class, $team]);

        $hotels = $team->hotels()->withCount(['prompts' => fn ($q) => $q->where('is_active', true), 'competitors'])->orderBy('name')->get();

        return view('hotels.index', [
            'hotels' => $hotels,
            'summaries' => $hotels->mapWithKeys(fn (Hotel $hotel) => [$hotel->id => $dashboards->hotelSummary($hotel)]),
            'canAdd' => $request->user()->can('create', [Hotel::class, $team]),
            'limit' => $team->limits()->hotels,
            'timezone' => $team->timezoneName(),
        ]);
    }

    public function edit(Hotel $hotel): View
    {
        $this->currentHotel->set($hotel);

        return view('hotels.edit', ['hotel' => $hotel]);
    }

    public function prompts(Hotel $hotel): View
    {
        $this->currentHotel->set($hotel);

        return view('hotels.prompts', ['hotel' => $hotel]);
    }

    public function competitors(Hotel $hotel): View
    {
        $this->currentHotel->set($hotel);

        return view('hotels.competitors', ['hotel' => $hotel]);
    }

    public function scans(Request $request, Hotel $hotel): View
    {
        $this->currentHotel->set($hotel);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ScanStatus::class)],
            'trigger' => ['nullable', Rule::enum(ScanTrigger::class)],
        ]);

        return view('hotels.scans', [
            'hotel' => $hotel,
            'filters' => $filters,
            'scans' => $hotel->scanRuns()
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['trigger'] ?? null, fn ($q, $trigger) => $q->where('trigger', $trigger))
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function citations(Hotel $hotel, CitationReport $report): View
    {
        $this->currentHotel->set($hotel);
        $scans = $report->recentScans($hotel);

        return view('hotels.citations', [
            'hotel' => $hotel,
            'scans' => $scans,
            'domains' => $report->domains($hotel, $scans),
        ]);
    }

    public function citationDomain(Hotel $hotel, string $domain, CitationReport $report): View
    {
        $this->currentHotel->set($hotel);
        $scans = $report->recentScans($hotel);
        $urls = $report->urls($domain, $scans);

        abort_if($urls === [], 404);

        return view('hotels.citation-domain', [
            'hotel' => $hotel,
            'domain' => $domain,
            'scans' => $scans,
            'urls' => $urls,
        ]);
    }

    /**
     * Hotel switcher: make this the current hotel and stay on the same kind of page.
     */
    public function switch(Request $request, Hotel $hotel): RedirectResponse
    {
        $this->currentHotel->set($hotel);

        if (! $hotel->isSetUp()) {
            return to_route('hotels.setup', $hotel);
        }

        $route = self::SECTIONS[$request->query('to', 'show')] ?? 'hotels.show';

        return to_route($route, $hotel);
    }

    /**
     * Menu shortcuts open the current hotel's page.
     */
    public function current(Request $request, string $section): RedirectResponse
    {
        $hotel = $this->currentHotel->get($request->user());

        if ($hotel === null) {
            return to_route('hotels.create');
        }

        return to_route($hotel->isSetUp() ? (self::SECTIONS[$section] ?? 'hotels.show') : 'hotels.setup', $hotel);
    }
}
