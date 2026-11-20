<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Navigation\SidebarMenu;
use Tests\TestCase;

class SidebarMenuTest extends TestCase
{
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

    public function test_coming_soon_items_have_a_badge_and_no_link(): void
    {
        $items = collect(app(SidebarMenu::class)->sections(null)['Navigation'])->keyBy('key');

        $this->assertSame('Soon', $items['reports']->badge);
        $this->assertNull($items['reports']->url);
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
