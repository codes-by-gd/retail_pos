<?php

namespace App\Http\Controllers;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ctrl+K search: products, customers, suppliers, bills, purchases, quotations — only what the user may open. */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $user = $request->user();
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $digits = preg_replace('/\D/', '', $q);
        $branches = current_branch()->allowedFor($user)->pluck('id');
        $results = [];

        if ($user->can('products.view')) {
            Product::query()->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('article_no', 'like', $like)
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $q))))
                ->orderBy('name')->limit(6)->get(['id', 'name', 'article_no'])
                ->each(function ($p) use (&$results) {
                    $results[] = ['group' => 'Products', 'title' => $p->name, 'sub' => $p->article_no, 'url' => route('products.show', $p)];
                });
        }

        foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $kind => $plural) {
            if (! $user->can("{$plural}.view")) {
                continue;
            }
            Contact::query()->whereIn('type', [$kind, Contact::BOTH])
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('company_name', 'like', $like)->orWhere('code', 'like', $like)
                    ->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%")))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'company_name', 'phone', 'code'])
                ->each(function ($c) use (&$results, $plural) {
                    $results[] = ['group' => ucfirst($plural), 'title' => $c->company_name ?: $c->name, 'sub' => trim($c->code.' · '.$c->phone, ' ·'),
                        'url' => route("{$plural}.show", $c)];
                });
        }

        if ($user->can('sales.view')) {
            Sale::query()->whereIn('branch_id', $branches)
                ->where(fn ($w) => $w->where('invoice_no', 'like', $like)->orWhere('customer_name', 'like', $like)
                    ->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('customer_phone', 'like', "%{$digits}%")))
                ->latest('sold_at')->limit(5)->get(['id', 'invoice_no', 'customer_name', 'grand_total', 'sold_at'])
                ->each(function ($s) use (&$results) {
                    $results[] = ['group' => 'Bills', 'title' => $s->invoice_no, 'sub' => $s->customer_name.' · ₹'.number_format((float) $s->grand_total, 2).' · '.$s->sold_at->format('d M Y'),
                        'url' => route('sales.show', $s)];
                });
        }

        if ($user->can('purchases.view')) {
            Purchase::query()->whereIn('branch_id', $branches)
                ->where(fn ($w) => $w->where('reference_no', 'like', $like)->orWhere('supplier_invoice_no', 'like', $like))
                ->latest('id')->limit(4)->get(['id', 'reference_no', 'supplier_invoice_no', 'grand_total'])
                ->each(function ($p) use (&$results) {
                    $results[] = ['group' => 'Purchases', 'title' => $p->reference_no, 'sub' => trim(($p->supplier_invoice_no ? 'Bill '.$p->supplier_invoice_no.' · ' : '').'₹'.number_format((float) $p->grand_total, 2)),
                        'url' => route('purchases.show', $p)];
                });
        }

        if ($user->can('quotations.manage')) {
            Quotation::query()->whereIn('branch_id', $branches)
                ->where(fn ($w) => $w->where('reference_no', 'like', $like)->orWhere('customer_name', 'like', $like))
                ->latest('id')->limit(4)->get(['id', 'reference_no', 'customer_name'])
                ->each(function ($qt) use (&$results) {
                    $results[] = ['group' => 'Quotations', 'title' => $qt->reference_no, 'sub' => $qt->customer_name, 'url' => route('quotations.show', $qt)];
                });
        }

        return response()->json(['results' => $results]);
    }
}
