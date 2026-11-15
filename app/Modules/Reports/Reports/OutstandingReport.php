<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receivables (customers who owe us) or payables (suppliers we owe), as of today.
 * Balance comes from the ledger (the truth); unpaid bills are aged by bill date. Whatever the bills
 * don't explain (opening balances, unallocated advances) shows as "Opening / on account".
 */
class OutstandingReport extends Report
{
    public function __construct(private readonly bool $payables = false) {}

    public function key(): string
    {
        return $this->payables ? 'payables' : 'receivables';
    }

    public function title(): string
    {
        return $this->payables ? 'Supplier payables' : 'Customer receivables';
    }

    public function description(): string
    {
        return $this->payables
            ? 'What we owe each supplier, unpaid bills by age and overdue amounts (payment terms).'
            : 'Who owes us money (udhaar), with unpaid bills by age.';
    }

    public function group(): string
    {
        return self::FINANCIAL;
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function scope(): ?string
    {
        return null;   // the contact ledger is business-wide
    }

    public function build(ReportFilters $f): array
    {
        $types = $this->payables ? [Contact::SUPPLIER, Contact::BOTH] : [Contact::CUSTOMER, Contact::BOTH];
        $contacts = Contact::query()->whereIn('type', $types)->select('contacts.id', 'contacts.code', 'contacts.name', 'contacts.company_name', 'contacts.phone', 'contacts.payment_terms_days')
            ->withBalance()->get()
            ->filter(fn ($c) => $this->payables ? (float) $c->balance < -0.004 : (float) $c->balance > 0.004)
            ->keyBy('id');

        $bills = $this->payables
            ? DB::table('purchases')->where('due_amount', '>', 0)->whereIn('supplier_id', $contacts->keys())->get(['supplier_id as cid', 'received_date as d', 'due_amount'])
            : DB::table('sales')->where('status', Sale::COMPLETED)->where('due_amount', '>', 0)->whereIn('customer_id', $contacts->keys())->get(['customer_id as cid', 'sold_at as d', 'due_amount']);
        $lastPaid = DB::table('contact_payments')->whereIn('contact_id', $contacts->keys())
            ->where('direction', $this->payables ? ContactPayment::OUT : ContactPayment::IN)
            ->groupBy('contact_id')->selectRaw('contact_id, MAX(date) as d')->pluck('d', 'contact_id');

        $today = today();
        $rows = $contacts->map(function ($c) use ($bills, $lastPaid, $today) {
            $balance = abs(round((float) $c->balance, 2));
            $row = ['code' => $c->code, 'name' => $c->company_name ?: $c->name, 'phone' => $c->phone, 'balance' => $balance,
                'bills' => 0, 'a0' => 0.0, 'a30' => 0.0, 'a60' => 0.0, 'a90' => 0.0, 'overdue' => 0.0, 'last_paid' => $lastPaid[$c->id] ?? null];
            foreach ($bills->where('cid', $c->id) as $b) {
                $age = Carbon::parse($b->d)->startOfDay()->diffInDays($today);
                $due = (float) $b->due_amount;
                $row['bills']++;
                $row[$age <= 30 ? 'a0' : ($age <= 60 ? 'a30' : ($age <= 90 ? 'a60' : 'a90'))] += $due;
                if ($this->payables && $c->payment_terms_days !== null && $age > $c->payment_terms_days) {
                    $row['overdue'] += $due;
                }
            }
            $billed = $row['a0'] + $row['a30'] + $row['a60'] + $row['a90'];
            $row['other'] = round($balance - $billed, 2);
            foreach (['a0', 'a30', 'a60', 'a90', 'overdue'] as $k) {
                $row[$k] = round($row[$k], 2);
            }

            return $row;
        })->sortByDesc('balance')->values();

        $c = fn (...$a) => Section::col(...$a);
        $columns = [
            $c('code', 'Code'), $c('name', $this->payables ? 'Supplier' : 'Customer'), $c('phone', 'Phone'),
            $c('balance', $this->payables ? 'We owe' : 'Owes us', 'money', true),
            $c('bills', 'Unpaid bills', 'int', true),
            $c('a0', '0–30 days', 'money', true), $c('a30', '31–60', 'money', true), $c('a60', '61–90', 'money', true), $c('a90', '90+ days', 'money', true),
            $c('other', 'Opening / on account', 'money', true),
        ];
        if ($this->payables) {
            $columns[] = $c('overdue', 'Overdue (terms)', 'money', true);
        }
        $columns[] = $c('last_paid', $this->payables ? 'Last paid' : 'Last payment', 'date');

        return [Section::make($this->payables ? 'Payables' : 'Receivables', $columns, $rows)
            ->note('Balance from the account ledger, as of today. Ages count from the bill date. "Opening / on account" is the part not tied to a bill (opening balance, or an advance not yet used — negative).')];
    }
}
