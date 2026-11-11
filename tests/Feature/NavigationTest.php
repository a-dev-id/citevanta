<?php

namespace Tests\Feature;

use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\TestBatch;
use App\Models\User;
use App\Services\PilotStudy;
use App\Services\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($this->owner);
        $this->owner->forceFill(['is_platform_admin' => false])->save();
        Http::preventStrayRequests();
        $this->actingAs($this->owner);
    }

    private function sidebar(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_customer_sidebar_has_six_compact_groups_and_no_platform_links(): void
    {
        $page = $this->get(route('dashboard'))->assertOk();
        $nav = $this->sidebar($page->getContent());
        $this->assertSame(6, $nav->query('//*[@id="sidebar-menu"]//span[@class="nav-link-icon"]')->length);
        $this->assertSame(5, $nav->query('//*[@id="sidebar-menu"]//button[@data-bs-toggle="collapse"]')->length);
        $this->assertSame(0, $nav->query('//*[@id="sidebar-menu"]//button[@aria-expanded="true"]')->length);
        $this->assertSame(1, $nav->query('//*[@id="sidebar-menu"]//a[@aria-current="page"]')->length);
        $page->assertSee('Quick Test')->assertSee('Monitoring Campaigns')->assertSee('Action Plan')->assertSee('Organizations &amp; Brands', false)->assertSee('Team Members')->assertSee('Plan &amp; Usage', false)->assertDontSee('Platform cost controls')->assertDontSee('Visibility Pilot')->assertDontSee('Batch Testing');
        foreach ($nav->query('//*[@id="sidebar-menu"]//a') as $link) {
            $this->assertNotSame(url('/batch-settings'), $link->getAttribute('href'));
            $this->get($link->getAttribute('href'))->assertOk();
        }
        Http::assertNothingSent();
    }

    public function test_current_child_expands_only_its_group_and_has_one_active_link(): void
    {
        foreach ([['/tests', 'monitoring'], ['/batches', 'monitoring'], ['/pilot', 'monitoring'], ['/prompts', 'monitoring'], ['/history', 'results'], ['/competitors', 'results'], ['/hotels', 'results'], ['/opportunities', 'improvements'], ['/improvements', 'improvements'], ['/organization?section=brands', 'workspace'], ['/organization?section=team', 'workspace'], ['/organization?section=usage', 'workspace'], ['/settings', 'settings']] as [$url,$group]) {
            $page = $this->get($url)->assertOk();
            $nav = $this->sidebar($page->getContent());
            $this->assertSame(1, $nav->query('//*[@id="sidebar-menu"]//a[@aria-current="page"]')->length, $url);
            $this->assertSame(1, $nav->query('//*[@id="sidebar-menu"]//button[@aria-expanded="true"]')->length, $url);
            $this->assertSame(1, $nav->query('//*[@id="sidebar-menu"]//button[@aria-expanded="true" and @aria-controls="nav-'.$group.'"]')->length, $url);
        }
    }

    public function test_workspace_sections_reuse_existing_permissions_and_forms(): void
    {
        $this->get('/organization?section=team')->assertOk()->assertSee('Add a member')->assertDontSee('id="organization-limit"', false);
        $this->get('/organization?section=usage')->assertOk()->assertSee('Monthly search credits')->assertDontSee('Add a member')->assertDontSee('id="organization-limit"', false);
        $this->get('/organization?section=brands')->assertOk()->assertSee('Monitored brands')->assertDontSee('Add a member');
        $this->get('/organization?section=costs')->assertOk()->assertDontSee('id="organization-limit"', false)->assertDontSee('Add a member');
        $this->get('/organization?section=invalid')->assertRedirect();
        $this->get('/batch-settings')->assertNotFound();
    }

    public function test_viewers_can_review_campaigns_but_cannot_access_paid_controls(): void
    {
        $context = app(TenantContext::class);
        $viewer = User::factory()->create();
        $context->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->actingAs($viewer);
        $this->get('/dashboard')->assertOk()->assertDontSee('Quick Test');
        $this->get('/batches')->assertOk()->assertDontSee('New Campaign');
        $this->get('/batches?view=templates')->assertOk()->assertSee('Preview pilot template');
        $this->get('/pilot')->assertOk()->assertDontSee('action="'.url('/pilot/enable').'"', false);
        $this->get('/organization?section=team')->assertOk()->assertDontSee('Add a member');
        $this->get('/organization?section=costs')->assertOk()->assertDontSee('id="organization-limit"', false);
        foreach (['/tests', '/batches/create', '/prompts/create'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/tests', '/batches/preview'] as $url) {
            $this->post($url, [])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_plan_entitlements_hide_request_entry_points_without_changing_limits(): void
    {
        $plan = app(TenantContext::class)->organization()->plan_key;
        config(['plans.definitions.'.$plan.'.providers' => []]);
        $this->get('/dashboard')->assertOk()->assertDontSee('Quick Test');
        $this->get('/batches')->assertOk()->assertDontSee('New Campaign');
        $this->get('/batches?view=templates')->assertOk()->assertSee('No pilot templates available')->assertDontSee('Preview pilot template');
        Http::assertNothingSent();
    }

    public function test_campaign_filters_and_template_reuse_the_same_batch(): void
    {
        $pilot = app(PilotStudy::class)->prepare($this->owner);
        $completed = $pilot->replicate(['pilot_key', 'pilot_snapshot', 'pilot_enabled_at', 'pilot_approved_by', 'pilot_approval_fingerprint']);
        $completed->forceFill(['creation_id' => (string) Str::uuid(), 'name' => 'Completed campaign fixture', 'status' => 'completed'])->save();
        $this->get('/batches?view=active')->assertOk()->assertSee($pilot->name)->assertDontSee('Completed campaign fixture');
        $this->get('/batches?view=completed')->assertOk()->assertSee('Completed campaign fixture')->assertDontSee($pilot->name);
        $this->get('/batches?view=templates')->assertOk()->assertSee('Open existing pilot');
        $this->assertSame(2, TestBatch::count());
        $this->assertSame(1, TestBatch::whereNotNull('pilot_key')->count());
        $this->get('/batches?view=invalid')->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_other_workspaces_do_not_see_nandini_pilot_or_campaign_data(): void
    {
        $pilot = app(PilotStudy::class)->prepare($this->owner);
        $org = Organization::factory()->create(['owner_id' => $this->owner->id, 'plan_key' => 'agency']);
        $attributes = MonitoredBrand::factory()->make(['organization_id' => $org->id])->getAttributes();
        $brand = DB::table('monitored_brands')->insertGetId($attributes);
        app(TenantContext::class)->set($org->id, $brand, $this->owner);
        $this->withSession(['organization_id' => $org->id, 'monitored_brand_id' => $brand]);
        $this->get('/batches')->assertOk()->assertDontSee($pilot->name);
        $this->get('/batches?view=templates')->assertOk()->assertSee('No pilot templates available')->assertDontSee('Nandini visibility pilot');
        $this->get('/pilot')->assertNotFound();
    }

    public function test_organization_administrators_have_monitoring_access_without_owner_or_platform_tools(): void
    {
        $administrator = User::factory()->create();
        app(TenantContext::class)->organization()->members()->attach($administrator->id, ['role' => 'administrator']);
        $this->actingAs($administrator);
        $this->get('/dashboard')->assertOk()->assertSee('Quick Test')->assertDontSee('Platform cost controls');
        $this->get('/batches')->assertOk()->assertSee('New Campaign');
        $this->get('/organization?section=team')->assertOk()->assertDontSee('Add a member');
        $this->get('/organization?section=costs')->assertOk()->assertDontSee('id="organization-limit"', false);
        $this->get('/settings')->assertOk()->assertDontSee('NANDINI_OPENAI_API_KEY');
        $this->get('/batch-settings')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_platform_controls_are_available_outside_customer_navigation_only(): void
    {
        $this->owner->forceFill(['is_platform_admin' => true])->save();
        $response = $this->get('/dashboard')->assertOk()->assertSee('Platform administration')->assertDontSee('Cost Controls');
        $nav = $this->sidebar($response->getContent());
        $this->assertSame(0, $nav->query('//*[@id="sidebar-menu"]//a[contains(@href,"/batch-settings")]')->length);
        $this->get('/batch-settings')->assertNotFound();
    }
}
