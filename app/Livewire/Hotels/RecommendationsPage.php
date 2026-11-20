<?php

namespace App\Livewire\Hotels;

use App\Enums\RecommendationStatus;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Services\Hotels\CurrentHotel;
use App\Services\Recommendations\RecommendationReport;
use App\Services\Recommendations\RecommendationService;
use App\Services\Trials\TrialLocks;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /hotels/{hotel}/recommendations: the action checklist. Filters, sorting,
 * one-click status changes, "Looks done" confirmation and the content panel.
 * Data and changes go through RecommendationReport and RecommendationService.
 */
class RecommendationsPage extends Component
{
    #[Locked]
    public int $hotelId;

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: '')]
    public string $impact = '';

    #[Url(except: '')]
    public string $effort = '';

    #[Url(except: 'priority')]
    public string $sort = 'priority';

    /** Recommendation whose dismiss form is open. */
    public ?int $dismissing = null;

    public string $reason = '';

    /** Recommendation whose "Create content" panel is open. */
    public ?int $contentFor = null;

    public ?string $notice = null;

    public function mount(Hotel $hotel): void
    {
        Gate::authorize('view', $hotel);
        app(CurrentHotel::class)->set($hotel);
        $this->hotelId = $hotel->id;
    }

    public function setStatus(int $id, string $status, RecommendationService $service): void
    {
        $status = RecommendationStatus::tryFrom($status);
        abort_if($status === null || $status === RecommendationStatus::Dismissed || $status === RecommendationStatus::New, 422);
        Gate::authorize('update', $this->hotel());

        $service->setStatus($this->recommendation($id), $status, auth()->user());
        $this->notice = 'Marked as '.mb_strtolower($status->label()).'.';
    }

    public function startDismiss(int $id): void
    {
        $this->dismissing = $this->recommendation($id)->id;
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelDismiss(): void
    {
        $this->reset('dismissing', 'reason');
    }

    public function dismiss(RecommendationService $service): void
    {
        Gate::authorize('update', $this->hotel());
        $recommendation = $this->recommendation((int) $this->dismissing);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'reason']);

        $service->setStatus($recommendation, RecommendationStatus::Dismissed, auth()->user(), $this->reason);
        $this->reset('dismissing', 'reason');
        $this->notice = 'Recommendation dismissed.';
    }

    /**
     * "Looks done": the team confirms (done) or says it is not done yet.
     */
    public function confirmDone(int $id, RecommendationService $service): void
    {
        $this->setStatus($id, RecommendationStatus::Done->value, $service);
    }

    public function notDoneYet(int $id): void
    {
        Gate::authorize('update', $this->hotel());
        $this->recommendation($id)->update(['looks_done_at' => null]);
        $this->notice = 'Kept on your list.';
    }

    public function openContent(int $id): void
    {
        $recommendation = $this->recommendation($id);
        abort_if(app(TrialLocks::class)->isRecommendationLocked($this->hotel(), $recommendation->id), 403);

        $this->contentFor = $this->contentFor === $id ? null : $recommendation->id;
    }

    public function render(RecommendationReport $report, RecommendationService $service): View
    {
        $hotel = $this->hotel();
        $items = $report->list($hotel, [
            'category' => $this->category,
            'status' => $this->status,
            'impact' => $this->impact,
            'effort' => $this->effort,
        ], array_key_exists($this->sort, RecommendationReport::SORTS) ? $this->sort : 'priority');

        return view('livewire.hotels.recommendations-page', [
            'hotel' => $hotel,
            'items' => $items,
            'quickWins' => $report->quickWins($hotel),
            'openCount' => $report->openCount($hotel),
            'impacts' => $items->mapWithKeys(fn (Recommendation $r) => [$r->id => $service->measuredImpact($r)])->filter()->all(),
            // Trial: only the top 3 open recommendations in full (null = all).
            'unlocked' => app(TrialLocks::class)->unlockedRecommendations($hotel),
            'canBill' => auth()->user()->ownsTeam($hotel->team),
        ])->layout('layouts::app', [
            'title' => 'Recommendations',
            'breadcrumbs' => ['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Recommendations' => null],
        ]);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('view', $hotel);

        return $hotel;
    }

    private function recommendation(int $id): Recommendation
    {
        return $this->hotel()->recommendations()->findOrFail($id);
    }
}
