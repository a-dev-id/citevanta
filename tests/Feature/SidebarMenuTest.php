<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use App\Services\Navigation\SidebarMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_users_do_not_see_the_admin_section(): void
    {
        $sections = app(SidebarMenu::class)->sections((new User)->forceFill(['is_admin' => false]));

        $this->assertSame(['Navigation'], array_keys($sections));
    }

    public function test_admins_see_the_admin_section(): void
    {
        $sections = app(SidebarMenu::class)->sections((new User)->forceFill(['is_admin' => true]));

        $this->assertSame(['Navigation', 'Admin'], array_keys($sections));
        $this->assertSame(
            ['Teams', 'Hotels', 'Scan runs', 'Audits', 'Billing', 'Failed jobs', 'Integrations', 'System'],
            array_map(fn ($item) => $item->label, $sections['Admin']),
        );
    }

    public function test_reports_are_only_in_the_menu_of_private_teams(): void
    {
        $standard = User::factory()->withTeam()->create();
        $keys = collect(app(SidebarMenu::class)->sections($standard)['Navigation'])->pluck('key');
        $this->assertNotContains('reports', $keys);

        $private = User::factory()->withTeam(Team::factory()->create(['plan' => Plan::Private]))->create();
        $items = collect(app(SidebarMenu::class)->sections($private)['Navigation'])->keyBy('key');
        $this->assertNull($items['reports']->badge);
        $this->assertSame(route('reports.index'), $items['reports']->url);
    }

    public function test_recommendations_is_a_live_link(): void
    {
        $items = collect(app(SidebarMenu::class)->sections(null)['Navigation'])->keyBy('key');

        $this->assertNull($items['recommendations']->badge);
        $this->assertSame(route('recommendations.index'), $items['recommendations']->url);
    }

    public function test_an_active_child_opens_its_parent_group(): void
    {
        $items = collect(app(SidebarMenu::class)->sections(null, 'scans.index')['Navigation'])->keyBy('key');

        $this->assertTrue($items['scans']->isOpen());
        $this->assertFalse($items['hotels']->isOpen());
    }
}
