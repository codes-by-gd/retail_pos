<?php

namespace App\Modules\Contacts\Enums;

enum LedgerEntryType: string
{
    case Opening = 'opening';
    case Sale = 'sale';                       // debit: customer owes us
    case SaleReturn = 'sale_return';          // credit
    case PaymentReceived = 'payment_received'; // credit
    case Purchase = 'purchase';               // credit: we owe the supplier
    case PurchaseReturn = 'purchase_return';  // debit
    case PaymentMade = 'payment_made';        // debit
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::PaymentReceived => 'Payment received',
            self::Purchase => 'Purchase',
            self::PurchaseReturn => 'Purchase return',
            self::PaymentMade => 'Payment made',
            self::Adjustment => 'Adjustment',
        };
    }
}
