import {
    AdjustmentsHorizontalIcon,
    ArchiveBoxIcon,
    ArrowsRightLeftIcon,
    BanknotesIcon,
    BuildingOffice2Icon,
    BuildingStorefrontIcon,
    CalculatorIcon,
    ComputerDesktopIcon,
    ChartBarIcon,
    Cog6ToothIcon,
    CubeIcon,
    DocumentTextIcon,
    HomeIcon,
    PrinterIcon,
    ReceiptPercentIcon,
    ShieldCheckIcon,
    ShoppingCartIcon,
    TagIcon,
    TruckIcon,
    UserGroupIcon,
    UsersIcon,
} from '@heroicons/vue/24/outline';

/**
 * Sidebar menu. `permission` hides an item the user can't use (string or array = any of).
 * `soon: true` marks modules not built yet (no route) — shown disabled with the phase they land in.
 * `active` is a Ziggy route pattern used to highlight the item.
 */
export const navigation = [
    {
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: HomeIcon, permission: 'dashboard.view' },
            { label: 'POS Billing', route: 'pos.terminal', active: 'pos.*', icon: ShoppingCartIcon, permission: 'pos.access' },
        ],
    },
    {
        label: 'Inventory',
        items: [
            { label: 'Products', route: 'products.index', active: 'products.*', icon: CubeIcon, permission: 'products.view' },
            { label: 'Stock', route: 'stock.index', active: 'stock.*', icon: ArchiveBoxIcon, permission: 'products.view' },
            { label: 'Adjustments', route: 'stock-adjustments.index', active: 'stock-adjustments.*', icon: AdjustmentsHorizontalIcon, permission: 'stock.adjust' },
            { label: 'Transfers', route: 'stock-transfers.index', active: 'stock-transfers.*', icon: ArrowsRightLeftIcon, permission: 'stock.transfer' },
            { label: 'Barcode Labels', route: 'labels.index', active: 'labels.*', icon: PrinterIcon, permission: 'labels.print' },
            {
                label: 'Catalog Setup',
                route: 'categories.index',
                active: ['categories.*', 'brands.*', 'attributes.*', 'units.*', 'tax-rates.*'],
                icon: TagIcon,
                permission: 'catalog.manage',
            },
        ],
    },
    {
        label: 'Orders',
        items: [
            { label: 'Sales', route: 'sales.index', active: 'sales.*', icon: ReceiptPercentIcon, permission: 'sales.view' },
            { label: 'Register Shifts', route: 'register-sessions.index', active: 'register-sessions.*', icon: ComputerDesktopIcon, permission: 'pos.register.manage' },
            { label: 'Purchases', icon: TruckIcon, permission: 'purchases.view', soon: 'Phase 4' },
            { label: 'Quotations', icon: DocumentTextIcon, permission: 'quotations.manage', soon: 'Phase 4' },
        ],
    },
    {
        label: 'Contacts',
        items: [
            { label: 'Customers', route: 'customers.index', active: ['customers.*', 'customer-groups.*'], icon: UsersIcon, permission: 'customers.view' },
            { label: 'Suppliers', route: 'suppliers.index', active: 'suppliers.*', icon: BuildingStorefrontIcon, permission: 'suppliers.view' },
        ],
    },
    {
        label: 'Finance',
        items: [
            { label: 'Expenses', icon: BanknotesIcon, permission: 'expenses.manage', soon: 'Phase 6' },
            {
                label: 'Reports',
                icon: ChartBarIcon,
                permission: ['reports.sales', 'reports.inventory', 'reports.financial'],
                soon: 'Phase 5',
            },
        ],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Users', route: 'users.index', active: 'users.*', icon: UserGroupIcon, permission: 'users.view' },
            { label: 'Roles & Permissions', route: 'roles.index', active: 'roles.*', icon: ShieldCheckIcon, permission: 'roles.manage' },
            { label: 'Registers', route: 'settings.registers.index', active: 'settings.registers.*', icon: CalculatorIcon, permission: 'settings.manage' },
            { label: 'Branches', route: 'settings.branches.index', active: 'settings.branches.*', icon: BuildingOffice2Icon, permission: 'settings.manage' },
            { label: 'Settings', route: 'settings.business.edit', active: 'settings.business.*', icon: Cog6ToothIcon, permission: 'settings.manage' },
        ],
    },
];
