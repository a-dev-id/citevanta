<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    public function test_the_login_page_shows_the_logo_with_the_product_name_from_config(): void
    {
        config(['app.name' => 'StayCited']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('cv-auth', false)
            ->assertSee('alt="StayCited"', false)
            ->assertSee('New to StayCited?')
            ->assertDontSee('CiteVanta')
            ->assertDontSee('Porto');
    }

    public function test_design_preview_renders_every_part_of_the_shell(): void
    {
        $this->get('/design-preview')
            ->assertOk()
            ->assertSee('cv-header', false)
            ->assertSee('data-cv-sidebar-toggle', false)
            ->assertSee('cv-page-header', false)
            ->assertSee('aria-label="Breadcrumb"', false)
            ->assertSeeInOrder(['Navigation', 'Dashboard', 'Hotels', 'Prompts', 'Competitors', 'Scans', 'Citations', 'Recommendations', 'Settings', 'Admin', 'Teams', 'Failed jobs'])
            ->assertSee('Switch hotel')
            ->assertSee('cv-widget', false)
            ->assertSee('cv-empty', false)
            ->assertSee('cv-skeleton', false)
            ->assertSee('accordion', false)
            ->assertDontSee('Porto');
    }

    public function test_design_preview_can_start_with_a_collapsed_sidebar(): void
    {
        $this->get('/design-preview?sidebar=collapsed')
            ->assertOk()
            ->assertSee('<html lang="en" class="sidebar-collapsed">', false);
    }

    public function test_widget_summary_colours_the_change_by_direction(): void
    {
        $up = Blade::render('<x-widget-summary title="Visibility" value="42%" :change="6.5" />');
        $this->assertStringContainsString('is-up', $up);
        $this->assertStringContainsString('+6.5 pts', $up);

        // For average position a lower number is better, so a drop is good news.
        $position = Blade::render('<x-widget-summary title="Position" value="#2" :change="-0.5" change-unit="places" :lower-is-better="true" />');
        $this->assertStringContainsString('is-up', $position);
        $this->assertStringContainsString('fa-arrow-up', $position);
        $this->assertStringContainsString('0.5 places better', $position);

        // Rank number went up (#2.6 -> #3.5): worse, so red, arrow down, and says so.
        $worse = Blade::render('<x-widget-summary title="Position" value="#3.5" :change="0.9" change-unit="places" :lower-is-better="true" />');
        $this->assertStringContainsString('is-down', $worse);
        $this->assertStringContainsString('fa-arrow-down', $worse);
        $this->assertStringContainsString('0.9 places worse', $worse);
        $this->assertStringNotContainsString('+0.9', $worse);

        $none = Blade::render('<x-widget-summary title="Visibility" value="42%" />');
        $this->assertStringContainsString('No previous scan', $none);
    }

    public function test_badge_status_maps_scan_statuses(): void
    {
        $html = Blade::render('<x-badge-status status="completed_with_errors" />');

        $this->assertStringContainsString('cv-badge--warning', $html);
        $this->assertStringContainsString('Completed with errors', $html);
    }

    public function test_empty_state_renders_text_and_action(): void
    {
        $html = Blade::render('<x-empty-state text="No scans yet." action-label="Run your first scan" action-url="/scan" />');

        $this->assertStringContainsString('No scans yet.', $html);
        $this->assertStringContainsString('href="/scan"', $html);
    }
}
