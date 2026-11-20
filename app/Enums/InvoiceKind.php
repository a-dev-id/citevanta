<?php

namespace App\Enums;

enum InvoiceKind: string
{
    /** First payment, or paying again after the subscription expired. */
    case New = 'new';
    /** The next period of a running subscription. */
    case Renewal = 'renewal';
    /** Pro-rata difference for upgrading during a period. */
    case Upgrade = 'upgrade';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
