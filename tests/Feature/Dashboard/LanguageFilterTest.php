<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Hotels\ContentPanel;
use App\Livewire\Hotels\HotelDashboard;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Reporting\VisibilityReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class LanguageFilterTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /** A hotel with English and Chinese prompts and a Chinese alternative name, scanned once. */
    private function bilingualHotel(): Hotel
    {
        $hotel = $this->scannableHotel(prompts: 5, attributes: ['alternative_names' => ['阿拉亚度假村']]);
        $hotel->prompts()->create(['text' => 'Ubud最浪漫的蜜月度假村是哪家？', 'persona' => 'couple', 'language' => 'zh', 'is_active' => true]);
        $hotel->prompts()->create(['text' => 'Ubud有哪些适合带孩子入住的度假村？最好有泳池和儿童俱乐部。', 'persona' => 'family', 'language' => 'zh', 'is_active' => true]);
        $this->completedScans($hotel, [1.0]);

        return $hotel;
    }

    public function test_a_chinese_answer_that_names_the_hotel_in_chinese_counts_as_a_mention(): void
    {
        $hotel = $this->bilingualHotel();
        $scan = $hotel->scanRuns()->sole();

        $chinese = $scan->responses()->whereHas('prompt', fn ($q) => $q->where('language', 'zh'))->with('analysis')->get();
        $namedInChinese = $chinese->filter(fn ($r) => str_contains($r->answer_text, '**阿拉亚度假村**'));

        $this->assertNotEmpty($namedInChinese, 'the fake engine writes the Chinese name in some Chinese answers');
        $this->assertTrue($namedInChinese->every(fn ($r) => $r->analysis->hotel_mentioned));
        $this->assertTrue($chinese->contains(fn ($r) => preg_match('/以下是|根据最近的评价/u', $r->answer_text) === 1), 'list answers are framed in Chinese');
    }

    public function test_the_language_filter_and_visibility_by_language_read_the_answers_per_language(): void
    {
        $hotel = $this->bilingualHotel();
        $user = User::factory()->withTeam($hotel->team)->create();
        $scan = $hotel->scanRuns()->sole();
        $service = app(DashboardService::class);

        $zh = app(VisibilityReportBuilder::class)->build($scan, [], null, 'zh');
        $dashboard = $service->build($hotel, $user, null, null, 'zh');

        $this->assertSame(['en', 'zh'], $dashboard->languages);
        $this->assertSame('zh', $dashboard->language);
        $this->assertSame([$zh->totalAnswers, $zh->mentions], [$dashboard->metrics->answers, $dashboard->metrics->mentions]);
        $this->assertCount(2, $dashboard->prompts, 'only the Chinese prompts');
        $this->assertSame(['en', 'zh'], array_column($dashboard->byLanguage, 'language'));
        $this->assertSame($zh->visibility, collect($dashboard->byLanguage)->firstWhere('language', 'zh')['visibility']);

        $all = $service->build($hotel, $user);
        $this->assertSame($scan->answers_count, $all->metrics->answers);
    }

    public function test_the_dashboard_shows_language_pills_and_the_by_language_tab(): void
    {
        $hotel = $this->bilingualHotel();
        $user = User::factory()->withTeam($hotel->team)->create();

        Livewire::actingAs($user)->test(HotelDashboard::class, ['hotel' => $hotel])
            ->assertSee('All languages')
            ->assertSee('By language')
            ->call('setLanguage', 'zh')
            ->assertSet('language', 'zh')
            ->assertSee('Ubud最浪漫的蜜月度假村是哪家？')
            ->assertDontSee('Which resort in Ubud is best for traveller type number 1?');
    }

    public function test_a_single_language_scan_has_no_language_filter(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $this->completedScans($hotel, [0.5]);

        $dashboard = app(DashboardService::class)->build($hotel, $user, null, null, 'en');

        $this->assertNull($dashboard->language);
        $this->assertSame([], $dashboard->byLanguage);
    }

    public function test_the_content_panel_of_another_teams_recommendation_is_forbidden(): void
    {
        $recommendation = Recommendation::factory()->create();

        Livewire::actingAs(User::factory()->withTeam()->create())
            ->test(ContentPanel::class, ['recommendationId' => $recommendation->id])
            ->assertForbidden();
    }
}
