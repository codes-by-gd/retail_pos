<?php

namespace App\Modules\Reports;

use App\Models\User;
use App\Modules\Reports\Reports\DeadStockReport;
use App\Modules\Reports\Reports\DimensionSalesReport;
use App\Modules\Reports\Reports\ExpensesReport;
use App\Modules\Reports\Reports\Gstr1Report;
use App\Modules\Reports\Reports\Gstr3bReport;
use App\Modules\Reports\Reports\LowStockReport;
use App\Modules\Reports\Reports\LoyaltyReport;
use App\Modules\Reports\Reports\OutstandingReport;
use App\Modules\Reports\Reports\PaymentModesReport;
use App\Modules\Reports\Reports\ProfitLossReport;
use App\Modules\Reports\Reports\PurchaseRegisterReport;
use App\Modules\Reports\Reports\RegisterShiftsReport;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Reports\SalesSummaryReport;
use App\Modules\Reports\Reports\StockMovementReport;
use App\Modules\Reports\Reports\StockValuationReport;

/** The list of reports, in menu order. Add a report here to make it appear on the Reports page. */
class ReportRegistry
{
    /** @return list<Report> */
    public function all(): array
    {
        return [
            new SalesSummaryReport,
            new DimensionSalesReport('sales-by-item', 'Sales by item', 'Best and worst sellers — per article or per size/colour.',
                ['product' => ['Article', 'Article'], 'variant' => ['Size / colour (SKU)', 'Item']]),
            new DimensionSalesReport('sales-by-category', 'Sales by category', 'Men, Women, Kids… and their sub-categories.', ['category' => ['Category', 'Category']]),
            new DimensionSalesReport('sales-by-brand', 'Sales by brand', 'Which brands sell — useful when negotiating with suppliers.', ['brand' => ['Brand', 'Brand']]),
            new DimensionSalesReport('sales-by-size', 'Sales by size / colour', 'Which sizes and colours sell, to buy the right size ratio.', ['attribute' => ['Attribute', 'Value']]),
            new DimensionSalesReport('sales-by-cashier', 'Sales by cashier', 'Bills and sales per cashier.', ['cashier' => ['Cashier', 'Cashier']]),
            new DimensionSalesReport('sales-by-customer', 'Sales by customer', 'Top customers by purchase value.', ['customer' => ['Customer', 'Customer']]),
            new DimensionSalesReport('sales-by-branch', 'Sales by branch', 'Compare branches (choose “All branches”).', ['branch' => ['Branch', 'Branch']]),
            new DimensionSalesReport('sales-by-hour', 'Hourly sales', 'Busy hours of the day — plan staff at the counter.', ['hour' => ['Hour', 'Hour']]),
            new PaymentModesReport,
            new LoyaltyReport,

            new StockValuationReport,
            new LowStockReport,
            new DeadStockReport,
            new StockMovementReport,
            new PurchaseRegisterReport,
            new PurchaseRegisterReport(bySupplier: true),

            new ProfitLossReport,
            new DimensionSalesReport('profit', 'Profit & margin', 'Gross profit and margin % by article, category, brand, day…', [
                'product' => ['Article', 'Article'], 'variant' => ['Size / colour (SKU)', 'Item'], 'category' => ['Category', 'Category'],
                'brand' => ['Brand', 'Brand'], 'day' => ['Day', 'Date'], 'month' => ['Month', 'Month'], 'cashier' => ['Cashier', 'Cashier'], 'branch' => ['Branch', 'Branch'],
            ], profit: true),
            new OutstandingReport,
            new OutstandingReport(payables: true),
            new ExpensesReport,
            new RegisterShiftsReport,

            new Gstr1Report,
            new Gstr3bReport,
        ];
    }

    public function find(string $key): ?Report
    {
        foreach ($this->all() as $report) {
            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /** Reports the user may open, grouped for the index page. */
    public function groupedFor(User $user): array
    {
        return collect($this->all())
            ->filter(fn (Report $r) => $user->can($r->permission()))
            ->map(fn (Report $r) => $r->meta())
            ->groupBy('group')
            ->map(fn ($items, $group) => ['group' => $group, 'reports' => $items->values()->all()])
            ->values()->all();
    }
}
