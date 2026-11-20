<?php

namespace App\Services\Insights\Accuracy;

/**
 * Lists what one answer claims about the hotel and checks each claim against
 * the hotel's facts. OpenAiAccuracyChecker or FakeAccuracyChecker (fake engine).
 */
interface AccuracyChecker
{
    public function check(AccuracyInput $input): AccuracyResult;
}
