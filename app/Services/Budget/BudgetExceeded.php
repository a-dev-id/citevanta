<?php

namespace App\Services\Budget;

use RuntimeException;

/**
 * BudgetGuard refused an AI call. The work is paused, not failed (except
 * RUNAWAY, which aborts the scan).
 */
final class BudgetExceeded extends RuntimeException
{
    /** A bucket (audits, trials, admin) would go over its cap. */
    public const BUCKET = 'bucket';

    /** The total monthly budget would go over. */
    public const TOTAL = 'total';

    /** Above 90% of the total only paid scheduled scans continue. */
    public const PRIORITY = 'priority';

    /** OpenAI said there is no credit or a spend limit is reached. */
    public const BILLING_STOP = 'billing_stop';

    /** A trial that is not verified or approved yet. */
    public const TRIAL_HOLD = 'trial_hold';

    /** The team's own OpenAI key is missing or OpenAI refused it. */
    public const TEAM_KEY = 'team_key';

    /** The team's own monthly AI budget (for its own key) is used up. */
    public const TEAM_BUDGET = 'team_budget';

    /** The scan made more calls than its plan allows. */
    public const RUNAWAY = 'runaway';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
