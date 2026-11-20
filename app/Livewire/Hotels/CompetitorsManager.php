<?php

namespace App\Livewire\Hotels;

use App\Livewire\Concerns\MapsValidationErrors;
use App\Models\Hotel;
use App\Services\Dashboard\DashboardService;
use App\Services\Hotels\CompetitorService;
use App\Services\Hotels\HotelSetupService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Step c: competitors, up to the plan limit. Rows save when a field loses focus.
 */
class CompetitorsManager extends Component
{
    use MapsValidationErrors;

    #[Locked]
    public int $hotelId;

    #[Locked]
    public bool $wizard = false;

    public string $newName = '';

    public string $newWebsite = '';

    /** @var array<int, array{name: string, website: string}> competitor id => fields */
    public array $rows = [];

    public function mount(int $hotelId, bool $wizard = false): void
    {
        $this->hotelId = $hotelId;
        $this->wizard = $wizard;
        $this->loadRows();
    }

    public function add(CompetitorService $competitors): void
    {
        $this->resetErrorBag(['newName', 'newWebsite']);

        $added = $this->withMappedErrors(
            fn () => $competitors->add($this->hotel(), $this->newName, $this->newWebsite),
            ['name' => 'newName', 'website' => 'newWebsite'],
        );

        if ($added !== null) {
            $this->reset('newName', 'newWebsite');
            $this->loadRows();
        }
    }

    /**
     * Save a row when one of its fields changes ("rows.12.name").
     */
    public function updatedRows(mixed $value, string $key): void
    {
        [$id] = explode('.', $key);
        $competitor = $this->hotel()->competitors()->findOrFail((int) $id);
        $this->resetErrorBag(["rows.{$id}.name", "rows.{$id}.website"]);

        $updated = $this->withMappedErrors(
            fn () => app(CompetitorService::class)->update($competitor, $this->rows[$id]['name'] ?? '', $this->rows[$id]['website'] ?? ''),
            "rows.{$id}.",
        );

        if ($updated !== null) {
            $this->rows[$id] = ['name' => $updated->name, 'website' => (string) $updated->website];
        }
    }

    public function remove(int $id, CompetitorService $competitors): void
    {
        $competitors->delete($this->hotel()->competitors()->findOrFail($id));
        $this->loadRows();
    }

    public function continue(HotelSetupService $setup): void
    {
        $setup->completeStep($this->hotel(), HotelSetupService::STEP_COMPETITORS);
        $this->dispatch('wizard-step-saved', hotelId: $this->hotelId, step: HotelSetupService::STEP_COMPETITORS);
    }

    public function back(): void
    {
        $this->dispatch('wizard-back');
    }

    public function render(CompetitorService $competitors): View
    {
        $hotel = $this->hotel();

        return view('livewire.hotels.competitors-manager', [
            'used' => count($this->rows),
            'limit' => $competitors->limit($hotel),
            // Outside the wizard: numbers from the latest completed scan.
            'stats' => $this->wizard ? null : app(DashboardService::class)->competitorStats($hotel),
        ]);
    }

    private function loadRows(): void
    {
        $this->rows = $this->hotel()->competitors()->orderBy('id')->get()
            ->mapWithKeys(fn ($competitor) => [$competitor->id => [
                'name' => $competitor->name,
                'website' => (string) $competitor->website,
            ]])->all();
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('update', $hotel);

        return $hotel;
    }
}
