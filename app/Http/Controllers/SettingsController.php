<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('settings', ['configured' => (bool) config('intelligence.openai_key')]);
    }
}
