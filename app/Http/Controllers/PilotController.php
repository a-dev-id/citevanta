<?php

namespace App\Http\Controllers;

use App\Models\TestRun;
use App\Services\ImprovementIntelligence;
use App\Services\PilotQuality;
use App\Services\PilotStudy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PilotController extends Controller
{
    public function index(PilotStudy $study): View
    {
        $batch = $study->current();

        return view('pilot.index', ['batch' => $batch, 'preview' => $study->preview($batch), 'report' => $batch ? $study->report($batch) : null]);
    }

    public function prepare(Request $request, PilotStudy $study): RedirectResponse
    {
        $study->prepare($request->user());

        return redirect()->route('pilot.index')->with('status', '12 source-plan prompts and one approximate search scenario prepared. Execution remains disabled; no API request was made.');
    }

    public function review(Request $request, TestRun $testRun, PilotStudy $study, PilotQuality $quality): RedirectResponse
    {
        $batch = $study->current();
        abort_unless($batch && $testRun->test_batch_id === $batch->id, 404);
        $data = $request->validate(['decision' => 'required|in:approved,flagged', 'note' => 'nullable|required_if:decision,flagged|string|min:5|max:4000', 'fingerprint' => 'required|string|size:64', 'acknowledge' => 'nullable|boolean']);
        $quality->review($testRun, $request->user(), $data['decision'], $data['note'] ?? null, $data['fingerprint'], $request->boolean('acknowledge'));

        return redirect()->route('pilot.index')->with('status', 'Quality decision saved. Only valid, approved results with unchanged evidence enter final pilot metrics.');
    }

    public function improvements(Request $request, PilotStudy $study, ImprovementIntelligence $intelligence): RedirectResponse
    {
        $batch = $study->current();
        abort_unless($batch, 404);
        $count = $intelligence->refresh($request->user(), $batch->id);

        return redirect()->route('pilot.index')->with('status', $count.' improvement hypotheses refreshed from approved pilot evidence. No API request was made.');
    }
}
