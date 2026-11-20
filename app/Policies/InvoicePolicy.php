<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Invoices and payment proofs: the team's owners, and internal admins.
 */
class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->is_admin || $user->ownsTeam($invoice->team);
    }

    /**
     * Pay, or say "I have paid": the team's owners only.
     */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $user->ownsTeam($invoice->team);
    }
}
