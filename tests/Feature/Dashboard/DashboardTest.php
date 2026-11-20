<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DomainType;
use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Scanning\ScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /**
     * Two completed FakeEngine scans of the same hotel, the second more visible.
     *
     * @return array{0: Hotel, 1: ScanRun, 2: ScanRun, 3: User}
     */
    private function twoScans(): array
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $runs = [];

        foreach ([[20, 0.2, 'old'], [1, 0.8, 'new']] as [$daysAgo, $rate, $salt]) {
            $this->travelTo(Carbon::now()->subDays($daysAgo));
            config(['citevanta.fake.mention_rate' => $rate, 'citevanta.fake.salt' => $salt]);
            $run = $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'pending', 'trigger' => ScanTrigger::Scheduled, 'status' => ScanStatus::Queued]);
            app(ScanService::class)->runSync($run);
            $runs[] = $run->fresh();
            $this->travelBack();
        }

        return [$hotel, $runs[0], $runs[1], $user];
    }

    public function test_headline_numbers_and_changes_come_from_the_stored_scores(): void
    {
        [$hotel, $old, $new, $user] = $this->twoScans();
        $dashboard = app(DashboardService::class)->build($hotel, $user);

        $stored = $new->visibilityScores()->whereNull('persona')->sole();
        $storedOld = $old->visibilityScores()->whereNull('persona')->sole();

        $this->assertTrue($dashboard->scan->is($new));
        $this->assertTrue($dashboard->previous->is($old));
        $this->assertSame($stored->visibility_pct, $dashboard->metrics->visibility);
        $this->assertSame($stored->mentions, $dashboard->metrics->mentions);
        $this->assertSame($stored->avg_position, $dashboard->metrics->averagePosition);
        $this->assertSame(round($stored->visibility_pct - $storedOld->visibility_pct, 1), $dashboard->changes['visibility']);
        $this->assertGreaterThan(0, $dashboard->changes['visibility'], 'higher mention rate, higher visibility');

        // Personas add up to the overall row; share of voice matches competitor_scores.
        $this->assertSame($stored->answers, array_sum(array_column($dashboard->personas, 'answers')));
        $self = collect($dashboard->competitors)->firstWhere('is_self', true);
        $this->assertSame($new->competitorScores()->whereNull('competitor_id')->sole()->share_of_voice_pct, $self['share']);
        $this->assertCount(3, $dashboard->competitors, 'the hotel + 2 competitors');
    }

    public function test_the_engine_filter_uses_only_that_engines_rows(): void
    {
        [$hotel, , $new, $user] = $this->twoScans();
        $new->visibilityScores()->create([
            'hotel_id' => $hotel->id, 'engine' => 'gemini', 'persona' => null,
            'mentions' => 1, 'answers' => 10, 'visibility_pct' => 10.0, 'positive' => 1, 'neutral' => 0, 'negative' => 0,
        ]);
        $openai = $new->visibilityScores()->whereNull('persona')->where('engine', 'openai')->sole();
        $service = app(DashboardService::class);

        $all = $service->build($hotel, $user);
        $this->assertSame(['gemini', 'openai'], $all->engines);
        $this->assertNull($all->engine);
        $this->assertSame($openai->answers + 10, $all->metrics->answers);
        $this->assertSame($openai->mentions + 1, $all->metrics->mentions);

        $gemini = $service->build($hotel, $user, null, 'gemini');
        $this->assertSame('gemini', $gemini->engine);
        $this->assertSame(10.0, $gemini->metrics->visibility);

        $this->assertNull($service->build($hotel, $user, null, 'unknown')->engine, 'unknown engines fall back to all');
    }

    public function test_cited_links_by_type_and_own_website_detection(): void
    {
        [$hotel, , $new, $user] = $this->twoScans();
        $response = $new->responses()->first();
        $new->responses()->each(fn ($r) => $r->citations()->delete());
        $urls = [
            'https://www.alayahotels.com/rooms', 'https://www.booking.com/x', 'https://www.expedia.co.id/y',
            'https://www.tripadvisor.com.au/z', 'https://cntraveler.com/a', 'https://www.kayonresort.com/b',
            'https://www.reddit.com/r/bali', 'https://www.hyatt.com/c', 'https://baliguide.example/d',
        ];
        foreach ($urls as $url) {
            $response->citations()->create(['url' => $url, 'domain' => preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST))]);
        }

        $dashboard = app(DashboardService::class)->build($hotel, $user);

        $this->assertSame(9, $dashboard->links['total']);
        $this->assertSame(
            ['own' => 1, 'competitor' => 1, 'ota' => 2, 'review' => 1, 'media' => 1, 'chain' => 1, 'social' => 1, 'other' => 1],
            collect($dashboard->links['types'])->mapWithKeys(fn ($row) => [$row['type']->value => $row['citations']])->all(),
        );
        $this->assertSame(22.2, collect($dashboard->links['types'])->firstWhere('type', DomainType::Ota)['share']);
        $this->assertSame('competitor', collect($dashboard->domains)->firstWhere('domain', 'kayonresort.com')['type']);
        $this->assertTrue($dashboard->ownCited);
        $this->assertSame('alayahotels.com', $dashboard->ownDomain);

        $response->citations()->where('domain', 'alayahotels.com')->delete();
        $this->assertFalse(app(DashboardService::class)->build($hotel, $user)->ownCited);
    }

    public function test_the_dashboard_page_shows_the_numbers_and_prompt_answers(): void
    {
        [$hotel, , $new, $user] = $this->twoScans();
        $visibility = DashboardService::pct($new->visibilityScores()->whereNull('persona')->sole()->visibility_pct);

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee($hotel->name)
            ->assertSee($visibility)
            ->assertSee('vs previous scan')
            ->assertSee('Visibility by persona')
            ->assertSee('Competitor share of voice')
            ->assertSee('Where AI gets its information')
            ->assertSee('What the domain types mean')
            ->assertSee('Your website')
            ->assertSee('Prompt results')
            ->assertSee('cv-mark-own', false)
            ->assertSee('Visibility trend')
            ->assertSee('Scan history')
            ->assertDontSee('You are viewing scan #');
    }

    public function test_viewing_a_past_scan_shows_its_numbers_with_a_banner(): void
    {
        [$hotel, $old, , $user] = $this->twoScans();
        $oldVisibility = DashboardService::pct($old->visibilityScores()->whereNull('persona')->sole()->visibility_pct);

        $this->actingAs($user)->get(route('hotels.scans.show', [$hotel, $old]))
            ->assertOk()
            ->assertSee("You are viewing scan #{$old->id} from")
            ->assertSee('View latest')
            ->assertSee($oldVisibility)
            ->assertSee('No previous scan to compare');
    }

    public function test_a_scan_of_another_hotel_is_not_found_under_this_hotel(): void
    {
        [$hotel, , , $user] = $this->twoScans();
        $otherHotel = $this->scannableHotel(attributes: ['name' => 'Other Resort']);
        $otherScan = $otherHotel->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Completed, 'finished_at' => now()]);

        $this->actingAs($user)->get("/hotels/{$hotel->id}/scans/{$otherScan->id}")->assertNotFound();
    }

    public function test_no_scans_yet_and_failed_scan_empty_states(): void
    {
        $hotel = $this->scannableHotel();
        $user = User::factory()->withTeam($hotel->team)->create();

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('No scans yet. Run your first scan')
            ->assertSee('Run your first scan');

        $failed = $hotel->scanRuns()->create([
            'engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Failed,
            'finished_at' => now(), 'errors' => ['Prompt #1 run 1 (openai): ErrorException - Incorrect API key'],
        ]);

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertSee("Scan #{$failed->id} failed")
            ->assertSee('Incorrect API key')
            ->assertDontSee('Retry failed calls (admin)');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('hotels.show', $hotel))->assertSee('Retry failed calls (admin)');
    }

    public function test_other_pages_show_latest_scan_numbers(): void
    {
        [$hotel, $old, $new, $user] = $this->twoScans();
        $prompt = $hotel->prompts()->first();
        $promptVisibility = app(DashboardService::class)->promptVisibility($hotel)[$prompt->id];
        $kayon = $new->competitorScores()->whereNotNull('competitor_id')->with('competitor')->get()->firstWhere('competitor.name', 'Kayon Jungle Resort');
        $domain = $new->responses()->first()->citations()->first()->domain;

        $this->actingAs($user)->get('/hotels')->assertOk()
            ->assertSee(DashboardService::pct($new->visibilityScores()->whereNull('persona')->sole()->visibility_pct));
        $this->actingAs($user)->get(route('hotels.prompts', $hotel))->assertOk()->assertSee('Latest visibility')->assertSee(DashboardService::pct($promptVisibility));
        $this->actingAs($user)->get(route('hotels.competitors', $hotel))->assertOk()->assertSee('Share of voice')->assertSee(DashboardService::pct($kayon->share_of_voice_pct));
        $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()->assertSee("Scan #{$old->id}")->assertSee("Scan #{$new->id}");
        $this->actingAs($user)->get(route('hotels.scans', [$hotel, 'status' => 'failed']))->assertOk()->assertSee('No scans match these filters.');
        $this->actingAs($user)->get(route('hotels.citations', $hotel))->assertOk()->assertSee($domain)->assertSee("#{$new->id}");
        $this->actingAs($user)->get(route('hotels.citations.domain', [$hotel, $domain]))->assertOk()->assertSee($prompt->hotel->prompts()->first()->text === '' ? '' : 'Cited for these prompts');
        $this->actingAs($user)->get(route('hotels.citations.domain', [$hotel, 'never-cited.example']))->assertNotFound();
    }

    public function test_the_switcher_keeps_the_section_and_changes_the_current_hotel(): void
    {
        [$hotel, , , $user] = $this->twoScans();
        $user->currentTeam->update(['plan' => Plan::Pro]);
        $second = Hotel::factory()->for($hotel->team)->create(['setup_completed_at' => now()]);

        $this->actingAs($user)->get(route('hotels.switch', ['hotel' => $second, 'to' => 'prompts']))
            ->assertRedirect(route('hotels.prompts', $second));
        $this->actingAs($user)->get('/competitors')->assertRedirect(route('hotels.competitors', $second));
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('hotels.show', $second));
    }
}
