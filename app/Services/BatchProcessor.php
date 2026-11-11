<?php

namespace App\Services;

use App\Models\BatchItem;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BatchProcessor
{
    public function __construct(private TestExecutor $executor, private BatchManager $manager, private VisibilityAnalysis $analysis, private CostControl $costs) {}

    public function process(int $limit = 1): int
    {
        $context = app(TenantContext::class);
        if ($context->organizationId) {
            return $this->processWorkspace($limit);
        }
        abort_unless(app()->runningInConsole(), 403);
        $limit = max(1, min($limit, config('spending.cron_request_limit')));
        $processed = 0;
        $started = microtime(true);
        $batches = DB::table('test_batches')->whereIn('status', ['running', 'paused', 'cancelling'])->orderBy('id')->get(['organization_id', 'monitored_brand_id', 'user_id', 'id']);
        $visited = [];
        foreach ($batches as $batch) {
            $workspaceKey = $batch->organization_id.':'.$batch->monitored_brand_id;
            if (isset($visited[$workspaceKey])) {
                continue;
            }
            if ($processed >= $limit || microtime(true) - $started + config('intelligence.timeout') + 10 > config('spending.runtime_seconds')) {
                break;
            }
            $user = User::find($batch->user_id);
            $membership = DB::table('organization_user')->where('organization_id', $batch->organization_id)->where('user_id', $batch->user_id)->whereIn('role', ['owner', 'administrator'])->exists();
            $active = DB::table('organizations')->where('id', $batch->organization_id)->where('status', 'active')->exists() && DB::table('monitored_brands')->where('id', $batch->monitored_brand_id)->where('organization_id', $batch->organization_id)->where('active', true)->exists();
            if (! $user || ! $membership || ! $active) {
                DB::table('test_batches')->where('id', $batch->id)->where('status', 'running')->update(['status' => 'paused', 'status_note' => 'Organization, brand, or submitting member access is no longer active.']);

                continue;
            }
            $visited[$workspaceKey] = true;
            $processed += $context->run($batch->organization_id, $batch->monitored_brand_id, fn () => $this->processWorkspace($limit - $processed), $user);
        }

        return $processed;
    }

    private function processWorkspace(int $limit = 1): int
    {
        $limit = max(1, min($limit, config('spending.cron_request_limit')));
        $lock = Cache::lock('intelligence:batch-processor', config('spending.runtime_seconds') + 60);
        if (! $lock->get()) {
            return 0;
        }
        $processed = 0;
        $start = microtime(true);
        try {
            $this->recover();
            while ($processed < $limit && microtime(true) - $start + config('intelligence.timeout') + 10 <= config('spending.runtime_seconds')) {
                $item = $this->claim();
                if (! $item) {
                    break;
                }
                try {
                    $batch = $item->batch;
                    $prompt = new SearchPrompt($item->prompt_snapshot);
                    $market = new MarketScenario($item->market_snapshot);
                    $market->search_settings = ['type' => 'approximate', 'country' => $item->market_snapshot['country']];
                    $run = $this->executor->execute($batch->user, $batch->provider, $prompt, $market, $batch->model, $item->submission_id, $item);
                    $processed++;
                    $this->finish($item, $run);
                } catch (ValidationException $exception) {
                    DB::transaction(function () use ($item, $exception) {
                        $batch = TestBatch::lockForUpdate()->findOrFail($item->test_batch_id);
                        $current = BatchItem::lockForUpdate()->findOrFail($item->id);
                        $current->update(['status' => in_array($batch->status, ['cancelled', 'cancelling'], true) ? 'cancelled' : 'pending', 'attempts' => max(0, $current->attempts - 1), 'submission_id' => null, 'claimed_at' => null, 'status_note' => $exception->getMessage()]);
                        if ($current->status === 'cancelled') {
                            app(CreditLedger::class)->cancelBatch($batch);
                        }
                        if ($batch->status === 'running') {
                            $batch->update(['status' => 'paused', 'status_note' => $exception->getMessage()]);
                        }
                    });
                    $this->manager->refresh($item->batch);
                    break;
                } catch (Throwable) {
                    $item->batch->update(['status' => 'paused', 'status_note' => 'Processing was interrupted. Saved work will be reconciled safely on the next processor execution; no uncertain request is automatically repeated.']);
                    break;
                }
            }

            return $processed;
        } finally {
            $lock->release();
        }
    }

    private function claim(): ?BatchItem
    {
        return DB::transaction(function () {
            $candidate = BatchItem::whereIn('status', ['pending', 'retry_wait'])->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->whereHas('batch', fn ($q) => $q->where('status', 'running'))->orderBy('id')->first();
            if (! $candidate) {
                return null;
            }
            $batch = TestBatch::lockForUpdate()->findOrFail($candidate->test_batch_id);
            $item = BatchItem::lockForUpdate()->findOrFail($candidate->id);
            if ($batch->status !== 'running' || ! in_array($item->status, ['pending', 'retry_wait'], true)) {
                return null;
            }
            $item->update(['status' => 'processing', 'attempts' => $item->attempts + 1, 'submission_id' => (string) Str::uuid(), 'claimed_at' => now(), 'status_note' => null]);

            return $item;
        });
    }

    public function recover(): void
    {
        BatchItem::where('status', 'processing')->where('claimed_at', '<', now()->subSeconds(config('spending.lease_seconds')))->each(function (BatchItem $item) {
            $run = TestRun::where('submission_id', $item->submission_id)->first();
            if (! $run) {
                $batch = $item->batch;
                $item->update(['status' => in_array($batch->status, ['cancelled', 'cancelling'], true) ? 'cancelled' : 'pending', 'attempts' => max(0, $item->attempts - 1), 'submission_id' => null, 'claimed_at' => null, 'status_note' => 'Recovered a claim that never created or dispatched an API request.']);
                if ($item->status === 'cancelled') {
                    app(CreditLedger::class)->cancelBatch($batch);
                }
                $this->manager->refresh($batch);

                return;
            }
            if (in_array($run->status, ['completed', 'incomplete', 'failed'], true) && $run->response) {
                $this->costs->settle($run);
                $this->finish($item, $run);

                return;
            }
            $run->update(['status' => 'interrupted']);
            $run->response()->updateOrCreate([], ['status' => 'interrupted', 'error_details' => 'Batch execution ended without a confirmed provider outcome. This request will not be automatically repeated.']);
            $this->costs->settle($run->fresh());
            $item->update(['status' => 'interrupted', 'test_run_id' => $run->id, 'status_note' => 'Provider outcome unknown. Budget reservation retained; this item is never automatically repeated.']);
            if ($item->batch->status === 'running') {
                $item->batch->update(['status' => 'paused', 'status_note' => 'An interrupted request has an unknown outcome. Review it; resume continues pending items only.']);
            }
            $this->manager->refresh($item->batch);
        });
    }

    private function finish(BatchItem $item, TestRun $run): void
    {
        DB::transaction(function () use ($item, $run) {
            $run->load(['response', 'cost']);
            $raw = $run->response?->raw_metadata ?? [];
            $retry = ($raw['http_status'] ?? null) === 429 && ($raw['error']['code'] ?? null) === 'rate_limit_exceeded';
            $batch = TestBatch::lockForUpdate()->findOrFail($item->test_batch_id);
            $item = BatchItem::lockForUpdate()->findOrFail($item->id);
            $status = $run->status === 'completed' ? 'completed' : 'failed';
            $note = $run->response?->error_details;
            if ($run->status === 'completed') {
                try {
                    $this->analysis->analyze($run->response);
                } catch (Throwable) {
                    $note = 'Response saved. Local Phase 2 analysis is pending; reopen the result to retry analysis without another API call.';
                }
            }
            if ($retry && $item->attempts < min(config('spending.max_attempts'), $batch->configuration['max_attempts']) && $batch->status === 'running') {
                $seconds = max(60 * (2 ** ($item->attempts - 1)), (int) ($raw['retry_after_seconds'] ?? 0));
                $status = 'retry_wait';
                $item->available_at = now()->addSeconds(min(86400, $seconds));
                $note = 'Confirmed rate-limit rejection; bounded retry scheduled after backoff. Earlier attempt remains saved.';
            }
            if ($batch->status === 'running' && (in_array($raw['http_status'] ?? null, [400, 401, 403, 404], true) || (($raw['http_status'] ?? null) === 429 && ! $retry))) {
                $batch->update(['status' => 'paused', 'status_note' => 'Provider credentials, billing, model access, or request configuration requires attention. Review the saved error before resuming pending work.']);
            }
            if ($run->cost && in_array($run->cost->status, ['uncertain', 'reserved', 'overrun'], true)) {
                if ($status !== 'completed') {
                    $status = 'interrupted';
                }
                if ($batch->status === 'running') {
                    $batch->update(['status' => 'paused', 'status_note' => 'A request has uncertain cost or outcome. Its reservation is retained. Review before resuming pending work; uncertain items are not repeated.']);
                }
            }
            if ($batch->pilot_key && $status !== 'completed' && $batch->status === 'running') {
                $batch->update(['status' => 'paused', 'status_note' => 'Pilot paused after an unsuccessful result. Review the saved failure before explicitly resuming remaining items; automatic retries are disabled.']);
            }
            if ($batch->pilot_key && config('pilot.first_live_review_required') && $batch->runs()->count() === 1) {
                $batch->update(['status' => in_array($batch->status, ['cancelled', 'cancelling'], true) ? $batch->status : 'paused', 'status_note' => 'First pilot attempt saved. Validate its evidence and reconciled cost, approve its quality review, then explicitly approve continuation before any remaining request.', 'pilot_enabled_at' => null, 'pilot_approved_by' => null, 'pilot_approval_fingerprint' => null]);
            }
            $item->fill(['status' => $status, 'test_run_id' => $run->id, 'status_note' => $note, 'claimed_at' => null]);
            $item->save();
            if (in_array($batch->status, ['cancelled', 'cancelling'], true) && $status === 'retry_wait') {
                $item->update(['status' => 'cancelled']);
            }
            $this->manager->refresh($batch);
        });
    }
}
