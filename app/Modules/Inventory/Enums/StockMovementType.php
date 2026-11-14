<?php

namespace App\Modules\Inventory\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case TransferCancel = 'transfer_cancel';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case SaleCancel = 'sale_cancel';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::AdjustmentIn => 'Adjustment (+)',
            self::AdjustmentOut => 'Adjustment (−)',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::TransferCancel => 'Transfer cancelled',
            self::Purchase => 'Purchase',
            self::PurchaseReturn => 'Purchase return',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::SaleCancel => 'Sale cancelled',
            self::Import => 'Import',
        };
    }
}
