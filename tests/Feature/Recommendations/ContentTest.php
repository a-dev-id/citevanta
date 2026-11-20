<?php

namespace Tests\Feature\Recommendations;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\Plan;
use App\Enums\RecommendationCategory;
use App\Livewire\Hotels\ContentPanel;
use App\Models\CompetitorInsight;
use App\Models\ContentPiece;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Content\ContentInput;
use App\Services\Content\ContentLimitReached;
use App\Services\Content\ContentService;
use App\Services\Content\ContentWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /** @return array{0: Hotel, 1: Recommendation, 2: User} */
    private function setUpHotel(Plan $plan = Plan::Starter): array
    {
        $hotel = $this->scannableHotel(prompts: 0, plan: $plan);
        $hotel->facts()->create(['key' => 'Swimming pool', 'value' => 'Yes, a 25 m infinity pool', 'position' => 1]);
        $hotel->facts()->create(['key' => 'Check-in time', 'value' => '14:00', 'position' => 2]);
        $recommendation = Recommendation::factory()->for($hotel)->create();
        $user = User::factory()->withTeam($hotel->team)->create();

        return [$hotel, $recommendation, $user];
    }

    private function create(Recommendation $recommendation, ContentType $type, User $user, string $language = 'en'): ContentPiece
    {
        return app(ContentService::class)->request($recommendation, $type, $language, $user)->fresh();
    }

    public static function aiTypes(): array
    {
        return array_map(fn (ContentType $type) => [$type], array_values(array_filter(ContentType::cases(), fn ($t) => ! $t->isTemplate())));
    }

    #[DataProvider('aiTypes')]
    public function test_content_uses_the_facts_and_shows_placeholders_for_missing_facts(ContentType $type): void
    {
        [, $recommendation, $user] = $this->setUpHotel();

        $piece = $this->create($recommendation, $type, $user);

        $this->assertSame(ContentStatus::Ready, $piece->status);
        $this->assertStringNotContainsString('[check:', $piece->body, 'the fake writer never invents numbers');
        if (in_array($type, [ContentType::Faq, ContentType::WebsiteSection, ContentType::OtaListingUpdate], true)) {
            $this->assertStringContainsString('[add: distance to airport]', $piece->body);
            $this->assertStringContainsString('14:00', $piece->body);
        }
    }

    public function test_an_ai_number_that_is_not_in_the_facts_is_flagged(): void
    {
        [, $recommendation, $user] = $this->setUpHotel();
        $this->app->bind(ContentWriter::class, fn () => new class implements ContentWriter
        {
            public function write(ContentInput $input): array
            {
                return ['title' => 'FAQ', 'body' => "1. Our 25 m pool is open daily.\nWe are 15 minutes from the airport. Check-in from 14:00.", 'tokens_in' => 500, 'tokens_out' => 200, 'model' => 'gpt-5-mini'];
            }
        });

        $piece = $this->create($recommendation, ContentType::Faq, $user);

        $this->assertSame("1. Our 25 m pool is open daily.\nWe are [check: 15 minutes] from the airport. Check-in from 14:00.", $piece->body);
        $this->assertSame(1, $recommendation->hotel->aiCalls()->where('purpose', 'content')->count());
    }

    public function test_content_cost_is_a_team_cost_shown_in_admin_and_never_part_of_a_scan(): void
    {
        [$hotel, $recommendation, $user] = $this->setUpHotel();
        $scan = ScanRun::factory()->for($hotel)->create(['cost_usd' => 0.1, 'cost_breakdown' => ['scan' => 0.1]]);

        $this->create($recommendation, ContentType::Faq, $user);
        $this->create($recommendation, ContentType::SchemaJsonLd, $user);

        $call = $hotel->aiCalls()->where('purpose', 'content')->sole();
        $this->assertSame([$hotel->team_id, null], [$call->team_id, $call->scan_run_id]);
        $this->assertSame(['scan' => 0.1], $scan->fresh()->cost_breakdown);
        $this->assertSame('0.100000', $scan->fresh()->cost_usd);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.teams'))
            ->assertOk()
            ->assertSee('$'.number_format((float) $call->cost_usd, 4))
            ->assertSee('2 pieces');
    }

    public function test_ota_listing_update_has_a_checklist_with_where_what_and_why_and_texts_within_limits(): void
    {
        [$hotel, $recommendation, $user] = $this->setUpHotel();
        $hotel->facts()->create(['key' => 'Kids club', 'value' => 'No', 'position' => 3]);
        $hotel->facts()->create(['key' => 'Price range per night', 'value' => 'USD 120-180', 'position' => 4]);
        $scan = ScanRun::factory()->for($hotel)->create();
        CompetitorInsight::create([
            'hotel_id' => $hotel->id, 'scan_run_id' => $scan->id, 'prompt_id' => Prompt::factory()->for($hotel)->create()->id,
            'competitors' => ['Kayon Jungle Resort'], 'wins' => 1, 'sources' => [],
            'reasons' => [
                ['competitor' => 'Kayon Jungle Resort', 'reason' => 'infinity pool', 'quote' => '', 'fact_key' => 'Swimming pool', 'hotel_has' => 'has_it'],
                ['competitor' => 'Kayon Jungle Resort', 'reason' => 'yoga classes', 'quote' => '', 'fact_key' => null, 'hotel_has' => 'unknown'],
            ],
        ]);

        $body = $this->create($recommendation, ContentType::OtaListingUpdate, $user)->body;

        $this->assertStringContainsString('PART 1: CHECKLIST', $body);
        $this->assertStringContainsString("Swimming pool\n   What to set: Make sure \"Swimming pool\" is listed, with these details: Yes, a 25 m infinity pool.\n   Booking.com: Booking.com extranet > Property > Facilities & services\n   Agoda: Agoda YCS > Property > Facilities\n   Why: Kayon Jungle Resort was recommended for “infinity pool” (1 prompt).", $body);
        $this->assertStringContainsString('Make sure "Kids club" is not listed as available.', $body);
        $this->assertStringContainsString('Booking.com extranet > Property > Policies', $body, 'check-in time is a policy');
        $this->assertStringContainsString('[add: yoga classes]', $body, 'a competitor reason the facts do not cover');
        $this->assertStringNotContainsString('Price range per night', $body, 'rates are not part of the listing checklist');
        $this->assertStringContainsString('Agoda may take up to 14 days', $body);
        $this->assertStringContainsString('Booking.com – About the neighbourhood:', $body);
        $this->assertStringContainsString('Agoda description (send to Agoda support via Need Help in YCS):', $body);
        $this->assertStringNotContainsString('[check:', $body);
    }

    public function test_schema_markup_is_valid_json_built_from_the_facts_without_an_ai_call(): void
    {
        [$hotel, $recommendation, $user] = $this->setUpHotel();

        $piece = $this->create($recommendation, ContentType::SchemaJsonLd, $user);
        $json = json_decode($piece->body, true, 64, JSON_THROW_ON_ERROR);

        $lodging = $json['@graph'][0];
        $this->assertSame('https://schema.org', $json['@context']);
        $this->assertSame('Resort', $lodging['@type']);
        $this->assertSame($hotel->name, $lodging['name']);
        $this->assertSame('14:00', $lodging['checkinTime']);
        $this->assertSame('[add: price range per night]', $lodging['priceRange']);
        $this->assertSame('FAQPage', $json['@graph'][1]['@type']);
        $this->assertSame(0, $hotel->aiCalls()->count());
    }

    public function test_the_monthly_plan_limit_blocks_generation_and_failed_pieces_do_not_count(): void
    {
        [$hotel, $recommendation, $user] = $this->setUpHotel(Plan::Trial);
        ContentPiece::create(['hotel_id' => $hotel->id, 'type' => ContentType::Faq, 'status' => ContentStatus::Failed]);
        ContentPiece::create(['hotel_id' => $hotel->id, 'type' => ContentType::Faq, 'status' => ContentStatus::Ready])
            ->forceFill(['created_at' => now()->subMonths(2)])->save();

        foreach (range(1, 2) as $i) {
            $this->create($recommendation, ContentType::Faq, $user);
        }

        $this->assertSame(['used' => 2, 'limit' => 2, 'left' => 0, 'unlimited' => false], app(ContentService::class)->allowance($hotel, $user));
        $this->expectException(ContentLimitReached::class);
        $this->create($recommendation, ContentType::Faq, $user);
    }

    public function test_admins_have_no_content_limit(): void
    {
        [$hotel, $recommendation] = $this->setUpHotel(Plan::Trial);
        $admin = User::factory()->admin()->create();

        foreach (range(1, 4) as $i) {
            $this->create($recommendation, ContentType::Faq, $admin);
        }

        $this->assertTrue(app(ContentService::class)->allowance($hotel, $admin)['unlimited']);
    }

    public function test_versions_are_kept_and_edits_are_saved_on_the_version(): void
    {
        [, $recommendation, $user] = $this->setUpHotel();

        $first = $this->create($recommendation, ContentType::Faq, $user);
        $second = $this->create($recommendation, ContentType::Faq, $user);
        $indonesian = $this->create($recommendation, ContentType::Faq, $user, 'xx');

        $this->assertSame([1, 2, 'en'], [$first->version, $second->version, $indonesian->language], 'unknown language falls back to English');

        Livewire::actingAs($user)->test(ContentPanel::class, ['recommendationId' => $recommendation->id])
            ->assertSee('7 of 10 left this month')
            ->call('show', $first->id)
            ->set('text', 'Our own wording.')
            ->call('saveEdit')
            ->assertSee('Your edit is saved.');

        $this->assertSame(['Our own wording.', 'Our own wording.'], [$first->fresh()->edited_body, $first->fresh()->text()]);
        $this->assertNull($second->fresh()->edited_body);
    }

    public function test_the_panel_creates_content_and_shows_where_to_paste_it(): void
    {
        [, $recommendation, $user] = $this->setUpHotel();
        $recommendation->update(['category' => RecommendationCategory::OtaListing]);

        Livewire::actingAs($user)->test(ContentPanel::class, ['recommendationId' => $recommendation->id])
            ->assertSet('type', ContentType::OtaListingUpdate->value)
            ->call('generate')
            ->assertSee(ContentType::OtaListingUpdate->whereToPaste())
            ->assertSee('max 2,000 characters')
            ->assertSee('max 160 words');
    }

    public function test_download_gives_the_text_and_is_limited_to_the_team_and_the_hotel(): void
    {
        [$hotel, $recommendation, $user] = $this->setUpHotel();
        $piece = $this->create($recommendation, ContentType::SchemaJsonLd, $user);
        $otherHotel = Hotel::factory()->for(Team::factory())->create();
        $outsider = User::factory()->withTeam()->create();

        $response = $this->actingAs($user)->get(route('hotels.content.download', [$hotel, $piece]));
        $response->assertOk()->assertDownload();
        $this->assertJson($response->streamedContent());

        $this->actingAs($outsider)->get(route('hotels.content.download', [$hotel, $piece]))->assertForbidden();
        $user->teams()->attach($otherHotel->team, ['role' => 'owner']);
        $this->actingAs($user)->get(route('hotels.content.download', [$otherHotel, $piece]))->assertNotFound();
    }
}
