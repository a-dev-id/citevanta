<?php

namespace App\Services\Analysis;

use App\Enums\Sentiment;

/**
 * Turns one AI answer into an AnalysisResult: asks the analysis model for the
 * hotels it names (AnswerExtractor), then decides mentions, position and
 * competitors with deterministic fuzzy matching (HotelNameMatcher).
 */
final class ResponseAnalyzer
{
    public function __construct(
        private readonly AnswerExtractor $extractor,
        private readonly HotelNameMatcher $matcher,
    ) {}

    /**
     * @param  list<string>  $competitors
     * @param  list<string>  $alternativeNames  other names of the hotel (e.g. written in Chinese)
     */
    public function analyze(string $answer, string $hotelName, string $city, array $competitors = [], array $alternativeNames = []): AnalysisResult
    {
        $extracted = $this->extractor->extract($answer, $hotelName, $city, $competitors, $alternativeNames);

        return $this->fromExtracted($extracted, $answer, $hotelName, $city, $competitors, $alternativeNames);
    }

    /**
     * @param  list<string>  $competitors
     * @param  list<string>  $alternativeNames
     */
    public function fromExtracted(ExtractedAnswer $extracted, string $answer, string $hotelName, string $city, array $competitors = [], array $alternativeNames = []): AnalysisResult
    {
        $ignore = [$city];
        $hotels = $this->unique($extracted->hotels, $ignore);
        $names = array_values(array_unique([$hotelName, ...$alternativeNames]));

        // The earliest place any of the hotel's names appears in the list.
        $targetIndex = collect($names)
            ->map(fn (string $name) => $this->matcher->indexIn($hotels, $name, $ignore))
            ->filter(fn (?int $index) => $index !== null)
            ->min();

        $mentioned = $targetIndex !== null
            || collect($names)->contains(fn (string $name) => $this->matcher->appearsInText($answer, $name, $ignore))
            || $extracted->targetMentioned;

        $position = $mentioned && $extracted->isRankedList && $targetIndex !== null
            ? $targetIndex + 1
            : null;

        $sentiment = match (true) {
            ! $mentioned => Sentiment::NotMentioned,
            $extracted->targetSentiment === Sentiment::NotMentioned => Sentiment::Neutral,
            default => $extracted->targetSentiment,
        };

        $competitorsMentioned = [];
        foreach ($competitors as $competitor) {
            if ($this->matcher->indexIn($hotels, $competitor, $ignore) !== null
                || $this->matcher->appearsInText($answer, $competitor, $ignore)) {
                $competitorsMentioned[] = $competitor;
            }
        }

        $others = array_values(array_filter($hotels, function (string $name) use ($names, $competitors, $ignore) {
            foreach ($names as $hotelName) {
                if ($this->matcher->matches($name, $hotelName, $ignore)) {
                    return false;
                }
            }

            foreach ($competitors as $competitor) {
                if ($this->matcher->matches($name, $competitor, $ignore)) {
                    return false;
                }
            }

            return true;
        }));

        return new AnalysisResult(
            hotelMentioned: $mentioned,
            position: $position,
            sentiment: $sentiment,
            competitorsMentioned: $competitorsMentioned,
            otherHotelsMentioned: $others,
            summary: $extracted->summary !== '' ? $extracted->summary : ($mentioned ? 'Hotel mentioned.' : 'Hotel not mentioned.'),
            tokensIn: $extracted->tokensIn,
            tokensOut: $extracted->tokensOut,
        );
    }

    /**
     * Drop names that fuzzy-match an earlier one, keeping first-appearance order.
     *
     * @param  list<string>  $names
     * @param  list<string>  $ignore
     * @return list<string>
     */
    private function unique(array $names, array $ignore): array
    {
        $unique = [];

        foreach ($names as $name) {
            if ($this->matcher->indexIn($unique, $name, $ignore) === null) {
                $unique[] = $name;
            }
        }

        return $unique;
    }
}
