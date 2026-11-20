<?php

namespace App\Services\Scanning;

use Carbon\CarbonInterface;

/**
 * Whether "Scan now" is available, and why not.
 */
final readonly class ManualScanCheck
{
    /**
     * @param  int|null  $limit  monthly manual scans for the team; null = unlimited (admin)
     */
    public function __construct(
        public bool $allowed,
        public ?string $reason,
        public int $used,
        public ?int $limit,
        public ?CarbonInterface $availableAt = null,
        public ?CarbonInterface $nextScheduledAt = null,
    ) {}
}
