<?php

namespace App\Http\Controllers;

use App\Models\CreditEntry;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Services\BatchManager;
use App\Services\CostControl;
use App\Services\OrganizationQuota;
use App\Services\PilotStudy;
use App\Services\PlanCatalog;
use App\Services\SimulatedMonitoring;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BatchController extends Controller
{
    public function index(Request $request, CostControl $costs, PilotStudy $pilot, OrganizationQuota $quota): View
    {
        $filter = $request->validate(['view' => ['nullable', Rule::in(['all', 'active', 'completed', 'templates'])]])['view'] ?? 'all';
        $finished = ['completed', 'completed_with_errors', 'cancelled'];
        $query = TestBatch::query();
        $counts = ['all' => (clone $query)->count(), 'active' => (clone $query)->whereNotIn('status', $finished)->count(), 'completed' => (clone $query)->whereIn('status', $finished)->count()];
        $plan = $quota->plan();
        $canCreate = app(PlanCatalog::class)->allows('campaigns') && app(TenantContext::class)->canManage($request->user()) && in_array('openai', $plan['providers'], true) && $plan['batch_size'] > 0 && $plan['monthly_searches'] > 0;

        return view('batches.index', ['batches' => $query->when($filter === 'active', fn ($q) => $q->whereNotIn('status', $finished))->when($filter === 'completed', fn ($q) => $q->whereIn('status', $finished))->withCount(['items', 'items as completed_count' => fn ($q) => $q->where('status', 'completed'), 'items as failed_count' => fn ($q) => $q->whereIn('status', ['failed', 'interrupted']), 'items as remaining_count' => fn ($q) => $q->whereIn('status', ['pending', 'retry_wait', 'processing'])])->selectSub(CreditEntry::query()->selectRaw('COALESCE(SUM(credits),0)')->whereColumn('credit_entries.batch_item_id', 'batch_items.id')->join('batch_items', 'batch_items.id', '=', 'credit_entries.batch_item_id')->whereColumn('batch_items.test_batch_id', 'test_batches.id')->where('credit_entries.status', 'used'), 'credits_used')->latest()->paginate(15)->withQueryString(), 'balance' => $costs->tenantBalance(), 'filter' => $filter, 'counts' => $counts, 'canCreate' => $canCreate, 'pilotAvailable' => $pilot->isTarget() && in_array('openai', $plan['providers'], true) && $plan['batch_size'] >= 12, 'pilotBatch' => $pilot->isTarget() ? $pilot->current() : null]);
    }

    public function create(Request $request, CostControl $costs): View
    {
        $token = (string) Str::uuid();
        $tokens = $request->session()->get('batch_creations', []);
        $tokens[] = $token;
        $request->session()->put('batch_creations', array_slice($tokens, -10));

        return view('batches.create', ['prompts' => SearchPrompt::where('active', true)->get(), 'markets' => MarketScenario::all(), 'balance' => $costs->tenantBalance(), 'creation' => $token]);
    }

    public function preview(Request $request, BatchManager $manager): RedirectResponse
    {
        $data = $request->validate(['creation_id' => 'required|uuid', 'name' => 'required|string|max:150', 'prompt_ids' => 'required|array|min:1|max:100', 'prompt_ids.*' => ['required', 'integer', 'distinct', Rule::exists('search_prompts', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)->where('active', true)], 'market_ids' => 'required|array|min:1|max:100', 'market_ids.*' => ['required', 'integer', 'distinct', Rule::exists('market_scenarios', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)], 'repetitions' => 'required|integer|min:1|max:'.config('spending.max_repetitions')]);
        abort_unless(in_array($data['creation_id'], $request->session()->get('batch_creations', []), true), 419);
        $batch = $manager->preview($request->user(), $data);

        return redirect()->route('batches.show', $batch);
    }

    public function show(TestBatch $batch, CostControl $costs): View
    {
        $batch->load('items');
        $attempts = $batch->runs()->select(['id', 'test_batch_id', 'batch_item_id', 'status', 'model', 'executed_at'])->get();

        return view('batches.show', ['simulation' => app(SimulatedMonitoring::class)->available(), 'batch' => $batch, 'balance' => $costs->tenantBalance(), 'attempts' => $attempts]);
    }

    public function start(Request $request, TestBatch $batch, BatchManager $manager): RedirectResponse
    {
        $request->validate(['confirm_credits' => 'accepted', 'large_warning' => $batch->request_count >= config('spending.large_batch_warning') ? 'accepted' : 'nullable']);
        $manager->start($batch);

        return redirect()->route('batches.show', $batch)->with('status', 'Campaign started. Process the next searches to update its progress.');
    }

    public function process(Request $request, TestBatch $batch): RedirectResponse
    {
        $count = app(SimulatedMonitoring::class)->process($batch);

        return redirect()->route('batches.show', $batch)->with('status', $count.' simulated searches processed.');
    }

    public function cancel(TestBatch $batch, BatchManager $manager): RedirectResponse
    {
        $manager->cancel($batch);

        return redirect()->route('batches.show', $batch)->with('status', 'Pending work stopped. An in-flight request may still finish and be saved.');
    }
}
