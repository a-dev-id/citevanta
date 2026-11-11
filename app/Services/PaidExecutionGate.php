<?php

namespace App\Services;

use App\Models\BatchItem;
use App\Models\CostEntry;
use App\Models\CreditEntry;
use App\Models\Organization;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiRequestException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaidExecutionGate
{
    private ?int $searchId = null;

    public function forSearch(TestRun $run, Closure $callback): array
    {
        if ($this->searchId !== null || ! $run->exists) {
            $this->deny();
        }
        $this->searchId = $run->id;
        try {
            return $callback();
        } finally {
            $this->searchId = null;
        }
    }

    /** @param array<string, mixed> $settings */
    public function consume(array $settings): void
    {
        if (DB::transactionLevel() !== 0 || $this->searchId === null || ! Schema::hasTable('paid_execution_authorizations')) {
            $this->deny();
        }
        DB::transaction(function () use ($settings) {
            $grant = DB::table('paid_execution_authorizations')->where('id', 1)->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC');
            if (! $grant || $grant->status !== 'active' || $grant->consumed_at !== null || $grant->consumed_test_run_id !== null || ! $grant->activated_at || ! $grant->expires_at || CarbonImmutable::parse($grant->activated_at, 'UTC')->gt($now) || CarbonImmutable::parse($grant->expires_at, 'UTC')->lte($now) || ! User::whereKey($grant->approved_by)->where('is_platform_admin', true)->exists()) {
                $this->deny();
            }
            $context = app(TenantContext::class);
            if ($context->organizationId !== 1 || $context->brandId !== 1 || config('intelligence.model') !== 'gpt-6-luna' || ! config('pilot.first_live_review_required') || config('spending.automatic_organization_limits')) {
                $this->deny();
            }
            $financial = SpendingSetting::whereKey(1)->lockForUpdate()->first();
            $month = DB::table('budget_months')->where('month', $now->format('Y-m'))->lockForUpdate()->first();
            $batch = TestBatch::whereKey(1)->lockForUpdate()->first();
            $organization = Organization::whereKey(1)->lockForUpdate()->first();
            $run = TestRun::whereKey($this->searchId)->lockForUpdate()->first();
            if (! $financial || ! $month || $month->opening_micros === null || ! $organization || $organization->is_test_fixture || $organization->status !== 'active' || ! $batch || ! $run || $run->is_simulated || $run->status !== 'running' || $run->test_batch_id !== 1 || $batch->model !== 'gpt-6-luna' || $batch->user_id !== $run->user_id || $batch->ai_provider_id !== $run->ai_provider_id || ! $run->batch_item_id || $run->model !== 'gpt-6-luna' || $run->provider->identifier !== 'openai' || ! $run->provider->enabled || $financial->monthly_limit_micros > 2000000 || $organization->monthly_limit_micros <= 0 || $organization->monthly_limit_micros > 1500000 || $batch->pilot_budget_micros <= 0 || $batch->pilot_budget_micros > 650000) {
                $this->deny();
            }
            $item = BatchItem::whereKey($run->batch_item_id)->lockForUpdate()->first();
            $cost = CostEntry::where('test_run_id', $run->id)->lockForUpdate()->first();
            $credit = CreditEntry::where('organization_id', 1)->where('test_run_id', $run->id)->lockForUpdate()->first();
            $expected = ['model' => 'gpt-6-luna', 'input' => $item?->prompt_snapshot['text'] ?? null, 'instructions' => 'Answer in English. Use web search to answer the query. Clearly distinguish recommendations from businesses only mentioned for context. Cite sources.', 'tools' => [['type' => 'web_search', 'user_location' => ['type' => 'approximate', 'country' => 'AU']]], 'tool_choice' => 'required', 'max_output_tokens' => config('intelligence.max_output_tokens'), 'max_tool_calls' => 1, 'store' => false];
            if (! $item || $item->test_batch_id !== 1 || $item->id !== (int) $batch->items()->min('id') || $item->status !== 'processing' || $item->attempts !== 1 || $item->submission_id !== $run->submission_id || $item->search_prompt_id !== $run->search_prompt_id || $item->market_scenario_id !== $run->market_scenario_id || (int) $run->repetition !== 1 || $batch->status !== 'running' || $batch->runs()->count() !== 1 || $run->response()->exists() || ! $cost || $cost->status !== 'reserved' || (int) $cost->budget_month_id !== (int) $month->id || ! $credit || $credit->status !== 'reserved' || $credit->user_id !== $run->user_id || $credit->operation_key !== 'batch:'.$item->id || $credit->provider !== 'openai' || $credit->model !== 'gpt-6-luna' || $credit->batch_item_id !== $item->id || $context->actorId !== $run->user_id || ! DB::table('organization_user')->where('organization_id', 1)->where('user_id', $run->user_id)->whereIn('role', ['owner', 'administrator'])->exists() || ! DB::table('monitored_brands')->where('organization_id', 1)->where('id', 1)->where('active', true)->exists() || PilotQuality::hash($settings) !== PilotQuality::hash($expected) || PilotQuality::hash($run->request_settings) !== PilotQuality::hash($settings)) {
                $this->deny();
            }
            $study = app(PilotStudy::class);
            $study->assertConfiguration($batch);
            $fingerprint = $study->fingerprint($batch);
            if (! $batch->pilot_enabled_at || ! $batch->pilot_approved_by || ! DB::table('organization_user')->where('organization_id', 1)->where('user_id', $batch->pilot_approved_by)->whereIn('role', ['owner', 'administrator'])->exists() || ! hash_equals($fingerprint, $batch->pilot_approval_fingerprint ?? '') || ! hash_equals($fingerprint, $grant->pilot_fingerprint ?? '')) {
                $this->deny();
            }
            $quote = app(RequestPricing::class)->quote($expected['input'], 'gpt-6-luna', $financial->safety_percent);
            $period = app(CreditLedger::class)->balance();
            if ($cost->pricing_snapshot != $quote['pricing'] || $cost->reserved_micros !== $quote['reserve'] || $cost->committed_micros !== $quote['reserve'] || $credit->credit_period_id !== $period['period']->id || $credit->credits !== app(CreditLedger::class)->weight('openai', 'gpt-6-luna') || $period['allowance'] < $period['used'] + $period['reserved'] || app(OrganizationQuota::class)->committed() + $month->opening_micros > $organization->monthly_limit_micros || $batch->costs()->sum('committed_micros') > $batch->pilot_budget_micros || $organization->brands()->where('active', true)->count() > app(PlanCatalog::class)->current()['brands'] || $organization->members()->count() > app(PlanCatalog::class)->current()['members']) {
                $this->deny();
            }
            app(CostControl::class)->assertAvailable(0);
            $updated = DB::table('paid_execution_authorizations')->where('id', 1)->where('status', 'active')->whereNull('consumed_at')->whereNull('consumed_test_run_id')->update(['status' => 'consumed', 'consumed_at' => $now, 'consumed_test_run_id' => $run->id, 'request_fingerprint' => PilotQuality::hash($settings), 'updated_at' => $now]);
            if ($updated !== 1) {
                $this->deny();
            }
        });
    }

    private function deny(): never
    {
        throw new OpenAiRequestException('Paid execution is not authorized. No provider request was sent.', ['error' => ['code' => 'paid_execution_denied']]);
    }
}
