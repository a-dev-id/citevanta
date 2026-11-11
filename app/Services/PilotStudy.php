<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\Organization;
use App\Models\PilotResultReview;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class PilotStudy
{
    public function __construct(private TenantContext $context, private BatchManager $batches, private RequestPricing $pricing, private CostControl $costs, private OrganizationQuota $quota, private VisibilityMetrics $metrics, private PilotQuality $quality) {}

    public function isTarget(): bool
    {
        return $this->context->organizationId && $this->context->organization()->slug === config('pilot.organization_slug') && $this->context->brand()->slug === config('pilot.brand_slug');
    }

    public function current(): ?TestBatch
    {
        abort_unless($this->isTarget(), 404);

        return TestBatch::where('pilot_key', config('pilot.key'))->first();
    }

    public function definition(): array
    {
        $prompts = config('pilot.prompts');
        $country = config('pilot.country');
        if (! is_array($prompts) || count($prompts) !== 12 || count(array_unique(array_column($prompts, 'key'))) !== 12 || count(array_unique(array_column($prompts, 'text'))) !== 12 || ! in_array($country, ['AU', 'US', 'GB', 'ID'], true)) {
            $this->blocked('The pilot requires exactly 12 distinct prompts and one supported country.');
        }
        foreach ($prompts as $prompt) {
            if (empty($prompt['category']) || empty($prompt['text']) || preg_match('/nandini|hanging gardens/iu', $prompt['text'])) {
                $this->blocked('Use the 12 unbranded source-plan questions.');
            }
        }

        return ['key' => config('pilot.key'), 'source' => config('pilot.source'), 'prompts' => $prompts, 'country' => $country, 'language' => config('pilot.language'), 'repetitions' => 1, 'requests' => 12];
    }

    public function prepare(User $user): TestBatch
    {
        abort_unless($this->isTarget(), 404);
        $this->context->assertManager($user);
        $definition = $this->definition();

        return DB::transaction(function () use ($user, $definition) {
            Organization::whereKey($this->context->organizationId)->lockForUpdate()->firstOrFail();
            if ($batch = $this->current()) {
                if (! $batch->runs()->exists() && $batch->status === 'draft') {
                    $this->refreshPrice($batch);
                }

                return $batch->fresh();
            }
            $ids = [];
            foreach ($definition['prompts'] as $entry) {
                $uuid = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, config('pilot.source.url').'|'.$definition['key'].'|'.$this->context->organizationId.'|'.$this->context->brandId.'|'.$entry['key']);
                $prompt = SearchPrompt::firstOrCreate(['prompt_id' => $uuid], ['category' => $entry['category'], 'text' => $entry['text'], 'version' => 1, 'active' => true]);
                if (! $prompt->active || $prompt->text !== $entry['text'] || $prompt->category !== $entry['category']) {
                    $this->blocked('A source-plan prompt has been edited or disabled. Review it before creating this pilot.');
                }
                $ids[] = $prompt->id;
            }
            $location = ['type' => 'approximate', 'country' => $definition['country']];
            $market = MarketScenario::where('country', $definition['country'])->where('language', $definition['language'])->get()->first(fn ($m) => $m->search_settings == $location);
            if (! $market) {
                $market = MarketScenario::firstOrCreate(['name' => 'Pilot '.$definition['country'].' / '.$definition['language']], ['country' => $definition['country'], 'language' => $definition['language'], 'search_settings' => $location]);
            }
            if ($market->search_settings != $location) {
                $this->blocked('The pilot market has unsupported location settings.');
            }
            $batch = $this->batches->preview($user, ['creation_id' => (string) Str::uuid(), 'name' => config('pilot.name'), 'prompt_ids' => $ids, 'market_ids' => [$market->id], 'repetitions' => 1]);
            $configuration = $batch->configuration;
            $configuration['max_attempts'] = 1;
            $batch->update(['pilot_key' => $definition['key'], 'pilot_snapshot' => $definition, 'request_limit' => 12, 'configuration' => $configuration]);

            return $batch->fresh();
        });
    }

    private function refreshPrice(TestBatch $batch): void
    {
        $model = config('intelligence.model');
        try {
            $estimate = 0;
            $reserve = 0;
            $price = null;
            foreach ($batch->items as $item) {
                $quote = $this->pricing->quote($item->prompt_snapshot['text'], $model, $this->costs->settings()->safety_percent);
                $estimate += $quote['estimate'];
                $reserve += $quote['reserve'];
                $price = $quote['pricing'];
            }
            $batch->update(['model' => $model, 'estimated_micros' => $estimate, 'reserved_estimate_micros' => $reserve, 'pricing_snapshot' => $price, 'status_note' => null, 'pilot_enabled_at' => null, 'pilot_approved_by' => null, 'pilot_budget_micros' => null, 'pilot_approval_fingerprint' => null]);
        } catch (ValidationException) {
            $batch->update(['estimated_micros' => null, 'reserved_estimate_micros' => null, 'pricing_snapshot' => null, 'status_note' => 'Pricing is unavailable. Refresh this unexecuted preview after reviewing current rates.', 'pilot_enabled_at' => null, 'pilot_approved_by' => null, 'pilot_budget_micros' => null, 'pilot_approval_fingerprint' => null]);
        }
    }

    public function assertConfiguration(TestBatch $batch): void
    {
        abort_unless($this->isTarget() && $batch->pilot_key === config('pilot.key'), 404);
        $definition = $this->definition();
        $items = $batch->items()->orderBy('id')->get();
        $expected = collect($definition['prompts'])->map(fn ($p) => $p['category'].'|'.$p['text'])->sort()->values()->all();
        $actual = $items->map(fn ($i) => ($i->prompt_snapshot['category'] ?? '').'|'.($i->prompt_snapshot['text'] ?? ''))->sort()->values()->all();
        if ($batch->pilot_snapshot != $definition || $batch->request_count !== 12 || $batch->request_limit !== 12 || ($batch->configuration['max_attempts'] ?? null) !== 1 || (int) ($batch->configuration['repetitions'] ?? 0) !== 1 || count($items) !== 12 || $expected !== $actual || $items->pluck('search_prompt_id')->unique()->count() !== 12 || $items->pluck('market_scenario_id')->unique()->count() !== 1 || $items->contains(fn ($i) => (int) $i->repetition !== 1 || ($i->market_snapshot['country'] ?? null) !== $definition['country'] || ($i->market_snapshot['language'] ?? null) !== $definition['language'] || ($i->market_snapshot['search_settings'] ?? []) != ['type' => 'approximate', 'country' => $definition['country']]) || $batch->provider->identifier !== 'openai') {
            $this->blocked('Pilot snapshots or request limits changed. Restore the reviewed 12 × 1 × 1 configuration before enabling.');
        }
    }

    public function fingerprint(TestBatch $batch): string
    {
        return PilotQuality::hash([$batch->pilot_snapshot, $batch->pricing_snapshot, $batch->model, $batch->ai_provider_id, $batch->request_limit, $batch->configuration['max_attempts'] ?? null, $batch->items()->orderBy('id')->get()->map(fn ($i) => $i->only(['id', 'search_prompt_id', 'market_scenario_id', 'repetition', 'prompt_snapshot', 'market_snapshot']))->all(), config('intelligence.max_output_tokens'), $this->costs->settings()->safety_percent, config('pilot.first_live_review_required'), now('UTC')->format('Y-m')]);
    }

    public function preview(?TestBatch $batch): array
    {
        $definition = $this->definition();
        $estimate = 0;
        $reserve = 0;
        $pendingReserve = 0;
        $searchFees = 0;
        $checks = [];
        $price = null;
        $check = function (string $name, callable $callback) use (&$checks) {
            try {
                $callback();
                $checks[$name] = ['passed' => true, 'message' => 'Verified'];
            } catch (ValidationException $e) {
                $checks[$name] = ['passed' => false, 'message' => $e->getMessage()];
            }
        };
        $check('Pricing and search-tool charges', function () use ($definition, $batch, &$estimate, &$reserve, &$searchFees, &$price, &$pendingReserve) {
            foreach ($definition['prompts'] as $entry) {
                $q = $this->pricing->quote($entry['text'], $batch?->model ?? config('intelligence.model'), $this->costs->settings()->safety_percent);
                $estimate += $q['estimate'];
                $reserve += $q['reserve'];
                $searchFees += $q['pricing']['rates']['search_micros'];
                $price = $q['pricing'];
            }
            if ($batch) {
                if ($batch->pricing_snapshot != $price) {
                    $this->blocked('Pricing differs from the saved preview. Refresh an unexecuted draft or review the pricing version.');
                }foreach ($batch->items()->whereIn('status', ['pending', 'retry_wait'])->get() as $item) {
                    $pendingReserve += $this->pricing->quote($item->prompt_snapshot['text'], $batch->model, $this->costs->settings()->safety_percent)['reserve'];
                }
            } else {
                $pendingReserve = $reserve;
            }
        });
        $priced = $checks['Pricing and search-tool charges']['passed'];
        $pending = $batch ? $batch->items()->whereIn('status', ['pending', 'retry_wait'])->count() : 12;
        $check('Organization quotas', function () use ($pending, $batch) {
            $this->quota->assertBatch(12, 1);
            $credits = app(CreditLedger::class);
            $needed = $batch ? $credits->requiredForBatch($batch) : $pending * $credits->weight('openai', config('intelligence.model'));
            $credits->assertAvailable($needed);
        });
        $check('Global application spending protection', function () use ($priced, $pendingReserve) {
            if (! $priced) {
                $this->blocked('Current pricing is required.');
            }$this->costs->assertAvailable($pendingReserve);
        });
        $check('Organization spending protection', function () use ($priced, $pendingReserve) {
            $org = $this->context->organization();
            $opening = $this->quota->opening();
            $limit = app(SubscriptionFinancialProtection::class)->limit();
            if (! $priced || $opening === null || $limit === 0 || $this->quota->committed() + $opening + $pendingReserve > $limit) {
                $this->blocked('Organization allowance is disabled, unreconciled, or insufficient for remaining safety reservations.');
            }
        });
        $check('Batch and source configuration', function () use ($batch) {
            if ($batch) {
                $this->assertConfiguration($batch);
            }if ($this->costs->settings()->batch_request_limit < 12) {
                $this->blocked('The application per-batch request cap must allow 12 requests.');
            }
        });
        $check('Provider configuration', function () use ($batch) {
            if (! AiProvider::where('identifier', 'openai')->where('enabled', true)->exists() || ! config('intelligence.openai_key')) {
                $this->blocked('OpenAI must be enabled and securely configured on the server.');
            }if ($batch && $batch->model !== config('intelligence.model')) {
                $this->blocked('The configured model changed after preparation. Review a new pilot version.');
            }
        });
        $used = $batch ? (int) $batch->costs()->sum('committed_micros') : 0;

        return ['definition' => $definition, 'checks' => $checks, 'ready' => collect($checks)->every('passed', true), 'estimated' => $priced ? $estimate : null, 'reserve' => $priced ? $reserve : null, 'pending_reserve' => $priced ? $pendingReserve : null, 'search_fees' => $priced ? $searchFees : null, 'minimum_budget' => $priced ? $pendingReserve + $used : null, 'pricing' => $price, 'daily_limit' => config('intelligence.daily_limit'), 'pending' => $pending, 'committed' => $used, 'fingerprint' => $batch ? $this->fingerprint($batch) : null];
    }

    public function enable(TestBatch $batch, User $user, int $budget, string $fingerprint): void
    {
        $this->context->assertManager($user);
        DB::transaction(function () use ($batch, $user, $budget, $fingerprint) {
            $this->costs->settings()->newQuery()->where('id', 1)->lockForUpdate()->firstOrFail();
            Organization::whereKey($this->context->organizationId)->lockForUpdate()->firstOrFail();
            $batch = TestBatch::lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, ['draft', 'paused'], true) || $batch->items()->where('status', 'processing')->exists()) {
                $this->blocked('Only an unexecuted draft or paused pilot without in-flight items can be enabled or resumed.');
            }
            $preview = $this->preview($batch);
            if (! hash_equals($preview['fingerprint'], $fingerprint) || ! $preview['ready'] || $preview['minimum_budget'] === null || $budget < $preview['minimum_budget']) {
                $this->blocked('Approval requires the unchanged preview, current pricing, sufficient global/organization allowances, and a pilot ceiling covering the remaining safety reservation.');
            }
            $batch->update(['pilot_budget_micros' => $budget, 'pilot_approved_by' => $user->id, 'pilot_enabled_at' => now(), 'pilot_approval_fingerprint' => $this->fingerprint($batch)]);
            $this->batches->start($batch->fresh());
        });
    }

    public function assertApproval(TestBatch $batch): void
    {
        $this->assertConfiguration($batch);
        if (config('pilot.first_live_review_required') && ($first = $batch->runs()->orderBy('id')->first())) {
            if ($first->cost?->status !== 'settled' || ! $this->quality->isApproved($first)) {
                $this->blocked('The first pilot result must have reconciled cost and a current quality approval before a separate continuation approval can enable remaining requests.');
            }
        }
        if (! $batch->pilot_enabled_at || ! $batch->pilot_approved_by || ! $batch->pilot_budget_micros || ! $batch->pilot_approval_fingerprint || ! hash_equals($batch->pilot_approval_fingerprint, $this->fingerprint($batch)) || ! DB::table('organization_user')->where('organization_id', $batch->organization_id)->where('user_id', $batch->pilot_approved_by)->whereIn('role', ['owner', 'administrator'])->exists()) {
            $this->blocked('Pilot execution is disabled or its approval is stale. Review the pilot preview, approve its budget, and explicitly enable execution.');
        }
    }

    public function assertDispatch(TestBatch $batch, int $reserve): void
    {
        $this->assertApproval($batch);
        if ($batch->runs()->count() >= 12 || (int) $batch->costs()->sum('committed_micros') + $reserve > $batch->pilot_budget_micros) {
            $this->blocked('The approved pilot request or spending ceiling is reached.');
        }
    }

    public function report(TestBatch $batch): array
    {
        abort_unless($this->isTarget() && $batch->pilot_key === config('pilot.key'), 404);
        $valid = $this->metrics->runs($batch->id)->keyBy('id');
        $eligible = collect();
        $approved = collect();
        $rows = collect();
        $reviews = PilotResultReview::with('reviewer')->where('test_batch_id', $batch->id)->orderByDesc('id')->get()->groupBy('test_run_id');
        foreach ($batch->items()->with(['run.response', 'run.usage', 'run.cost'])->orderBy('id')->get() as $item) {
            $run = $item->run;
            $inspection = $run ? $this->quality->inspect($run) : null;
            $review = $run ? ($reviews[$run->id] ?? collect())->first() : null;
            $fresh = $review && $inspection && hash_equals($review->evidence_fingerprint, $inspection['fingerprint']);
            $included = $run && $item->status === 'completed' && $valid->has($run->id) && $inspection['can_approve'];
            if ($included) {
                $eligible->push($valid[$run->id]);
                if ($fresh && $review->decision === 'approved') {
                    $approved->push($valid[$run->id]);
                }
            }
            $rows->push(['item' => $item, 'run' => $run, 'quality' => $inspection, 'review' => $review, 'review_current' => $fresh, 'included' => $included, 'review_history' => $run ? ($reviews[$run->id] ?? collect()) : collect()]);
        }
        $attempts = $batch->runs()->with(['usage', 'cost'])->get();
        $known = $attempts->filter(fn ($r) => $r->cost?->usage_cost_micros !== null);

        return ['rows' => $rows, 'approved' => $approved, 'summary' => $this->metrics->summary($approved), 'provisional' => $this->metrics->summary($eligible), 'competitors' => $this->metrics->hotels($approved)->filter(fn ($r) => ! $r['hotel']->isMonitoredBrand()), 'opportunities' => $this->metrics->opportunities($approved), 'attempts' => $attempts->count(), 'failed' => $attempts->whereIn('status', ['failed', 'interrupted', 'incomplete'])->count(), 'known_cost' => (int) $known->sum(fn ($r) => $r->cost->usage_cost_micros), 'known_cost_count' => $known->count(), 'unknown_cost_count' => $attempts->count() - $known->count(), 'tokens' => (int) $attempts->sum(fn ($r) => $r->usage?->total_tokens ?? 0), 'input_tokens' => (int) $attempts->sum(fn ($r) => $r->usage?->input_tokens ?? 0), 'output_tokens' => (int) $attempts->sum(fn ($r) => $r->usage?->output_tokens ?? 0), 'flagged' => $rows->filter(fn ($r) => $r['review_current'] && $r['review']->decision === 'flagged')->count(), 'unreviewed' => $rows->filter(fn ($r) => $r['run'] && ! $r['review_current'])->count()];
    }

    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['pilot' => $message]);
    }
}
