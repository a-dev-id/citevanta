<?php

namespace Tests\Feature\Site;

use App\Enums\ScanTrigger;
use App\Livewire\Hotels\DetailsForm;
use App\Mail\AuditReady;
use App\Models\AiCall;
use App\Models\Audit;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['citevanta.fake.mention_rate' => 0.5]);
    }

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'hotel_name' => 'Alaya Resort Ubud',
            'city' => 'Ubud',
            'country' => 'Indonesia',
            'website' => 'https://alayahotels.com',
            'email' => 'owner@alaya.test',
            'consent' => '1',
            'locale' => 'en',
        ];
    }

    private function submit(array $overrides = [], string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('audit.store'), $this->form($overrides));
    }

    public function test_an_audit_asks_10_english_questions_once_on_chatgpt_and_emails_the_report(): void
    {
        $response = $this->submit();
        $audit = Audit::sole();

        $response->assertRedirect(route('audit.show', $audit->token));
        $this->assertSame(48, strlen($audit->token));
        $this->assertSame(Audit::COMPLETED, $audit->status);
        $this->assertSame(10, $audit->questions);
        $this->assertTrue($audit->consent);

        $scan = $audit->scanRun;
        $this->assertSame([ScanTrigger::Audit, 'openai', 1, 10], [$scan->trigger, $scan->engine, $scan->runs_per_prompt, $scan->responses()->count()]);
        $this->assertTrue($scan->hotel->is_audit);
        $this->assertNull($scan->hotel->team_id);
        $this->assertSame(['en'], $scan->hotel->prompts()->distinct()->pluck('language')->all());

        // Only the audit's own cost: no accuracy, competitor reason or recommendation calls.
        $this->assertSame(['audit'], $audit->hotel_id ? AiCall::query()->where('hotel_id', $audit->hotel_id)->pluck('purpose')->map->value->unique()->values()->all() : []);
        $this->assertEqualsWithDelta((float) $scan->cost_usd, (float) $audit->cost_usd, 1e-6);
        $this->assertSame(0, Recommendation::count());

        Mail::assertQueued(AuditReady::class, fn ($mail) => $mail->hasTo('owner@alaya.test') && $mail->audit->is($audit));
        $this->assertNotNull($audit->email_sent_at);
    }

    public function test_the_report_shows_the_score_hotels_instead_and_sources_and_is_not_indexed(): void
    {
        $audit = Audit::factory()->create(['hotel_name' => 'Alaya Resort Ubud']);

        $this->get(route('audit.show', $audit->token))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('30%')
            ->assertSee('3 of 10 traveller questions mentioned your hotel')
            ->assertSee('Bamboo Valley Retreat')
            ->assertSee('Booking sites (OTAs)')
            ->assertSee(route('register', ['audit' => $audit->token]), false);
    }

    public function test_a_running_audit_shows_progress_and_polls(): void
    {
        $audit = Audit::factory()->running()->create();

        $this->get(route('audit.show', $audit->token))->assertOk()->assertSee('Your audit is running')->assertSee('wire:poll.5s', false);
    }

    public function test_report_links_cannot_be_guessed(): void
    {
        Audit::factory()->create();

        $this->get('/audit/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/audit/1')->assertNotFound();
    }

    public function test_at_most_3_audits_per_ip_and_per_email_a_day(): void
    {
        config(['citevanta.audit.daily_budget' => 30]);
        foreach (['a', 'b', 'c'] as $n) {
            $this->submit(['hotel_name' => "Hotel {$n}", 'email' => "{$n}@x.test"]);
        }
        $this->submit(['hotel_name' => 'Hotel d', 'email' => 'd@x.test'])->assertSessionHasErrors('audit', null, 'audit');

        foreach (['1', '2', '3'] as $n) {
            $this->submit(['hotel_name' => "Other {$n}", 'email' => 'same@x.test'], "10.0.1.{$n}");
        }
        $this->submit(['hotel_name' => 'Other 4', 'email' => 'same@x.test'], '10.0.1.4')->assertSessionHasErrors('audit', null, 'audit');

        $this->assertSame(6, Audit::count());
    }

    public function test_the_daily_budget_stops_new_audits_but_still_reuses_recent_reports(): void
    {
        config(['citevanta.audit.daily_budget' => 1]);
        $this->submit();

        $this->submit(['hotel_name' => 'Another Hotel', 'email' => 'b@x.test'], '10.0.0.2')
            ->assertSessionHasErrors(['audit' => "Today's free audits are fully booked, please try again tomorrow."], null, 'audit');

        $this->submit(['email' => 'c@x.test'], '10.0.0.3')->assertRedirect();
        $this->assertSame(2, Audit::count());
    }

    public function test_the_same_hotel_within_7_days_reuses_the_report_and_after_7_days_runs_again(): void
    {
        $this->submit();
        $first = Audit::sole();

        $this->submit(['hotel_name' => ' alaya resort UBUD ', 'city' => 'ubud', 'email' => 'manager@alaya.test'], '10.0.0.2');
        $reuse = Audit::latest('id')->first();

        $this->assertSame($first->id, $reuse->source_audit_id);
        $this->assertNull($reuse->scan_run_id);
        $this->assertSame(1, ScanRun::count(), 'no new AI calls');
        Mail::assertQueued(AuditReady::class, fn ($mail) => $mail->hasTo('manager@alaya.test'));
        $this->get(route('audit.show', $reuse->token))->assertSee($first->questions_mentioned.' of 10 traveller questions');

        $this->travel(8)->days();
        $this->submit(['email' => 'later@alaya.test'], '10.0.0.3');
        $this->assertSame(2, ScanRun::count());
    }

    public function test_bots_filling_the_honeypot_get_no_audit(): void
    {
        $this->submit(['fax' => 'http://spam.test'])->assertRedirect('/');

        $this->assertSame(0, Audit::count());
    }

    public function test_the_trial_is_prefilled_from_the_report_and_counted_as_a_conversion(): void
    {
        $audit = Audit::factory()->create(['hotel_name' => 'Alaya Resort Ubud', 'email' => 'owner@alaya.test', 'website' => 'https://alayahotels.com']);

        $this->get(route('register', ['audit' => $audit->token]))
            ->assertOk()->assertSee('value="owner@alaya.test"', false)->assertSee('value="Alaya Resort Ubud"', false);

        $this->post(route('register'), [
            'name' => 'Owner', 'email' => 'owner@alaya.test', 'team_name' => 'Alaya Resort Ubud',
            'password' => 'a-Long-password-123', 'password_confirmation' => 'a-Long-password-123',
        ]);

        $user = User::where('email', 'owner@alaya.test')->sole();
        $this->assertSame($user->currentTeam->id, $audit->fresh()->converted_team_id);

        Livewire::actingAs($user)->test(DetailsForm::class, ['wizard' => true])
            ->assertSet('name', 'Alaya Resort Ubud')->assertSet('city', 'Ubud')->assertSet('country', 'Indonesia')
            ->assertSet('website', 'https://alayahotels.com');
    }

    public function test_an_indonesian_audit_email_is_in_indonesian(): void
    {
        $this->submit(['locale' => 'id']);

        Mail::assertQueued(AuditReady::class, fn ($mail) => $mail->envelope()->subject === 'Laporan visibilitas AI untuk Alaya Resort Ubud');
    }

    public function test_admins_see_leads_with_costs_and_can_export_csv(): void
    {
        $converted = Audit::factory()->create(['email' => 'lead@hotel.test', 'consent' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.audits'))->assertOk()->assertSee('lead@hotel.test')->assertSee('$0.1200');

        $csv = $this->actingAs($admin)->get(route('admin.audits.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('lead@hotel.test', $csv);
        $this->assertStringContainsString($converted->hotel_name, $csv);

        $this->actingAs(User::factory()->withTeam()->create())->get(route('admin.audits'))->assertForbidden();
    }
}
