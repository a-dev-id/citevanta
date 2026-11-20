<?php

namespace App\Services\Insights\Accuracy;

/**
 * One answer to check against the hotel's facts.
 */
final class AccuracyInput
{
    /**
     * @param  array<string, string>  $facts  fact key => value (only facts with a value)
     */
    public function __construct(
        public readonly string $hotelName,
        public readonly string $city,
        public readonly array $facts,
        public readonly string $answer,
    ) {}
}
