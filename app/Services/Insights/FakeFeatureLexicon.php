<?php

namespace App\Services\Insights;

use Illuminate\Support\Str;

/**
 * The hotel features FakeEngine writes into answers, and the fact key each
 * one is about. Used by the offline insight fakes (accuracy, competitor
 * reasons) so they read answers the same way.
 */
final class FakeFeatureLexicon
{
    public const PRICE_KEY = 'Price range per night';

    /** Phrase in an answer => fact key. More specific phrases first. */
    public const FEATURES = [
        'kids club' => 'Kids club',
        'babysitting' => 'Babysitting',
        'family suites' => 'Family rooms',
        'lagoon pool' => 'Swimming pool',
        'pool villas' => 'Private pool villas',
        'adults-only pool' => 'Adults-only pool',
        'infinity pool' => 'Swimming pool',
        'pool' => 'Swimming pool',
        'couples spa' => 'Spa',
        'spa' => 'Spa',
        'wi-fi' => 'Free Wi-Fi',
        'airport' => 'Distance to airport',
        'breakfast' => 'Restaurant',
        'kid-friendly menu' => 'Restaurant',
        'candle-lit dinners' => 'Restaurant',
        'price point' => self::PRICE_KEY,
        'great value' => self::PRICE_KEY,
        'meeting rooms' => 'Meeting rooms',
        'co-working' => 'Co-working space',
        'desks' => 'Work desk in room',
        'free bikes' => 'Bicycles',
        'treks' => 'Guided treks',
        'nature walks' => 'Nature walks',
        'tour desk' => 'Tour desk',
        'sunset views' => 'View',
        'tropical gardens' => 'Gardens',
    ];

    /**
     * Features named in a text, one per fact key: phrase => fact key.
     *
     * @return array<string, string>
     */
    public static function in(string $text): array
    {
        $text = Str::lower($text);
        $found = [];

        foreach (self::FEATURES as $phrase => $factKey) {
            if (! in_array($factKey, $found, true) && str_contains($text, $phrase)) {
                $found[$phrase] = $factKey;
            }
        }

        return $found;
    }

    /**
     * The value of a fact by key (case-insensitive), '' when missing or empty.
     *
     * @param  array<string, string>  $facts
     * @return array{0: string, 1: string} [the hotel's key, value]
     */
    public static function fact(array $facts, string $factKey): array
    {
        foreach ($facts as $key => $value) {
            if (Str::lower(trim((string) $key)) === Str::lower($factKey)) {
                return [(string) $key, trim((string) $value)];
            }
        }

        return [$factKey, ''];
    }

    public static function isNegative(string $value): bool
    {
        return preg_match('/^(no|none|not)\b/i', trim($value)) === 1;
    }
}
