<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    /** Stock on hand at the current branch. */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category_id', 'level']);
        $branchId = current_branch()->id();

        $base = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('stock_levels', fn ($j) => $j->on('stock_levels.product_variant_id', '=', 'product_variants.id')
                ->where('stock_levels.branch_id', $branchId))
            ->whereNull('products.deleted_at')
            ->where('products.track_stock', true)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('products.name', 'like', "%{$s}%")
                ->orWhere('product_variants.name', 'like', "%{$s}%")
                ->orWhere('product_variants.sku', 'like', "%{$s}%")
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $s))))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('products.category_id', $id))
            // "low" = at or below reorder level, including out of stock (matches the dashboard KPI)
            ->when(($filters['level'] ?? null) === 'low', fn ($q) => $q
                ->whereRaw('COALESCE(stock_levels.quantity, 0) <= product_variants.reorder_level'))
            ->when(($filters['level'] ?? null) === 'out', fn ($q) => $q->whereRaw('COALESCE(stock_levels.quantity, 0) <= 0'))
            ->when(($filters['level'] ?? null) === 'in', fn ($q) => $q->where('stock_levels.quantity', '>', 0));

        $totals = (clone $base)->selectRaw('COUNT(*) as items, COALESCE(SUM(stock_levels.quantity * stock_levels.avg_cost), 0) as value, COALESCE(SUM(CASE WHEN stock_levels.quantity > 0 THEN stock_levels.quantity * product_variants.selling_price ELSE 0 END), 0) as retail_value')
            ->toBase()->first();

        $rows = $base
            ->select([
                'product_variants.id', 'product_variants.product_id', 'product_variants.name as variant_name', 'product_variants.sku',
                'product_variants.selling_price', 'product_variants.reorder_level', 'products.name as product_name',
                DB::raw('COALESCE(stock_levels.quantity, 0) as quantity'), DB::raw('COALESCE(stock_levels.avg_cost, 0) as avg_cost'),
            ])
            ->with('product.unit:id,short_name')
            ->orderBy('products.name')->orderBy('product_variants.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($v) => [
                'id' => $v->id,
                'product_id' => $v->product_id,
                'name' => $v->variant_name ? "{$v->product_name} - {$v->variant_name}" : $v->product_name,
                'sku' => $v->sku,
                'unit' => $v->product->unit->short_name,
                'quantity' => (float) $v->quantity,
                'reorder_level' => (float) $v->reorder_level,
                'avg_cost' => (float) $v->avg_cost,
                'value' => round((float) $v->quantity * (float) $v->avg_cost, 2),
                'selling_price' => (float) $v->selling_price,
            ]);

        return Inertia::render('Inventory/Stock/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'totals' => ['items' => (int) $totals->items, 'value' => round((float) $totals->value, 2), 'retail_value' => round((float) $totals->retail_value, 2)],
            'categories' => Category::options(),
        ]);
    }

    /** Stock ledger: every movement at the current branch, optionally for one item. */
    public function ledger(Request $request): Response
    {
        $filters = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $branchId = current_branch()->id();

        $movements = StockMovement::query()
            ->where('branch_id', $branchId)
            ->with(['variant' => fn ($q) => $q->withTrashed()->with(['product' => fn ($p) => $p->withTrashed()->select('id', 'name')]), 'user:id,name', 'reference'])
            ->when($filters['variant_id'] ?? null, fn ($q, $id) => $q->where('product_variant_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'date' => $m->created_at->toIso8601String(),
                'item' => $m->variant->fullName(),
                'variant_id' => $m->product_variant_id,
                'sku' => $m->variant->sku,
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'quantity' => (float) $m->quantity,
                'unit_cost' => (float) $m->unit_cost,
                'balance_after' => (float) $m->balance_after,
                'reference' => $m->reference?->reference_no ?? null,
                'reference_url' => $this->referenceUrl($m),
                'note' => $m->note,
                'user' => $m->user?->name,
            ]);

        $variant = ! empty($filters['variant_id']) ? ProductVariant::withTrashed()->with('product')->find($filters['variant_id']) : null;

        return Inertia::render('Inventory/Stock/Ledger', [
            'movements' => $movements,
            'filters' => $filters,
            'variant' => $variant ? ['id' => $variant->id, 'name' => $variant->fullName(), 'sku' => $variant->sku] : null,
            'types' => collect(StockMovementType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    private function referenceUrl(StockMovement $m): ?string
    {
        return match ($m->reference_type) {
            (new StockAdjustment)->getMorphClass() => route('stock-adjustments.show', $m->reference_id),
            (new StockTransfer)->getMorphClass() => route('stock-transfers.show', $m->reference_id),
            default => null,
        };
    }
}
