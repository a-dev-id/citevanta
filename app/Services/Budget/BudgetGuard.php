<?php

namespace App\Services\Budget;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use App\Enums\ScanStatus;
use App\Mail\Budget\RunawayScanStopped;
use App\Models\AiUsage;
use App\Models\ScanRun;
use App\Services\Ai\OpenAiKeyScope;
use App\Services\Ai\TeamAiKey;
use App\Services\Scanning\CostCalculator;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use OpenAI\Exceptions\ErrorException;
use Throwable;

/**
 * The one place every AI call goes through.
 *
 * Before the call (authorize):
 *  1. OpenAI billing stop active: refuse everything.
 *  2. Trial not verified or not approved: refuse.
 *  3. Runaway guard: a scan may not make more calls than its plan allows.
 *  4. Estimate = measured average x 1.5. Refuse when the total would go
 *     over, when the total is above 90% and this is not a paid scheduled
 *     scan, or when the bucket (audits, trials, admin) would go over.
 *
 * After the call (record): the real cost from the API usage goes into
 * ai_usages, and the 50/80/100% alerts are checked.
 */
final class BudgetGuard
{
    public function __construct(
        private readonly AiSpend $spend,
        private readonly BillingStop $billingStop,
        private readonly BudgetAlerts $alerts,
        private readonly CostCalculator $costs,
        private readonly AdminMailer $admins,
    ) {}

    /**
     * @return float the estimated cost of the call
     *
     * @throws BudgetExceeded
     */
    public function authorize(AiCallContext $context, AiCallKind $kind): float
    {
        if (self::usesTeamKey($context)) {
            return $this->authorizeTeamKey($context, $kind);
        }

        if ($this->billingStop->isActive()) {
            throw new BudgetExceeded(BudgetExceeded::BILLING_STOP, 'AI calls are stopped: OpenAI credit or spend limit reached.');
        }

        $team = $context->team;
        if ($team !== null && $team->isOnTrial()
            && ($team->awaitsTrialApproval() || ! $team->owners()->whereNotNull('email_verified_at')->exists())) {
            throw new BudgetExceeded(BudgetExceeded::TRIAL_HOLD, 'This trial is waiting for email verification or approval.');
        }

        $this->guardRunaway($context);

        $estimate = $this->spend->estimate($kind);
        $total = $this->spend->total();
        $totalCap = $this->spend->totalCap();

        if ($total + $estimate > $totalCap) {
            throw new BudgetExceeded(BudgetExceeded::TOTAL, 'The monthly AI budget is used up.');
        }

        if (! $context->isPaidScheduled && $total >= $totalCap * (float) config('citevanta.budget.priority_percent', 90) / 100) {
            throw new BudgetExceeded(BudgetExceeded::PRIORITY, 'The monthly AI budget is almost used up: only scheduled scans of paying customers run.');
        }

        if ($context->bucket !== AiBucket::Paid && $this->spend->spent($context->bucket) + $estimate > $this->spend->cap($context->bucket)) {
            throw new BudgetExceeded(BudgetExceeded::BUCKET, "The monthly budget for {$context->bucket->label()} is used up.");
        }

        return $estimate;
    }

    /**
     * Authorize, make the call, record its real cost. An OpenAI billing
     * error starts the billing stop and is never retried.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @param  Closure(T): array{0: string, 1: int, 2: int, 3?: int}  $usage  model, tokens in, tokens out, web searches
     * @return T
     *
     * @throws BudgetExceeded
     */
    public function run(AiCallContext $context, AiCallKind $kind, Closure $call, Closure $usage): mixed
    {
        $this->authorize($context, $kind);
        $teamKey = self::usesTeamKey($context) ? (string) $context->team->openai_key : null;

        try {
            $result = app(OpenAiKeyScope::class)->using($teamKey, $call);
        } catch (Throwable $e) {
            // The team's own key: only this team stops; never fall back to our key.
            if ($teamKey !== null && ($code = self::teamKeyErrorCode($e)) !== null) {
                app(TeamAiKey::class)->stop($context->team, $code);

                throw new BudgetExceeded(BudgetExceeded::TEAM_KEY, TeamAiKey::stopMessage($code));
            }

            if ($teamKey === null && BillingStop::isBillingError($e)) {
                $this->billingStop->trigger($e);

                throw new BudgetExceeded(BudgetExceeded::BILLING_STOP, 'OpenAI credit or spend limit reached.');
            }

            throw $e;
        }

        $measured = $usage($result);
        $this->record($context, $kind, $measured[0], $measured[1], $measured[2], $measured[3] ?? 0);

        return $result;
    }

