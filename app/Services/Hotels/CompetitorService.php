<?php

namespace App\Services\Hotels;

use App\Models\Competitor;
use App\Models\Hotel;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Plans\PlanGuard;
use App\Services\Support\Url;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A hotel's competitors: up to the plan limit, no duplicates, and never the
 * hotel itself (compared with HotelNameMatcher, so "ALAYA Ubud" counts).
 */
final class CompetitorService
{
    public function __construct(
        private readonly HotelNameMatcher $matcher,
        private readonly PlanGuard $plans,
    ) {}

    public function limit(Hotel $hotel): int
    {
        return $this->plans->competitorLimit($hotel);
    }

    public function count(Hotel $hotel): int
    {
        return $hotel->competitors()->count();
    }

    /**
     * @throws ValidationException
     */
    public function add(Hotel $hotel, ?string $name, ?string $website): Competitor
    {
        if ($this->count($hotel) >= $this->limit($hotel)) {
            throw ValidationException::withMessages([
                'name' => 'Your plan allows '.$this->limit($hotel).' competitors. Remove one to add another.',
            ]);
        }

        $data = $this->validate($hotel, $name, $website);

        // A competitor removed earlier is restored, keeping its scan history.
        $trashed = $hotel->competitors()->onlyTrashed()->where('name', $data['name'])->first();
        if ($trashed !== null) {
            $trashed->restore();
            $trashed->update(['website' => $data['website']]);

            return $trashed;
        }

        return $hotel->competitors()->create($data);
    }

    /**
     * @throws ValidationException
     */
    public function update(Competitor $competitor, ?string $name, ?string $website): Competitor
    {
        $competitor->update($this->validate($competitor->hotel, $name, $website, $competitor));

        return $competitor;
    }

    public function delete(Competitor $competitor): void
    {
        $competitor->delete();
    }

    /**
     * @return array{name: string, website: ?string}
     *
     * @throws ValidationException
     */
    private function validate(Hotel $hotel, ?string $name, ?string $website, ?Competitor $except = null): array
    {
        $data = Validator::make(
            ['name' => trim((string) $name), 'website' => Url::normalize($website)],
            [
                'name' => ['required', 'string', 'max:255'],
                'website' => ['nullable', 'url:http,https', 'max:255'],
            ],
            ['name.required' => 'Enter the competitor\'s name.'],
        )->validate();

        $ignore = [$hotel->city];

        if ($this->matcher->matches($data['name'], $hotel->name, $ignore)) {
            throw ValidationException::withMessages(['name' => 'That is your own hotel. Add a competing hotel instead.']);
        }

        $duplicate = $hotel->competitors()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->pluck('name')
            ->first(fn (string $existing) => $this->matcher->matches($existing, $data['name'], $ignore));

        if ($duplicate !== null) {
            throw ValidationException::withMessages(['name' => "\"{$duplicate}\" is already a competitor."]);
        }

        return $data;
    }
}
