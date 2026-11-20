<?php

namespace App\Services\Analysis;

/**
 * Reads one AI answer and extracts structured facts about the hotels in it.
 * The deterministic matching on top of this lives in ResponseAnalyzer.
 */
interface AnswerExtractor
{
    /**
     * @param  list<string>  $competitors
     * @param  list<string>  $alternativeNames  other names of the target hotel (e.g. in Chinese)
     */
    public function extract(string $answer, string $hotelName, string $city, array $competitors = [], array $alternativeNames = []): ExtractedAnswer;
}
