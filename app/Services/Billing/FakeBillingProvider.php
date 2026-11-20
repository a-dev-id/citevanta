<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Team;

/**
 * For tests (BILLING_PROVIDER=fake): a payment link per invoice and
 * automatic renewal, with every call recorded in $calls.
 */
class FakeBillingProvider implements BillingProvider
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    public function name(): string
    {
        return 'fake';
    }

    public function prepareInvoice(Invoice $invoice): void
    {
        $this->calls[] = ['prepare', $invoice->number];
        $invoice->forceFill([
            'amount_due' => $invoice->total,
            'provider_reference' => 'fake-'.$invoice->number,
            'payment_url' => 'https://pay.example.test/'.$invoice->number,
        ])->save();
    }

    public function paymentUrl(Invoice $invoice): ?string
    {
        return $invoice->payment_url;
    }

    public function supportsAutomaticRenewal(): bool
    {
        return true;
    }

    public function startAutomaticRenewal(Team $team, Invoice $invoice): string
    {
        $this->calls[] = ['start', $invoice->number];

        return 'https://pay.example.test/authorise/'.$invoice->number;
    }

    public function updateAutomaticRenewal(Team $team, int $amount, int $months): void
    {
        $this->calls[] = ['update', ['team' => $team->id, 'amount' => $amount, 'months' => $months]];
    }

    public function cancelAutomaticRenewal(Team $team): void
    {
        $this->calls[] = ['cancel', $team->id];
    }

    public function voidInvoice(Invoice $invoice): void
    {
        $this->calls[] = ['void', $invoice->number];
    }

    /**
     * @return list<mixed>
     */
    public function callsOf(string $type): array
    {
        return array_values(array_map(fn ($call) => $call[1], array_filter($this->calls, fn ($call) => $call[0] === $type)));
    }
}
