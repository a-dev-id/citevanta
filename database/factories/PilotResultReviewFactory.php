<?php

namespace Database\Factories;

use App\Models\TestRun;
use App\Services\PilotQuality;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class PilotResultReviewFactory extends Factory
{
    public function definition(): array
    {
        $run = TestRun::whereHas('batch', fn ($q) => $q->whereNotNull('pilot_key'))->latest('id')->firstOrFail();

        return ['test_batch_id' => $run->test_batch_id, 'test_run_id' => $run->id, 'reviewed_by' => app(TenantContext::class)->actorId, 'decision' => 'flagged', 'note' => 'Fixture requires review', 'checks' => [], 'evidence_fingerprint' => app(PilotQuality::class)->inspect($run)['fingerprint']];
    }
}
