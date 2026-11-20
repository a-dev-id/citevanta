<?php

namespace App\Services\Trials;

use App\Enums\PromptSource;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanInsightsJob;
use App\Models\Audit;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Services\Scanning\ScanFinalizer;
use Illuminate\Support\Facades\DB;

/**
 * A trial that came from a free audit starts with the audit's results: its
 * questions (as inactive prompts marked "audit"), answers, citations and
 * analysis are copied into the hotel's first completed scan ("From your free
 * audit"), so no setup scan runs. The trial's own active prompts are used
 * from the next scan on.
 *
 * Copied answers carry no token counts: the audit's cost stays with the
 * audit (Admin > Audits) and is not counted again for the trial.
 */
final class AuditImporter
{
    public function __construct(private readonly ScanFinalizer $finalizer) {}

    /**
     * The audit's scan, if this hotel can start from it.
     */
    public function sourceScan(Hotel $hotel): ?ScanRun
    {
        $audit = $hotel->source_audit_id ? Audit::query()->with(['scanRun', 'source.scanRun'])->find($hotel->source_audit_id) : null;
        $scan = $audit?->scanRun ?? $audit?->source?->scanRun;

        if ($scan === null || ! in_array($scan->status->value, ScanStatus::finishedWithResults(), true) || $hotel->scanRuns()->exists()) {
            return null;
        }

        return $scan;
    }

    public function import(Hotel $hotel, ScanRun $source): ScanRun
    {
        $scanRun = DB::transaction(function () use ($hotel, $source): ScanRun {
            $prompts = [];

            foreach ($source->hotel->prompts()->withTrashed()->whereIn('id', $source->prompt_ids ?? [])->orderBy('id')->get() as $prompt) {
                $prompts[$prompt->id] = $hotel->prompts()->create([
                    'text' => $prompt->text,
                    'persona' => $prompt->persona,
                    'language' => $prompt->language,
                    'source' => PromptSource::Audit,
                    'is_active' => false,
                ])->id;
            }

            $scanRun = $hotel->scanRuns()->create([
                'engine' => $source->engine,
                'model' => $source->model,
                'trigger' => ScanTrigger::AuditImport,
                'prompt_ids' => array_values($prompts),
                'runs_per_prompt' => $source->runs_per_prompt,
                'status' => ScanStatus::Running,
                'started_at' => $source->started_at,
                'cost_usd' => 0,
            ]);

            foreach ($source->responses()->with(['citations', 'analysis'])->get() as $response) {
                if (! isset($prompts[$response->prompt_id])) {
                    continue;
                }

                $copy = $scanRun->responses()->create([
                    'prompt_id' => $prompts[$response->prompt_id],
                    'engine' => $response->engine,
                    'model' => $response->model,
                    'run_number' => $response->run_number,
                    'answer_text' => $response->answer_text,
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'web_search_calls' => 0,
                ]);

                foreach ($response->citations as $citation) {
                    $copy->citations()->create($citation->only(['url', 'domain', 'title']));
                }

                if ($response->analysis !== null) {
                    $copy->analysis()->create($response->analysis->only([
                        'hotel_mentioned', 'position', 'sentiment', 'competitors_mentioned', 'other_hotels_mentioned', 'summary',
                    ]) + ['tokens_in' => 0, 'tokens_out' => 0]);
                }
            }

            return $scanRun;
        });

        $scanRun = $this->finalizer->finalize($scanRun);

        // Recommendations and accuracy checks for the trial's first results.
        ScanInsightsJob::dispatch($scanRun->id);

        return $scanRun;
    }
}
