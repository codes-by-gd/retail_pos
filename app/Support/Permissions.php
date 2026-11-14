<?php

namespace App\Support;

/**
 * Single source of truth for permission names and default roles.
 * Run `php artisan db:seed --class=RolesAndPermissionsSeeder` after editing to sync the database.
 * The "Admin" role bypasses all checks (see AppServiceProvider Gate::before).
 */
final class Permissions
{
    public const ADMIN_ROLE = 'Admin';

    /** Grouped by module => [permission => label] */
    public const GROUPS = [
        'Dashboard' => [
            'dashboard.view' => 'View dashboard',
        ],
        'POS' => [
            'pos.access' => 'Use the POS billing screen',
            'pos.discount' => 'Give discounts',
            'pos.price_override' => 'Change selling price on a bill',
            'pos.returns' => 'Process returns / exchanges',
            'pos.register.manage' => 'Open / close cash registers',
        ],
        'Inventory' => [
            'products.view' => 'View products',
            'products.manage' => 'Create / edit products',
            'products.delete' => 'Delete products',
            'catalog.manage' => 'Manage categories, brands, units & taxes',
            'stock.adjust' => 'Adjust stock',
            'stock.transfer' => 'Transfer stock between branches',
            'labels.print' => 'Print barcode labels',
        ],
        'Orders' => [
            'sales.view' => 'View sales',
            'sales.cancel' => 'Cancel sales',
            'quotations.manage' => 'Manage quotations',
            'purchases.view' => 'View purchases',
            'purchases.manage' => 'Create / receive purchases',
            'payments.manage' => 'Record payments',
        ],
        'Contacts' => [
            'customers.view' => 'View customers',
            'customers.manage' => 'Create / edit customers',
            'suppliers.view' => 'View suppliers',
            'suppliers.manage' => 'Create / edit suppliers',
        ],
        'Expenses' => [
            'expenses.manage' => 'Manage expenses',
        ],
        'Reports' => [
            'reports.sales' => 'Sales reports',
            'reports.inventory' => 'Inventory reports',
            'reports.financial' => 'Profit, GST & financial reports',
        ],
        'Users' => [
            'users.view' => 'View users',
            'users.manage' => 'Create / edit users',
            'roles.manage' => 'Manage roles & permissions',
        ],
        'Settings' => [
            'settings.manage' => 'Manage business settings & branches',
            'activity.view' => 'View audit log',
        ],
    ];

    /** Default roles created by the seeder (Admin gets everything implicitly). */
    public const DEFAULT_ROLES = [
        'Manager' => ['*', '!roles.manage', '!settings.manage'],
        'Cashier' => [
            'dashboard.view', 'pos.access', 'pos.discount', 'pos.returns', 'pos.register.manage',
            'products.view', 'sales.view', 'customers.view', 'customers.manage',
        ],
        'Stock Keeper' => [
            'dashboard.view', 'products.view', 'products.manage', 'catalog.manage',
            'stock.adjust', 'stock.transfer', 'labels.print',
            'purchases.view', 'purchases.manage', 'suppliers.view', 'suppliers.manage',
            'reports.inventory',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GROUPS)));
    }

    /** Expands '*' and '!perm' patterns into a concrete list. */
    public static function resolve(array $patterns): array
    {
        $result = in_array('*', $patterns, true) ? self::all() : [];

        foreach ($patterns as $p) {
            if ($p === '*') {
                continue;
            }
            str_starts_with($p, '!')
                ? $result = array_diff($result, [substr($p, 1)])
                : $result[] = $p;
        }

        return array_values(array_unique($result));
    }
}
