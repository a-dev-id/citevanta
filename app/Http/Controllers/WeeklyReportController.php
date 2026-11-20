<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/**
 * One-click unsubscribe from the weekly report (signed link in the email).
 */
class WeeklyReportController extends Controller
{
    public function __invoke(User $user): View
    {
        $user->forceFill(['weekly_report' => false])->save();

        return view('weekly-report-unsubscribed');
    }
}
