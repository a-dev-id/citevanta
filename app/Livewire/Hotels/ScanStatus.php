<?php

namespace App\Livewire\Hotels;

use App\Models\Hotel;
use App\Services\Scanning\ScanProgress;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Scan running" banner on the hotel dashboard. Polls every 10 seconds while
 * a scan is queued or running; when it finishes, it tells the dashboard to
 * refresh (the rest of the dashboard keeps showing the last completed scan
 * until then).
 */
class ScanStatus extends Component
{
    #[Locked]
    public int $hotelId;

    public bool $wasActive = false;

    public function mount(int $hotelId): void
    {
        $this->hotelId = $hotelId;
        $this->hotel();
    }

    /**
     * The dashboard started a scan: show the banner straight away.
     */
    #[On('scan-started')]
    public function refreshBanner(): void {}

    public function render(): View
    {
        $scan = $this->hotel()->scanRuns()->latest('id')->first();
        $active = $scan?->status->isInProgress() ?? false;

        if ($this->wasActive && ! $active) {
            $this->dispatch('scan-finished');
        }

        $this->wasActive = $active;

        return view('livewire.hotels.scan-status', [
            'scan' => $active ? $scan : null,
            'progress' => $active ? ScanProgress::for($scan) : null,
        ]);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('view', $hotel);

        return $hotel;
    }
}
