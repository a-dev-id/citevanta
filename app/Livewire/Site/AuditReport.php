<?php

namespace App\Livewire\Site;

use App\Enums\DomainType;
use App\Models\Audit;
use App\Services\Plans\PlanCatalog;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /audit/{token}: the free audit report. While the audit runs it shows
 * progress and polls every 5 seconds; then the results. Public (anyone with
 * the unguessable link), never indexed.
 */
class AuditReport extends Component
{
    #[Locked]
    public string $token;

    public function mount(string $token): void
    {
        abort_unless(Audit::query()->where('token', $token)->exists(), 404);
        $this->token = $token;
    }

    public function render(PlanCatalog $plans): View
    {
        $audit = Audit::with(['source', 'scanRun'])->where('token', $this->token)->firstOrFail();
        App::setLocale($audit->locale);
        $results = $audit->results();
        $scan = $results->scanRun()->first();

        $total = (int) (config('citevanta.audit.prompts', 10));
        $answered = $scan ? $scan->responses()->count() : 0;
        $domainTotal = array_sum($results->domain_types ?? []);

        return view('livewire.site.audit-report', [
            'audit' => $audit,
            'results' => $results,
            'finished' => $audit->isFinished(),
            'answered' => min($answered, $total),
            'total' => $total,
            'domainTypes' => collect($results->domain_types ?? [])->map(fn (int $count, string $type) => [
                'type' => DomainType::tryFrom($type) ?? DomainType::Other,
                'count' => $count,
                'share' => $domainTotal > 0 ? (int) round($count / $domainTotal * 100) : 0,
            ])->values()->all(),
            'questionList' => $scan ? $scan->hotel?->prompts()->whereIn('id', $scan->prompt_ids ?? [])->orderBy('id')->pluck('text')->all() ?? [] : [],
            'trialDays' => $plans->trialDays(),
        ])->layout('layouts::public', [
            'title' => __('audit.report.title', ['hotel' => $audit->hotel_name]).' – '.config('app.name'),
            'description' => __('audit.report.description', ['hotel' => $audit->hotel_name, 'city' => $audit->city]),
            'noindex' => true,
            'livewire' => true,
        ]);
    }
}
