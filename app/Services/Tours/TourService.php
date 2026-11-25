<?php

namespace App\Services\Tours;

use App\Models\Team;
use App\Models\User;
use App\Models\UserTour;

/**
 * Guided tours: which tour a page has, the steps this team may see, whether it starts by itself, and what
 * the user's choices (Done, Skip, "Don't show this again") are remembered as. State is per user, in the database.
 *
 * Remembering:
 *  - Done, or closing with "Don't show this again" ticked: the tour is finished (for that version).
 *  - Skip without the checkbox: the tour is offered again on the next visit, at most MAX_RETURNS more times,
 *    then it stops by itself.
 *  - A new version of a tour is offered once more, whatever happened before.
 */
final class TourService
{
    /** How many more times a skipped tour comes back (so it is shown at most 1 + 2 = 3 times). */
    public const MAX_RETURNS = 2;

    public function enabled(): bool
    {
        return (bool) config('citevanta.tours.enabled', true);
    }

    /**
     * What the page needs to run its tour, or null when it has none for this user (tours off, no tour on this
     * page, an admin page, or no step left for the team's plan).
     *
     * @return array<string, mixed>|null
     */
    public function forPage(User $user, ?string $route): ?array
    {
        $key = TourDefinitions::forRoute($route);
        $team = $user->currentTeam;

        if (! $this->enabled() || $key === null || $team === null || str_starts_with((string) $route, 'admin.')) {
            return null;
        }

        $definition = TourDefinitions::all()[$key];

        if (! $this->planAllows($team, $definition['plan'] ?? null)) {
            return null;
        }

        $steps = $this->steps($key, $team);

        if ($steps === []) {
            return null;
        }

        return [
            'key' => $key,
            'version' => $definition['version'],
            'name' => (string) __("tours.{$key}.name"),
            'autostart' => $this->mayAutostart($user, $team) && $this->isOffered($user, $key),
            'steps' => $steps,
            'ui' => (array) __('tours.ui'),
            'urls' => [
                'finish' => route('tours.finish', $key),
                'skip' => route('tours.skip', $key),
                'reset' => route('tours.reset'),
            ],
        ];
    }

    /**
     * The steps of a tour that this team may see, with their text in the current language.
     *
     * @return list<array{id: string, target: string, sidebar: bool, title: string, text: string}>
     */
    public function steps(string $key, Team $team): array
    {
        $steps = [];

        foreach (TourDefinitions::all()[$key]['steps'] ?? [] as $step) {
            if (! $this->planAllows($team, $step['plan'] ?? null)) {
                continue;
            }

            $steps[] = [
                'id' => $step['id'],
                'target' => $step['target'],
                'sidebar' => (bool) ($step['sidebar'] ?? false),
                'title' => (string) __("tours.{$key}.steps.{$step['id']}.title"),
                'text' => (string) __("tours.{$key}.steps.{$step['id']}.text"),
            ];
        }

        return $steps;
    }

    /** Private-plan steps for Private teams only, trial steps (about locked features) for trial teams only. */
    public function planAllows(Team $team, ?string $plan): bool
    {
        return match ($plan) {
            'private' => $team->isPrivate(),
            'trial' => $team->isOnTrial(),
            default => true,
        };
    }

    /** A tour starts by itself only for a user with a verified email whose team has a hotel that is set up. */
    public function mayAutostart(User $user, Team $team): bool
    {
        return $user->hasVerifiedEmail() && $team->hotels()->whereNotNull('setup_completed_at')->exists();
    }

    /** Whether memory says the tour should be offered (again) to this user. */
    public function isOffered(User $user, string $key): bool
    {
        $record = UserTour::query()->where('user_id', $user->id)->where('tour', $key)->first();

        if ($record === null || $record->version < TourDefinitions::all()[$key]['version']) {
            return true;
        }

        return $record->status !== UserTour::FINISHED && $record->skips <= self::MAX_RETURNS;
    }

    /** Done, or closed with "Don't show this again" ticked. */
    public function finish(User $user, string $key): void
    {
        $this->save($user, $key, finished: true);
    }

    /** Skip (or Esc). Without the checkbox the tour comes back; with it, it is finished. */
    public function skip(User $user, string $key, bool $dontShowAgain): void
    {
        $this->save($user, $key, finished: $dontShowAgain);
    }

    /** Forget everything a user did with the tours, so every tour is offered again. */
    public function reset(User $user): int
    {
        return UserTour::query()->where('user_id', $user->id)->delete();
    }

    private function save(User $user, string $key, bool $finished): void
    {
        $version = TourDefinitions::all()[$key]['version'];
        $record = UserTour::query()->where('user_id', $user->id)->where('tour', $key)->first();
        $current = $record !== null && $record->version >= $version;

        if (! $finished && $current && $record->status === UserTour::FINISHED) {
            return; // skipping a replay does not undo "finished"
        }

        UserTour::query()->updateOrCreate(['user_id' => $user->id, 'tour' => $key], $finished
            ? ['version' => $version, 'status' => UserTour::FINISHED, 'finished_at' => now(), 'skips' => $current ? $record->skips : 0]
            : ['version' => $version, 'status' => UserTour::SKIPPED, 'finished_at' => null, 'skips' => ($current ? $record->skips : 0) + 1]);
    }
}
