<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Services\Audits\AuditService;
use App\Services\Billing\BillingService;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetPanel;
use App\Services\Content\ContentService;
use App\Services\Cron\SystemHealth;
use App\Services\Deploy\DeployTasks;
use App\Services\Plans\PlanCatalog;
use App\Services\Scanning\ScanMaintenance;
use App\Services\Scanning\ScanPlanner;
use App\Services\Trials\TrialCosts;
use App\Services\Trials\TrialGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Internal admin pages (users.is_admin only, see the "admin" middleware).
 */
class AdminController extends Controller
{
    public function teams(ContentService $content, BillingService $billing): View
    {
        $teams = Team::query()->withCount(['users', 'hotels'])->latest()->paginate(25);
        $totalMrr = [];

        Team::query()->whereIn('subscription_status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->each(function (Team $team) use ($billing, &$totalMrr): void {
                if (($mrr = $billing->mrr($team)) !== null) {
                    $totalMrr[$mrr['currency']] = ($totalMrr[$mrr['currency']] ?? 0) + $mrr['amount'];
                }
            });

        return view('admin.teams', [
            'teams' => $teams,
            'content' => $content->monthlyTeamUsage($teams),
            'mrr' => $teams->mapWithKeys(fn (Team $team) => [$team->id => $billing->mrr($team)]),
            'aiCost' => app(TrialCosts::class)->costs($teams->getCollection()),
            'totalMrr' => $totalMrr,
            'paidPlans' => PlanCatalog::PAID,
        ]);
    }

    public function hotels(): View
    {
        return view('admin.hotels', [
            // Hotels created only for free audits are listed under Admin > Audits.
            'hotels' => Hotel::query()->where('is_audit', false)->with('team')->withCount('scanRuns')->latest()->paginate(25),
        ]);
    }

    /**
     * Free audit leads, with cost per day and this month.
     */
    public function audits(AuditService $audits): View
    {
        return view('admin.audits', [
            'audits' => Audit::query()->with(['source', 'convertedTeam'])->latest('id')->paginate(50),
            'costs' => $audits->costs(),
            'today' => $audits->newAuditsToday(),
            'budget' => (int) config('citevanta.audit.daily_budget', 30),
        ]);
    }

    /**
     * All audit leads as CSV (consented and not; filter in the spreadsheet).
     */
    public function exportAudits(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'hotel', 'city', 'country', 'website', 'email', 'consent', 'status', 'visibility', 'questions_mentioned', 'questions', 'reused', 'converted_to_trial', 'converted_at', 'cost_usd']);

            Audit::query()->with('source')->orderBy('id')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $audit) {
                    $results = $audit->results();
                    fputcsv($out, [
                        $audit->created_at->toDateTimeString(), $audit->hotel_name, $audit->city, $audit->country, $audit->website,
                        $audit->email, $audit->consent ? 'yes' : 'no', $results->status, $results->visibility,
                        $results->questions_mentioned, $results->questions, $audit->source_audit_id ? 'yes' : 'no',
                        $audit->converted_team_id ? 'yes' : 'no', $audit->converted_at?->toDateTimeString(),
                        $audit->source_audit_id ? '0' : (string) $audit->cost_usd,
                    ]);
                }
            });

            fclose($out);
        }, 'audits-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function scanRuns(ScanPlanner $planner): View
    {
        $scanRuns = ScanRun::query()->with('hotel.team')->withCount('responses')->latest()->paginate(25);

        return view('admin.scan-runs', [
            'scanRuns' => $scanRuns,
            'missing' => $scanRuns->mapWithKeys(fn (ScanRun $run) => [
                $run->id => $run->status->isInProgress() ? 0 : count($planner->missingCalls($run)),
            ]),
        ]);
    }

    public function retryScanRun(ScanRun $scanRun, ScanMaintenance $scans): RedirectResponse
    {
        try {
            $queued = $scans->retryMissing($scanRun);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $queued === 0
            ? "Scan #{$scanRun->id} has no missing calls."
            : "Scan #{$scanRun->id}: {$queued} missing call(s) queued. Results are recalculated when they finish.");
    }

    public function failedJobs(): View
    {
        return view('admin.failed-jobs', [
            'jobs' => DB::table('failed_jobs')->latest('failed_at')->paginate(25),
        ]);
    }

    public function retryFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('status', 'Job sent back to the queue.');
    }

    public function deleteFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', 'Failed job deleted.');
    }

    public function system(SystemHealth $health, BudgetPanel $budget): View
    {
        return view('admin.system', [
            'budget' => $budget->data(),
            'health' => $health->collect(),
            'cronLines' => $health->cronLines(),
            'warnings' => $health->productionWarnings(),
            'storageMethod' => app(DeployTasks::class)->storageMethod(),
            'environment' => app()->environment(),
        ]);
    }

    /**
     * Admin > System > Deploy tasks: migrate, cache config/routes/views/events, storage link.
     */
    public function runDeployTasks(DeployTasks $tasks): RedirectResponse
    {
        return to_route('admin.system')->with('deploy_results', $tasks->run());
    }

    public function resumePaused(BudgetPanel $budget): RedirectResponse
    {
        try {
            $resumed = $budget->resume();
        } catch (BudgetExceeded $e) {
            return to_route('admin.system')->with('error', $e->getMessage());
        }

        return to_route('admin.system')->with('status', "{$resumed} paused ".Str::plural('job', $resumed).' resumed.');
    }

    public function approveTrial(Team $team, TrialGate $trials): RedirectResponse
    {
        $trials->approveTrial($team);

        return back()->with('status', "Trial of {$team->name} approved. The owner was emailed.");
    }

    public function clearCaches(DeployTasks $tasks): RedirectResponse
    {
        return to_route('admin.system')->with('deploy_results', $tasks->clearCaches());
    }
}
