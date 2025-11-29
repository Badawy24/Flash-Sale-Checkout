<?php

namespace App\Enums;

enum StatusEnum: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case USED = 'used';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PAID => 'Paid',
            self::CANCELLED => 'Cancelled',
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expired',
            self::USED => 'Used',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
