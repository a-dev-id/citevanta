<?php

namespace App\Services\Setup;

use App\Models\Competitor;
use App\Models\Hotel;
use App\Models\HotelTracking;
use App\Models\Prompt;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\SetupChecklistState;
use App\Models\Team;
use App\Models\User;
use App\Services\Hotels\CurrentHotel;
use Illuminate\Support\Facades\Route;

/**
 * The first-time setup checklist (a floating card at the bottom right of the customer app).
 *
 * Every item ticks by itself from real data on each page load and after Livewire actions; nobody ticks anything by
 * hand. Items for every team come first, then the Private plan items, and a trial team also gets an "Upgrade when
 * you are ready" item that is not counted in the progress.
 *
 * Done rules (items about a hotel use the user's current hotel):
 *  email           the user's email is verified
 *  hotel           the team has a hotel with a name, a city and a website
 *  facts           the required facts (config setup_checklist.required_facts) have a value
 *  competitors     at least 3 competitors
 *  prompts         at least 10 active prompts
 *  scan            one scan has completed
 *  recommendation  one recommendation was opened and acted on: its status is not "new" any more (to do, in progress,
 *                  done or dismissed)
 *  openai_key      Private: the team's own key passed its test (and is not stopped) and a monthly budget is set
 *  google          Private: a GA4 property and a Search Console site are connected
 *  script          Private: the website script sent its first event, or Check installation passed
 *  health          Private: the tracking health check ran and shows no error (red)
 */
final class SetupChecklist
{
    /** Items for every team, in order. */
    public const BASE = ['email', 'hotel', 'facts', 'competitors', 'prompts', 'scan', 'recommendation'];

    /** Extra items on the Private plan, in order. */
    public const PRIVATE = ['openai_key', 'google', 'script', 'health'];

    public function __construct(private readonly CurrentHotel $currentHotel) {}

    public function enabled(): bool
    {
        return (bool) config('citevanta.setup_checklist.enabled', true);
    }

    /** Shown to a user with a verified email and a team, never on an admin page. */
    public function available(?User $user, ?string $route): bool
    {
        return $this->enabled()
            && $user !== null
            && $user->currentTeam !== null
            && $user->hasVerifiedEmail()
            && ! str_starts_with((string) $route, 'admin.');
    }

    /**
     * The items for a user and team, in order, each with its text in the current language and where it leads.
     *
     * @return list<array{key: string, done: bool, counted: bool, title: string, help: string, action: string, url: string|null, icon: string}>
     */
    public function items(User $user, Team $team): array
    {
        $hotel = $this->hotelFor($user, $team);
        $keys = [...self::BASE, ...($team->isPrivate() ? self::PRIVATE : [])];
        $items = [];

        foreach ($keys as $key) {
            $items[] = $this->item($key, $this->isDone($key, $user, $team, $hotel), true, $hotel, $user, $team);
        }

        if ($team->isOnTrial()) {
            $items[] = $this->item('upgrade', false, false, $hotel, $user, $team);
        }

        return $items;
    }

