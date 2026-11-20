<?php

namespace App\Services\Insights\Competitors;

/**
 * Explains why competitors were recommended instead of the hotel for one
 * prompt. OpenAiCompetitorReasoner or FakeCompetitorReasoner (fake engine).
 */
interface CompetitorReasoner
{
    public function explain(CompetitorReasonInput $input): CompetitorReasonResult;
}
