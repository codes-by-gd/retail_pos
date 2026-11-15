<?php

namespace App\Modules\Loyalty\Services;

use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Loyalty\Models\LoyaltyTransaction as T;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty points for named customers (never the walk-in customer).
 * Earn: 1 point per `loyalty.earn_per` rupees of the bill paid other than by points.
 * Redeem: points are a payment method worth `loyalty.point_value` each, min `loyalty.min_redeem`
 * points, at most `loyalty.max_redeem_percent` % of the bill. Oldest points are used first.
 * Returns take back earned points in proportion; cancellations undo everything.
 */
class LoyaltyService
{
    public static function enabled(): bool
    {
        return (bool) settings('loyalty.enabled');
    }

    public static function rules(): array
    {
        return [
            'enabled' => self::enabled(),
            'earn_per' => (float) settings('loyalty.earn_per', 100),
            'point_value' => (float) settings('loyalty.point_value', 1),
            'min_redeem' => (int) settings('loyalty.min_redeem', 100),
            'max_redeem_percent' => (float) settings('loyalty.max_redeem_percent', 50),
            'expiry_months' => (int) settings('loyalty.expiry_months', 12),
        ];
    }

    public function balance(Contact|int $contact): int
    {
        return (int) T::where('contact_id', is_int($contact) ? $contact : $contact->id)->sum('points');
    }

    /** Rupee value of a number of points. */
    public static function value(int $points): string
    {
        return (string) BigDecimal::of((string) settings('loyalty.point_value', 1))->multipliedBy($points)->toScale(2, RoundingMode::HALF_UP);
    }

    /** Most points that may be used on a bill of $grand for this customer (0 if not allowed). */
    public function maxRedeemable(Contact $customer, string|float $grand): int
    {
        if (! self::enabled() || $customer->is_walk_in) {
            return 0;
        }
        $r = self::rules();
        $byBill = $r['point_value'] > 0 ? (int) floor((float) $grand * $r['max_redeem_percent'] / 100 / $r['point_value'] + 1e-9) : 0;
        $max = min($this->balance($customer), $byBill);

        return $max >= $r['min_redeem'] ? $max : 0;
    }

    /** Validate a points payment at checkout; returns the number of points. @throws \DomainException */
    public function checkRedemption(Contact $customer, string $amount, string|float $grand): int
    {
        if (! self::enabled()) {
            throw new \DomainException('Loyalty points are switched off.');
        }
        if ($customer->is_walk_in) {
            throw new \DomainException('Choose the customer to use their points.');
        }
        $value = BigDecimal::of((string) settings('loyalty.point_value', 1));
        $points = BigDecimal::of($amount)->dividedBy($value, 4, RoundingMode::HALF_UP);
        if (! $points->isEqualTo($points->toScale(0, RoundingMode::DOWN)) || ! $points->isPositive()) {
            throw new \DomainException('Points must be used in whole points.');
        }
        $points = $points->toInt();
        $r = self::rules();
        if ($points < $r['min_redeem']) {
            throw new \DomainException("At least {$r['min_redeem']} points must be used at a time.");
        }
        $balance = $this->balance($customer);
        if ($points > $balance) {
            throw new \DomainException("{$customer->name} has only {$balance} points.");
        }
        if ($points > $this->maxRedeemable($customer, $grand)) {
            throw new \DomainException("Points can pay at most {$r['max_redeem_percent']}% of a bill.");
        }

        return $points;
    }

    /** After checkout: record points used and points earned. */
    public function afterSale(Sale $sale, Contact $customer, int $redeemed, User $user): void
    {
        if ($customer->is_walk_in || (! self::enabled() && ! $redeemed)) {
            return;
        }
        if ($redeemed > 0) {
            $this->consume($customer, $redeemed, T::REDEEM, $sale, null, "Used on {$sale->invoice_no}", $user);
        }
        $earned = 0;
        if (self::enabled()) {
            $base = (float) $sale->grand_total - (float) self::value($redeemed);
            $earned = (int) floor(max(0, $base) / max(0.01, (float) settings('loyalty.earn_per', 100)) + 1e-9);
            if ($earned > 0) {
                $this->credit($customer, $earned, T::EARN, $sale, "Bill {$sale->invoice_no}", $user, $sale->sold_at);
            }
        }
        $sale->update(['points_earned' => $earned, 'points_redeemed' => $redeemed]);
    }

    /** Take back the points earned on the returned part of a bill (cumulative, never more than earned). */
    public function afterReturn(SaleReturn $return, User $user): void
    {
        $sale = Sale::find($return->sale_id);
        if (! $sale || $sale->points_earned <= 0) {
            return;
        }
        $returnedTotal = (float) SaleReturn::where('sale_id', $sale->id)->sum('total');
        $shouldReverse = min($sale->points_earned, (int) floor($sale->points_earned * $returnedTotal / max(0.01, (float) $sale->grand_total) + 1e-9));
        $already = -(int) T::where('sale_id', $sale->id)->where('type', T::REVERSE)->sum('points');
        $take = $shouldReverse - $already;
        if ($take > 0) {
            $this->takeBack($sale, $take, $return, "Return {$return->credit_note_no}", $user);
        }
    }

