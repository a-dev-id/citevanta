<?php

namespace App\Services\Insights\Competitors;

use App\Enums\DomainType;
use App\Enums\HotelHasFeature;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Dashboard\DomainClassifier;
use App\Services\Dashboard\DomainContext;
use App\Services\Insights\Accuracy\FakeAccuracyChecker;
use App\Services\Insights\FakeFeatureLexicon;
use Illuminate\Support\Str;

/**
 * Offline competitor reasons (CITEVANTA_ENGINE=fake). For each winning
 * competitor it reads that competitor's line in the FakeEngine answers, turns
 * the features named there into reasons, adds "mentioned by <travel guide>"
 * for travel media cited next to it, and checks each feature against the
 * hotel's facts (has it / lacks it / unknown). Same input, same output.
 */
final class FakeCompetitorReasoner implements CompetitorReasoner
{
    public const MODEL = 'fake-engine';

    public function __construct(
        private readonly HotelNameMatcher $matcher,
        private readonly DomainClassifier $classifier,
    ) {}

    public function explain(CompetitorReasonInput $input): CompetitorReasonResult
    {
        $reasons = [];
        $seen = [];

        foreach ($input->competitors as $competitor) {
            foreach ($input->answers as $answer) {
                $line = FakeAccuracyChecker::lineAbout($this->matcher, $answer['text'], $competitor, $input->city);

                if ($line === '') {
                    continue;
                }

                $quote = Str::limit(trim((string) preg_replace('/^\d+\.\s*/', '', str_replace('**', '', $line))), 200);

                foreach (FakeFeatureLexicon::in($line) as $phrase => $factKey) {
                    [$key, $value] = FakeFeatureLexicon::fact($input->facts, $factKey);
                    $this->add($reasons, $seen, new CompetitorReason(
                        competitor: $competitor,
                        reason: $phrase,
                        quote: $quote,
                        factKey: $value === '' ? null : $key,
                        hotelHas: match (true) {
                            $value === '' => HotelHasFeature::Unknown,
                            FakeFeatureLexicon::isNegative($value) => HotelHasFeature::LacksIt,
                            default => HotelHasFeature::HasIt,
                        },
                    ));
                }

                foreach ($answer['citations'] as $citation) {
                    if ($this->classifier->classify($citation['domain'], new DomainContext(null)) === DomainType::TravelMedia) {
                        $this->add($reasons, $seen, new CompetitorReason($competitor, 'mentioned by '.$citation['domain'], $quote, null, HotelHasFeature::Unknown));
                    }
                }
            }
        }

        $reasons = array_slice($reasons, 0, OpenAiCompetitorReasoner::MAX_REASONS);
        $top = collect($reasons)->take(2)->pluck('reason')->implode(' and ');

        return new CompetitorReasonResult(
            reasons: $reasons,
            summary: $reasons === []
                ? 'The answers recommended '.implode(' and ', $input->competitors).' without naming specific features.'
                : implode(' and ', $input->competitors).' won mainly on '.$top.'.',
            tokensIn: intdiv(strlen(implode("\n", array_column($input->answers, 'text'))), 4) + 40 * count($input->facts) + 400,
            tokensOut: 60 + 50 * count($reasons),
            model: self::MODEL,
        );
    }

    /**
     * @param  list<CompetitorReason>  $reasons
     * @param  array<string, true>  $seen
     */
    private function add(array &$reasons, array &$seen, CompetitorReason $reason): void
    {
        $key = Str::lower($reason->competitor.'|'.$reason->reason);

        if (! isset($seen[$key])) {
            $seen[$key] = true;
            $reasons[] = $reason;
        }
    }
}
