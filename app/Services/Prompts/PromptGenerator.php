<?php

namespace App\Services\Prompts;

/**
 * Produces realistic traveller prompts for a destination. Implementations must
 * never include the hotel's own name: we test unprompted recommendations.
 *
 * Swap the binding in AppServiceProvider to change the source (templates now,
 * AI-generated later).
 */
interface PromptGenerator
{
    /** @return list<GeneratedPrompt> */
    public function generate(PromptContext $context, int $count = 10): array;
}
