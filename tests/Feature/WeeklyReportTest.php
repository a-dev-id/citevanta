<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Jobs\Middleware\ThrottleMail;
use App\Mail\WeeklyReport;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Models\VisibilityScore;
use App\Services\Reporting\WeeklyReportSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Weekly email report: Monday 08:00 in the team's timezone, only with new
 * scan results, opt-in rules, unsubscribe, and mail throttling.
 */
class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    /** Monday 23 Nov 2026, 08:00 in Asia/Makassar (UTC+8). */
    private const MONDAY_8AM_MAKASSAR = '2026-11-23 00:00:00';

    private function teamWithScan(string $timezone = 'Asia/Makassar', string $scannedAt = '2026-11-21 03:00:00'): Team
    {
        $team = Team::factory()->create(['timezone' => $timezone]);
        $hotel = Hotel::factory()->for($team)->create(['name' => 'Alaya Resort Ubud', 'setup_completed_at' => now()]);
        $this->scan($hotel, $scannedAt, overall: [4, 10], personas: ['family' => [3, 5], 'business' => [1, 5]]);
        Recommendation::factory()->for($hotel)->create(['title' => 'Add a family FAQ to your website']);

        return $team;
    }

    /**
     * @param  array{0: int, 1: int}  $overall  mentions, answers
     * @param  array<string, array{0: int, 1: int}>  $personas
     */
    private function scan(Hotel $hotel, string $finishedAt, array $overall, array $personas = []): ScanRun
    {
        $run = ScanRun::factory()->for($hotel)->create(['finished_at' => Carbon::parse($finishedAt, 'UTC')]);

        foreach ([null => $overall] + $personas as $persona => [$mentions, $answers]) {
            VisibilityScore::factory()->create([
                'scan_run_id' => $run->id, 'persona' => $persona === '' ? null : $persona,
                'mentions' => $mentions, 'answers' => $answers, 'visibility_pct' => round($mentions / $answers * 100, 1),
            ]);
        }

        return $run;
    }

    private function send(string $utc): array
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));

        return app(WeeklyReportSender::class)->run();
    }

    public function test_owners_get_the_report_on_monday_at_8_in_the_team_timezone_once_a_week(): void
    {
        Mail::fake();
        $team = $this->teamWithScan();
        $owner = User::factory()->withTeam($team)->create();

        $this->send('2026-11-22 23:59:00'); // 07:59 Makassar
        Mail::assertNothingQueued();

        $this->send(self::MONDAY_8AM_MAKASSAR);
        $this->send('2026-11-23 05:00:00');

        Mail::assertQueued(WeeklyReport::class, 1);
        Mail::assertQueued(WeeklyReport::class, function (WeeklyReport $mail) use ($owner) {
            $html = $mail->render();

            return $mail->hasTo($owner->email)
                && str_contains($html, 'AI visibility: 40%')
                && str_contains($html, 'Families')
                && str_contains($html, 'Business travellers')
                && str_contains($html, 'Add a family FAQ to your website')
                && str_contains($html, route('weekly-report.unsubscribe', ['user' => $owner->id]).'?');
        });
    }

    public function test_the_report_follows_each_teams_own_timezone(): void
    {
        Mail::fake();
        $team = $this->teamWithScan('Europe/Amsterdam');
        User::factory()->withTeam($team)->create();

        $this->send(self::MONDAY_8AM_MAKASSAR); // 01:00 in Amsterdam
        Mail::assertNothingQueued();

        $this->send('2026-11-23 07:00:00'); // 08:00 in Amsterdam
        Mail::assertQueued(WeeklyReport::class, 1);
    }

    public function test_no_report_without_a_new_completed_scan_since_the_last_one(): void
    {
        Mail::fake();
        $team = $this->teamWithScan(scannedAt: '2026-11-10 03:00:00');
        User::factory()->withTeam($team)->create();
        $team->forceFill(['weekly_report_sent_at' => Carbon::parse('2026-11-16 00:00:00', 'UTC')])->save();

        $result = $this->send(self::MONDAY_8AM_MAKASSAR);

        $this->assertSame(['teams' => 0, 'emails' => 0, 'skipped' => 1], $result);
        Mail::assertNothingQueued();
    }

    public function test_members_get_it_only_when_they_opt_in_and_owners_can_opt_out(): void
    {
        Mail::fake();
        $team = $this->teamWithScan();
        $owner = User::factory()->withTeam($team)->create();
        $member = User::factory()->create();
        $optedIn = User::factory()->create(['weekly_report' => true]);
        $team->addMember($member, TeamRole::Member);
        $team->addMember($optedIn, TeamRole::Member);
        $optedOutOwner = User::factory()->create(['weekly_report' => false]);
        $team->addMember($optedOutOwner, TeamRole::Owner);

        $this->send(self::MONDAY_8AM_MAKASSAR);

        Mail::assertQueued(WeeklyReport::class, 2);
        Mail::assertQueued(WeeklyReport::class, fn ($mail) => $mail->hasTo($owner->email));
        Mail::assertQueued(WeeklyReport::class, fn ($mail) => $mail->hasTo($optedIn->email));
    }

    public function test_the_signed_unsubscribe_link_turns_the_report_off_and_profile_can_turn_it_on_again(): void
    {
        $owner = User::factory()->withTeam()->create();

        $this->get(route('weekly-report.unsubscribe', ['user' => $owner->id]))->assertForbidden();
        $this->get(URL::signedRoute('weekly-report.unsubscribe', ['user' => $owner->id]))->assertOk()->assertSee('Unsubscribed');
        $this->assertFalse($owner->fresh()->weekly_report);
        $this->assertFalse($owner->fresh()->wantsWeeklyReport($owner->currentTeam));

        $this->actingAs($owner)->put(route('settings.notifications'), ['weekly_report' => '1'])->assertRedirect(route('settings.profile'));
        $this->assertTrue($owner->fresh()->weekly_report);
    }

    public function test_outgoing_mail_is_throttled_per_hour_and_waits_in_the_queue(): void
    {
        config(['citevanta.mail.max_per_hour' => 2]);
        RateLimiter::clear(ThrottleMail::KEY);
        $job = new class
        {
            public ?int $releasedFor = null;

            public function release(int $delay): void
            {
                $this->releasedFor = $delay;
            }
        };
        $sent = 0;
        $send = function () use ($job, &$sent): void {
            (new ThrottleMail)->handle($job, function () use (&$sent) {
                $sent++;
            });
        };

        $send();
        $send();
        $send();

        $this->assertSame(2, $sent);
        $this->assertGreaterThan(3500, $job->releasedFor, 'back in the queue until the hour is over');
    }
}
