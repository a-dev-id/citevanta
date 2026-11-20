<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
