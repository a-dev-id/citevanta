<?php

namespace Tests\Feature\Dashboard;

use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test on the demo data: every menu page works for the demo user, and
 * none of the hotel pages are reachable for a user from another team.
 */
class MenuPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $demo;

    private Hotel $hotel;

    private ScanRun $oldestScan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->demo = User::where('email', DemoSeeder::DEMO_EMAIL)->sole();
        $this->hotel = Hotel::where('name', DemoSeeder::HOTEL_NAME)->sole();
        $this->oldestScan = $this->hotel->scanRuns()->oldest('id')->first();
    }

    /**
     * @return list<string>
     */
    private function hotelPages(): array
    {
        $domain = $this->hotel->scanRuns()->latest('id')->first()->responses()->first()->citations()->first()->domain;

        return [
            route('hotels.show', $this->hotel),
            route('hotels.scans.show', [$this->hotel, $this->oldestScan]),
            route('hotels.edit', $this->hotel),
            route('hotels.prompts', $this->hotel),
            route('hotels.competitors', $this->hotel),
            route('hotels.scans', $this->hotel),
            route('hotels.citations', $this->hotel),
            route('hotels.citations.domain', [$this->hotel, $domain]),
            route('hotels.setup', $this->hotel),
            route('hotels.switch', $this->hotel),
        ];
    }

    public function test_every_menu_page_returns_200_for_the_demo_user(): void
    {
        $menu = ['/dashboard', '/hotels', '/hotels/create', '/prompts', '/competitors', '/scans', '/citations', '/settings/team', '/settings/profile'];

        foreach ([...$menu, ...$this->hotelPages()] as $url) {
            $this->actingAs($this->demo)->followingRedirects()->get($url)->assertOk();
        }
    }

    public function test_hotel_pages_are_forbidden_for_another_team(): void
    {
        $outsider = User::factory()->withTeam()->create();

        foreach ($this->hotelPages() as $url) {
            $status = $this->actingAs($outsider)->get($url)->status();
            $this->assertContains($status, [403, 404], "{$url} returned {$status}");
        }
    }

    public function test_the_outsiders_menu_shortcuts_never_show_the_demo_hotel(): void
    {
        $outsider = User::factory()->withTeam()->create();

        foreach (['/dashboard', '/hotels', '/prompts', '/competitors', '/scans', '/citations'] as $url) {
            $this->actingAs($outsider)->followingRedirects()->get($url)->assertOk()->assertDontSee(DemoSeeder::HOTEL_NAME);
        }
    }

    public function test_admin_pages_work_for_the_demo_admin(): void
    {
        $admin = User::where('email', DemoSeeder::ADMIN_EMAIL)->sole();

        foreach (['/admin/teams', '/admin/hotels', '/admin/scan-runs', '/admin/failed-jobs', '/admin/system'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->actingAs($this->demo)->get('/admin/system')->assertForbidden();
    }
}
