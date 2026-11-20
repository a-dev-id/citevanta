<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    /** Waiting for payment. */
    case Pending = 'pending';
    /** The owner said "I have paid"; an admin checks the bank statement. */
    case Submitted = 'submitted';
    case Paid = 'paid';
    /** An admin could not find the payment; the owner can submit again. */
    case Rejected = 'rejected';
    /** No longer payable (replaced, or the subscription was canceled). */
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for payment',
            self::Submitted => 'Payment being checked',
            self::Paid => 'Paid',
            self::Rejected => 'Payment not found',
            self::Void => 'Void',
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Submitted => 'info',
            self::Paid => 'success',
            self::Rejected => 'danger',
            self::Void => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Submitted, self::Rejected];
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::open());
    }
}
