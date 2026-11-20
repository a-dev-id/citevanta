<?php

namespace App\Services\Trials;

use App\Models\Audit;
use App\Models\Hotel;
use App\Models\Team;
use App\Services\Analysis\HotelNameMatcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * One trial per hotel: a trial team cannot add a hotel (same city, name
 * matched with HotelNameMatcher) that another team already has, because
 * every team started as a trial and may have paid since.
 */
final class TrialHotelGuard
{
    public const MESSAGE = 'This hotel already has an account. Contact us.';

    public function __construct(private readonly HotelNameMatcher $names) {}

    public function blocks(Team $team, string $name, string $city, ?Hotel $ignore = null): bool
    {
        if (! $team->isOnTrial()) {
            return false;
        }

        return $this->hotelsInCity($city)
            ->where('team_id', '!=', $team->id)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->get(['id', 'name'])
            ->contains(fn (Hotel $hotel) => $this->names->matches($hotel->name, $name, [$city]));
    }

    /**
     * The free audit this team came from, if it was for this hotel.
     */
    public function sourceAudit(Team $team, string $name, string $city): ?Audit
    {
        return Audit::query()->where('converted_team_id', $team->id)->latest('id')->get()
            ->first(fn (Audit $audit) => mb_strtolower(trim($audit->city)) === mb_strtolower(trim($city))
                && $this->names->matches($audit->hotel_name, $name, [$city]));
    }

    /**
     * @return Builder<Hotel>
     */
    private function hotelsInCity(string $city)
    {
        return Hotel::query()->where('is_audit', false)->whereNotNull('team_id')
            ->whereRaw('lower(trim(city)) = ?', [mb_strtolower(trim($city))]);
    }
}
