<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Contacts\Services\PaymentService;
use App\Modules\Inventory\Models\ProductVariant;
use App\Modules\Pos\Models\Register;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Purchases\Services\PurchaseService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\CheckoutService;
use App\Modules\Sales\Services\SaleReturnService;
use App\Modules\Settings\Models\Branch;
use App\Support\Gst\GstCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo trading history so the dashboard and reports have something to show: register shifts, bills,
 * a few returns, fortnightly restock purchases and customer receipts for the last DAYS days.
 * Runs through the real services with the clock moved back (Carbon::setTestNow), so stock, ledgers,
 * numbering and GST are exactly what the app would have produced. Deterministic (fixed random seed).
 * Skipped when sales already exist.
 */
class DemoSalesSeeder extends Seeder
{
    public const DAYS = 45;

    private int $failed = 0;

    private array $stock = [];   // "branchId:variantId" => qty available

    public function run(CheckoutService $checkout, RegisterService $registers, SaleReturnService $returns,
        PurchaseService $purchases, PaymentService $payments): void
    {
        if (Sale::exists()) {
            return;
        }
        mt_srand(2026);
        $tz = config('app.timezone');
        $realNow = Carbon::createFromTimestamp(time(), $tz);
        $start = $realNow->copy()->subDays(self::DAYS)->startOfDay();

        $branches = Branch::active()->orderBy('id')->get();
        $cashiers = [
            $branches[0]->id => User::where('email', 'cashier@pos.test')->first(),
            ($branches[1] ?? $branches[0])->id => User::where('email', 'manager@pos.test')->first(),
        ];
        $manager = User::where('email', 'manager@pos.test')->first();

        $variants = ProductVariant::with(['product.taxRate', 'product.unit'])->where('is_active', true)->get()
            ->filter(fn ($v) => $v->product && $v->product->is_active)->values();
        $customers = Contact::whereIn('type', [Contact::CUSTOMER, Contact::BOTH])->where('is_active', true)->with('group')->get();
        $walkIn = $customers->firstWhere('is_walk_in', true);
        $named = $customers->where('is_walk_in', false)->whereNull('gstin')->values();
        $b2b = $customers->first(fn ($c) => $c->gstin);
        $suppliers = Contact::whereIn('type', [Contact::SUPPLIER])->whereNotNull('gstin')->get();
        $recent = [];   // sales that may come back as returns

        for ($day = $start->copy(); $day->lte($realNow); $day->addDay()) {
            $isToday = $day->isSameDay($realNow);
            if ($isToday && $realNow->hour < 11) {
                break;
            }
            $diwali = $day->between(Carbon::create(2026, 10, 30, 0, 0, 0, $tz), Carbon::create(2026, 11, 8, 23, 59, 59, $tz));
            $weekend = $day->isWeekend();

            foreach ($branches as $bi => $branch) {
                $register = Register::where('branch_id', $branch->id)->orderBy('id')->first();
                $cashier = $cashiers[$branch->id] ?? $manager;
                Carbon::setTestNow($day->copy()->setTime(10, 0));
                $this->loadStock($branch);

                // Fortnightly restock from a supplier (morning, before opening)
                if ((int) round($start->diffInDays($day)) % 14 === 0) {
                    $this->restock($purchases, $branch, $manager, $suppliers->random(), $variants, $day);
                }

                $session = $registers->open($register, $cashier, 2000);

                $bills = ($bi === 0 ? mt_rand(6, 11) : mt_rand(3, 6)) + ($weekend ? 4 : 0);
                $bills = (int) round($bills * ($diwali ? 2.2 : 1));
                $closeAt = $isToday ? $realNow->copy()->subMinutes(5) : $day->copy()->setTime(21, 30);
                $times = collect(range(1, $bills))->map(fn () => $day->copy()->setTime(10, 30)->addMinutes(mt_rand(0, 630)))
                    ->filter(fn ($t) => $t->lt($closeAt))->sort()->values();

                foreach ($times as $t) {
                    Carbon::setTestNow($t);
                    $sale = $this->bill($checkout, $session, $cashier, $branch, $variants, mt_rand(1, 100) <= 4 && $b2b ? $b2b : (mt_rand(1, 100) <= 35 ? $named->random() : $walkIn));
                    if ($sale && mt_rand(1, 100) <= 5) {
                        $recent[] = $sale->id;
                    }
                }

                // A customer occasionally brings something back (size didn't fit / defect)
                if ($recent && mt_rand(1, 100) <= 45) {
                    Carbon::setTestNow($closeAt->copy()->subMinutes(30));
                    $sale = Sale::with('items')->find(array_shift($recent));
                    if ($sale && $sale->branch_id === $branch->id && $sale->items->isNotEmpty()) {
                        $item = $sale->items->first();
                        $damaged = mt_rand(1, 100) <= 20;
                        rescue(fn () => $returns->create($sale, [['sale_item_id' => $item->id, 'quantity' => 1, 'restock' => ! $damaged]],
                            'cash', $damaged ? 'Stitching defect' : 'Size did not fit', $cashier, $session, $branch), report: false);
                    }
                }

                // Customer settles some credit on Saturdays
                if ($bi === 0 && $day->isSaturday()) {
                    Carbon::setTestNow($closeAt->copy()->subMinutes(20));
                    foreach ($customers->where('is_walk_in', false) as $c) {
                        $balance = (float) $c->balance();
                        if ($balance > 500) {
                            rescue(fn () => $payments->record($c, ContactPayment::IN, ['amount' => round($balance * 0.6), 'method' => $c->gstin ? 'bank' : 'upi',
                                'reference' => 'UTR'.mt_rand(100000, 999999), 'date' => $day->toDateString()], $manager, $branch), report: false);
                        }
                    }
                }

                if (! $isToday) {
                    Carbon::setTestNow($closeAt);
                    $expected = (float) $registers->summary($session)['expected_cash'];
                    $diff = mt_rand(1, 100) <= 12 ? [-100, -50, -20, 10][mt_rand(0, 3)] : 0;
                    $registers->close($session, $expected + $diff, $diff ? 'Counted twice' : null, $cashier);
                }
            }
        }
        Carbon::setTestNow();
        $this->command?->info(Sale::count().' demo bills created'.($this->failed ? ", {$this->failed} skipped" : ''));
    }

