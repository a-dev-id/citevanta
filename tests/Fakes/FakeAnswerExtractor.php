<?php

namespace Tests\Fakes;

use App\Enums\Sentiment;
use App\Services\Analysis\AnswerExtractor;
use App\Services\Analysis\ExtractedAnswer;
use Closure;

/**
 * Offline stand-in for the analysis model. By default it reads numbered list
 * lines ("1. Hotel Name - ...") as the hotels, in order, and reports positive
 * sentiment when the target is in the list.
 */
final class FakeAnswerExtractor implements AnswerExtractor
{
    public int $calls = 0;

    /** @param  (Closure(string, string, string, array): ExtractedAnswer)|null  $using */
    public function __construct(private readonly ?Closure $using = null) {}

    public function extract(string $answer, string $hotelName, string $city, array $competitors = [], array $alternativeNames = []): ExtractedAnswer
    {
        $this->calls++;

        if ($this->using) {
            return ($this->using)($answer, $hotelName, $city, $competitors);
        }

        preg_match_all('/^\s*\d+\.\s*([^\-\n]+?)\s*(?:-|$)/m', $answer, $m);
        $hotels = $m[1];
        $mentioned = stripos($answer, $hotelName) !== false;

        return new ExtractedAnswer(
            hotels: $hotels,
            isRankedList: count($hotels) > 1,
            targetMentioned: $mentioned,
            targetSentiment: $mentioned ? Sentiment::Positive : Sentiment::NotMentioned,
            summary: $mentioned ? 'Recommended.' : 'Not recommended.',
            tokensIn: 100,
            tokensOut: 50,
        );
    }
}
