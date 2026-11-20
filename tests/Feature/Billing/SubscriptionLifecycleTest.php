<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingInterval;
use App\Enums\ContentType;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Mail\Billing\InvoiceCreated;
use App\Mail\Billing\InvoiceReminder;
use App\Mail\Billing\PaymentReceived;
use App\Mail\Billing\PaymentRejected;
use App\Mail\Billing\SubscriptionExpired;
use App\Mail\Billing\SubscriptionPastDue;
use App\Mail\Billing\TrialEnding;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Recommendation;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycle;
use App\Services\Content\ContentLimitReached;
use App\Services\Content\ContentService;
use App\Services\Plans\PlanGuard;
use App\Services\Scanning\ManualScanGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Renewals, reminders, past_due grace, expiry (read-only), upgrades,
 * downgrades, cancel/resume, trial ending, and admin trial/comp actions.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['citevanta.billing.provider' => 'manual']);
        $this->travelTo(Carbon::parse('2026-11-01 02:00:00', 'UTC'));
    }

    private function paidOwner(Plan $plan = Plan::Starter, string $endsAt = '2026-12-01 02:00:00', int $hotels = 1): User
    {
        $team = Team::factory()->paid($plan, BillingInterval::Monthly, Carbon::parse($endsAt, 'UTC'), 'usd', $hotels)->create();

        return User::factory()->withTeam($team)->create();
    }

    private function lifecycle(): SubscriptionLifecycle
    {
        return app(SubscriptionLifecycle::class);
    }

    private function pay(Invoice $invoice): void
    {
        app(BillingService::class)->markPaid($invoice);
    }

    public function test_the_renewal_invoice_is_created_7_days_before_the_period_ends_with_reminders_at_3_days_and_on_the_due_day(): void
    {
        $owner = $this->paidOwner();

        $this->travelTo(Carbon::parse('2026-11-23 01:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->assertSame(0, Invoice::count(), 'not yet: 8 days left');

        $this->travelTo(Carbon::parse('2026-11-24 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->lifecycle()->run();
        $invoice = Invoice::sole();
        $this->assertSame([InvoiceKind::Renewal, '2026-12-01 02:00:00'], [$invoice->kind, $invoice->due_at->toDateTimeString()]);
        $this->assertSame('2026-12-01', $invoice->period_starts_at->toDateString());
        Mail::assertQueued(InvoiceCreated::class, 1);

        $this->travelTo(Carbon::parse('2026-11-28 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->lifecycle()->run();
        Mail::assertQueued(InvoiceReminder::class, fn ($mail) => ! $mail->dueToday && $mail->hasTo($owner->email));

        // 1 Dec 00:30 Makassar is the due day.
        $this->travelTo(Carbon::parse('2026-11-30 16:30:00', 'UTC'));
        $this->lifecycle()->run();
        $this->lifecycle()->run();
        Mail::assertQueued(InvoiceReminder::class, fn ($mail) => $mail->dueToday);
        Mail::assertQueued(InvoiceReminder::class, 2);
    }

    public function test_paying_the_renewal_extends_the_period_from_the_old_end_date(): void
    {
        $owner = $this->paidOwner();
        $this->travelTo(Carbon::parse('2026-11-25 03:00:00', 'UTC'));
        $this->lifecycle()->run();

        $this->pay(Invoice::sole());

        $team = $owner->currentTeam->fresh();
        $this->assertSame('2027-01-01 02:00:00', $team->current_period_ends_at->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Active, $team->subscription_status);
    }

    public function test_unpaid_teams_are_past_due_for_7_days_with_scans_running_then_expire_read_only_and_paying_restores_everything(): void
    {
        $owner = $this->paidOwner();
        $team = $owner->currentTeam;
        $hotel = Hotel::factory()->for($team)->create(['setup_completed_at' => now()]);
        $recommendation = Recommendation::factory()->for($hotel)->create();

        $this->travelTo(Carbon::parse('2026-12-01 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $team->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $team->subscription_status);
        $this->assertSame('2026-12-08 02:00:00', $team->grace_ends_at->toDateTimeString());
        $this->assertTrue(app(PlanGuard::class)->canScan($hotel->fresh()), 'scans keep running during the grace period');
        Mail::assertQueued(SubscriptionPastDue::class, 1);
        $this->actingAs($owner)->get(route('settings.team'))->assertSee('Payment overdue');

        $this->travelTo(Carbon::parse('2026-12-08 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $team->refresh();
        $this->assertSame(SubscriptionStatus::Expired, $team->subscription_status);
        Mail::assertQueued(SubscriptionExpired::class, fn ($mail) => ! $mail->wasTrial);

        // Read-only: no scans (scheduled or manual), no content, but results stay visible.
        $hotel->refresh();
        $this->assertFalse(app(PlanGuard::class)->canScan($hotel));
        $this->assertSame('Your subscription has expired. Renew it on Settings > Billing to scan again.', app(ManualScanGate::class)->check($hotel, $owner)->reason);
        try {
            app(ContentService::class)->request($recommendation, ContentType::Faq, 'en', $owner);
            $this->fail('content generation must be blocked');
        } catch (ContentLimitReached $e) {
            $this->assertStringContainsString('subscription has expired', $e->getMessage());
        }
        $this->actingAs($owner)->get(route('hotels.index'))->assertOk()->assertSee($hotel->name);
        $this->actingAs($owner)->get(route('settings.team'))->assertSee('The account is read-only');

        $this->pay($team->invoices()->open()->sole());

        $team->refresh();
        $this->assertSame(SubscriptionStatus::Active, $team->subscription_status);
        $this->assertSame('2027-01-08 03:00:00', $team->current_period_ends_at->toDateTimeString(), 'a new period from the payment date');
        $this->assertTrue(app(PlanGuard::class)->canScan($hotel->fresh()));
    }

    public function test_trials_get_a_notice_2_days_before_the_end_and_expire_when_unpaid(): void
    {
        $team = Team::factory()->create(['plan' => Plan::Trial, 'subscription_status' => SubscriptionStatus::Trialing, 'trial_ends_at' => Carbon::parse('2026-11-04 02:00:00', 'UTC')]);
        $owner = User::factory()->withTeam($team)->create();

        $this->lifecycle()->run();
        Mail::assertNotQueued(TrialEnding::class);

        $this->travelTo(Carbon::parse('2026-11-02 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->lifecycle()->run();
        Mail::assertQueued(TrialEnding::class, 1);

        $this->travelTo(Carbon::parse('2026-11-04 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->assertSame(SubscriptionStatus::Expired, $team->fresh()->subscription_status);
        Mail::assertQueued(SubscriptionExpired::class, fn ($mail) => $mail->wasTrial && $mail->hasTo($owner->email));
    }

    public function test_an_upgrade_bills_the_pro_rata_difference_and_starts_when_paid(): void
    {
        $owner = $this->paidOwner(Plan::Starter, '2026-12-01 02:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 02:00:00', 'UTC')); // 15 of 30 days left

        $this->actingAs($owner)->post(route('billing.plan'), ['plan' => 'pro', 'interval' => 'monthly', 'hotels' => 1, 'currency' => 'usd', 'payment_method' => 'manual']);

        $invoice = Invoice::sole();
        $this->assertSame([InvoiceKind::Upgrade, 2500], [$invoice->kind, $invoice->total], '($99 - $49) x 15/30');
        $this->assertSame(Plan::Starter, $owner->currentTeam->fresh()->plan, 'not before payment');

        $this->pay($invoice);

        $team = $owner->currentTeam->fresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame('2026-12-01 02:00:00', $team->current_period_ends_at->toDateTimeString(), 'the period does not change');
    }

    public function test_downgrades_and_hotel_count_changes_apply_at_the_next_renewal(): void
    {
        $owner = $this->paidOwner(Plan::Pro, '2026-12-01 02:00:00', hotels: 2);

        $this->actingAs($owner)->post(route('billing.plan'), ['plan' => 'starter', 'interval' => 'monthly', 'hotels' => 3, 'currency' => 'usd', 'payment_method' => 'manual'])
            ->assertSessionHas('status', 'Your changes apply at the next renewal on 1 Dec 2026.');

        $team = $owner->currentTeam->fresh();
        $this->assertSame([Plan::Pro, 2, Plan::Starter, 3], [$team->plan, $team->hotel_quantity, $team->next_plan, $team->next_quantity]);
        $this->assertSame(0, Invoice::count());

        $this->travelTo(Carbon::parse('2026-11-25 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $renewal = Invoice::sole();
        $this->assertSame([Plan::Starter, 3, 14700], [$renewal->plan, $renewal->hotels, $renewal->total]);

        $this->pay($renewal);
        $team->refresh();
        $this->assertSame([Plan::Starter, 3, null, null], [$team->plan, $team->hotel_quantity, $team->next_plan, $team->next_quantity]);
        $this->assertSame(3, $team->limits()->hotels);
    }

    public function test_cancel_runs_to_the_end_of_the_period_and_resume_undoes_it(): void
    {
        $owner = $this->paidOwner();
        $this->travelTo(Carbon::parse('2026-11-25 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $renewal = Invoice::sole();

        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee('Change plan')->assertSee('Cancel subscription')->assertSee($renewal->number);
        $this->actingAs($owner)->post(route('billing.cancel'))->assertRedirect(route('settings.billing'));
        $team = $owner->currentTeam->fresh();
        $this->assertSame(SubscriptionStatus::Canceled, $team->subscription_status);
        $this->assertSame(InvoiceStatus::Void, $renewal->fresh()->status);
        $this->assertFalse($team->isReadOnly());

        $this->actingAs($owner)->post(route('billing.resume'))->assertSessionHas('status', 'Your subscription continues.');
        $this->assertSame(SubscriptionStatus::Active, $team->fresh()->subscription_status);

        $this->actingAs($owner)->post(route('billing.cancel'));
        $this->travelTo(Carbon::parse('2026-12-01 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->assertSame(SubscriptionStatus::Expired, $team->fresh()->subscription_status);
        $this->assertSame(0, Invoice::open()->count(), 'no renewal invoice after cancelling');

        $this->actingAs($owner)->post(route('billing.resume'))->assertSessionHas('error', 'The subscription has ended. Choose a plan to start again.');
    }

    public function test_with_automatic_renewal_a_scheduled_change_updates_the_amount_charged_next(): void
    {
        config(['citevanta.billing.provider' => 'fake']);
        $owner = $this->paidOwner(Plan::Pro);
        $owner->currentTeam->forceFill(['payment_method' => 'automatic', 'billing_provider' => 'fake', 'provider_subscription_id' => 'plan-1'])->save();

        $this->actingAs($owner)->post(route('billing.plan'), ['plan' => 'starter', 'interval' => 'annual', 'hotels' => 1, 'currency' => 'usd', 'payment_method' => 'automatic']);

        $this->assertSame([['team' => $owner->currentTeam->id, 'amount' => 49000, 'months' => 12]], app(FakeBillingProvider::class)->callsOf('update'));

        $this->travelTo(Carbon::parse('2026-11-25 03:00:00', 'UTC'));
        $this->lifecycle()->run();
        $this->assertSame(0, Invoice::count(), 'the provider charges automatic renewals; no invoice to send');
    }

    public function test_admins_can_extend_a_trial_and_comp_a_plan_which_has_no_mrr(): void
    {
        $team = Team::factory()->expiredTrial()->create();
        User::factory()->withTeam($team)->create();
        $paying = $this->paidOwner(Plan::Pro)->currentTeam;
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.teams.extend-trial', $team), ['days' => 5])->assertRedirect();
        $team->refresh();
        $this->assertSame([SubscriptionStatus::Trialing, '2026-11-06'], [$team->subscription_status, $team->trial_ends_at->toDateString()]);
        $this->assertFalse($team->isReadOnly());

        $this->actingAs($admin)->post(route('admin.teams.comp', $team), ['plan' => 'pro', 'hotels' => 2, 'until' => '2027-01-31'])->assertRedirect();
        $team->refresh();
        $this->assertSame([Plan::Pro, SubscriptionStatus::Active, true, 2], [$team->plan, $team->subscription_status, $team->is_comped, $team->limits()->hotels]);

        $this->actingAs($admin)->get(route('admin.teams'))->assertOk()->assertSeeInOrder(['Total MRR', '$99'])->assertSee('(free)', false);
        $this->assertNull(app(BillingService::class)->mrr($team));
        $this->assertSame(['currency' => 'usd', 'amount' => 9900], app(BillingService::class)->mrr($paying));

        $this->actingAs($paying->owners()->first())->post(route('admin.teams.comp', $team), ['plan' => 'pro', 'hotels' => 1])->assertForbidden();
    }

    public function test_every_billing_email_renders_with_the_invoice_details(): void
    {
        $team = $this->paidOwner()->currentTeam;
        $invoice = Invoice::factory()->for($team)->create(['rejected_reason' => 'Amount not found']);
        $mails = [
            new InvoiceCreated($invoice), new InvoiceReminder($invoice, dueToday: true), new PaymentReceived($invoice),
            new PaymentRejected($invoice), new SubscriptionPastDue($team), new SubscriptionExpired($team), new TrialEnding($team),
        ];

        foreach ($mails as $mail) {
            $this->assertStringContainsString(config('app.name'), $mail->render());
        }

        $this->assertStringContainsString($invoice->format($invoice->amount_due), $mails[0]->render());
        $this->assertStringContainsString(config('citevanta.billing.bank.account_number'), $mails[0]->render());
        $this->assertStringContainsString('Amount not found', $mails[3]->render());
    }

    public function test_annual_mrr_is_the_yearly_price_divided_by_12(): void
    {
        $team = Team::factory()->paid(Plan::Starter, BillingInterval::Annual, now()->addMonths(6), 'usd', 1)->create();

        $this->assertSame(['currency' => 'usd', 'amount' => 4083], app(BillingService::class)->mrr($team), '$490 / 12 = $40.83');
    }
}
