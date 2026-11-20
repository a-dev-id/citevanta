<?php

namespace App\Services\Analysis;

use App\Enums\Sentiment;
use App\Services\Engines\FakeEngine;

/**
 * Offline AnswerExtractor (CITEVANTA_ENGINE=fake). Reads the hotels the
 * FakeEngine wrote in **bold**, and the sentiment cue words it used, without
 * any API call. The fuzzy matching in ResponseAnalyzer stays the same.
 */
final class FakeAnalyzer implements AnswerExtractor
{
    public function __construct(private readonly HotelNameMatcher $matcher) {}

    public function extract(string $answer, string $hotelName, string $city, array $competitors = [], array $alternativeNames = []): ExtractedAnswer
    {
        preg_match_all('/\*\*(.+?)\*\*/u', $answer, $matches);
        $hotels = array_values(array_unique(array_map('trim', $matches[1])));

        $isRankedList = count($hotels) > 1 && preg_match('/^\s*\d+\.\s/m', $answer) === 1;
        $targetIndex = collect([$hotelName, ...$alternativeNames])
            ->map(fn (string $name) => $this->matcher->indexIn($hotels, $name, [$city]))
            ->filter(fn (?int $index) => $index !== null)
            ->min();
        $mentioned = $targetIndex !== null;

        $sentiment = $mentioned
            ? $this->sentimentOf($this->segmentFor($answer, $hotels[$targetIndex]))
            : Sentiment::NotMentioned;

        return new ExtractedAnswer(
            hotels: $hotels,
            isRankedList: $isRankedList,
            targetMentioned: $mentioned,
            targetSentiment: $sentiment,
            summary: $this->summary($mentioned, $isRankedList, $targetIndex, $sentiment, $hotels),
            tokensIn: intdiv(strlen($answer), 4) + 150,
            tokensOut: 80,
        );
    }

    /**
     * The line or paragraph that talks about the given hotel.
     */
    private function segmentFor(string $answer, string $name): string
    {
        foreach (preg_split('/\n+/', $answer) ?: [] as $segment) {
            if (str_contains($segment, '**'.$name.'**')) {
                return $segment;
            }
        }

        return $answer;
    }

    private function sentimentOf(string $segment): Sentiment
    {
        $segment = mb_strtolower($segment);

        foreach (FakeEngine::NEGATIVE_CUES as $cue) {
            if (str_contains($segment, $cue)) {
                return Sentiment::Negative;
            }
        }

        foreach (FakeEngine::POSITIVE_CUES as $cue) {
            if (str_contains($segment, $cue)) {
                return Sentiment::Positive;
            }
        }

        return Sentiment::Neutral;
    }

    /**
     * @param  list<string>  $hotels
     */
    private function summary(bool $mentioned, bool $isRankedList, ?int $index, Sentiment $sentiment, array $hotels): string
    {
        if (! $mentioned) {
            return $hotels === []
                ? 'No hotels recommended.'
                : 'Not mentioned; recommends '.implode(', ', array_slice($hotels, 0, 3)).' instead.';
        }

        $where = $isRankedList ? 'Ranked #'.($index + 1).' of '.count($hotels) : 'Recommended';

        return "{$where} with a {$sentiment->value} description.";
    }
}
