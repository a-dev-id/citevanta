<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTeamSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TeamSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('view', $team);

        return view('settings.team', [
            'team' => $team,
            'members' => $team->users()->orderBy('name')->get(),
            'limits' => $team->limits(),
            'canUpdate' => $request->user()->can('update', $team),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(UpdateTeamSettingsRequest $request): RedirectResponse
    {
        $request->user()->currentTeam->update($request->validated());

        return to_route('settings.team')->with('status', 'Team settings saved.');
    }
}
