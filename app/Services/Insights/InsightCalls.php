<?php

namespace App\Services\Insights;

use App\Enums\AiCallKind;
use App\Enums\AiPurpose;
use App\Models\AiCall;
use App\Models\ScanRun;
use App\Services\Budget\AiCallContext;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetGuard;
use App\Services\Budget\BudgetPauser;
use App\Services\Scanning\CostCalculator;
use App\Services\Scanning\ScanCost;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bookkeeping for insight AI calls (ai_calls): whether a call already ran
 * (jobs are idempotent), its cost, and the scan's cost breakdown.
 */
final class InsightCalls
{
    public function __construct(
        private readonly CostCalculator $costs,
        private readonly ScanCost $scanCost,
    ) {}

    /**
     * Make an insight call through the budget guard. The result must have
     * model, tokensIn and tokensOut.
     *
     * @template T of object
     *
     * @param  \Closure(): T  $call
     * @return T
     *
     * @throws BudgetExceeded
     */
    public function guarded(ScanRun $scanRun, \Closure $call): object
    {
        return app(BudgetGuard::class)->run(
            AiCallContext::forScan($scanRun),
            AiCallKind::Insight,
            $call,
            fn (object $result) => [(string) $result->model, (int) $result->tokensIn, (int) $result->tokensOut],
        );
    }

    /**
     * The budget refused an insight call: resume later from Admin > System.
     */
    public function pause(ScanRun $scanRun): void
    {
        app(BudgetPauser::class)->pauseInsights($scanRun);
    }

    public function done(ScanRun $scanRun, AiPurpose $purpose, string $subject): bool
    {
        return AiCall::query()
            ->where('scan_run_id', $scanRun->id)
            ->where('purpose', $purpose->value)
            ->where('subject', $subject)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function record(ScanRun $scanRun, AiPurpose $purpose, string $subject, string $model, int $tokensIn, int $tokensOut, array $result): AiCall
    {
        return AiCall::create([
            'team_id' => $scanRun->hotel?->team_id,
            'hotel_id' => $scanRun->hotel_id,
            'scan_run_id' => $scanRun->id,
            'purpose' => $purpose,
            'subject' => $subject,
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd' => round($this->costs->tokens($model, $tokensIn, $tokensOut), 6),
            'result' => $result,
        ]);
    }

    /**
     * A call that failed for good: stored so it is not retried and shows in admin.
     * Only the exception message is kept (never request data or keys).
     */
    public function recordFailure(ScanRun $scanRun, AiPurpose $purpose, string $subject, Throwable $e): void
    {
        AiCall::firstOrCreate(
            ['scan_run_id' => $scanRun->id, 'purpose' => $purpose->value, 'subject' => $subject],
            [
                'team_id' => $scanRun->hotel?->team_id,
                'hotel_id' => $scanRun->hotel_id,
                'model' => (string) config('citevanta.analysis_model'),
                'error' => Str::limit(class_basename($e).': '.$e->getMessage(), 480),
            ],
        );
    }

    public function refreshCost(ScanRun $scanRun): void
    {
        $this->scanCost->refresh($scanRun);
    }
}
