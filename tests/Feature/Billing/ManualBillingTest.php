<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Enums\TeamRole;
use App\Mail\Billing\InvoiceCreated;
use App\Mail\Billing\PaymentReceived;
use App\Mail\Billing\PaymentRejected;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\Plans\PlanGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Manual bank transfer: invoice, unique amount, "I have paid" with a private
 * proof, admin mark as paid / reject, and activation.
 */
class ManualBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['citevanta.billing.provider' => 'manual']);
    }

    private function trialOwner(): User
    {
        return User::factory()->withTeam(Team::factory()->plan(Plan::Trial)->create())->create();
    }

    /** @return array<string, string|int> */
    private function plan(array $overrides = []): array
    {
        return $overrides + ['plan' => 'starter', 'interval' => 'monthly', 'hotels' => 1, 'currency' => 'usd', 'payment_method' => 'manual'];
    }

    public function test_choosing_a_plan_creates_an_invoice_with_a_unique_transfer_amount_and_emails_it(): void
    {
        $owner = $this->trialOwner();

        $response = $this->actingAs($owner)->post(route('billing.plan'), $this->plan());

        $invoice = Invoice::sole();
        $response->assertRedirect(route('billing.invoices.show', $invoice));
        $this->assertSame('INV-'.now()->format('Ym').'-0001', $invoice->number);
        $this->assertSame([InvoiceKind::New, InvoiceStatus::Pending, 'manual'], [$invoice->kind, $invoice->status, $invoice->provider]);
        $this->assertSame(4900, $invoice->total);
        $this->assertGreaterThanOrEqual(1, $invoice->unique_code);
        $this->assertLessThanOrEqual(99, $invoice->unique_code);
        $this->assertSame(4900 + $invoice->unique_code, $invoice->amount_due);
        $this->assertTrue($invoice->due_at->isSameDay(now()->addDays(7)));
        Mail::assertQueued(InvoiceCreated::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->invoice->is($invoice));

        // The team stays on the trial until the invoice is paid.
        $this->assertSame(Plan::Trial, $owner->currentTeam->fresh()->plan);

        $this->actingAs($owner)->get(route('billing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->format($invoice->amount_due))
            ->assertSee(config('citevanta.billing.bank.account_number'))
            ->assertSee('I have paid');
    }

    public function test_open_invoices_with_the_same_price_get_different_amounts_and_numbers_count_up(): void
    {
        foreach (range(1, 5) as $i) {
            $this->actingAs($this->trialOwner())->post(route('billing.plan'), $this->plan());
        }

        $invoices = Invoice::orderBy('id')->get();
        $this->assertCount(5, $invoices->pluck('amount_due')->unique());
        $this->assertSame('INV-'.now()->format('Ym').'-0005', $invoices->last()->number);
    }

    public function test_i_have_paid_stores_the_proof_privately_and_admins_mark_it_paid_which_activates_the_plan(): void
    {
        $this->freezeTime();
        $owner = $this->trialOwner();
        $this->actingAs($owner)->post(route('billing.plan'), $this->plan(['plan' => 'pro']));
        $invoice = Invoice::sole();

        $this->actingAs($owner)->post(route('billing.invoices.paid', $invoice), [
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertRedirect(route('billing.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Submitted, $invoice->status);
        Storage::disk('local')->assertExists($invoice->proof_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'proofs are never public');
        $this->actingAs($owner)->get(route('billing.invoices.proof', $invoice))->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.billing'))->assertOk()->assertSee($invoice->number)->assertSee(route('billing.invoices.proof', $invoice), false);
        $this->actingAs($admin)->get(route('billing.invoices.proof', $invoice))->assertOk();

        $this->actingAs($admin)->post(route('admin.billing.paid', $invoice), ['paid_on' => now()->toDateString(), 'note' => 'BCA statement'])->assertRedirect();

        $team = $owner->currentTeam->fresh();
        $this->assertSame([Plan::Pro, SubscriptionStatus::Active], [$team->plan, $team->subscription_status]);
        $this->assertSame(now()->addMonthNoOverflow()->toDateTimeString(), $team->current_period_ends_at->toDateTimeString());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame($admin->id, $invoice->fresh()->marked_paid_by);
        Mail::assertQueued(PaymentReceived::class, fn ($mail) => $mail->hasTo($owner->email));

        $hotel = Hotel::factory()->for($team)->create(['setup_completed_at' => now()]);
        $this->assertTrue(app(PlanGuard::class)->canScan($hotel));
    }

    public function test_marking_an_invoice_paid_twice_applies_it_once(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->post(route('billing.plan'), $this->plan());
        $invoice = Invoice::sole();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.billing.paid', $invoice), ['paid_on' => now()->toDateString()]);
        $endsAt = $owner->currentTeam->fresh()->current_period_ends_at;
        $this->actingAs($admin)->post(route('admin.billing.paid', $invoice), ['paid_on' => now()->toDateString()])->assertSessionHas('error');

        $this->assertTrue($owner->currentTeam->fresh()->current_period_ends_at->equalTo($endsAt));
        Mail::assertQueued(PaymentReceived::class, 1);
    }

    public function test_rejecting_a_payment_emails_the_owner_who_can_submit_again(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->post(route('billing.plan'), $this->plan());
        $invoice = Invoice::sole();
        $this->actingAs($owner)->post(route('billing.invoices.paid', $invoice));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.billing.reject', $invoice), ['reason' => 'No transfer of this amount on 20 Nov.'])
            ->assertRedirect();

        $this->assertSame(InvoiceStatus::Rejected, $invoice->fresh()->status);
        Mail::assertQueued(PaymentRejected::class, fn ($mail) => $mail->hasTo($owner->email));
        $this->actingAs($owner)->get(route('billing.invoices.show', $invoice))->assertSee('No transfer of this amount on 20 Nov.');

        $this->actingAs($owner)->post(route('billing.invoices.paid', $invoice));
        $this->assertSame(InvoiceStatus::Submitted, $invoice->fresh()->status);
    }

    public function test_the_proof_must_be_an_image_or_pdf_of_at_most_5_mb(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->post(route('billing.plan'), $this->plan());
        $invoice = Invoice::sole();

        $this->actingAs($owner)->post(route('billing.invoices.paid', $invoice), ['proof' => UploadedFile::fake()->create('run.exe', 10)])
            ->assertSessionHasErrors(['proof' => 'Upload an image (JPG, PNG, WebP) or a PDF.']);
        $this->actingAs($owner)->post(route('billing.invoices.paid', $invoice), ['proof' => UploadedFile::fake()->create('scan.pdf', 5121, 'application/pdf')])
            ->assertSessionHasErrors(['proof' => 'The file can be at most 5 MB.']);

        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->status);
    }

    public function test_invoices_and_proofs_are_only_for_the_teams_owners_and_admins(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->post(route('billing.plan'), $this->plan());
        $invoice = Invoice::sole();
        $this->actingAs($owner)->post(route('billing.invoices.paid', $invoice), ['proof' => UploadedFile::fake()->image('t.png')]);

        $otherOwner = $this->trialOwner();
        $member = User::factory()->create();
        $owner->currentTeam->addMember($member, TeamRole::Member);
        $member->switchTeam($owner->currentTeam);

        foreach ([$otherOwner, $member] as $user) {
            $this->actingAs($user)->get(route('billing.invoices.show', $invoice))->assertForbidden();
            $this->actingAs($user)->get(route('billing.invoices.proof', $invoice))->assertForbidden();
            $this->actingAs($user)->post(route('billing.invoices.paid', $invoice))->assertForbidden();
        }

        $this->actingAs($member)->get(route('settings.billing'))->assertForbidden();
        $this->actingAs($member)->post(route('billing.plan'), $this->plan())->assertForbidden();
        $this->actingAs($otherOwner)->get(route('settings.billing'))->assertOk()->assertDontSee($invoice->number);
        $this->actingAs($owner)->post(route('admin.billing.paid', $invoice), ['paid_on' => now()->toDateString()])->assertForbidden();
        $this->assertSame(InvoiceStatus::Submitted, $invoice->fresh()->status);
    }

    public function test_indonesian_teams_pay_in_idr_with_a_rupiah_unique_code(): void
    {
        config(['citevanta.pricing.prices.starter.idr' => 790000, 'citevanta.pricing.prices.pro.idr' => 1590000]);
        $owner = $this->trialOwner();
        Hotel::factory()->for($owner->currentTeam)->create(['country' => 'Indonesia']);

        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee('<option value="idr" selected', false);
        $this->actingAs($owner)->post(route('billing.plan'), $this->plan(['currency' => 'idr']));

        $invoice = Invoice::sole();
        $this->assertSame([790000, 'idr'], [$invoice->total, $invoice->currency]);
        $this->assertGreaterThanOrEqual(1, $invoice->unique_code);
        $this->assertLessThanOrEqual(999, $invoice->unique_code);
        $this->assertStringStartsWith('Rp 79', $invoice->format($invoice->amount_due));
    }

    public function test_idr_cannot_be_chosen_until_idr_prices_are_set(): void
    {
        $this->actingAs($this->trialOwner())->post(route('billing.plan'), $this->plan(['currency' => 'idr']))->assertSessionHasErrors('currency');

        $this->assertSame(0, Invoice::count());
    }

    public function test_annual_is_10_months_with_group_discount_and_tax_on_top_or_included(): void
    {
        config(['citevanta.billing.tax.rate' => 11, 'citevanta.billing.tax.prices_include_tax' => false]);
        $owner = $this->trialOwner();

        $this->actingAs($owner)->post(route('billing.plan'), $this->plan(['plan' => 'pro', 'interval' => 'annual', 'hotels' => 5]));

        // 99 x 5 hotels x 10 months = 4950, minus 15% group discount = 4207.50, plus 11% = 4670.33
        $invoice = Invoice::sole();
        $this->assertSame([495000, 15, 74250, 46283, 467033], [$invoice->subtotal, $invoice->discount_percent, $invoice->discount, $invoice->tax, $invoice->total]);
        $this->actingAs($owner)->get(route('billing.invoices.show', $invoice))->assertSee('PPN 11%')->assertSee('Volume discount (15%)');

        config(['citevanta.billing.tax.prices_include_tax' => true]);
        $this->actingAs($this->trialOwner())->post(route('billing.plan'), $this->plan());
        $included = Invoice::latest('id')->first();
        // $49 includes 11%: tax = 49 - 49 / 1.11 = 4.86
        $this->assertSame([4900, 486], [$included->total, $included->tax]);
        $this->actingAs(User::query()->latest('id')->first())->get(route('billing.invoices.show', $included))->assertSee('of which PPN 11%');
    }

    public function test_owners_cannot_pay_for_fewer_hotels_than_they_have(): void
    {
        $owner = $this->trialOwner();
        Hotel::factory()->count(2)->for($owner->currentTeam)->create();

        $this->actingAs($owner)->post(route('billing.plan'), $this->plan(['hotels' => 1]))
            ->assertSessionHas('error', 'Your team has 2 hotels. Choose at least that many, or remove a hotel first.');

        $this->assertSame(0, Invoice::count());
    }
}
