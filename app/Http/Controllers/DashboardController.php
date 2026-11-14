<?php

namespace App\Http\Controllers;

use App\Modules\Inventory\Models\ProductVariant;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $branchId = current_branch()->id();

        // Sales KPIs and charts arrive in Phase 5; stock figures are live since Phase 1.
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

        return Inertia::render('Dashboard', ['lowStock' => $lowStock]);
    }
}
