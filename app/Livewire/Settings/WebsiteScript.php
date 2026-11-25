<?php

namespace App\Livewire\Settings;

use App\Models\Hotel;
use App\Models\HotelTracking;
use App\Models\TrackingEvent;
use App\Services\Hotels\CurrentHotel;
use App\Services\Tracking\TrackingInstallCheck;
use App\Services\Tracking\TrackingScript;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings > Website script (owners, private plan): the snippet for the hotel's web developer, which booking
 * links and parameter names the script reads, a debug mode, "Check installation" and a sentence for the
 * privacy policy. The script itself is generated in TrackingScript; events arrive at TrackingController.
 */
class WebsiteScript extends Component
{
    #[Locked]
    public ?int $hotelId = null;

    public bool $enabled = true;

    /** One CSS selector per line. */
    public string $selectors = '';

    /** @var array<string, string> field => parameter names, comma separated */
    public array $params = ['checkin' => '', 'checkout' => '', 'adults' => '', 'children' => ''];

    /** @var array<string, string> field => CSS selector of a date input or guests field */
    public array $inputs = ['checkin' => '', 'checkout' => '', 'adults' => '', 'children' => ''];

    public string $dateFormat = 'dmy';

    public ?string $notice = null;

    /** @var array{installed: bool, message: string}|null */
    public ?array $checkResult = null;

    public function mount(CurrentHotel $current): void
    {
        $hotel = $current->get(auth()->user());
        $this->authorizeOwner($hotel);
        $this->hotelId = $hotel?->id;

        if ($hotel !== null) {
            $own = (array) (HotelTracking::forHotel($hotel)->settings ?? []);
            $this->enabled = (bool) HotelTracking::forHotel($hotel)->enabled;
            $this->selectors = implode("\n", (array) ($own['selectors'] ?? []));
            $this->dateFormat = in_array($own['date_format'] ?? null, ['dmy', 'mdy'], true) ? $own['date_format'] : (string) config('citevanta.tracking.date_format');

            foreach (array_keys($this->params) as $field) {
                $this->params[$field] = implode(', ', (array) ($own['params'][$field] ?? []));
                $this->inputs[$field] = (string) ($own['inputs'][$field] ?? '');
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'selectors' => ['nullable', 'string', 'max:1500'],
            'params.*' => ['nullable', 'string', 'max:300', 'regex:/^[A-Za-z0-9_.\-]{1,40}(\s*,\s*[A-Za-z0-9_.\-]{1,40}){0,9}$/'],
            'inputs.*' => ['nullable', 'string', 'max:300', 'regex:/^[^{}<>;\\\\`]+$/'],
            'dateFormat' => ['required', 'in:dmy,mdy'],
        ], [
            'params.*.regex' => 'Use parameter names separated by commas, for example checkin, arrival.',
            'inputs.*.regex' => 'Enter a CSS selector like input[name="checkin"].',
        ]);

        $selectors = array_values(array_filter(array_map('trim', preg_split('/\R/', $this->selectors) ?: [])));

        foreach ($selectors as $selector) {
            if (strlen($selector) > 150 || preg_match('/[{}<>;\\\\`]/', $selector)) {
                $this->addError('selectors', 'Each line must be a CSS selector like a.book-now (no braces, semicolons or brackets of tags).');

                return;
            }
        }

        if (count($selectors) > 10) {
            $this->addError('selectors', 'Use at most 10 selectors.');

            return;
        }

        $params = [];
        foreach ($this->params as $field => $names) {
            $list = array_values(array_filter(array_map('trim', explode(',', $names))));
            if ($list !== []) {
                $params[$field] = $list;
            }
        }

        $tracking = HotelTracking::forHotel($this->hotel());
        $tracking->update([
            'enabled' => $this->enabled,
            'settings' => array_filter([
                'selectors' => $selectors ?: null,
                'params' => $params ?: null,
                'inputs' => array_filter(array_map('trim', $this->inputs)) ?: null,
                'date_format' => $this->dateFormat,
            ], fn ($value) => $value !== null),
        ]);

        $this->notice = 'Saved. The script updates itself within 5 minutes; nothing needs to change on your website.';
    }

    public function checkInstallation(TrackingInstallCheck $check): void
    {
        $this->reset('notice');
        $this->checkResult = $check->run(HotelTracking::forHotel($this->hotel())->load('hotel'));
    }

    public function render(): View
    {
        $hotel = $this->hotelId ? Hotel::find($this->hotelId) : null;
        $tracking = $hotel !== null ? HotelTracking::forHotel($hotel)->load('hotel') : null;
        $events = $hotel !== null ? TrackingEvent::query()->where('hotel_id', $hotel->id)->where('occurred_at', '>=', now()->subDays(30)) : null;

        return view('livewire.settings.website-script', [
            'hotel' => $hotel,
            'tracking' => $tracking,
            'snippet' => $tracking !== null ? TrackingScript::snippet($tracking) : '',
            'privacy' => $hotel !== null ? TrackingScript::privacySentence($hotel->name) : '',
            'defaults' => (array) config('citevanta.tracking'),
            'seen' => $events === null ? ['ai' => 0, 'clicks' => 0] : [
                'ai' => (clone $events)->where('type', TrackingEvent::AI_REFERRAL)->count(),
                'clicks' => (clone $events)->where('type', TrackingEvent::BOOKING_CLICK)->count(),
            ],
            'booking' => (string) $hotel?->booking_engine_domain,
        ])->layout('layouts::app', ['title' => 'Website script', 'breadcrumbs' => ['Settings' => null, 'Website script' => null]]);
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
