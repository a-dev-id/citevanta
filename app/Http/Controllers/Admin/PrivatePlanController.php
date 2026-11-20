<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\PrivatePlanService;
use App\Services\Plans\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin > Teams > Private plan: price per team and editable limits.
 */
class PrivatePlanController extends Controller
{
    public function edit(Team $team): View
    {
        return view('admin.private-plan', [
            'team' => $team,
            'limits' => $team->isPrivate() ? $team->limits() : PlanLimits::for(Plan::Private),
            'editable' => (array) config('citevanta.editable_limits', []),
        ]);
    }

    public function update(Request $request, Team $team, PrivatePlanService $plans): RedirectResponse
    {
        $limitRules = collect((array) config('citevanta.editable_limits', []))
            ->mapWithKeys(fn (string $key) => ["limits.{$key}" => ['nullable', 'integer', 'min:0', 'max:1000']])->all();

        $data = $request->validate([
            'price_usd' => ['nullable', 'integer', 'min:0', 'required_without:price_idr'],
            'price_idr' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', Rule::in(['usd', 'idr'])],
            'interval' => ['required', Rule::in(['monthly', 'annual'])],
            'paid_until' => ['nullable', 'date'],
        ] + $limitRules);

        if (($data['currency'] === 'idr' && empty($data['price_idr'])) || ($data['currency'] === 'usd' && empty($data['price_usd']))) {
            return back()->withInput()->withErrors(['currency' => 'Set a price in the billing currency.']);
        }

        $plans->apply($team, [
            'price_usd' => isset($data['price_usd']) ? (int) $data['price_usd'] : null,
            'price_idr' => isset($data['price_idr']) ? (int) $data['price_idr'] : null,
            'currency' => $data['currency'],
            'interval' => $data['interval'],
            'paid_until' => isset($data['paid_until']) ? Carbon::parse($data['paid_until'], $team->timezoneName())->endOfDay()->utc() : null,
            'limits' => (array) ($data['limits'] ?? []),
        ]);

        return to_route('admin.teams')->with('status', "{$team->name} is on the Private plan.");
    }
}
