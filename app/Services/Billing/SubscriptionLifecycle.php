<?php

namespace App\Services\Billing;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Mail\Billing\InvoiceReminder;
use App\Mail\Billing\SubscriptionExpired;
use App\Mail\Billing\SubscriptionPastDue;
use App\Mail\Billing\TrialEnding;
use App\Models\Invoice;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

/**
 * Time-based billing, run hourly by `citevanta:billing`:
 *
 *  1. "Trial ends in 2 days" email.
 *  2. Renewal invoice 7 days before the period ends (not for automatic renewal).
 *  3. Reminders 3 days before and on the due day.
 *  4. Period ended unpaid: past_due with a 7-day grace period (scans keep running).
 *  5. Trial over, grace over, canceled or comped period over: expired (read-only).
 *
 * Every step checks state first, so running it twice changes nothing.
 */
final class SubscriptionLifecycle
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly BillingProviders $providers,
        private readonly BillingMailer $mailer,
    ) {}

    /**
     * @return array<string, int> what happened, for the command output
     */
    public function run(): array
    {
        return [
            'trial_ending' => $this->trialEndingNotices(),
            'renewal_invoices' => $this->createRenewalInvoices(),
            'reminders' => $this->sendReminders(),
            'past_due' => $this->markPastDue(),
            'expired' => $this->expire(),
        ];
    }

    public function trialEndingNotices(): int
    {
        $teams = Team::query()
            ->where('subscription_status', SubscriptionStatus::Trialing->value)
            ->whereNull('trial_ending_notified_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays((int) config('citevanta.billing.trial_ending_notice_days', 2))])
            ->get();

        foreach ($teams as $team) {
            $team->forceFill(['trial_ending_notified_at' => now()])->save();
            $this->mailer->toOwners($team, fn () => new TrialEnding($team));
        }

        return $teams->count();
    }

    public function createRenewalInvoices(): int
    {
        $created = 0;

        Team::query()
            ->whereIn('subscription_status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->where('is_comped', false)
            ->where('cancel_at_period_end', false)
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', now()->addDays((int) config('citevanta.billing.renewal_days_before', 7)))
            ->each(function (Team $team) use (&$created): void {
                $before = $team->invoices()->count();
                $this->createRenewalInvoice($team);
                $created += (int) ($team->invoices()->count() > $before);
            });

        return $created;
    }

    /**
     * The invoice for the next period, or the open one already sent.
     */
    public function createRenewalInvoice(Team $team): ?Invoice
    {
        if (! $team->hasPaidPeriod() || $team->is_comped || $team->cancel_at_period_end
            || $team->current_period_ends_at === null || $team->renewsAutomatically()) {
            return null;
        }

        $open = $team->invoices()->open()->where('kind', InvoiceKind::Renewal->value)->latest('id')->first();
        if ($open !== null) {
            return $open;
        }

        $quote = $this->billing->renewalQuote($team);
        if ($quote === null) {
            Log::warning("Billing: no renewal price for team {$team->id}");

            return null;
        }

        $interval = $team->next_interval ?? $team->billing_interval;
        $start = $team->current_period_ends_at->copy();

        return $this->billing->createInvoice(
            $team,
            InvoiceKind::Renewal,
            $quote,
            $team->next_plan ?? $team->plan,
            $interval,
            dueAt: $start,
            periodStart: $start,
            periodEnd: $start->copy()->addMonthsNoOverflow($interval->months()),
            automatic: $team->payment_method === PaymentMethod::Automatic && $this->providers->current()->supportsAutomaticRenewal(),
        );
    }

    public function sendReminders(): int
    {
        $sent = 0;
        $reminderDays = (int) config('citevanta.billing.reminder_days_before', 3);

        Invoice::query()->with('team')
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Rejected->value])
            ->where('due_at', '<=', now()->addDays($reminderDays))
            ->where(fn ($query) => $query->whereNull('reminder_sent_at')->orWhereNull('due_reminder_sent_at'))
            ->each(function (Invoice $invoice) use (&$sent): void {
                $dueDayStarts = $invoice->due_at->copy()->setTimezone($invoice->team->timezoneName())->startOfDay();

                if (now()->greaterThanOrEqualTo($dueDayStarts)) {
                    if ($invoice->due_reminder_sent_at === null) {
                        $invoice->forceFill(['due_reminder_sent_at' => now(), 'reminder_sent_at' => $invoice->reminder_sent_at ?? now()])->save();
                        $this->mailer->toOwners($invoice->team, fn () => new InvoiceReminder($invoice, dueToday: true));
                        $sent++;
                    }

                    return;
                }

                if ($invoice->reminder_sent_at === null) {
                    $invoice->forceFill(['reminder_sent_at' => now()])->save();
                    $this->mailer->toOwners($invoice->team, fn () => new InvoiceReminder($invoice, dueToday: false));
                    $sent++;
                }
            });

        return $sent;
    }

    public function markPastDue(): int
    {
        $count = 0;

        Team::query()
            ->where('subscription_status', SubscriptionStatus::Active->value)
            ->where('is_comped', false)
            ->where('cancel_at_period_end', false)
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<', now())
            ->each(function (Team $team) use (&$count): void {
                // Give an automatic charge a day to arrive by webhook.
                if ($team->renewsAutomatically() && $team->current_period_ends_at->isAfter(now()->subDay())) {
                    return;
                }

                $count += (int) $this->pastDue($team);
            });

        return $count;
    }

    /**
     * The period ended unpaid (or an automatic charge failed): 7 days of grace.
     */
    public function pastDue(Team $team): bool
    {
        if ($team->subscription_status !== SubscriptionStatus::Active || $team->current_period_ends_at === null) {
            return false;
        }

        $graceFrom = $team->current_period_ends_at->isPast() ? $team->current_period_ends_at : now();

        $team->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => $graceFrom->copy()->addDays((int) config('citevanta.billing.grace_days', 7)),
        ])->save();

        $this->createRenewalInvoice($team);
        $this->mailer->toOwners($team, fn () => new SubscriptionPastDue($team));

        return true;
    }

    public function expire(): int
    {
        $teams = Team::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subscription_status', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '<', now()))
                ->orWhere(fn ($q) => $q->where('subscription_status', SubscriptionStatus::PastDue->value)->where('grace_ends_at', '<', now()))
                ->orWhere(fn ($q) => $q->where('subscription_status', SubscriptionStatus::Canceled->value)->where('current_period_ends_at', '<', now()))
                ->orWhere(fn ($q) => $q->where('subscription_status', SubscriptionStatus::Active->value)->where('is_comped', true)->where('current_period_ends_at', '<', now())))
            ->get();

        foreach ($teams as $team) {
            $wasTrial = $team->subscription_status === SubscriptionStatus::Trialing;

            if ($team->renewsAutomatically()) {
                $this->providers->forTeam($team)->cancelAutomaticRenewal($team);
            }

            $team->forceFill([
                'subscription_status' => SubscriptionStatus::Expired,
                'cancel_at_period_end' => false,
                'is_comped' => false,
                'provider_subscription_id' => null,
                'payment_method' => PaymentMethod::Manual,
            ])->save();

            $this->mailer->toOwners($team, fn () => new SubscriptionExpired($team, $wasTrial));
        }

        return $teams->count();
    }
}
