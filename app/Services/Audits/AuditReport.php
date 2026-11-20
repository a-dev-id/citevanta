<?php

namespace App\Services\Audits;

/**
 * What a finished audit found (built by AuditReportBuilder).
 */
final class AuditReport
{
    /**
     * @param  list<array{name: string, answers: int}>  $recommendedInstead
     * @param  array<string, int>  $domainTypes  DomainType value => citations
     * @param  list<string>  $insights
     */
    public function __construct(
        public readonly int $questions,
        public readonly int $questionsMentioned,
        public readonly ?float $visibility,
        public readonly array $recommendedInstead,
        public readonly array $domainTypes,
        public readonly array $insights,
    ) {}
}