    private function loadStock(Branch $branch): void
    {
        $this->stock = DB::table('stock_levels')->where('branch_id', $branch->id)->pluck('quantity', 'product_variant_id')
            ->mapWithKeys(fn ($q, $v) => ["{$branch->id}:{$v}" => (float) $q])->all();
    }

    private function bill(CheckoutService $checkout, $session, User $cashier, Branch $branch, $variants, Contact $customer): ?Sale
    {
        $wholesale = $customer->group?->price_type === 'wholesale';
        $count = $wholesale ? mt_rand(3, 6) : [1, 1, 1, 2, 2, 3][mt_rand(0, 5)];
        $items = [];
        $calc = [];
        foreach ($variants->shuffle() as $v) {
            if (count($items) >= $count) {
                break;
            }
            $p = $v->product;
            $qty = $p->unit->allow_decimal ? [1.5, 2, 2.5, 3][mt_rand(0, 3)] : ($wholesale ? mt_rand(2, 4) : (mt_rand(1, 100) <= 10 ? 2 : 1));
            $key = "{$branch->id}:{$v->id}";
            if ($p->track_stock && ($this->stock[$key] ?? 0) < $qty) {
                continue;
            }
            if (! $p->track_stock && mt_rand(1, 100) > 15) {
                continue;   // alterations only now and then
            }
            $price = $wholesale && $v->wholesale_price !== null ? $v->wholesale_price : $v->selling_price;
            $items[] = ['variant_id' => $v->id, 'quantity' => $qty];
            $calc[] = ['key' => count($calc), 'unit_price' => (string) $price, 'quantity' => (string) $qty, 'price_includes_tax' => $p->price_includes_tax,
                'rate' => (string) $p->taxRate->rate, 'cess_rate' => (string) $p->taxRate->cess_rate, 'threshold' => $p->taxRate->threshold_amount,
                'rate_above' => $p->taxRate->rate_above, 'discount_type' => null, 'discount_value' => 0];
            if ($p->track_stock) {
                $this->stock[$key] -= $qty;
            }
        }
        if (! $items) {
            return null;
        }

        $groupDiscount = (float) ($customer->group?->discount_percent ?? 0);
        $discount = $groupDiscount > 0 ? ['type' => 'percent', 'value' => $groupDiscount]
            : (mt_rand(1, 100) <= 12 ? ['type' => 'percent', 'value' => [5, 10][mt_rand(0, 1)]] : null);
        $interState = ! $customer->is_walk_in && $customer->state_code && $customer->state_code !== $branch->state_code;
        $total = (float) GstCalculator::calculate($calc, $discount, $interState, (bool) settings('tax.round_off'))['totals']['grand_total'];

        $roll = mt_rand(1, 100);
        $payments = match (true) {
            $wholesale && (float) $customer->balance() + $total / 2 <= (float) $customer->credit_limit => [['method' => 'upi', 'amount' => round($total * 0.5, 2), 'reference' => 'UPI'.mt_rand(10000, 99999)], ['method' => 'account', 'amount' => round($total - round($total * 0.5, 2), 2)]],
            $wholesale => [['method' => 'upi', 'amount' => $total, 'reference' => 'UPI'.mt_rand(10000, 99999)]],
            $roll <= 45 => [['method' => 'cash', 'amount' => mt_rand(0, 1) ? $total : ceil($total / 100) * 100]],
            $roll <= 85 => [['method' => 'upi', 'amount' => $total, 'reference' => 'UPI'.mt_rand(10000, 99999)]],
            default => [['method' => 'card', 'amount' => $total, 'reference' => 'XX'.mt_rand(1000, 9999)]],
        };

        try {
            return $checkout->checkout($session, $cashier, [
                'customer_id' => $customer->id, 'items' => $items,
                'bill_discount_type' => $discount['type'] ?? null, 'bill_discount_value' => $discount['value'] ?? 0,
                'payments' => $payments, 'expected_total' => $total,
            ]);
        } catch (\Throwable $e) {
            $this->failed++;
            $this->command?->warn('Demo bill skipped: '.$e->getMessage());

            return null;
        }
    }

