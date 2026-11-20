<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPaymentRequest;
use App\Models\Invoice;
use App\Services\Billing\BillingNotAllowed;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The printable invoice page, "Pay now", "I have paid" and the payment proof.
 * Access: the team's owners and admins (InvoicePolicy).
 */
class InvoiceController extends Controller
{
    public function show(Invoice $invoice, BillingService $billing): View
    {
        $invoice->load('team');

        return view('billing.invoice', [
            'invoice' => $invoice,
            'team' => $invoice->team,
            'owner' => $invoice->team->owners()->orderBy('users.id')->first(),
            'business' => (array) config('citevanta.billing.business'),
            'bank' => (array) config('citevanta.billing.bank'),
            'taxName' => (string) config('citevanta.billing.tax.name', 'Tax'),
            'canPay' => request()->user()->can('pay', $invoice),
            'payOnline' => $invoice->isOpen() && $invoice->provider !== 'manual',
        ]);
    }

    /**
     * "Pay now": to the provider's payment page.
     */
    public function pay(Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $url = $billing->paymentUrl($invoice);

        return $url !== null
            ? redirect()->away($url)
            : to_route('billing.invoices.show', $invoice)->with('error', 'Online payment is not available right now. Please try again in a few minutes.');
    }

    public function submitPayment(SubmitPaymentRequest $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $billing->submitPayment($invoice, $request->file('proof'));
        } catch (BillingNotAllowed $e) {
            return to_route('billing.invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return to_route('billing.invoices.show', $invoice)
            ->with('status', 'Thank you. We will check the payment and confirm by email, usually within one working day.');
    }

    public function proof(Invoice $invoice): StreamedResponse
    {
        abort_if($invoice->proof_path === null || ! Storage::disk(BillingService::PROOF_DISK)->exists($invoice->proof_path), 404);

        return Storage::disk(BillingService::PROOF_DISK)->response(
            $invoice->proof_path,
            $invoice->proof_name ?? basename($invoice->proof_path),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
