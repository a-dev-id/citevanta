<?php

namespace App\Livewire;

use App\Models\SetupChecklistState;
use App\Services\Setup\SetupChecklist as Checklist;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The floating setup checklist (see App\Services\Setup\SetupChecklist): an expanded card, a small pill, or
 * nothing, as the user left it. The items are recomputed on every render, so they tick after Livewire actions
 * too (the page asks for a refresh after each action, see resources/js/checklist.js).
 *
 * When every item is done, "All set" shows once on the next visit and the checklist hides itself for good.
 * "Setup checklist" in the help menu brings it back.
 */
class SetupChecklist extends Component
{
    /** The page it sits on: a Livewire update request has no route of its own. */
    #[Locked]
    public string $routeName = '';

    public string $state = SetupChecklistState::EXPANDED;

    public function mount(Checklist $checklist): void
    {
        $user = auth()->user();
        $this->routeName = (string) request()->route()?->getName();

        if (! $checklist->available($user, $this->routeName)) {
            $this->state = SetupChecklistState::HIDDEN;

            return;
        }

        $team = $user->currentTeam;
        $record = $checklist->record($user, $team);

        // Done: "All set" shows on the first visit after that, and the visit after hides it for good.
        if ($record->state !== SetupChecklistState::HIDDEN && $checklist->progress($checklist->items($user, $team))['complete']) {
            if ($record->all_set_views >= 1) {
                $record->state = SetupChecklistState::HIDDEN;
            } else {
                $record->all_set_views++;
            }

            $record->save();
        }

        $this->state = $record->state;
    }

    public function minimize(Checklist $checklist): void
    {
        $this->change($checklist, SetupChecklistState::MINIMIZED);
    }

    public function expand(Checklist $checklist): void
    {
        $this->change($checklist, SetupChecklistState::EXPANDED);
    }

    public function hide(Checklist $checklist): void
    {
        $this->change($checklist, SetupChecklistState::HIDDEN);
    }

    /** "Setup checklist" in the help menu. */
    #[On('setup-checklist-restore')]
    public function restore(Checklist $checklist): void
    {
        $user = auth()->user();

        if ($checklist->available($user, $this->routeName)) {
            $this->state = $checklist->restore($user, $user->currentTeam)->state;
        }
    }

    /** After a Livewire action on the page: nothing to do, the render below recomputes the items. */
    #[On('setup-checklist-refresh')]
    public function refresh(): void {}

    public function render(Checklist $checklist): View
    {
        $user = auth()->user();

        if ($this->state === SetupChecklistState::HIDDEN || ! $checklist->available($user, $this->routeName)) {
            return view('livewire.setup-checklist', ['visible' => false, 'items' => [], 'progress' => ['done' => 0, 'total' => 0, 'percent' => 0, 'complete' => false]]);
        }

        $items = $checklist->items($user, $user->currentTeam);

        return view('livewire.setup-checklist', ['visible' => true, 'items' => $items, 'progress' => $checklist->progress($items)]);
    }

    private function change(Checklist $checklist, string $state): void
    {
        $user = auth()->user();

        if (! $checklist->available($user, $this->routeName)) {
            return;
        }

        $this->state = $checklist->setState($user, $user->currentTeam, $state)->state;
    }
}
