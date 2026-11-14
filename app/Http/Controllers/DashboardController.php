<?php

namespace App\Http\Controllers;

use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Sales\Models\Sale;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $branchId = current_branch()->id();

        // Today's sales for the current branch (charts and reports arrive in Phase 5).
        $today = $branchId ? Sale::where('branch_id', $branchId)->where('status', Sale::COMPLETED)
            ->where('sold_at', '>=', now()->startOfDay())
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as total')->first() : null;

        $lowStock = $branchId ? ProductVariant::query()
            ->active()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('stock_levels', fn ($j) => $j->on('stock_levels.product_variant_id', '=', 'product_variants.id')
                ->where('stock_levels.branch_id', $branchId))
            ->whereNull('products.deleted_at')
            ->where('products.is_active', true)
            ->where('products.track_stock', true)
            ->whereRaw('COALESCE(stock_levels.quantity, 0) <= product_variants.reorder_level')
            ->count() : 0;

        $bills = (int) ($today->bills ?? 0);
        $total = (float) ($today->total ?? 0);

        return Inertia::render('Dashboard', [
            'lowStock' => $lowStock,
            'today' => ['bills' => $bills, 'total' => $total, 'average' => $bills ? round($total / $bills, 2) : 0],
        ]);
    }
}
