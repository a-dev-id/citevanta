<?php

namespace App\Http\Controllers;

use App\Models\Improvement;
use App\Models\ImprovementEvent;
use App\Services\CostControl;
use App\Services\CurrentWorkspace;
use App\Services\ImprovementExplainer;
use App\Services\ImprovementIntelligence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ImprovementController extends Controller
{
    public function __construct(private CurrentWorkspace $workspace) {}

    public function index(Request $request, ImprovementIntelligence $intelligence): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Improvement::STATUSES))]]);
        $rows = $this->workspace->improvements()->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest()->paginate(15)->withQueryString();

        return view('improvements.index', ['improvements' => $rows, 'summary' => $intelligence->summary()]);
    }

    public function refresh(Request $request, ImprovementIntelligence $intelligence): RedirectResponse
    {
        $count = $intelligence->refresh($request->user());

        return redirect()->route('improvements.index')->with('status', $count.' evidence-based recommendations refreshed. Review status and notes were preserved. No API request was made.');
    }

    private function find(int $id): Improvement
    {
        return $this->workspace->improvements()->findOrFail($id);
    }

    public function show(Request $request, int $improvement, ImprovementExplainer $explainer, CostControl $costs): View
    {
        $record = $this->find($improvement);
        $record->load(['events.user', 'explanations.cost']);
        $preview = null;
        $blocked = null;
        try {
            $preview = $explainer->preview($record);
            $costs->assertAvailable($preview['quote']['reserve']);
        } catch (ValidationException $exception) {
            $blocked = $exception->getMessage();
        }
        $submission = (string) Str::uuid();
        $request->session()->put('improvement_previews.'.$record->id, ['submission' => $submission, 'fingerprint' => $preview['fingerprint'] ?? null]);

        return view('improvements.show', ['improvement' => $record, 'preview' => $preview, 'blocked' => $blocked, 'submission' => $submission]);
    }

    public function update(Request $request, int $improvement): RedirectResponse
    {
        $record = $this->find($improvement);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Improvement::STATUSES))], 'notes' => 'nullable|string|max:10000', 'target_url' => ['nullable', 'url:http,https', 'max:2048', function ($attribute, $value, $fail) {
            if (parse_url($value, PHP_URL_USER) || parse_url($value, PHP_URL_PASS)) {
                $fail('Target URLs must not include credentials.');
            }
        }]]);
        DB::transaction(function () use ($record, $data, $request) {
            $current = $this->workspace->improvements()->lockForUpdate()->findOrFail($record->id);
            $changed = $current->status !== $data['status'];
            $edited = $current->notes !== ($data['notes'] ?? null) || $current->target_url !== ($data['target_url'] ?? null);
            if ($changed || $edited) {
                ImprovementEvent::create(['improvement_id' => $current->id, 'user_id' => $request->user()->id, 'from_status' => $current->status, 'to_status' => $data['status'], 'note' => $data['notes'] ?? ($edited ? 'Target URL or notes updated.' : null)]);
            }
            $completedAt = $data['status'] === 'completed' ? ($current->completed_at ?? now()) : null;
            $current->update([...$data, 'completed_at' => $completedAt]);
        });

        return redirect()->route('improvements.show', $record)->with('status', 'Recommendation review saved. No website was changed.');
    }

    public function explain(Request $request, int $improvement, ImprovementExplainer $explainer, ImprovementIntelligence $intelligence): RedirectResponse
    {
        $record = $this->find($improvement);
        $data = $request->validate(['submission_id' => 'required|uuid', 'acknowledge' => 'accepted']);
        $saved = $request->session()->get('improvement_previews.'.$record->id);
        abort_unless($saved && $saved['submission'] === $data['submission_id'] && $saved['fingerprint'], 419);
        $intelligence->refresh($request->user());
        $record = $this->find($record->id);
        $result = $explainer->execute($record, $request->user(), $data['submission_id'], $saved['fingerprint']);

        return redirect()->route('improvements.show', $record)->with('status', $result->status === 'completed' ? 'AI-generated explanation saved with usage metadata.' : 'Explanation was not completed. Review its saved status and cost reservation.');
    }
}
