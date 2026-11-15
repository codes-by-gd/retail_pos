<?php

namespace App\Modules\Notifications;

/**
 * Every alert the system can send. `permissions` = who may receive it (any one of them); branch-scoped
 * alerts only go to users assigned to that branch (Admins get all). Users can switch each type off or
 * add email in their notification preferences.
 */
final class NotificationTypes
{
    public const LOW_STOCK = 'low_stock';

    public const SALE_CANCELLED = 'sale_cancelled';

    public const BIG_DISCOUNT = 'big_discount';

    public const CASH_DIFFERENCE = 'cash_difference';

    public const SUPPLIER_DUES = 'supplier_dues';

    public const CUSTOMER_DUES = 'customer_dues';

    public const DAILY_SUMMARY = 'daily_summary';

    public const BACKUP = 'backup';

    public const ALL = [
        self::LOW_STOCK => ['label' => 'Low / out of stock', 'description' => 'An item drops to or below its reorder level after a sale, transfer or adjustment.',
            'permissions' => ['products.manage', 'purchases.manage']],
        self::SALE_CANCELLED => ['label' => 'Bill cancelled', 'description' => 'Someone cancels a bill.', 'permissions' => ['sales.cancel']],
        self::BIG_DISCOUNT => ['label' => 'Large discount', 'description' => 'A bill is discounted more than the limit set in Settings.', 'permissions' => ['reports.financial']],
        self::CASH_DIFFERENCE => ['label' => 'Cash short / excess', 'description' => 'A register is closed with a cash difference above the limit.', 'permissions' => ['reports.financial']],
        self::SUPPLIER_DUES => ['label' => 'Supplier bills due', 'description' => 'Daily: supplier bills overdue or due in the next 3 days.', 'permissions' => ['payments.manage']],
        self::CUSTOMER_DUES => ['label' => 'Customer dues', 'description' => 'Daily: customer bills unpaid for more than 30 days.', 'permissions' => ['payments.manage']],
        self::DAILY_SUMMARY => ['label' => 'Daily sales summary', 'description' => 'End of day: bills, net sales and cash differences per branch.', 'permissions' => ['reports.sales']],
        self::BACKUP => ['label' => 'Backup problems', 'description' => 'The nightly database backup failed.', 'permissions' => ['settings.manage']],
    ];

    public static function label(string $type): string
    {
        return self::ALL[$type]['label'] ?? $type;
    }
}
