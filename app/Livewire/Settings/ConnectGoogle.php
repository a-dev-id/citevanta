<?php

namespace App\Livewire\Settings;

use App\Jobs\GoogleHealthJob;
use App\Models\Hotel;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHealth;
use App\Services\Google\GoogleLinks;
use App\Services\Hotels\CurrentHotel;
use App\Services\Support\Url;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings > Connect Google (owners, private plan): share GA4 and Search
 * Console with the agent email, pick the property and site, see the
 * tracking health check and confirm the booking date mapping.
 * Logic in GoogleLinks and GoogleHealth.
 */
class ConnectGoogle extends Component
{
    #[Locked]
    public ?int $hotelId = null;

    /** @var list<array{property: string, displayName: string, urls: list<string>}> */
    public array $properties = [];

    /** @var list<array{siteUrl: string, permissionLevel: string}> */
    public array $sites = [];

    /** @var list<string> */
    public array $problems = [];

    public bool $checked = false;

    public string $property = '';

    public string $site = '';

    public string $bookingDomain = '';

    /** @var array<string, string> */
    public array $mapping = ['checkin' => '', 'checkout' => '', 'adults' => '', 'children' => '', 'nights' => ''];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(CurrentHotel $current): void
    {
        $hotel = $current->get(auth()->user());
        $this->authorizeOwner($hotel);
        $this->hotelId = $hotel?->id;

        if ($hotel !== null) {
            $link = $hotel->googleLink;
            $this->property = (string) ($link?->ga4_property ?? '');
            $this->site = (string) ($link?->gsc_site ?? '');
            $this->bookingDomain = (string) ($hotel->booking_engine_domain ?? '');
            $this->mapping = array_map(fn ($v) => (string) $v, array_intersect_key(($link?->date_mapping ?? []) + $this->mapping, $this->mapping));
        }
    }

    public function check(GoogleLinks $links): void
    {
        $this->reset('notice', 'error');

        try {
            $result = $links->candidates($this->hotel());
        } catch (GoogleApiException $e) {
            $this->error = $e->reason === 'not_connected'
                ? 'The Google connection is not available right now. Please try again later; our team has been notified.'
                : $e->getMessage();

            return;
        }

        $this->properties = $result['properties'];
        $this->sites = $result['sites'];
        $this->problems = $result['errors'];
        $this->checked = true;
        $this->property = $this->property ?: (string) ($this->properties[0]['property'] ?? '');
        $this->site = $this->site ?: (string) ($this->sites[0]['siteUrl'] ?? '');
    }

    public function link(GoogleLinks $links): void
    {
        $this->reset('notice', 'error');

        try {
            $links->link($this->hotel(), $this->property ?: null, $this->site ?: null);
        } catch (GoogleApiException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = 'Connected. The tracking check runs now and the first data arrives within a few hours.';
    }

    public function saveBookingDomain(): void
    {
        $this->validate(['bookingDomain' => ['nullable', 'string', 'max:190', 'regex:/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i']], ['bookingDomain.regex' => 'Enter a domain like booking.example.com.']);
        $hotel = $this->hotel();
        $hotel->update(['booking_engine_domain' => Url::host($this->bookingDomain) ?: null]);
        $this->bookingDomain = (string) $hotel->booking_engine_domain;

        if ($hotel->googleLink !== null) {
            GoogleHealthJob::dispatch($hotel->googleLink->id, 'cross_domain');
        }

        $this->notice = 'Booking engine saved.';
    }

    public function saveMapping(): void
    {
        $link = $this->hotel()->googleLink;
        abort_if($link === null, 404);

        $mapping = array_map(fn ($v) => $v !== '' ? $v : null, $this->mapping);
        $old = (array) ($link->date_mapping ?? []);
        // A changed mapping means different data: the next sync reads the booking dates again from the start.
        $changed = ($old['checkin'] ?? null) !== $mapping['checkin'] || ($old['checkout'] ?? null) !== $mapping['checkout'] || ($old['nights'] ?? null) !== $mapping['nights'];
        $link->update(['date_mapping' => $mapping + ['confirmed' => true]] + ($changed ? ['booking_dates_synced_at' => null] : []));
        GoogleHealthJob::dispatch($link->id, 'booking_dates');
        $this->notice = 'Booking date mapping saved.';
    }

    public function runHealth(): void
    {
        $link = $this->hotel()->googleLink;
        abort_if($link === null, 404);

        GoogleHealthJob::dispatchAll($link);
        $this->notice = 'The tracking check is running. Results update here in a minute.';
    }

    public function disconnect(GoogleLinks $links): void
    {
        $links->unlink($this->hotel());
        $this->reset('properties', 'sites', 'problems', 'checked', 'property', 'site');
        $this->notice = 'Disconnected. Please also remove '.config('services.google.agent_email').' in GA4 (Admin > Property access management) and in Search Console (Settings > Users and permissions).';
    }

    public function render(): View
    {
        $hotel = $this->hotelId ? Hotel::find($this->hotelId) : null;
        $link = $hotel?->googleLink;

        return view('livewire.settings.connect-google', [
            'hotel' => $hotel,
            'link' => $link,
            'agentEmail' => (string) config('services.google.agent_email'),
            'checks' => GoogleHealth::LABELS,
            'health' => (array) ($link?->health ?? []),
            'parameters' => (array) (($link?->health['booking_dates']['details']['parameters'] ?? [])),
        ])->layout('layouts::app', ['title' => 'Connect Google', 'breadcrumbs' => ['Settings' => null, 'Connect Google' => null]]);
    }

    private function hotel(): Hotel
    {
        $hotel = $this->hotelId ? Hotel::find($this->hotelId) : null;
        $this->authorizeOwner($hotel);
        abort_if($hotel === null, 404);

        return $hotel;
    }

    private function authorizeOwner(?Hotel $hotel): void
    {
        $team = auth()->user()->currentTeam;
        abort_unless(auth()->user()->ownsTeam($team) && $team->isPrivate(), 403);
        abort_if($hotel !== null && $hotel->team_id !== $team->id, 403);
    }
}
