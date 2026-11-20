<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Team;
use App\Services\Billing\BillingNotAllowed;
use App\Services\Billing\BillingService;
use App\Services\Plans\PlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin > Billing (open invoices, mark as paid, reject) and the billing
 * actions on Admin > Teams (extend trial, comp plan).
 */
class BillingController extends Controller
{
    public function index(): View
    {
        return view('admin.billing', [
            'open' => Invoice::query()->open()->with('team')
                ->orderByRaw('case when status = ? then 0 else 1 end', [InvoiceStatus::Submitted->value])
                ->orderBy('due_at')->get(),
            'recent' => Invoice::query()->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Void->value])
                ->with(['team', 'markedPaidBy'])->latest('updated_at')->limit(25)->get(),
            'provider' => (string) config('citevanta.billing.provider', 'manual'),
            'xenditMode' => (string) config('services.xendit.mode', 'test'),
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $invoice->isOpen()) {
            return back()->with('error', "{$invoice->number} is not open.");
        }

        $paidAt = Carbon::parse($data['paid_on'], $invoice->team->timezoneName())->isToday()
            ? now()
            : Carbon::parse($data['paid_on'], $invoice->team->timezoneName())->endOfDay()->utc();

        $billing->markPaid($invoice, $paidAt, $data['note'] ?? null, $request->user());

        return back()->with('status', "{$invoice->number} marked as paid. The subscription is active.");
    }

    public function reject(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $billing->reject($invoice, $data['reason']);
        } catch (BillingNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$invoice->number} rejected. The owner was emailed.");
    }

    public function extendTrial(Request $request, Team $team, BillingService $billing): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);

        try {
            $billing->extendTrial($team, (int) $data['days']);
        } catch (BillingNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Trial of {$team->name} extended by {$data['days']} days.");
    }

    public function comp(Request $request, Team $team, BillingService $billing): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_map(fn ($plan) => $plan->value, PlanCatalog::PAID))],
            'until' => ['nullable', 'date', 'after:today'],
            'hotels' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $until = isset($data['until']) ? Carbon::parse($data['until'], $team->timezoneName())->endOfDay()->utc() : null;
        $billing->comp($team, Plan::from($data['plan']), $until, (int) $data['hotels']);

        return back()->with('status', "{$team->name} is on a free ".Plan::from($data['plan'])->label().' plan'.($until ? ' until '.$until->setTimezone($team->timezoneName())->format('j M Y') : '').'.');
    }
}
