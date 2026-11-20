<?php

namespace Tests\Feature\Insights;

use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_scan_runs_show_the_cost_split_by_purpose(): void
    {
        ScanRun::factory()->create([
            'cost_usd' => 0.1234,
            'cost_breakdown' => ['scan' => 0.1, 'analysis' => 0.02, 'accuracy' => 0.0024, 'competitor_reasons' => 0.001, 'content' => 0],
        ]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.scan-runs'))
            ->assertOk()
            ->assertSeeInOrder(['$0.1234', 'Scan', '$0.1000', 'Analysis', '$0.0200', 'Accuracy', '$0.0024', 'Competitor reasons', '$0.0010'])
            ->assertDontSee('Content');
    }
}
