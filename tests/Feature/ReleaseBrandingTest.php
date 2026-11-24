<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Deploy\ReleaseInfo;
use App\Services\Deploy\StaleCacheGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Favicon set and its ?v= cache buster on every layout, the web app
 * manifest, and the version shown to admins.
 */
class ReleaseBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function faviconTags(int|string $version = 1): array
    {
        return [
            '<link rel="icon" href="'.asset('favicon.ico').'?v='.$version.'" sizes="16x16 32x32 48x48">',
            '<link rel="icon" type="image/svg+xml" href="'.asset('favicon.svg').'?v='.$version.'">',
            '<link rel="apple-touch-icon" sizes="180x180" href="'.asset('apple-touch-icon.png').'?v='.$version.'">',
            '<link rel="manifest" href="'.asset('site.webmanifest').'?v='.$version.'">',
        ];
    }

    public function test_the_public_app_and_auth_layouts_output_the_favicon_tags_with_the_asset_version(): void
    {
        $pages = [
            'public' => $this->get('/'),
            'auth' => $this->get(route('login')),
            'app' => $this->actingAs(User::factory()->withTeam()->create())->get(route('dashboard')),
        ];

        foreach ($pages as $layout => $response) {
            $response->assertOk();

            foreach ($this->faviconTags() as $tag) {
                $response->assertSee($tag, false);
            }
        }
    }

    public function test_the_asset_version_comes_from_config_so_a_new_icon_can_be_forced(): void
    {
        config(['citevanta.asset_version' => 7]);

        foreach ($this->faviconTags(7) as $tag) {
            $this->get('/')->assertSee($tag, false);
        }
    }

    public function test_the_audit_report_page_and_the_admin_pages_share_the_same_tags(): void
    {
        $admin = User::factory()->admin()->withTeam()->create();

        foreach ($this->faviconTags() as $tag) {
            $this->actingAs($admin)->get(route('admin.system'))->assertSee($tag, false);
            $this->get(route('free-audit'))->assertSee($tag, false);
        }
    }

    public function test_the_icon_files_exist_and_the_ico_holds_16_32_and_48_pixels(): void
    {
        foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $ico = (string) file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', $ico);
        $sizes = array_map(fn (int $i) => ord($ico[6 + 16 * $i]), range(0, $header['count'] - 1));
        $this->assertSame([0, 1, 3], [$header['reserved'], $header['type'], $header['count']]);
        $this->assertSame([16, 32, 48], $sizes);

        $this->assertSame([180, 180], array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2));
        $this->assertSame([192, 192], array_slice(getimagesize(public_path('icon-192.png')), 0, 2));
        $this->assertSame([512, 512], array_slice(getimagesize(public_path('icon-512.png')), 0, 2));
        $this->assertStringStartsWith('<svg', (string) file_get_contents(public_path('favicon.svg')));
    }

    public function test_the_manifest_uses_the_app_name_and_brand_colour(): void
    {
        config(['app.name' => 'Acme Visibility', 'citevanta.asset_version' => 3]);

        $response = $this->get('/site.webmanifest')->assertOk();

        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('name', 'Acme Visibility')
            ->assertJsonPath('short_name', 'Acme Visibility')
            ->assertJsonPath('theme_color', '#0088cc')
            ->assertJsonPath('icons.0.src', asset('icon-192.png').'?v=3')
            ->assertJsonPath('icons.1.sizes', '512x512');
    }

    public function test_admins_see_the_version_in_the_footer_and_on_the_system_page_but_other_users_do_not(): void
    {
        config(['citevanta.version' => '9.8.7', 'citevanta.build_date' => '2026-11-24']);
        $label = 'v9.8.7 · 24 Nov 2026';
        $this->assertSame($label, ReleaseInfo::label());

        $admin = User::factory()->admin()->withTeam()->create();
        $this->actingAs($admin)->get(route('dashboard'))->assertSee($label);
        $this->actingAs($admin)->get(route('admin.system'))->assertSee($label)->assertSee('Version');

        $this->actingAs(User::factory()->withTeam()->create())->get(route('dashboard'))->assertDontSee('v9.8.7');
    }

    public function test_admin_system_compares_release_json_with_the_running_version(): void
    {
        config(['citevanta.version' => '1.6.0']);
        $file = base_path('release.json');
        $this->assertFileDoesNotExist($file);
        $admin = User::factory()->admin()->withTeam()->create();

        try {
            file_put_contents($file, StaleCacheGuard::releaseFile('new-id', '1.6.0', '2026-11-24'));
            $this->assertTrue(ReleaseInfo::matchesConfig());
            $this->actingAs($admin)->get(route('admin.system'))->assertSee('new-id')->assertSee('agree')->assertDontSee('half done');
            $this->actingAs($admin)->get(route('dashboard'))->assertSee('new-id');

            file_put_contents($file, StaleCacheGuard::releaseFile('new-id', '1.7.0', '2026-12-01'));
            $this->assertFalse(ReleaseInfo::matchesConfig());
            $this->actingAs($admin)->get(route('admin.system'))->assertSee('half done');
        } finally {
            @unlink($file);
        }
    }
}
