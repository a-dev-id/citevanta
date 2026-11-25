<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
| The website tracking script and its endpoint. Loaded in bootstrap/app.php WITHOUT the "web" middleware group:
| no session, no cookies, no CSRF. The endpoint is rate limited per public key (see AppServiceProvider), never per IP.
*/

Route::get('t/{key}.js', [TrackingController::class, 'script'])->where('key', 'cvk_[a-z0-9]{24}')->name('tracking.script');
Route::post('t/collect', [TrackingController::class, 'collect'])->middleware('throttle:tracking')->name('tracking.collect');
Route::options('t/collect', [TrackingController::class, 'preflight']);
