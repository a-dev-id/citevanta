<?php

namespace App\Services\Scanning;

use App\Models\ScanRun;
use App\Services\Plans\PlanGuard;

/**
 * Decides which calls a scan makes (prompts x runs x engines) and saves that
 * plan on the scan run, so retries and progress always use the same set.
 */
final class ScanPlanner
{
    public function __construct(private readonly PlanGuard $plans) {}

    /**
     * Fill in whatever the scan run does not have yet: the hotel's active
     * prompts, the plan's runs per prompt and the plan's (existing) engines.
     */
    public function plan(ScanRun $scanRun): ScanRun
    {
        $hotel = $scanRun->hotel;
        $changes = [];

        if ($scanRun->prompt_ids === null) {
            $changes['prompt_ids'] = $hotel->prompts()->where('is_active', true)->orderBy('id')->pluck('id')->all();
        }

        if ($scanRun->runs_per_prompt === null) {
            $changes['runs_per_prompt'] = $this->plans->runsPerPrompt($hotel);
        }

        if ($scanRun->engineList() === []) {
            $changes['engine'] = implode(',', $this->plans->engines($hotel));
        }

        if ($changes !== []) {
            $scanRun->update($changes);
        }

        return $scanRun;
    }

    /**
     * @return list<PlannedCall>
     */
    public function calls(ScanRun $scanRun): array
    {
        $calls = [];

        foreach ($scanRun->prompt_ids ?? [] as $promptId) {
            for ($run = 1; $run <= (int) $scanRun->runs_per_prompt; $run++) {
                foreach ($scanRun->engineList() as $engine) {
                    $calls[] = new PlannedCall((int) $promptId, $run, $engine);
                }
            }
        }

        return $calls;
    }

    /**
     * Planned calls that have no analysed answer yet.
     *
     * @return list<PlannedCall>
     */
    public function missingCalls(ScanRun $scanRun): array
    {
        $done = $scanRun->responses()
            ->whereHas('analysis')
            ->get(['prompt_id', 'run_number', 'engine'])
            ->map(fn ($response) => "{$response->prompt_id}:{$response->run_number}:{$response->engine}")
            ->flip();

        return array_values(array_filter(
            $this->calls($scanRun),
            fn (PlannedCall $call) => ! $done->has("{$call->promptId}:{$call->runNumber}:{$call->engine}"),
        ));
    }
}
