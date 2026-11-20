<?php

use App\Http\Controllers\Webhooks\XenditWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Payment provider webhooks. Loaded in bootstrap/app.php WITHOUT the "web"
| middleware group: no session, no cookies, no CSRF. Each controller checks
| the provider's token itself.
*/

Route::post('webhooks/xendit', XenditWebhookController::class)->middleware('throttle:120,1')->name('webhooks.xendit');
