<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Mail\Billing\PaymentReceived;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Models\XenditEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Xendit provider with faked HTTP responses: payment links, automatic
 * renewal, webhook token check, idempotency, and switching providers.
 */
class XenditBillingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-callback-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        config([
            'citevanta.billing.provider' => 'xendit',
            'services.xendit.secret_key' => 'xnd_development_secret',
            'services.xendit.callback_token' => self::TOKEN,
        ]);
    }

    private function trialOwner(): User
    {
        return User::factory()->withTeam(Team::factory()->plan(Plan::Trial)->create())->create();
    }

    private function fakeSession(string $id = 'ps-1', string $url = 'https://checkout-staging.xendit.co/ps-1'): void
    {
        Http::fake(['api.xendit.co/sessions' => Http::response([
            'payment_session_id' => $id, 'status' => 'ACTIVE', 'payment_link_url' => $url,
        ], 201)]);
    }

    private function webhook(array $payload, ?string $token = self::TOKEN)
    {
        return $this->postJson(route('webhooks.xendit'), $payload, $token === null ? [] : ['x-callback-token' => $token]);
    }

    private function choose(User $owner, string $method = 'manual')
    {
        return $this->actingAs($owner)->post(route('billing.plan'), [
            'plan' => 'starter', 'interval' => 'monthly', 'hotels' => 1, 'currency' => 'usd', 'payment_method' => $method,
        ]);
    }

    public function test_an_invoice_gets_a_xendit_payment_link_and_the_paid_webhook_activates_the_plan(): void
    {
        $this->fakeSession();
        $owner = $this->trialOwner();

        $this->choose($owner);

        $invoice = Invoice::sole();
        $this->assertSame(['xendit', 'ps-1', 4900, 0], [$invoice->provider, $invoice->provider_reference, $invoice->amount_due, $invoice->unique_code]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.co/sessions'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_secret:'))
            && $request['session_type'] === 'PAY' && $request['mode'] === 'PAYMENT_LINK'
            && $request['amount'] === 49 && $request['currency'] === 'USD'
            && str_starts_with($request['reference_id'], $invoice->number.'-'));

        $this->actingAs($owner)->get(route('billing.invoices.pay', $invoice))->assertRedirect('https://checkout-staging.xendit.co/ps-1');

        $this->webhook([
            'event' => 'payment_session.completed',
            'data' => ['payment_session_id' => 'ps-1', 'reference_id' => $invoice->number.'-abc123', 'session_type' => 'PAY', 'status' => 'COMPLETED', 'payment_id' => 'py-1'],
        ])->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('py-1', $invoice->fresh()->provider_payment_id);
        $this->assertSame([Plan::Starter, SubscriptionStatus::Active], [$owner->currentTeam->fresh()->plan, $owner->currentTeam->fresh()->subscription_status]);
        $this->assertSame('processed', XenditEvent::sole()->status);
    }

    public function test_webhooks_without_the_right_callback_token_are_refused_and_not_stored(): void
    {
        $payload = ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-1']];

        $this->webhook($payload, null)->assertUnauthorized();
        $this->webhook($payload, 'wrong-token')->assertUnauthorized();
        config(['services.xendit.callback_token' => '']);
        $this->webhook($payload, '')->assertUnauthorized();

        $this->assertSame(0, XenditEvent::count());
    }

    public function test_the_same_webhook_twice_is_acknowledged_and_changes_nothing(): void
    {
        $this->fakeSession();
        $owner = $this->trialOwner();
        $this->choose($owner);
        $payload = ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-1', 'session_type' => 'PAY', 'status' => 'COMPLETED', 'payment_id' => 'py-1']];

        $this->webhook($payload)->assertOk();
        $endsAt = $owner->currentTeam->fresh()->current_period_ends_at->toDateTimeString();
        $this->travel(1)->hours();
        $this->webhook($payload)->assertOk()->assertJson(['message' => 'Already received.']);

        $this->assertSame(1, XenditEvent::count());
        $this->assertSame($endsAt, $owner->currentTeam->fresh()->current_period_ends_at->toDateTimeString());
        Mail::assertQueued(PaymentReceived::class, 1);
    }

    public function test_automatic_renewal_authorises_then_each_cycle_pays_a_period_and_cancel_stops_the_plan(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 02:00:00', 'UTC'));
        Http::fake([
            'api.xendit.co/sessions' => Http::response(['payment_session_id' => 'ps-sub', 'status' => 'ACTIVE', 'payment_link_url' => 'https://checkout-staging.xendit.co/ps-sub'], 201),
            'api.xendit.co/recurring/plans/repl-1/deactivate' => Http::response(['id' => 'repl-1', 'status' => 'INACTIVE']),
        ]);
        $owner = $this->trialOwner();
        $team = $owner->currentTeam;

        $this->choose($owner, 'automatic')->assertRedirect('https://checkout-staging.xendit.co/ps-sub');
        Http::assertSent(fn (Request $request) => $request['session_type'] === 'SUBSCRIPTION'
            && $request['subscription']['schedule']['interval'] === 'MONTH' && $request['subscription']['schedule']['interval_count'] === 1);
        $invoice = Invoice::sole();
        $this->assertTrue($invoice->automatic);
        Mail::assertNothingQueued();

        $this->webhook(['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-sub', 'session_type' => 'SUBSCRIPTION', 'status' => 'COMPLETED', 'recurring_plan_id' => 'repl-1']])->assertOk();
        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->status, 'paid by the first cycle, not by the session');
        $this->assertTrue($team->fresh()->renewsAutomatically());

        $this->webhook(['event' => 'recurring.cycle.succeeded', 'data' => ['id' => 'recy-1', 'plan_id' => 'repl-1', 'cycle_number' => 1, 'status' => 'SUCCEEDED', 'amount' => 49, 'currency' => 'USD']])->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('2026-12-01 02:00:00', $team->fresh()->current_period_ends_at->toDateTimeString());

        // No renewal invoice for automatic renewals; the next cycle pays the next period.
        $this->travelTo(Carbon::parse('2026-11-26 02:00:00', 'UTC'));
        app(SubscriptionLifecycle::class)->run();
        $this->assertSame(1, Invoice::count());

        $this->travelTo(Carbon::parse('2026-12-01 02:10:00', 'UTC'));
        $this->webhook(['event' => 'recurring.cycle.succeeded', 'data' => ['id' => 'recy-2', 'plan_id' => 'repl-1', 'cycle_number' => 2, 'status' => 'SUCCEEDED', 'amount' => 49, 'currency' => 'USD']])->assertOk();
        $this->assertSame(2, Invoice::where('status', InvoiceStatus::Paid->value)->count());
        $this->assertSame('2027-01-01 02:00:00', $team->fresh()->current_period_ends_at->toDateTimeString());

        $this->actingAs($owner->fresh())->post(route('billing.cancel'))->assertSessionHas('status');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.co/recurring/plans/repl-1/deactivate'
            && $request->hasHeader('api-version', '2026-01-01'));
        $this->assertSame([SubscriptionStatus::Canceled, null], [$team->fresh()->subscription_status, $team->fresh()->provider_subscription_id]);
    }

    public function test_a_failed_cycle_after_the_period_ended_makes_the_team_past_due(): void
    {
        $team = Team::factory()->paid(Plan::Pro, BillingInterval::Monthly, now()->subHour())->create([
            'payment_method' => PaymentMethod::Automatic, 'billing_provider' => 'xendit', 'provider_subscription_id' => 'repl-9',
        ]);

        $this->webhook(['event' => 'recurring.cycle.failed', 'data' => ['id' => 'recy-9', 'plan_id' => 'repl-9', 'status' => 'FAILED']])->assertOk();

        $this->assertSame(SubscriptionStatus::PastDue, $team->fresh()->subscription_status);
    }

    public function test_switching_to_xendit_keeps_open_manual_invoices_manual_and_the_next_renewal_uses_xendit(): void
    {
        config(['citevanta.billing.provider' => 'manual']);
        $owner = $this->trialOwner();
        $this->choose($owner);
        $manual = Invoice::sole();
        app(BillingService::class)->markPaid($manual);

        config(['citevanta.billing.provider' => 'xendit']);
        $this->fakeSession('ps-renew', 'https://checkout-staging.xendit.co/ps-renew');
        $this->actingAs($owner)->get(route('billing.invoices.show', $manual))->assertOk();

        $this->travel(25)->days();
        app(SubscriptionLifecycle::class)->run();

        $renewal = Invoice::latest('id')->first();
        $this->assertSame(['manual', 'xendit'], [$manual->fresh()->provider, $renewal->provider]);
        $this->assertSame('https://checkout-staging.xendit.co/ps-renew', $renewal->payment_url);
        $this->actingAs($owner)->get(route('billing.invoices.show', $renewal))->assertSee('Pay online')->assertDontSee('Pay by bank transfer');
    }

    public function test_a_xendit_outage_still_creates_the_invoice_and_pay_now_retries(): void
    {
        Http::fake(['api.xendit.co/sessions' => Http::sequence()
            ->push(['error_code' => 'SERVER_ERROR', 'message' => 'down'], 503)
            ->push(['payment_session_id' => 'ps-2', 'payment_link_url' => 'https://checkout-staging.xendit.co/ps-2'], 201)]);
        $owner = $this->trialOwner();

        $this->choose($owner);
        $invoice = Invoice::sole();
        $this->assertNull($invoice->payment_url);

        $this->actingAs($owner)->get(route('billing.invoices.pay', $invoice))->assertRedirect('https://checkout-staging.xendit.co/ps-2');
    }
}
