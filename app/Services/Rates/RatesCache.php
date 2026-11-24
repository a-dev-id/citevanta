<?php

namespace App\Services\Rates;

use App\Models\Hotel;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for the public rates pages. The cache store has no tags (database
 * cache on shared hosting), so every key contains the hotel's rates_version:
 * changing rates or rate settings bumps the version, and all old pages are
 * simply never read again.
 */
final class RatesCache
{
    public function remember(Hotel $hotel, string $key, Closure $build): string
    {
        return (string) Cache::remember(
            "rates:{$hotel->id}:v{$hotel->rates_version}:{$key}",
            now()->addMinutes((int) config('citevanta.rates.cache_minutes', 1440)),
            $build,
        );
    }

    /**
     * Call after every change to rates, room types or the page settings.
     */
    public function forget(Hotel $hotel): void
    {
        Hotel::query()->whereKey($hotel->id)->increment('rates_version');
        $hotel->refresh();
    }
}
