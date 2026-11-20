<?php

namespace App\Services\Dashboard;

use App\Enums\DomainType;
use App\Models\AccuracyIssue;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Services\Scanning\ManualScanCheck;
use App\Services\Scanning\ScanProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Everything the hotel dashboard shows, prepared by DashboardService.
 */
final readonly class HotelDashboard
{
    /**
     * @param  list<string>  $engines  engines with data in the shown scan
     * @param  array{visibility: ?float, position: ?float, positive: ?float, sources: ?int}  $changes
     * @param  list<array{persona: string, answers: int, mentions: int, visibility: float}>  $personas
     * @param  list<array{name: string, is_self: bool, mentions: int, visibility: float, share: float}>  $competitors
     * @param  list<array{domain: string, citations: int, type: string}>  $domains  type is a DomainType value
     * @param  array{total: int, types: list<array{type: DomainType, citations: int, share: float}>}  $links
     * @param  list<array<string, mixed>>  $prompts
     * @param  Collection<int, ScanRun>  $history
     * @param  array{scans: int, visibility: array<string, mixed>, share_of_voice: array<string, mixed>, sources: array<string, mixed>}|null  $trends  null below 2 scans
     * @param  array{enabled: bool, issues: Collection<int, AccuracyIssue>|null, open: int, missing: int}  $accuracy  "What AI gets wrong" card
     * @param  array{top: Collection<int, Recommendation>|null, open: int}  $recommendations  "Top recommendations" card
     * @param  list<string>  $languages  prompt languages in the shown scan (filter shown when 2 or more)
     * @param  list<array{language: string, name: string, answers: int, mentions: int, visibility: float}>  $byLanguage
     * @param  array{enabled: bool, summary: array<string, mixed>|null}  $competitorReasons  "Why competitors win" card (CompetitorInsightReport::summary)
     */
    public function __construct(
        public Hotel $hotel,
        public ?ScanRun $scan,
        public bool $isLatest,
        public ?ScanRun $previous,
        public ?ScanRun $failedScan,
        public array $engines,
        public ?string $engine,
        public ?ScanMetrics $metrics,
        public array $changes,
        public array $personas,
        public array $competitors,
        public array $domains,
        public ?string $ownDomain,
        public bool $ownCited,
        public array $links,
        public array $prompts,
        public Collection $history,
        public ?ScanRun $activeScan,
        public ?ScanProgress $progress,
        public ManualScanCheck $manualScan,
        public string $allowanceText,
        public ?CarbonInterface $lastCompletedAt,
        public ?CarbonInterface $nextScheduledAt,
        public ?array $trends = null,
        public array $accuracy = ['enabled' => false, 'issues' => null, 'open' => 0, 'missing' => 0],
        public array $recommendations = ['top' => null, 'open' => 0],
        public array $languages = [],
        public ?string $language = null,
        public array $byLanguage = [],
        public array $competitorReasons = ['enabled' => false, 'summary' => null],
    ) {}
}
