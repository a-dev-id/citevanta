<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Enums\TeamRole;
use App\Services\Plans\PlanLimits;
use Carbon\CarbonInterface;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property Plan $plan
 * @property CarbonInterface|null $trial_ends_at
 * @property string $timezone
 * @property SubscriptionStatus $subscription_status
 * @property BillingInterval|null $billing_interval
 * @property string|null $currency
 * @property int $hotel_quantity
 * @property CarbonInterface|null $current_period_starts_at
 * @property CarbonInterface|null $current_period_ends_at
 * @property CarbonInterface|null $grace_ends_at
 * @property bool $cancel_at_period_end
 * @property bool $is_comped
 * @property Plan|null $next_plan
 * @property BillingInterval|null $next_interval
 * @property int|null $next_quantity
 * @property PaymentMethod $payment_method
 * @property string|null $billing_provider
 * @property string|null $provider_subscription_id
 * @property CarbonInterface|null $trial_ending_notified_at
 * @property string|null $weekly_report_week
 * @property CarbonInterface|null $weekly_report_sent_at
 * @property string|null $signup_ip_hash
 * @property bool $trial_approval_required
 * @property CarbonInterface|null $trial_approved_at
 * @property array<string, int>|null $plan_limits
 * @property int|null $private_price_usd
 * @property int|null $private_price_idr
 * @property string|null $openai_key encrypted at rest
 * @property string|null $openai_key_last4
 * @property CarbonInterface|null $openai_key_checked_at
 * @property CarbonInterface|null $openai_key_stopped_at
 * @property string|null $openai_key_stop_code
 * @property float $ai_budget_usd
 */
#[Hidden(['openai_key'])]
#[Fillable([
    'name', 'plan', 'trial_ends_at', 'timezone', 'subscription_status', 'billing_interval', 'currency',
    'hotel_quantity', 'current_period_starts_at', 'current_period_ends_at', 'grace_ends_at', 'cancel_at_period_end',
    'is_comped', 'next_plan', 'next_interval', 'next_quantity', 'payment_method', 'billing_provider',
    'provider_subscription_id', 'trial_ending_notified_at', 'weekly_report_week', 'weekly_report_sent_at',
    'signup_ip_hash', 'trial_approval_required', 'trial_approved_at', 'plan_limits', 'private_price_usd',
    'private_price_idr', 'ai_budget_usd',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * Bootstrap the model: new teams start on a trial.
     */
    protected static function booted(): void
    {
        static::creating(function (Team $team): void {
            $team->plan ??= Plan::Trial;

            if ($team->plan === Plan::Trial && $team->trial_ends_at === null) {
                $team->trial_ends_at = now()->addDays((int) config('citevanta.trial_days', 7));
            }

            if ($team->getAttribute('subscription_status') === null) {
                $team->subscription_status = $team->plan === Plan::Trial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active;
            }
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', TeamRole::Owner->value);
    }

    /**
     * @return HasMany<Hotel, $this>
     */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function addMember(User $user, TeamRole $role = TeamRole::Member): void
    {
        $this->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    /**
     * Get the limits of the team's plan. Paid plans are priced per hotel:
     * the team may add as many hotels as it pays for.
     */
    public function limits(): PlanLimits
    {
        $plan = $this->plan ?? Plan::Trial;
        $limits = PlanLimits::for($plan, (array) ($this->plan_limits ?? []));

        // Private: the hotel count is set per team, not bought per hotel.
        if ($plan === Plan::Private || ! $plan->isPaid()) {
            return $limits;
        }

        return $limits->withHotels(max($limits->hotels, (int) $this->hotel_quantity));
    }

    public function isPrivate(): bool
    {
        return $this->plan === Plan::Private;
    }

    /**
     * The team pays its own AI calls with its own OpenAI key.
     */
    public function hasOwnAiKey(): bool
    {
        return $this->openai_key !== null && $this->openai_key !== '';
    }

    /**
     * "sk-...abcd": the only form a saved key is ever shown in.
     */
    public function maskedAiKey(): ?string
    {
        return $this->hasOwnAiKey() ? 'sk-...'.$this->openai_key_last4 : null;
    }

    /**
     * The team's timezone, falling back to the app default.
     */
    public function timezoneName(): string
    {
        return $this->timezone ?: (string) config('citevanta.default_timezone', 'Asia/Makassar');
    }

    /**
     * Start of the current calendar month in the team's timezone (as UTC).
     * Monthly allowances reset at this moment.
     */
    public function currentPeriodStart(): CarbonInterface
    {
        return now($this->timezoneName())->startOfMonth()->utc();
    }

    /**
     * Start of next month in the team's timezone (as UTC).
     */
    public function nextPeriodStart(): CarbonInterface
    {
        return now($this->timezoneName())->startOfMonth()->addMonthNoOverflow()->utc();
    }

    /**
     * Determine if the team is on a trial that has ended.
     */
    public function trialHasExpired(): bool
    {
        return $this->plan === Plan::Trial
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }

    /**
     * Read-only: users can log in, see results and pay, but no scans or
     * content generation run. Also true in the minutes between a deadline
     * passing and the hourly billing task recording it.
     */
    public function isReadOnly(): bool
    {
        if ($this->trialHasExpired()) {
            return true;
        }

        return match ($this->subscription_status ?? SubscriptionStatus::Active) {
            SubscriptionStatus::Expired => true,
            SubscriptionStatus::Trialing => $this->trialHasExpired(),
            SubscriptionStatus::Canceled => $this->current_period_ends_at !== null && $this->current_period_ends_at->isPast(),
            SubscriptionStatus::PastDue => $this->grace_ends_at !== null && $this->grace_ends_at->isPast(),
            SubscriptionStatus::Active => false,
        };
    }

    public function hasPaidPeriod(): bool
    {
        return in_array($this->subscription_status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Canceled], true)
            && $this->plan->isPaid();
    }

    public function renewsAutomatically(): bool
    {
        return $this->payment_method === PaymentMethod::Automatic && $this->provider_subscription_id !== null;
    }

    /** A team on the free trial (not yet paid). */
    public function isOnTrial(): bool
    {
        return $this->plan === Plan::Trial;
    }

    public function awaitsTrialApproval(): bool
    {
        return $this->isOnTrial() && $this->trial_approval_required && $this->trial_approved_at === null;
    }

    public function hasScheduledChange(): bool
    {
        return $this->next_plan !== null || $this->next_interval !== null || $this->next_quantity !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
            'trial_ends_at' => 'datetime',
            'subscription_status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'hotel_quantity' => 'integer',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'is_comped' => 'boolean',
            'next_plan' => Plan::class,
            'next_interval' => BillingInterval::class,
            'next_quantity' => 'integer',
            'payment_method' => PaymentMethod::class,
            'trial_ending_notified_at' => 'datetime',
            'weekly_report_sent_at' => 'datetime',
            'trial_approval_required' => 'boolean',
            'trial_approved_at' => 'datetime',
            'plan_limits' => 'array',
            'private_price_usd' => 'integer',
            'private_price_idr' => 'integer',
            'openai_key' => 'encrypted',
            'openai_key_checked_at' => 'datetime',
            'openai_key_stopped_at' => 'datetime',
            'ai_budget_usd' => 'float',
        ];
    }
}
