<?php

use App\Http\Controllers\CronController;
use Illuminate\Support\Facades\Route;

/*
| Fallback URL cron. Loaded in bootstrap/app.php WITHOUT the "web" middleware
| group: no session, no cookies, no CSRF. Throttled to 6 calls a minute.
*/

Route::middleware('throttle:cron')->group(function () {
    Route::get('cron/{token}', [CronController::class, 'run'])->name('cron.run');
    Route::get('cron/{token}/health', [CronController::class, 'health'])->name('cron.health');
});