    private function restock(PurchaseService $purchases, Branch $branch, User $user, Contact $supplier, $variants, Carbon $day): void
    {
        $items = [];
        foreach ($variants as $v) {
            if (! $v->product->track_stock) {
                continue;
            }
            $have = $this->stock["{$branch->id}:{$v->id}"] ?? 0;
            $target = $v->product->unit->allow_decimal ? 60 : ($branch->code === 'BR1' ? 10 : 5);
            if ($have < $target / 2) {
                $items[] = ['variant_id' => $v->id, 'quantity' => $target - floor($have), 'unit_cost' => (float) $v->purchase_price ?: round($v->selling_price * 0.55, 2),
                    'discount_type' => 'percent', 'discount_value' => 0];
            }
        }
        if (! $items) {
            return;
        }
        $purchase = rescue(fn () => $purchases->create([
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => strtoupper(substr($supplier->company_name ?? $supplier->name, 0, 3)).'/'.mt_rand(1000, 9999),
            'supplier_invoice_date' => $day->copy()->subDays(2)->toDateString(), 'received_date' => $day->toDateString(),
            'items' => $items, 'other_charges' => 350, 'update_cost_price' => false,
        ], $branch, $user), report: false);
        if ($purchase) {
            // pay about 60% now; the rest stays on the supplier's account
            app(PaymentService::class)->record($supplier, ContactPayment::OUT, ['amount' => round((float) $purchase->grand_total * 0.6),
                'method' => 'bank', 'reference' => 'NEFT'.mt_rand(100000, 999999), 'date' => $day->toDateString()], $user, $branch, null, $purchase);
            $this->loadStock($branch);
        }
    }
}
