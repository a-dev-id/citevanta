<?php

namespace Tests\Feature\Site;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use App\Mail\AuditSlotsOpen;
use App\Models\AiUsage;
use App\Models\Audit;
use App\Models\AuditWaitlist;
use App\Services\Audits\AuditWaitlistNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Free audit: 5 new audits a day, the audits money cap, the waitlist, and
 * Cloudflare Turnstile on the public forms.
 */
class AuditBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function submit(string $hotel, string $email, string $ip, array $extra = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('audit.store'), $extra + [
            'hotel_name' => $hotel, 'city' => 'Ubud', 'country' => 'Indonesia', 'email' => $email, 'locale' => 'en',
        ]);
    }

    public function test_five_new_audits_a_day_then_fully_booked_with_a_waitlist_offer(): void
    {
        foreach (range(1, 5) as $i) {
            $this->submit("Hotel {$i}", "lead{$i}@x.test", "10.0.0.{$i}")->assertRedirect();
        }

        $this->from('/free-audit')->submit('Hotel 6', 'late@x.test', '10.0.0.9')
            ->assertSessionHasErrors(['audit' => "Today's free audits are fully booked, please try again tomorrow."], null, 'audit')
            ->assertSessionHas('audit_full', true);
        $this->assertSame(5, Audit::count());

        $this->withSession(['audit_full' => true, '_old_input' => ['email' => 'late@x.test']])->get('/free-audit')
            ->assertSee('Email me when slots open')->assertSee('value="late@x.test"', false);

        $this->post(route('audit.waitlist'), ['email' => 'late@x.test', 'locale' => 'en'])->assertSessionHas('audit_waitlisted');
        $this->assertSame(['late@x.test'], AuditWaitlist::pluck('email')->all());

        $this->travel(1)->days();
        $this->assertSame(1, app(AuditWaitlistNotifier::class)->notify());
        $this->assertSame(0, app(AuditWaitlistNotifier::class)->notify(), 'emailed once');
        Mail::assertQueued(AuditSlotsOpen::class, fn ($mail) => $mail->hasTo('late@x.test'));
    }

    public function test_the_audits_money_cap_also_books_audits_out(): void
    {
        config(['citevanta.budget.buckets.audits' => 10]);
        AiUsage::create(['bucket' => AiBucket::Audits, 'kind' => AiCallKind::Answer, 'model' => 'gpt-5-mini', 'cost_usd' => 9.95, 'created_at' => now()]);

        $this->submit('Hotel 1', 'a@x.test', '10.0.0.1')->assertSessionHas('audit_full', true);
        $this->assertSame(0, Audit::count());
        $this->assertSame(0, app(AuditWaitlistNotifier::class)->notify(), 'no waitlist emails while the money cap is full');
    }

    public function test_turnstile_is_checked_server_side_on_the_audit_and_contact_forms_when_configured(): void
    {
        $this->get('/free-audit')->assertDontSee('cf-turnstile', false);

        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true])
            ->push(['success' => false])]);

        $this->get('/free-audit')->assertSee('data-sitekey="site-key"', false);

        $this->submit('Hotel 1', 'a@x.test', '10.0.0.1')->assertSessionHasErrors('cf-turnstile-response', null, 'audit');
        $this->submit('Hotel 1', 'a@x.test', '10.0.0.1', ['cf-turnstile-response' => 'bad'])->assertSessionHasErrors('cf-turnstile-response', null, 'audit');
        $this->submit('Hotel 1', 'a@x.test', '10.0.0.1', ['cf-turnstile-response' => 'good'])->assertSessionHasNoErrors();
        $this->assertSame(1, Audit::count());

        $this->post(route('contact.send'), ['name' => 'A', 'email' => 'a@x.test', 'message' => 'Hello, I have a question.', 'cf-turnstile-response' => 'bad'])
            ->assertSessionHasErrors(['cf-turnstile-response' => 'Please confirm you are not a robot, then send the form again.']);
        Http::assertSentCount(3);
    }
}
