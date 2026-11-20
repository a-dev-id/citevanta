<?php

namespace App\Services\Insights\Accuracy;

use App\Enums\AccuracyVerdict;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Insights\FakeFeatureLexicon;
use Illuminate\Support\Str;

/**
 * Offline accuracy check (CITEVANTA_ENGINE=fake). Reads the line about the
 * hotel in a FakeEngine answer, finds the features it names and compares
 * them with the hotel's facts, the way the real check would:
 *  - fact missing or empty: not_in_facts
 *  - fact starts with "No" but the answer says the hotel has it: wrong
 *  - a price claim against a price fact: outdated for some answers (seeded
 *    by the answer text, so the same answer always gives the same result)
 *  - otherwise: correct
 */
final class FakeAccuracyChecker implements AccuracyChecker
{
    public const MODEL = 'fake-engine';

    public function __construct(private readonly HotelNameMatcher $matcher) {}

    public function check(AccuracyInput $input): AccuracyResult
    {
        $segment = self::lineAbout($this->matcher, $input->answer, $input->hotelName, $input->city);
        $quote = Str::limit(trim((string) preg_replace('/^\d+\.\s*/', '', str_replace('**', '', $segment))), 200);
        $claims = [];

        foreach (FakeFeatureLexicon::in($segment) as $phrase => $factKey) {
            [$key, $value] = FakeFeatureLexicon::fact($input->facts, $factKey);
            $isPrice = $factKey === FakeFeatureLexicon::PRICE_KEY;

            $verdict = match (true) {
                $value === '' => AccuracyVerdict::NotInFacts,
                FakeFeatureLexicon::isNegative($value) => AccuracyVerdict::Wrong,
                $isPrice && crc32($input->answer) % 5 < 2 => AccuracyVerdict::Outdated,
                default => AccuracyVerdict::Correct,
            };

            $claims[] = new AccuracyClaim(
                factKey: $key,
                aiClaim: $isPrice ? "{$input->hotelName} is described as mid-range in price." : "{$input->hotelName} has a {$phrase}.",
                aiValue: $isPrice ? 'mid-range price' : 'has '.Str::lower($factKey),
                trueValue: $value === '' ? null : $value,
                verdict: $verdict,
                quote: $quote,
            );
        }

        return new AccuracyResult(
            claims: $claims,
            tokensIn: intdiv(strlen($input->answer), 4) + 40 * count($input->facts) + 250,
            tokensOut: 40 + 60 * count($claims),
            model: self::MODEL,
        );
    }

    /**
     * The answer line that names a hotel in bold ("1. **Alaya Resort Ubud** - ..."), or ''.
     */
    public static function lineAbout(HotelNameMatcher $matcher, string $answer, string $hotelName, string $city): string
    {
        foreach (preg_split('/\n+/', $answer) ?: [] as $line) {
            preg_match_all('/\*\*(.+?)\*\*/u', $line, $names);

            foreach ($names[1] as $name) {
                if ($matcher->matches($name, $hotelName, [$city])) {
                    return trim($line);
                }
            }
        }

        return '';
    }
}
