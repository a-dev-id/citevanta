<?php

namespace App\Livewire\Hotels;

use App\Models\AccuracyIssue;
use App\Models\Hotel;
use App\Services\Hotels\CurrentHotel;
use App\Services\Insights\Accuracy\AccuracyReport;
use App\Services\Insights\Accuracy\AccuracyService;
use App\Services\Trials\TrialLocks;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /hotels/{hotel}/accuracy: what AI assistants get wrong about the hotel,
 * with filters, and dismiss / reopen. Data and changes go through
 * AccuracyReport and AccuracyService.
 */
class AccuracyPage extends Component
{
    #[Locked]
    public int $hotelId;

    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $verdict = '';

    #[Url(except: '')]
    public string $engine = '';

    /** Issue whose dismiss form is open. */
    public ?int $dismissing = null;

    public string $reason = '';

    public ?string $notice = null;

    public function mount(Hotel $hotel): void
    {
        Gate::authorize('view', $hotel);
        app(CurrentHotel::class)->set($hotel);
        $this->hotelId = $hotel->id;
    }

    public function startDismiss(int $issueId): void
    {
        $this->dismissing = $this->issue($issueId)->id;
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelDismiss(): void
    {
        $this->reset('dismissing', 'reason');
    }

    public function dismiss(AccuracyService $accuracy): void
    {
        $issue = $this->issue((int) $this->dismissing);
        Gate::authorize('update', $this->hotel());

        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'reason']);

        $accuracy->dismiss($issue, auth()->user(), $this->reason);
        $this->reset('dismissing', 'reason');
        $this->notice = 'Issue dismissed. It will not come back unless you reopen it.';
    }

    public function reopen(int $issueId, AccuracyService $accuracy): void
    {
        Gate::authorize('update', $this->hotel());
        $accuracy->reopen($this->issue($issueId));
        $this->notice = 'Issue reopened.';
    }

    public function render(AccuracyReport $report): View
    {
        $hotel = $this->hotel();
        $issues = $report->issues($hotel, [
            'status' => $this->status === 'all' ? '' : $this->status,
            'severity' => $this->severity,
            'verdict' => $this->verdict,
            'engine' => $this->engine,
        ]);

        return view('livewire.hotels.accuracy-page', [
            'hotel' => $hotel,
            'issues' => $issues,
            'links' => $report->answerLinks($hotel, $issues),
            'missingFacts' => $report->missingFacts($hotel),
            'engines' => $report->engines($hotel),
            'openCount' => $report->openCount($hotel),
            // Trial: only the top 3 open issues in full (null = all).
            'unlocked' => app(TrialLocks::class)->unlockedIssues($hotel),
            'lockUrl' => auth()->user()->ownsTeam($hotel->team) ? route('settings.billing') : null,
        ])->layout('layouts::app', [
            'title' => 'Accuracy',
            'breadcrumbs' => ['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Accuracy' => null],
        ]);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::findOrFail($this->hotelId);
        Gate::authorize('view', $hotel);

        return $hotel;
    }

    private function issue(int $issueId): AccuracyIssue
    {
        return $this->hotel()->accuracyIssues()->findOrFail($issueId);
    }
}
