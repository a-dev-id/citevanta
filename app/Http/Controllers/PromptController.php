<?php

namespace App\Http\Controllers;

use App\Models\SearchPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PromptController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:200', 'active' => 'nullable|in:0,1']);
        $prompts = SearchPrompt::query()->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) => $q->where('text', 'like', '%'.$text.'%')->orWhere('category', 'like', '%'.$text.'%')))->when(isset($filters['active']), fn ($q) => $q->where('active', $filters['active']))->latest()->paginate(12)->withQueryString();

        return view('prompts.index', compact('prompts'));
    }

    public function create(): View
    {
        return view('prompts.form', ['prompt' => new SearchPrompt(['active' => true])]);
    }

    private function validated(Request $request): array
    {
        return $request->validate(['category' => 'required|string|max:100', 'text' => 'required|string|max:4000', 'active' => 'required|boolean']);
    }

    public function store(Request $request): RedirectResponse
    {
        SearchPrompt::create([...$this->validated($request), 'prompt_id' => (string) Str::uuid(), 'version' => 1]);

        return redirect()->route('prompts.index')->with('status', 'Prompt created. No test was executed.');
    }

    public function edit(SearchPrompt $prompt): View
    {
        return view('prompts.form', compact('prompt'));
    }

    public function update(Request $request, SearchPrompt $prompt): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($prompt, $data) {
            $current = SearchPrompt::lockForUpdate()->findOrFail($prompt->id);
            $changed = $current->text !== $data['text'] || $current->category !== $data['category'];
            $current->update([...$data, 'version' => $current->version + ($changed ? 1 : 0)]);
        });

        return redirect()->route('prompts.index')->with('status', 'Prompt updated. Saved test snapshots are preserved.');
    }
}
