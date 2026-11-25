<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function adminPages(): array
    {
        return [['/admin/teams'], ['/admin/hotels'], ['/admin/scan-runs'], ['/admin/failed-jobs'], ['/admin/system']];
    }

    #[DataProvider('adminPages')]
    public function test_regular_users_get_403_on_admin_pages(string $url): void
    {
        $this->actingAs(User::factory()->withTeam()->create())->get($url)->assertForbidden();
    }

    #[DataProvider('adminPages')]
    public function test_admins_can_open_admin_pages(string $url): void
    {
        $this->actingAs(User::factory()->admin()->withTeam()->create())->get($url)->assertOk();
    }

    public function test_admin_menu_is_only_shown_to_admins(): void
    {
        $this->actingAs(User::factory()->withTeam()->create())->get('/dashboard')->assertDontSee('Failed jobs');
        $this->actingAs(User::factory()->admin()->withTeam()->create())->get('/dashboard')->assertSee('Failed jobs');
    }

    /**
     * Right after a new release is uploaded its tables do not exist yet; the
     * System page (with the "Run deploy tasks" button) must still open.
     */
    public function test_the_system_page_opens_before_new_tables_are_created(): void
    {
        $admin = User::factory()->admin()->withTeam()->create();

        // The live database is on the release before the free audit (19 Nov 2026):
        // undo every migration since then, as if the new zip was just extracted.
        Artisan::call('migrate:rollback', ['--step' => 17]);
        $this->assertFalse(Schema::hasTable('audits'));

        $this->actingAs($admin)->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Database updates are waiting')
            ->assertSee('Run deploy tasks');
    }
}
