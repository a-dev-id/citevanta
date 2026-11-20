<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Team;

/**
 * Bank transfer to the account in config citevanta.billing.bank. Each
 * invoice gets a unique amount (a small random code added to the total) so
 * an admin can match the payment in the bank statement and mark it paid.
 */
class ManualBankTransferProvider implements BillingProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function prepareInvoice(Invoice $invoice): void
    {
        $code = $this->uniqueCode($invoice);

        $invoice->forceFill(['unique_code' => $code, 'amount_due' => $invoice->total + $code])->save();
    }

    public function paymentUrl(Invoice $invoice): ?string
    {
        return null;
    }

    public function supportsAutomaticRenewal(): bool
    {
        return false;
    }

    public function startAutomaticRenewal(Team $team, Invoice $invoice): string
    {
        throw new BillingNotAllowed('Automatic renewal is not available yet. Pay by bank transfer.');
    }

    public function updateAutomaticRenewal(Team $team, int $amount, int $months): void {}

    public function cancelAutomaticRenewal(Team $team): void {}

    public function voidInvoice(Invoice $invoice): void {}

    /**
     * A code that no other open invoice in the same currency uses for the same total.
     */
    private function uniqueCode(Invoice $invoice): int
    {
        [$min, $max] = (array) config("citevanta.billing.unique_code.{$invoice->currency}", [1, 999]);
        $taken = Invoice::query()->open()
            ->where('currency', $invoice->currency)
            ->whereKeyNot($invoice->id)
            ->pluck('amount_due')
            ->all();

        $candidates = range((int) $min, (int) $max);
        shuffle($candidates);

        foreach ($candidates as $code) {
            if (! in_array($invoice->total + $code, $taken, false)) {
                return $code;
            }
        }

        return random_int((int) $min, (int) $max);
    }
}
