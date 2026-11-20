<?php

namespace App\Enums;

enum PaymentMethod: string
{
    /** An invoice before each period (bank transfer, or a Xendit payment link). */
    case Manual = 'manual';
    /** The provider charges a saved card or e-wallet every period (Xendit). */
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Invoice each period',
            self::Automatic => 'Automatic renewal (card or e-wallet)',
        };
    }
}
