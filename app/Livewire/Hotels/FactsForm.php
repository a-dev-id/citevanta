<?php

namespace App\Livewire\Hotels;

use App\Models\Hotel;
use App\Services\Hotels\HotelSetupService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Step b: hotel facts (the source of truth for later accuracy checks).
 */
class FactsForm extends Component
{
    #[Locked]
    public int $hotelId;

    #[Locked]
    public bool $wizard = false;

    /** @var list<array{key: string, value: string}> */
    public array $facts = [];

    public bool $saved = false;

    public function mount(int $hotelId, bool $wizard = false): void
    {
        $this->hotelId = $hotelId;
        $this->wizard = $wizard;
        $this->facts = app(HotelSetupService::class)->factRowsFor($this->hotel());
    }

    public function addRow(): void
    {
        $this->facts[] = ['key' => '', 'value' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->facts[$index]);
        $this->facts = array_values($this->facts);
        $this->resetErrorBag();
    }

    public function save(HotelSetupService $setup): void
    {
        $this->saved = false;
        $setup->saveFacts($this->hotel(), $this->facts);

        if ($this->wizard) {
            $this->dispatch('wizard-step-saved', hotelId: $this->hotelId, step: HotelSetupService::STEP_FACTS);

            return;
        }

        $this->facts = $setup->factRowsFor($this->hotel());
        $this->saved = true;
    }

    public function back(): void
    {
        $this->dispatch('wizard-back');
    }

    public function render(): View
    {
        return view('livewire.hotels.facts-form');
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('update', $hotel);

        return $hotel;
    }
}