    /** Cancelled bill: earned points taken back, redeemed points given back. */
    public function afterCancel(Sale $sale, User $user): void
    {
        $already = -(int) T::where('sale_id', $sale->id)->where('type', T::REVERSE)->sum('points');
        if ($sale->points_earned - $already > 0) {
            $this->takeBack($sale, $sale->points_earned - $already, null, "Cancelled {$sale->invoice_no}", $user);
        }
        if ($sale->points_redeemed > 0) {
            $this->credit($sale->customer()->first(), $sale->points_redeemed, T::RESTORE, $sale, "Cancelled {$sale->invoice_no}", $user);
        }
    }

    /** Manual adjustment (+ or −) by staff. */
    public function adjust(Contact $customer, int $points, string $note, User $user): void
    {
        DB::transaction(function () use ($customer, $points, $note, $user) {
            if ($points > 0) {
                $this->credit($customer, $points, T::ADJUST, null, $note, $user);
            } elseif ($points < 0) {
                $balance = $this->balance($customer);
                if (-$points > $balance) {
                    throw new \DomainException("{$customer->name} has only {$balance} points.");
                }
                $this->consume($customer, -$points, T::ADJUST, null, null, $note, $user);
            }
        });
    }

    /** Expire what is left of batches past their date. @return int points expired */
    public function expire(?Carbon $today = null): int
    {
        $today ??= today();
        $total = 0;
        T::where('remaining', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<', $today->toDateString())
            ->orderBy('id')->chunkById(200, function ($batches) use (&$total) {
                foreach ($batches as $b) {
                    DB::transaction(function () use ($b, &$total) {
                        $left = $b->remaining;
                        $b->update(['remaining' => 0]);
                        T::create(['contact_id' => $b->contact_id, 'type' => T::EXPIRE, 'points' => -$left,
                            'note' => 'Expired (earned '.$b->created_at->format('d M Y').')']);
                        $total += $left;
                    });
                }
            });

        return $total;
    }

    // ---------------------------------------------------------------------

    private function credit(Contact $customer, int $points, string $type, ?Sale $sale, string $note, ?User $user, $from = null): void
    {
        $months = (int) settings('loyalty.expiry_months', 12);
        T::create([
            'contact_id' => $customer->id, 'branch_id' => $sale?->branch_id, 'type' => $type, 'points' => $points, 'remaining' => $points,
            'expires_at' => $months > 0 ? Carbon::parse($from ?? now())->addMonthsNoOverflow($months)->toDateString() : null,
            'sale_id' => $sale?->id, 'note' => $note, 'user_id' => $user?->id,
        ]);
    }

    /** Spend points oldest-first from the + batches; records one − row. */
    private function consume(Contact $customer, int $points, string $type, ?Sale $sale, ?SaleReturn $return, string $note, ?User $user): void
    {
        $left = $points;
        $batches = T::where('contact_id', $customer->id)->where('remaining', '>', 0)
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();
        foreach ($batches as $b) {
            if ($left <= 0) {
                break;
            }
            $use = min($left, $b->remaining);
            $b->update(['remaining' => $b->remaining - $use]);
            $left -= $use;
        }
        T::create(['contact_id' => $customer->id, 'branch_id' => $sale?->branch_id, 'type' => $type, 'points' => -$points,
            'sale_id' => $sale?->id, 'sale_return_id' => $return?->id, 'note' => $note, 'user_id' => $user?->id]);
    }

    /** Reverse earned points: from that bill's batch first; never below a zero balance. */
    private function takeBack(Sale $sale, int $points, ?SaleReturn $return, string $note, User $user): void
    {
        $customer = Contact::find($sale->customer_id);
        $points = min($points, max(0, $this->balance($customer)));
        if ($points <= 0) {
            return;
        }
        $own = T::where('sale_id', $sale->id)->where('type', T::EARN)->lockForUpdate()->first();
        $fromOwn = $own ? min($points, $own->remaining) : 0;
        if ($fromOwn > 0) {
            $own->update(['remaining' => $own->remaining - $fromOwn]);
        }
        // anything already spent from that batch comes out of other batches, oldest first
        $rest = $points - $fromOwn;
        if ($rest > 0) {
            $batches = T::where('contact_id', $customer->id)->where('remaining', '>', 0)->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($batches as $b) {
                if ($rest <= 0) {
                    break;
                }
                $use = min($rest, $b->remaining);
                $b->update(['remaining' => $b->remaining - $use]);
                $rest -= $use;
            }
        }
        T::create(['contact_id' => $customer->id, 'branch_id' => $sale->branch_id, 'type' => T::REVERSE, 'points' => -$points,
            'sale_id' => $sale->id, 'sale_return_id' => $return?->id, 'note' => $note, 'user_id' => $user->id]);
    }
}
