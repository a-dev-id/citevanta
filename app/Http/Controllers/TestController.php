<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Services\CostControl;
use App\Services\CurrentWorkspace;
use App\Services\PlanCatalog;
use App\Services\SimulatedMonitoring;
use App\Services\TenantContext;
use App\Services\TestExecutor;
use App\Services\VisibilityAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TestController extends Controller
{
    public function create(Request $request): View
    {
        $submission = (string) Str::uuid();
        $tokens = $request->session()->get('test_submissions', []);
        $tokens[] = $submission;
        $request->session()->put('test_submissions', array_slice($tokens, -10));

        $simulation = app(SimulatedMonitoring::class)->available();
        $global = app(CostControl::class)->balance();
        $organization = app(CostControl::class)->tenantBalance();
        $financialReady = $global['remaining'] !== null && $global['remaining'] > 0 && $organization['remaining'] !== null && $organization['remaining'] > 0;

        return view('tests.create', ['simulation' => $simulation, 'monitoringModel' => app(SimulatedMonitoring::class)->model(), 'financialReady' => $simulation || $financialReady, 'providers' => AiProvider::where('enabled', true)->whereIn('identifier', app(PlanCatalog::class)->platforms())->get(), 'prompts' => SearchPrompt::where('active', true)->get(), 'markets' => MarketScenario::all(), 'submission' => $submission, 'configured' => $simulation || (bool) config('intelligence.openai_key')]);
    }

    public function store(Request $request, TestExecutor $executor): RedirectResponse
    {
        $data = $request->validate(['provider_id' => ['required', Rule::exists('ai_providers', 'id')->where('enabled', true)->where('identifier', app(SimulatedMonitoring::class)->provider())], 'prompt_id' => ['required', Rule::exists('search_prompts', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)->where('active', true)], 'market_id' => ['required', Rule::exists('market_scenarios', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)], 'model' => ['required', Rule::in([app(SimulatedMonitoring::class)->model()])], 'submission_id' => 'required|uuid']);
        abort_unless(in_array($data['submission_id'], $request->session()->get('test_submissions', []), true), 419);
        $run = $executor->execute($request->user(), AiProvider::findOrFail($data['provider_id']), SearchPrompt::findOrFail($data['prompt_id']), MarketScenario::findOrFail($data['market_id']), $data['model'], $data['submission_id']);

        return redirect()->route('tests.show', $run);
    }

    public function index(Request $request, VisibilityAnalysis $analysis): View
    {
        $filters = $request->validate(['status' => 'nullable|in:running,completed,failed,incomplete,interrupted', 'q' => 'nullable|string|max:200']);
        $runs = TestRun::with(['provider', 'response.mentions'])->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->when($filters['q'] ?? null, fn ($q, $text) => $q->where('prompt_text', 'like', '%'.$text.'%'))->latest('executed_at')->paginate(15)->withQueryString();

        foreach ($runs as $run) {
            if ($run->response && $analysis->isValid($run->response)) {
                $analysis->preview($run->response);
                $run->response->loadMissing('assessments.hotel');
            }
        }

        return view('tests.index', compact('runs'));
    }

    public function show(TestRun $testRun, VisibilityAnalysis $analysis): View
    {
        $testRun->load(['provider', 'response.citations', 'response.mentions', 'usage', 'cost']);
        if (app(CurrentWorkspace::class)->canManage() && $testRun->status === 'running' && $testRun->executed_at->lt(now()->subMinutes(3))) {
            $testRun->update(['status' => 'interrupted']);
            $testRun->response()->updateOrCreate([], ['status' => 'interrupted', 'error_details' => 'Execution interrupted; provider processing is unknown. No automatic retry was made.']);
            $testRun->load('response.citations', 'response.mentions');
        }

        $analyzed = $testRun->response && $analysis->isValid($testRun->response);
        if ($analyzed) {
            $analysis->preview($testRun->response);
            $testRun->response->loadMissing(['assessments.hotel', 'citations.hotel']);
        }

        return view('tests.show', ['run' => $testRun, 'analyzed' => $analyzed]);
    }
}
