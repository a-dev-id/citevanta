<?php

namespace App\Http\Controllers;

use App\Services\Hotels\CurrentHotel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Team home: the current hotel's dashboard when it is set up; otherwise
     * the welcome / hotel list page (which links to finish setup).
     */
    public function __invoke(Request $request, CurrentHotel $currentHotel): View|RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $hotel = $currentHotel->get($request->user());

        if ($hotel?->isSetUp()) {
            return to_route('hotels.show', $hotel);
        }

        return view('dashboard', [
            'team' => $team,
            'hotels' => $team?->hotels()->orderBy('name')->get() ?? collect(),
        ]);
    }
}
