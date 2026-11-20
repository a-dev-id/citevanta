<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChoosePlanRequest;
use App\Services\Billing\BillingNotAllowed;
use App\Services\Billing\BillingService;
use App\Services\Plans\PlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Settings > Billing (team owners only).
 */
class BillingController extends Controller
{
    public function show(Request $request, BillingService $billing, PlanCatalog $catalog): View
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manageBilling', $team);

        $currency = $billing->currencyFor($team);

        return view('settings.billing', [
            'team' => $team,
            'plans' => $catalog->plans(),
            'currency' => $currency,
            'currencies' => $billing->currencies(),
            'minimumHotels' => $billing->minimumHotels($team),
            'running' => $billing->isRunning($team),
            'renewal' => $billing->renewalQuote($team),
            'automaticAvailable' => $billing->supportsAutomaticRenewal(),
            'openInvoice' => $team->invoices()->open()->latest('id')->first(),
            'invoices' => $team->invoices()->latest('id')->paginate(12),
            'annualMonths' => $catalog->annualMonths(),
            'discounts' => $catalog->discounts(),
        ]);
    }

    public function choosePlan(ChoosePlanRequest $request, BillingService $billing): RedirectResponse
    {
        try {
            $result = $billing->choosePlan(
                $request->user()->currentTeam,
                Plan::from($request->validated('plan')),
                BillingInterval::from($request->validated('interval')),
                (int) $request->validated('hotels'),
                $request->validated('currency'),
                PaymentMethod::from($request->validated('payment_method')),
            );
        } catch (BillingNotAllowed $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($result->redirectUrl !== null) {
            return redirect()->away($result->redirectUrl);
        }

        return $result->invoice !== null
            ? to_route('billing.invoices.show', $result->invoice)->with('status', $result->message)
            : to_route('settings.billing')->with('status', $result->message);
    }

    public function cancel(Request $request, BillingService $billing): RedirectResponse
    {
        return $this->act($request, fn ($team) => $billing->cancel($team), 'Your subscription will end at the end of the paid period.');
    }

    public function resume(Request $request, BillingService $billing): RedirectResponse
    {
        return $this->act($request, fn ($team) => $billing->resume($team), 'Your subscription continues.');
    }

    private function act(Request $request, callable $action, string $message): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manageBilling', $team);

        try {
            $action($team);
        } catch (BillingNotAllowed $e) {
            return to_route('settings.billing')->with('error', $e->getMessage());
        }

        return to_route('settings.billing')->with('status', $message);
    }
}
