<?php

namespace App\Http\Controllers;

use App\Models\TestRun;
use App\Services\ImprovementIntelligence;
use App\Services\VisibilityMetrics;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(VisibilityMetrics $metrics, ImprovementIntelligence $intelligence): View
    {
        $valid = $metrics->runs();

        return view('dashboard', ['improvementSummary' => $intelligence->summary(), 'summary' => $metrics->summary($valid), 'competitors' => $metrics->hotels($valid)->filter(fn ($row) => ! $row['hotel']->isMonitoredBrand() && $row['recommendations'] > 0)->take(6), 'opportunities' => $metrics->opportunities($valid)->take(5), 'excluded' => TestRun::count() - $valid->count(), 'runs' => TestRun::with(['provider', 'response.assessments.hotel'])->latest('executed_at')->limit(8)->get()]);
    }
}
