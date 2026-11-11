<?php

namespace App\Services;

use App\Models\Improvement;
use App\Models\ImprovementEvent;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImprovementIntelligence
{
    public function __construct(private VisibilityMetrics $metrics, private CurrentWorkspace $workspace) {}

    public function refresh(User $user, ?int $pilotBatchId = null): int
    {
        app(PlanCatalog::class)->authorize('advanced_improvements');
        app(TenantContext::class)->assertManager($user);
        $hotel = $this->workspace->hotel();
        $lock = Cache::lock('improvements:refresh:'.$hotel->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['improvements' => 'Recommendations are already being refreshed.']);
        }
        try {
            $runs = ($pilotBatchId === null ? $this->metrics->runs() : app(PilotStudy::class)->report(TestBatch::findOrFail($pilotBatchId))['approved'])->filter(function ($run) use ($hotel) {
                if ($run->response->assessments->contains('hotel_id', $hotel->id)) {
                    return true;
                }
                $names = array_unique([$hotel->name, ...$hotel->aliases, $this->workspace->shortName()]);
                $pattern = '/(?<![\\pL\\pN])(?:'.implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names)).')(?![\\pL\\pN])/iu';

                return ! preg_match($pattern, $run->response->text);
            });
            $categories = SearchPrompt::pluck('category', 'id');
            $evidence = $runs->map(function ($run) use ($hotel, $categories) {
                $assessment = $run->response->assessments->firstWhere('hotel_id', $hotel->id);

                return ['test_id' => $run->id, 'prompt_id' => $run->search_prompt_id, 'prompt_version' => $run->prompt_version, 'prompt' => $run->prompt_text, 'category' => $categories[$run->search_prompt_id] ?? 'Uncategorized', 'mentioned' => (bool) $assessment?->mentioned, 'recommended' => (bool) $assessment?->recommended, 'official_cited' => $run->response->citations->contains(fn ($c) => $c->hotel_id === $hotel->id && $c->classification === 'nandini_official'), 'excerpt' => mb_substr($run->response->text, 0, 1400), 'competitors' => $run->response->assessments->filter(fn ($a) => $a->hotel_id !== $hotel->id && $a->mentioned)->map(fn ($a) => ['name' => $a->hotel->name, 'recommended' => $a->recommended, 'rank' => $a->rank, 'official_cited' => $run->response->citations->whereIn('id', $a->supporting_citation_ids)->contains(fn ($c) => $c->hotel_id === $a->hotel_id && $c->classification === 'competitor_official')])->values()->all()];
            });
            $candidates = [];
            foreach ($evidence->groupBy(fn ($e) => hash('sha256', $e['prompt_id'].'|'.$e['prompt_version'].'|'.$e['prompt'])) as $key => $group) {
                $pages = $this->pages($group->first()['prompt']);
                $target = $pages->first()['url'] ?? null;
                $absent = $group->where('mentioned', false)->values();
                $instead = $group->filter(fn ($e) => ! $e['recommended'] && collect($e['competitors'])->contains('recommended', true))->values();
                $uncited = $group->where('mentioned', true)->where('official_cited', false)->values();
                $contextOnly = $group->where('mentioned', true)->where('recommended', false)->values();
                $this->candidate($candidates, 'absence:'.$key, 'Review coverage for an absent brand', 'Search intent coverage', $absent, ['coverage' => 'Compare the saved search question with verified property information. Draft an answer only where the property genuinely meets that intent; ask the property team to verify every claim.'], $target);
                $this->candidate($candidates, 'competitors:'.$key, 'Compare competitor recommendation evidence', 'Competitor positioning', $instead, ['comparison' => 'Read the linked competitor recommendations and their attached sources. Record only supported differences against verified property facts; do not infer why the model chose them.']);
                $this->candidate($candidates, 'citation:'.$key, 'Review the missing official citation', 'Citation opportunities', $uncited, ['citation' => 'Check whether the official page offers a clear, sourceable answer to this question. Record relevant facts and existing citations; do not assume any publisher will link to the property.'], $target);
                $this->candidate($candidates, 'identity:'.$key, 'Review brand information for context-only mentions', 'Brand information consistency', $contextOnly, ['identity' => 'Compare the verified property name, location, and official URL across the owned pages relevant to this prompt. Record any discrepancy before proposing a correction; a context-only mention does not prove inconsistency.'], $target);
                if ($pages->isNotEmpty()) {
                    $this->candidate($candidates, 'content:'.$key, 'Audit a verified page against the search question', 'Website content improvements', $absent, ['content' => 'Review the verified target page against the supporting search prompt. List questions the page actually leaves unanswered and request verified information from the property team before adding content.'], $pages->first()['url']);
                }
            }
            foreach ($evidence->where('recommended', false)->groupBy('category') as $category => $group) {
                if ($group->pluck('prompt_id')->unique()->count() < 2) {
                    continue;
                }
                $this->candidate($candidates, 'repeated:'.hash('sha256', $category), 'Investigate repeated gaps: '.mb_substr($category, 0, 100), 'AI search discoverability', $group->values(), ['discoverability' => 'Review the linked prompts together. Check indexability and crawl access for relevant owned pages, then document findings. Missing recommendations alone do not establish a crawling or content problem.']);
            }
            DB::transaction(function () use ($candidates, $hotel, $user, $pilotBatchId) {
                $scope = $this->workspace->improvements();
                if ($pilotBatchId !== null) {
                    $scope->where('detection_key', 'like', 'pilot:'.$pilotBatchId.':%');
                } else {
                    $scope->where('detection_key', 'not like', 'pilot:%');
                }
                $scope->update(['active' => false]);
                foreach ($candidates as $key => $attributes) {
                    if ($pilotBatchId !== null) {
                        $key = 'pilot:'.$pilotBatchId.':'.$key;
                    }
                    $record = Improvement::firstOrNew(['hotel_id' => $hotel->id, 'detection_key' => $key]);
                    $new = ! $record->exists;
                    $record->fill([...$attributes, 'active' => true, 'refreshed_at' => now()]);
                    if ($new) {
                        $record->status = 'new';
                    }
                    $record->save();
                    if ($new) {
                        ImprovementEvent::create(['improvement_id' => $record->id, 'user_id' => $user->id, 'to_status' => 'new', 'note' => 'Generated from saved valid test evidence. No API request.']);
                    }
                }
            });

            return count($candidates);
        } finally {
            $lock->release();
        }
    }

    private function candidate(array &$rows, string $key, string $title, string $category, Collection $evidence, array $actions, ?string $target = null): void
    {
        if ($evidence->isEmpty()) {
            return;
        }
        $count = $evidence->count();
        $repeated = $count >= 3 && $evidence->pluck('prompt_id')->unique()->count() >= 2;
        $rows[$key] = ['title' => $title, 'category' => $category, 'priority' => $repeated ? 'high' : ($count >= 2 ? 'medium' : 'low'), 'confidence' => $count >= 2 ? 'moderate' : 'low', 'explanation' => $count.' valid completed test(s) support this observation. Suggested actions are hypotheses for investigation, not proof of a website defect or an explanation of model selection. Repetitions are not independent market research.', 'evidence' => $evidence->values()->all(), 'actions' => $actions, 'verified_target_url' => $target];
    }

    public function pages(string $prompt): Collection
    {
        $hotel = $this->workspace->hotel();

        return collect(app(CurrentWorkspace::class)->verifiedPages())->filter(function ($page) use ($hotel, $prompt) {
            $url = $page['url'] ?? '';

            return ($page['verified_source'] ?? null) === $url && str_starts_with($url, 'https://') && collect($hotel->official_websites)->contains(fn ($official) => str_starts_with($url, rtrim($official, '/').'/') || $url === rtrim($official, '/')) && collect($page['keywords'] ?? [])->contains(fn ($word) => str_contains(mb_strtolower($prompt), $word));
        })->values();
    }

    public function summary(): array
    {
        $rows = $this->workspace->improvements()->where('active', true)->get();
        $open = $rows->whereNotIn('status', ['completed', 'dismissed']);

        return ['open' => $open->count(), 'high' => $open->where('priority', 'high')->count(), 'in_progress' => $open->where('status', 'in_progress')->count(), 'completed' => $this->workspace->improvements()->where('status', 'completed')->count(), 'recent' => $rows->sortByDesc('created_at')->take(5)];
    }
}
