<?php

namespace App\Livewire\Hotels;

use App\Models\Hotel;
use App\Services\Audits\AuditPrefill;
use App\Services\Hotels\HotelSetupService;
use App\Services\Hotels\PlanLimitReached;
use App\Services\Plans\PlanGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Step a: hotel details. Creates the hotel (checking the plan's hotel
 * limit) or updates it. Used by the wizard and the Hotels > Edit page.
 */
class DetailsForm extends Component
{
    #[Locked]
    public ?int $hotelId = null;

    #[Locked]
    public bool $wizard = false;

    public string $name = '';

    public string $city = '';

    public string $country = '';

    public string $website = '';

    public string $hotel_type = 'resort';

    public string $star_rating = '';

    /** @var list<string> */
    public array $guest_markets = [];

    /** Other names AI may use, one per line. */
    public string $alternative_names = '';

    public ?string $limitMessage = null;

    public bool $saved = false;

    public function mount(?int $hotelId = null, bool $wizard = false): void
    {
        $this->wizard = $wizard;

        if ($hotelId !== null) {
            $hotel = $this->authorizedHotel($hotelId);
            $this->hotelId = $hotel->id;
            $this->fill([
                'name' => $hotel->name,
                'city' => $hotel->city,
                'country' => $hotel->country,
                'website' => (string) $hotel->website,
                'hotel_type' => $hotel->hotel_type ?? 'resort',
                'star_rating' => (string) ($hotel->star_rating ?? ''),
                'guest_markets' => (array) ($hotel->guest_markets ?? []),
                'alternative_names' => implode("\n", (array) ($hotel->alternative_names ?? [])),
            ]);

            return;
        }

        $team = auth()->user()->currentTeam;
        Gate::authorize('viewAny', [Hotel::class, $team]);

        if (! app(PlanGuard::class)->canAddHotel($team)) {
            $this->limitMessage = $this->limitText($team->limits()->hotels, $team->plan->label());
        }

        // Signed up from a free audit report: start with that hotel's details.
        if (($prefill = AuditPrefill::hotelDetails()) !== null) {
            $this->fill($prefill);
        }
    }

    public function save(HotelSetupService $setup): void
    {
        $this->saved = false;
        $input = $this->only(['name', 'city', 'country', 'website', 'hotel_type', 'star_rating', 'guest_markets', 'alternative_names']);

        if ($this->hotelId === null) {
            $team = auth()->user()->currentTeam;
            Gate::authorize('viewAny', [Hotel::class, $team]);

            try {
                $hotel = $setup->createHotel($team, $input);
            } catch (PlanLimitReached $e) {
                $this->limitMessage = $e->getMessage();

                return;
            }
        } else {
            $hotel = $setup->updateDetails($this->authorizedHotel($this->hotelId), $input);
        }

        if ($this->wizard) {
            $this->dispatch('wizard-step-saved', hotelId: $hotel->id, step: HotelSetupService::STEP_DETAILS);

            return;
        }

        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.hotels.details-form', [
            'countries' => array_values(config('countries')),
            'hotelTypes' => config('citevanta.hotel_types'),
            'maxMarkets' => (int) config('citevanta.max_guest_markets', 5),
        ]);
    }

    private function authorizedHotel(int $id): Hotel
    {
        $hotel = Hotel::findOrFail($id);
        Gate::authorize('update', $hotel);

        return $hotel;
    }

    private function limitText(int $limit, string $plan): string
    {
        return "Your {$plan} plan includes {$limit} ".Str::plural('hotel', $limit).'. You have reached this limit, so a new hotel cannot be added.';
    }
}
