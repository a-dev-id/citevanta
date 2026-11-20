<?php

namespace App\Services\Insights;

use App\Enums\ScanStatus;
use App\Jobs\AccuracyCheckJob;
use App\Jobs\CompetitorReasonJob;
use App\Jobs\RecommendationWriterJob;
use App\Models\ScanRun;
use App\Services\Insights\Accuracy\AccuracyService;
use App\Services\Insights\Competitors\CompetitorReasonService;
use App\Services\Recommendations\RecommendationService;
use App\Services\Support\ApiRetry;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * What runs after a scan is finalised: the insight jobs (one AI call each)
 * and, once they are done, the wrap-up (auto-resolve, cost breakdown).
 *
 * Queued scans: ScanInsightsJob dispatches jobs() as a batch and
 * FinalizeInsightsJob runs finish(). scan:test and tests: runNow().
 */
final class ScanInsights
{
    public function __construct(
        private readonly AccuracyService $accuracy,
        private readonly CompetitorReasonService $competitors,
        private readonly RecommendationService $recommendations,
        private readonly InsightCalls $calls,
        private readonly ApiRetry $retry,
    ) {}

    /**
     * @return list<object> one job per AI call
     */
    public function jobs(ScanRun $scanRun): array
    {
        if (! in_array($scanRun->status->value, ScanStatus::finishedWithResults(), true)) {
            return [];
        }

        $jobs = [];

        if (config('citevanta.insights.accuracy.enabled')) {
            $responseIds = $scanRun->responses()
                ->whereHas('analysis', fn ($query) => $query->where('hotel_mentioned', true))
                ->orderBy('id')
                ->pluck('id');

            foreach ($responseIds as $responseId) {
                $jobs[] = new AccuracyCheckJob($scanRun->id, (int) $responseId);
            }
        }

        if (config('citevanta.insights.competitor_reasons.enabled')) {
            foreach ($this->competitors->candidates($scanRun) as $candidate) {
                $jobs[] = new CompetitorReasonJob($scanRun->id, $candidate);
            }
        }

        return $jobs;
    }

    /**
     * After the insight jobs: resolve stale issues, refresh the hotel's
     * recommendations from all the evidence, queue the AI wording (one call)
     * and store the final cost.
     *
     * @param  bool  $queueWriter  false: run the writer in this process (runNow)
     */
    public function finish(ScanRun $scanRun, bool $queueWriter = true): void
    {
        $scanRun->loadMissing('hotel');
        $hasResults = in_array($scanRun->status->value, ScanStatus::finishedWithResults(), true);

        if (config('citevanta.insights.accuracy.enabled') && $hasResults) {
            $this->accuracy->resolveStale($scanRun->hotel, $scanRun);
        }

        if (config('citevanta.recommendations.enabled') && $hasResults) {
            $this->recommendations->refresh($scanRun);

            if (config('citevanta.recommendations.ai_writing')) {
                $job = new RecommendationWriterJob($scanRun->id);

                if ($queueWriter) {
                    dispatch($job);
                } else {
                    try {
                        $this->retry->run(fn () => Bus::dispatchNow($job));
                    } catch (Throwable $e) {
                        $job->failed($e);
                    }
                }
            }
        }

        $this->calls->refreshCost($scanRun);
    }

    /**
     * Run every insight job in this process (scan:test, tests).
     */
    public function runNow(ScanRun $scanRun): void
    {
        foreach ($this->jobs($scanRun->fresh()) as $job) {
            try {
                $this->retry->run(fn () => Bus::dispatchNow($job));
            } catch (Throwable $e) {
                $job->failed($e);
            }
        }

        $this->finish($scanRun->fresh(), queueWriter: false);
    }
}