    /**
     * @param  list<array{done: bool, counted: bool}>  $items
     * @return array{done: int, total: int, percent: int, complete: bool}
     */
    public function progress(array $items): array
    {
        $counted = array_filter($items, fn (array $item) => $item['counted']);
        $done = count(array_filter($counted, fn (array $item) => $item['done']));
        $total = count($counted);

        return ['done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) round($done / $total * 100) : 0, 'complete' => $total > 0 && $done === $total];
    }

    /** The hotel the hotel items are about: the user's current hotel, else the team's first hotel. */
    public function hotelFor(User $user, Team $team): ?Hotel
    {
        $hotel = $this->currentHotel->get($user);

        return $hotel !== null && $hotel->team_id === $team->id ? $hotel : $team->hotels()->orderBy('id')->first();
    }

    public function isDone(string $key, User $user, Team $team, ?Hotel $hotel): bool
    {
        $link = $hotel?->googleLink;

        return match ($key) {
            'email' => $user->hasVerifiedEmail(),
            'hotel' => $team->hotels()->where('name', '!=', '')->whereNotNull('name')->where('city', '!=', '')->whereNotNull('city')->where('website', '!=', '')->whereNotNull('website')->exists(),
            'facts' => $hotel !== null && $this->hasRequiredFacts($hotel),
            'competitors' => $hotel !== null && Competitor::query()->where('hotel_id', $hotel->id)->count() >= (int) config('citevanta.setup_checklist.min_competitors', 3),
            'prompts' => $hotel !== null && Prompt::query()->where('hotel_id', $hotel->id)->where('is_active', true)->count() >= (int) config('citevanta.setup_checklist.min_prompts', 10),
            'scan' => $hotel !== null && ScanRun::query()->where('hotel_id', $hotel->id)->whereIn('status', ['completed', 'completed_with_errors'])->exists(),
            'recommendation' => $hotel !== null && Recommendation::query()->where('hotel_id', $hotel->id)->where('status', '!=', 'new')->exists(),
            'openai_key' => $team->hasOwnAiKey() && $team->openai_key_checked_at !== null && $team->openai_key_stopped_at === null && (float) $team->ai_budget_usd > 0,
            'google' => $link !== null && $link->ga4_property !== null && $link->gsc_site !== null,
            'script' => $hotel !== null && HotelTracking::query()->where('hotel_id', $hotel->id)->where(fn ($query) => $query->whereNotNull('last_seen_at')->orWhere('installed', true))->exists(),
            'health' => $link !== null && $link->health_checked_at !== null && ! $this->healthHasErrors($link->health),
            default => false,
        };
    }

    /** Every required fact (config setup_checklist.required_facts) is there with a value. */
    public function hasRequiredFacts(Hotel $hotel): bool
    {
        $have = $hotel->facts()->get(['key', 'value'])
            ->filter(fn ($fact) => trim((string) $fact->value) !== '')
            ->map(fn ($fact) => mb_strtolower(trim((string) $fact->key)))->all();

        foreach ((array) config('citevanta.setup_checklist.required_facts', []) as $required) {
            if (! in_array(mb_strtolower(trim((string) $required)), $have, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, array<string, mixed>>|null $health */
    private function healthHasErrors(?array $health): bool
    {
        foreach ((array) $health as $result) {
            if (($result['status'] ?? null) === 'red') {
                return true;
            }
        }

        return false;
    }

    // --- remembered state -----------------------------------------------------------------------

    /** The saved state, or a new one (not saved yet) that is expanded: the default on the first visit. */
    public function record(User $user, Team $team): SetupChecklistState
    {
        return SetupChecklistState::query()->firstOrNew(['user_id' => $user->id, 'team_id' => $team->id], ['state' => SetupChecklistState::EXPANDED, 'all_set_views' => 0]);
    }

    public function setState(User $user, Team $team, string $state): SetupChecklistState
    {
        abort_unless(in_array($state, SetupChecklistState::STATES, true), 422);

        $record = $this->record($user, $team);
        $record->state = $state;
        $record->save();

        return $record;
    }

    /** Bring it back (the help menu): expanded again, and "All set" may show once more. */
    public function restore(User $user, Team $team): SetupChecklistState
    {
        $record = $this->record($user, $team);
        $record->forceFill(['state' => SetupChecklistState::EXPANDED, 'all_set_views' => 0])->save();

        return $record;
    }

    /** Admin > Teams: forget what one member did with the checklist, so it starts as new. */
    public function reset(User $user, Team $team): int
    {
        return SetupChecklistState::query()->where('user_id', $user->id)->where('team_id', $team->id)->delete();
    }

    // --- items ----------------------------------------------------------------------------------

    /**
     * @return array{key: string, done: bool, counted: bool, title: string, help: string, action: string, url: string|null, icon: string}
     */
    private function item(string $key, bool $done, bool $counted, ?Hotel $hotel, User $user, Team $team): array
    {
        return [
            'key' => $key,
            'done' => $done,
            'counted' => $counted,
            'title' => (string) __("checklist.items.{$key}.title"),
            'help' => (string) __("checklist.items.{$key}.help"),
            'action' => (string) __("checklist.items.{$key}.action"),
            'url' => $this->url($key, $hotel, $user, $team),
            'icon' => $counted ? ($done ? 'fa-circle-check' : 'fa-circle') : 'fa-arrow-up-right-dots',
        ];
    }

    private function url(string $key, ?Hotel $hotel, User $user, Team $team): ?string
    {
        $hotelRoute = fn (string $name) => $hotel !== null ? route($name, $hotel) : route('hotels.create');

        return match ($key) {
            'email' => Route::has('verification.notice') ? route('verification.notice') : route('settings.profile'),
            'hotel' => $hotel === null ? route('hotels.create') : route('hotels.edit', $hotel),
            'facts' => $hotel === null ? route('hotels.create') : route('hotels.edit', $hotel),
            'competitors' => $hotelRoute('hotels.competitors'),
            'prompts' => $hotelRoute('hotels.prompts'),
            'scan' => $hotelRoute('hotels.show'),
            'recommendation' => $hotelRoute('hotels.recommendations'),
            'openai_key' => route('settings.ai-key'),
            'google', 'health' => route('settings.google'),
            'script' => route('settings.website-script'),
            'upgrade' => $user->ownsTeam($team) ? route('settings.billing') : null,
            default => null,
        };
    }
}
