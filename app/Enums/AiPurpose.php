<?php

namespace App\Enums;

/**
 * What an AI call was for; the scan run's cost is split by these.
 */
enum AiPurpose: string
{
    case Scan = 'scan';
    case Analysis = 'analysis';
    case Accuracy = 'accuracy';
    case CompetitorReasons = 'competitor_reasons';
    case Recommendations = 'recommendations';
    case Audit = 'audit';
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Scan',
            self::Analysis => 'Analysis',
            self::Accuracy => 'Accuracy',
            self::CompetitorReasons => 'Competitor reasons',
            self::Recommendations => 'Recommendations',
            self::Audit => 'Free audit',
            self::Content => 'Content',
        };
    }
}
