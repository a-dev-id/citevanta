<?php

namespace App\Enums;

/**
 * Types of AI call, for cost estimates and the measured averages.
 */
enum AiCallKind: string
{
    /** A traveller prompt answered with web search (scan or audit). */
    case Answer = 'answer';
    /** Reading one answer (analysis model, no tools). */
    case Analysis = 'analysis';
    /** Accuracy check, competitor reasons or recommendation writing. */
    case Insight = 'insight';
    case Content = 'content';
}
