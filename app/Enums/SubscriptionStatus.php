<?php

namespace App\Enums;

/**
 * A team's subscription state.
 *
 * - trialing: free trial (Starter limits) until teams.trial_ends_at
 * - active: paid (or comped) until teams.current_period_ends_at
 * - past_due: the period ended unpaid; scans keep running until grace_ends_at
 * - canceled: runs until the end of the paid period, then expires
 * - expired: read-only (no scans or content generation); data is kept
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
            self::Expired => 'Expired',
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::Trialing => 'info',
            self::Active => 'success',
            self::PastDue => 'warning',
            self::Canceled => 'neutral',
            self::Expired => 'danger',
        };
    }
}
