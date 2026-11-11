<?php

namespace App\Services;

use App\Models\BatchItem;
use App\Models\BudgetMonth;
use App\Models\CostEntry;
use App\Models\ImprovementExplanation;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\TestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CostControl
{
    public function __construct(private RequestPricing $pricing) {}

    public function settings(): SpendingSetting
    {
        return SpendingSetting::firstOrCreate(['id' => 1], ['monthly_limit_micros' => config('spending.monthly_limit_micros'), 'batch_request_limit' => config('spending.batch_request_limit'), 'safety_percent' => config('spending.safety_percent')]);
    }

    public function month(): BudgetMonth
    {
        $month = now('UTC')->format('Y-m');
        $existing = BudgetMonth::where('month', $month)->first();
        if ($existing) {
            return $existing;
        }
        $legacy = TestRun::withoutGlobalScopes()->where('is_simulated', false)->where('executed_at', '>=', now('UTC')->startOfMonth())->where('executed_at', '<', now('UTC')->startOfMonth()->addMonth())->whereDoesntHave('cost', fn ($q) => $q->withoutGlobalScopes());
        $last = (clone $legacy)->max('id') ?? 0;

        return BudgetMonth::firstOrCreate(['month' => $month], ['opening_micros' => $last ? null : 0, 'opening_through_id' => $last]);
    }

    public function balance(): array
    {
        $settings = $this->settings();
        $month = $this->month();
        $committed = (int) CostEntry::withoutGlobalScopes()->where('budget_month_id', $month->id)->sum('committed_micros');

        return ['settings' => $settings, 'month' => $month, 'committed' => $committed, 'opening' => $month->opening_micros, 'used' => $month->opening_micros === null ? null : $month->opening_micros + $committed, 'remaining' => $month->opening_micros === null ? null : max(0, $settings->monthly_limit_micros - $month->opening_micros - $committed), 'uncertain' => CostEntry::withoutGlobalScopes()->where('budget_month_id', $month->id)->whereIn('status', ['reserved', 'uncertain', 'overrun'])->count()];
    }

    public function tenantBalance(): array
    {
        $quota = app(OrganizationQuota::class);
        $organization = app(TenantContext::class)->organization();
        $organizationLimit = config('spending.automatic_organization_limits') ? app(SubscriptionFinancialProtection::class)->limit() : $organization->monthly_limit_micros;
        $settings = new SpendingSetting(['monthly_limit_micros' => $organizationLimit, 'batch_request_limit' => min($this->settings()->batch_request_limit, $quota->plan()['batch_size']), 'safety_percent' => $this->settings()->safety_percent]);
        $opening = $quota->opening();
        $committed = $quota->committed();

        return ['settings' => $settings, 'month' => new BudgetMonth(['month' => now('UTC')->format('Y-m')]), 'opening' => $opening, 'committed' => $committed, 'used' => $opening === null ? null : $opening + $committed, 'remaining' => $opening === null ? null : max(0, $organizationLimit - $opening - $committed), 'uncertain' => CostEntry::whereIn('status', ['reserved', 'uncertain', 'overrun'])->count()];
    }

    public function assertAvailable(int $reserve): void
    {
        $b = $this->balance();
        if (! config('spending.execution_enabled')) {
            throw ValidationException::withMessages(['service' => 'Monitoring is currently unavailable. Please try again later or contact support.']);
        }
        if ($b['opening'] === null) {
            throw ValidationException::withMessages(['cost' => 'Confirm this UTC month\'s pre-control spending allowance in Cost Controls before starting requests.']);
        }
        if ($b['settings']->monthly_limit_micros === 0 || $reserve > $b['remaining']) {
            throw ValidationException::withMessages(['cost' => 'Monthly application spending limit reached or insufficient budget for the safety reservation.']);
        }
    }

    public function reserve(array $attributes, ?BatchItem $item = null): TestRun
    {
        return DB::transaction(function () use ($attributes, $item) {
            if ($attributes['model'] !== config('intelligence.model')) {
                throw ValidationException::withMessages(['model' => 'The monitoring model changed. Create a fresh preview with the current model.']);
            }
            $settings = $this->settings();
            $settings = SpendingSetting::lockForUpdate()->findOrFail($settings->id);
            $month = $this->month();
            BudgetMonth::whereKey($month->id)->lockForUpdate()->firstOrFail();
            $quote = $this->pricing->quote($attributes['prompt_text'], $attributes['model'], $settings->safety_percent);
            if ($item) {
                $batch = TestBatch::lockForUpdate()->findOrFail($item->test_batch_id);
                app(OrganizationQuota::class)->assertBatch($batch->request_count, (int) $batch->configuration['repetitions']);
                $current = BatchItem::lockForUpdate()->findOrFail($item->id);
                if ($batch->status !== 'running' || $current->status !== 'processing' || $current->submission_id !== $attributes['submission_id']) {
                    throw ValidationException::withMessages(['batch' => 'The batch was stopped before dispatch. No API request was sent.']);
                }
                if ($batch->runs()->count() >= min($batch->request_limit, $settings->batch_request_limit)) {
                    throw ValidationException::withMessages(['batch' => 'Per-batch API request limit reached, including retry attempts.']);
                }
                if ($batch->pilot_key) {
                    app(PilotStudy::class)->assertDispatch($batch, $quote['reserve']);
                }
                if ($batch->pricing_snapshot != $quote['pricing']) {
                    throw ValidationException::withMessages(['cost' => 'Pricing changed after this batch was previewed. Create a fresh preview for pending work.']);
                }
            }
            $this->assertAvailable($quote['reserve']);
            app(OrganizationQuota::class)->assertRequest('openai', $quote['reserve'], (int) $attributes['user_id']);
            $credit = app(CreditLedger::class)->reserve($item ? 'batch:'.$item->id : 'search:'.$attributes['submission_id'], (int) $attributes['user_id'], 'openai', $attributes['model'], $item?->id);
            $run = TestRun::create($attributes);
            $credit->update(['test_run_id' => $run->id]);
            CostEntry::create(['test_run_id' => $run->id, 'budget_month_id' => $month->id, 'reserved_micros' => $quote['reserve'], 'committed_micros' => $quote['reserve'], 'safety_percent' => $settings->safety_percent, 'status' => 'reserved', 'pricing_snapshot' => $quote['pricing']]);

            return $run;
        });
    }

    public function settle(TestRun $run): void
    {
        DB::transaction(function () use ($run) {
            $entry = CostEntry::where('test_run_id', $run->id)->lockForUpdate()->first();
            if (! $entry || $entry->status !== 'reserved') {
                return;
            }
            $this->settleEntry($entry, $run->response?->raw_metadata ?? []);
            app(CreditLedger::class)->settle($run);
            if ($run->batch_item_id && $run->usage && in_array($entry->status, ['settled', 'overrun'], true)) {
                $run->usage->update(['estimated_cost' => number_format($entry->usage_cost_micros / 1000000, 6, '.', '')]);
            }
        });
    }

    public function confirmOpening(int $amount, int $user, string $note): void
    {
        DB::transaction(function () use ($amount, $user, $note) {
            $settings = $this->settings();
            SpendingSetting::whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $month = $this->month();
            $month = BudgetMonth::lockForUpdate()->findOrFail($month->id);
            if ($month->opening_micros !== null) {
                throw ValidationException::withMessages(['opening' => 'The opening allowance is already confirmed for this UTC month.']);
            }
            $minimum = 0;
            foreach (TestRun::withoutGlobalScopes()->with(['response' => fn ($q) => $q->withoutGlobalScopes()])->where('id', '<=', $month->opening_through_id)->where('executed_at', '>=', now('UTC')->startOfMonth())->where('executed_at', '<', now('UTC')->startOfMonth()->addMonth())->whereDoesntHave('cost', fn ($q) => $q->withoutGlobalScopes())->get() as $run) {
                try {
                    $price = $this->pricing->snapshot($run->model);
                    $known = $this->pricing->usage($run->response?->raw_metadata ?? [], $price);
                    if ($known !== null) {
                        $minimum += $this->pricing->withSafety($known, $settings->safety_percent);
                    }
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['opening' => 'Pricing is unavailable for existing usage. Review pricing before confirming this allowance.']);
                }
            }
            if ($amount < $minimum) {
                throw ValidationException::withMessages(['opening' => 'The allowance must cover at least '.RequestPricing::dollars($minimum).' of known usage estimates and safety reserve. Include any uncertain earlier charges.']);
            }
            $month->update(['opening_micros' => $amount, 'confirmed_by' => $user, 'opening_note' => $note]);
        });
    }

    public function reserveExplanation(array $attributes): ImprovementExplanation
    {
        return DB::transaction(function () use ($attributes) {
            $settings = $this->settings();
            $settings = SpendingSetting::lockForUpdate()->findOrFail($settings->id);
            $month = $this->month();
            BudgetMonth::whereKey($month->id)->lockForUpdate()->firstOrFail();
            $quote = $this->pricing->explanationQuote(json_encode($attributes['request_settings'], JSON_THROW_ON_ERROR), $attributes['model'], $settings->safety_percent);
            $this->assertAvailable($quote['reserve']);
            app(OrganizationQuota::class)->assertRequest('openai', $quote['reserve'], (int) $attributes['user_id']);
            app(PlanCatalog::class)->authorize('advanced_improvements');
            $credit = app(CreditLedger::class)->reserve('explain:'.$attributes['submission_id'], (int) $attributes['user_id'], 'openai', $attributes['model']);
            $explanation = ImprovementExplanation::create($attributes);
            $credit->update(['improvement_explanation_id' => $explanation->id]);
            CostEntry::create(['improvement_explanation_id' => $explanation->id, 'budget_month_id' => $month->id, 'reserved_micros' => $quote['reserve'], 'committed_micros' => $quote['reserve'], 'safety_percent' => $settings->safety_percent, 'status' => 'reserved', 'pricing_snapshot' => $quote['pricing']]);

            return $explanation;
        });
    }

    public function settleExplanation(ImprovementExplanation $explanation): void
    {
        DB::transaction(function () use ($explanation) {
            $entry = CostEntry::where('improvement_explanation_id', $explanation->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'reserved') {
                return;
            }
            $this->settleEntry($entry, $explanation->raw_metadata ?? []);
            app(CreditLedger::class)->settle($explanation);
        });
    }

    private function settleEntry(CostEntry $entry, array $raw): void
    {
        $safeRejection = in_array($raw['http_status'] ?? null, [400, 401, 403, 404, 429], true) || in_array($raw['error']['code'] ?? null, ['tls_certificate_error', 'paid_execution_denied'], true);
        $cost = $this->pricing->usage($raw, $entry->pricing_snapshot);
        if ($safeRejection) {
            $entry->update(['status' => 'released', 'committed_micros' => 0, 'usage_cost_micros' => 0]);
        } elseif ($cost !== null) {
            $committed = $this->pricing->withSafety($cost, $entry->safety_percent);
            $entry->update(['status' => $committed > $entry->reserved_micros ? 'overrun' : 'settled', 'usage_cost_micros' => $cost, 'committed_micros' => $committed]);
        } else {
            $entry->update(['status' => 'uncertain']);
        }
    }
}
