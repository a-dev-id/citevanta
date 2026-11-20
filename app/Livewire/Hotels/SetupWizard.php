<?php

namespace App\Livewire\Hotels;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Services\Hotels\CurrentHotel;
use App\Services\Hotels\HotelSetupService;
use App\Services\Trials\TrialGate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The 4-step setup wizard. Each step is its own component (reused on the edit
 * pages); this component only tracks which step is shown.
 */
class SetupWizard extends Component
{
    public const STEPS = [
        1 => 'Hotel details',
        2 => 'Hotel facts',
        3 => 'Competitors',
        4 => 'Prompts',
    ];

    #[Locked]
    public ?int $hotelId = null;

    public int $step = 1;

    public function mount(?Hotel $hotel = null): void
    {
        if ($hotel?->exists) {
            Gate::authorize('update', $hotel);
            app(CurrentHotel::class)->set($hotel);

            $this->hotelId = $hotel->id;
            $this->step = $hotel->isSetUp() ? HotelSetupService::STEP_PROMPTS : max(1, $hotel->setup_step);

            return;
        }

        Gate::authorize('viewAny', [Hotel::class, auth()->user()->currentTeam]);
    }

    #[On('wizard-step-saved')]
    public function stepSaved(int $hotelId, int $step): void
    {
        if ($this->hotelId === null) {
            // A new hotel was just created: continue at its own setup URL so it can be resumed.
            $this->redirectRoute('hotels.setup', ['hotel' => $hotelId]);

            return;
        }

        $this->step = min(HotelSetupService::STEP_PROMPTS, $step + 1);
    }

    #[On('wizard-back')]
    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    /**
     * Jump to a step that has already been reached.
     */
    public function goTo(int $step): void
    {
        if ($this->hotelId !== null && $step >= 1 && $step <= $this->reachedStep()) {
            $this->step = $step;
        }
    }

    #[On('wizard-finish')]
    public function finish(HotelSetupService $setup): void
    {
        $hotel = $this->hotel();

        try {
            $scan = $setup->finish($hotel, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('finish', collect($e->errors())->flatten()->first());

            return;
        }

        session()->flash('success', match (true) {
            $scan->trigger === ScanTrigger::AuditImport => 'Setup complete. Your first results come from your free audit; the next scan uses your own prompts.',
            $scan->status === ScanStatus::Waiting => 'Setup complete. '.TrialGate::message($scan->hold_reason),
            $scan->status === ScanStatus::PausedBudget => 'Setup complete. Your first scan is waiting for capacity and starts as soon as possible.',
            default => 'Setup complete. Your first scan has started.',
        });

        $this->redirectRoute('hotels.show', ['hotel' => $hotel->id]);
    }

    public function render(): View
    {
        $hotel = $this->hotelId ? Hotel::find($this->hotelId) : null;

        return view('livewire.hotels.setup-wizard', [
            'steps' => self::STEPS,
            'reached' => $this->reachedStep(),
            'hotel' => $hotel,
        ])->layout('layouts::app', [
            'title' => $hotel ? 'Set up '.$hotel->name : 'Add a hotel',
            'breadcrumbs' => ['Hotels' => route('hotels.index'), $hotel ? 'Finish setup' : 'Add hotel' => null],
        ]);
    }

    private function reachedStep(): int
    {
        if ($this->hotelId === null) {
            return 1;
        }

        $hotel = Hotel::find($this->hotelId);

        return $hotel?->isSetUp() ? HotelSetupService::STEP_PROMPTS : max(1, (int) $hotel?->setup_step);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('update', $hotel);

        return $hotel;
    }
}
