<?php

namespace App\Livewire\Hotels;

use App\Models\Hotel;
use App\Models\ScanRun;
use App\Services\Dashboard\DashboardService;
use App\Services\Hotels\CurrentHotel;
use App\Services\Scanning\ManualScanNotAllowed;
use App\Services\Scanning\ScanMaintenance;
use App\Services\Scanning\ScanService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The hotel dashboard: /hotels/{hotel} (latest completed scan) and
 * /hotels/{hotel}/scans/{scanRun} (a past scan). All numbers come from
 * DashboardService; this component only handles input and actions.
 */
class HotelDashboard extends Component
{
    #[Locked]
    public int $hotelId;

    #[Locked]
    public ?int $scanRunId = null;

    /** Engine filter; empty = all engines. */
    #[Url(as: 'engine', except: '')]
    public string $engine = '';

    /** Prompt language filter; empty = all languages. */
    #[Url(as: 'language', except: '')]
    public string $language = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(Hotel $hotel, ?ScanRun $scanRun = null): void
    {
        Gate::authorize('view', $hotel);
        abort_if($scanRun?->exists && $scanRun->hotel_id !== $hotel->id, 404);

        if (! $hotel->isSetUp()) {
            $this->redirectRoute('hotels.setup', $hotel);

            return;
        }

        app(CurrentHotel::class)->set($hotel);
        $this->hotelId = $hotel->id;
        $this->scanRunId = $scanRun?->exists ? $scanRun->id : null;
    }

    public function setEngine(string $engine): void
    {
        $this->engine = $engine;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function scanNow(ScanService $scans): void
    {
        $hotel = $this->hotel();
        Gate::authorize('scan', $hotel);
        $this->reset('notice', 'error');

        try {
            $scans->startManual($hotel, auth()->user());
            $this->notice = 'Scan started. The deep scan runs in the background and usually takes a few minutes. You can keep using '.config('app.name').' or leave this page; results appear here automatically when it finishes.';
            $this->dispatch('scan-started');
        } catch (ManualScanNotAllowed $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Admins: re-run the calls of a failed scan that have no answer.
     */
    public function retryFailed(int $scanRunId, ScanMaintenance $maintenance): void
    {
        abort_unless(auth()->user()->is_admin, 403);
        $scanRun = $this->hotel()->scanRuns()->findOrFail($scanRunId);

        try {
            $queued = $maintenance->retryMissing($scanRun);
            $this->notice = "{$queued} call(s) queued again.";
            $this->dispatch('scan-started');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * The progress banner saw the scan finish.
     */
    #[On('scan-finished')]
    public function scanFinished(): void
    {
        $this->notice = null;
    }

    public function render(DashboardService $dashboards): View
    {
        $hotel = $this->hotel();
        $viewing = $this->scanRunId ? $hotel->scanRuns()->findOrFail($this->scanRunId) : null;

        return view('livewire.hotels.hotel-dashboard', [
            'd' => $dashboards->build($hotel, auth()->user(), $viewing, $this->engine ?: null, $this->language ?: null),
        ])->layout('layouts::app', ['bare' => true, 'documentTitle' => $hotel->name]);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('view', $hotel);

        return $hotel;
    }
}