    public function record(AiCallContext $context, AiCallKind $kind, string $model, int $tokensIn, int $tokensOut, int $webSearches = 0): AiUsage
    {
        $billedToTeam = self::usesTeamKey($context);

        $usage = AiUsage::create([
            'bucket' => $context->bucket,
            'billed_to_team' => $billedToTeam,
            'kind' => $kind,
            'team_id' => $context->team?->id,
            'hotel_id' => $context->hotelId,
            'scan_run_id' => $context->scanRun?->id,
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'web_searches' => $webSearches,
            'cost_usd' => round($this->costs->tokens($model, $tokensIn, $tokensOut) + $this->costs->webSearch($webSearches), 6),
            'created_at' => now(),
        ]);

        $billedToTeam ? $this->alerts->checkTeam($context->team) : $this->alerts->check();

        return $usage;
    }

    /**
     * Private-plan teams pay every AI call with their own OpenAI key.
     */
    public static function usesTeamKey(AiCallContext $context): bool
    {
        return $context->team !== null && $context->team->isPrivate();
    }

    /**
     * Team key: no key, a stopped key or the team's own monthly budget refuse
     * the call. Our buckets, total and priority do not apply.
     */
    private function authorizeTeamKey(AiCallContext $context, AiCallKind $kind): float
    {
        $team = $context->team;

        if (! $team->hasOwnAiKey()) {
            throw new BudgetExceeded(BudgetExceeded::TEAM_KEY, 'Add your OpenAI API key in Settings > AI key to run scans and create content.');
        }

        if ($team->openai_key_stopped_at !== null) {
            throw new BudgetExceeded(BudgetExceeded::TEAM_KEY, TeamAiKey::stopMessage($team->openai_key_stop_code));
        }

        $this->guardRunaway($context);

        $estimate = $this->spend->estimate($kind);

        if (app(TeamAiKey::class)->spentThisMonth($team) + $estimate > (float) $team->ai_budget_usd) {
            throw new BudgetExceeded(BudgetExceeded::TEAM_BUDGET, TeamAiKey::stopMessage('team_budget'));
        }

        return $estimate;
    }

    /**
     * An error from the team's key that only the owner can fix: no credit,
     * a spend limit, or an invalid key.
     */
    private static function teamKeyErrorCode(Throwable $e): ?string
    {
        if (($code = BillingStop::errorCode($e)) !== null) {
            return $code;
        }

        if ($e instanceof ErrorException) {
            $errorCode = (string) $e->getErrorCode();

            if (in_array($errorCode, TeamAiKey::AUTH_CODES, true) || $e->getStatusCode() === 401) {
                return $errorCode !== '' ? $errorCode : 'invalid_api_key';
            }
        }

        return null;
    }

    /**
     * The most AI calls a scan may make: every planned call answered and
     * analysed, plus its insight calls. More means a loop: abort and alert.
     */
    public function maxCalls(ScanRun $scanRun): int
    {
        $planned = max($scanRun->plannedCalls(), count($scanRun->prompt_ids ?? []));

        return $planned * 2 + (int) config('citevanta.budget.max_insight_calls_per_scan', 40);
    }

    private function guardRunaway(AiCallContext $context): void
    {
        $scanRun = $context->scanRun;

        if ($scanRun === null) {
            return;
        }

        $made = AiUsage::query()->where('scan_run_id', $scanRun->id)->count();
        $max = $this->maxCalls($scanRun);

        if ($made < $max) {
            return;
        }

        if ($scanRun->status->isInProgress()) {
            if ($scanRun->batch_id !== null) {
                Bus::findBatch($scanRun->batch_id)?->cancel();
            }

            $scanRun->update([
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'errors' => [...($scanRun->errors ?? []), "Stopped: {$made} AI calls reached the limit of {$max} for this scan."],
            ]);
        }

        if (Cache::add('citevanta:runaway-alert:'.$scanRun->id, true, now()->addDays(7))) {
            $this->admins->send(fn () => new RunawayScanStopped($scanRun->id, $made, $max));
        }

        throw new BudgetExceeded(BudgetExceeded::RUNAWAY, "Scan #{$scanRun->id} stopped: too many AI calls.");
    }
}
