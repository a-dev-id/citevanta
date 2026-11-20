<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Profile and password forms. They post to Fortify's
     * user-profile-information.update and user-password.update routes.
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('settings.profile', [
            'user' => $user,
            'weeklyReport' => $user->currentTeam !== null ? $user->wantsWeeklyReport($user->currentTeam) : (bool) $user->weekly_report,
        ]);
    }

    /**
     * Email notifications: the weekly report on or off.
     */
    public function updateNotifications(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['weekly_report' => $request->boolean('weekly_report')])->save();

        return to_route('settings.profile')->with('status', 'notifications-updated');
    }
}
