<?php

namespace App\Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Http\Requests\ContactRequest;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Contacts\Models\CustomerGroup;
use App\Modules\Contacts\Services\ContactLedgerService;
use App\Modules\Contacts\Services\ContactService;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Support\Gst\IndianStates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers and suppliers share this controller; the route supplies `kind` (customer|supplier).
 */
class ContactController extends Controller
{
    private const BALANCE_SQL = '(SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM contact_ledger_entries WHERE contact_ledger_entries.contact_id = contacts.id)';

    public function __construct(private ContactService $contacts, private ContactLedgerService $ledger) {}

    public function index(Request $request): Response
    {
        $kind = $this->kind($request);
        $filters = $request->only(['search', 'group_id', 'status', 'balance', 'occasion']);

        $contacts = Contact::query()
            ->ofType($kind)
            ->select('contacts.*')
            ->withBalance()
            ->with('group:id,name')
            ->when($filters['search'] ?? null, function (Builder $q, $s) {
                $digits = preg_replace('/\D+/', '', $s);
                $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('gstin', 'like', '%'.strtoupper($s).'%')
                    ->when(strlen($digits) >= 3, fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%")));
            })
            ->when($filters['group_id'] ?? null, fn ($q, $id) => $q->where('customer_group_id', $id))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(($filters['balance'] ?? null) === 'receivable', fn ($q) => $q->whereRaw(self::BALANCE_SQL.' > 0'))
            ->when(($filters['balance'] ?? null) === 'payable', fn ($q) => $q->whereRaw(self::BALANCE_SQL.' < 0'))
            ->when($filters['occasion'] ?? null, fn ($q, $o) => $this->celebratingSoon($q, $o === 'anniversary' ? 'anniversary' : 'date_of_birth'))
            ->orderByDesc('is_walk_in')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Contact $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'type' => $c->type,
                'name' => $c->name,
                'company_name' => $c->company_name,
                'phone' => $c->phone,
                'city' => $c->city,
                'gstin' => $c->gstin,
                'group' => $c->group?->name,
                'balance' => (float) $c->balance,
                'is_active' => $c->is_active,
                'is_walk_in' => $c->is_walk_in,
                'date_of_birth' => $c->date_of_birth?->toDateString(),
                'anniversary' => $c->anniversary?->toDateString(),
            ]);

        return Inertia::render('Contacts/Index', [
            'kind' => $kind,
            'contacts' => $contacts,
            'filters' => $filters,
            'groups' => $kind === Contact::CUSTOMER ? CustomerGroup::orderBy('name')->get(['id as value', 'name as label']) : [],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Contacts/Form', $this->formOptions($this->kind($request)));
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $contact = $this->contacts->create($request->validated());

        return to_route($this->kind($request).'s.show', $contact)->with('success', "{$contact->name} ({$contact->code}) created.");
    }

    public function show(Request $request, Contact $contact): Response
    {
        $kind = $this->kind($request);
        abort_unless($kind === Contact::CUSTOMER ? $contact->isCustomer() : $contact->isSupplier(), 404);

        $period = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $contact->load('group');

        return Inertia::render('Contacts/Show', [
            'kind' => $kind,
            'contact' => [
                ...$contact->only(['id', 'code', 'type', 'name', 'company_name', 'phone', 'alt_phone', 'email', 'gstin', 'state_code',
                    'address', 'city', 'pincode', 'credit_limit', 'payment_terms_days', 'notes', 'is_active', 'is_walk_in']),
                'state_name' => $contact->stateName(),
                'group' => $contact->group?->only(['name', 'price_type', 'discount_percent']),
                'date_of_birth' => $contact->date_of_birth?->toDateString(),
                'anniversary' => $contact->anniversary?->toDateString(),
                'created_at' => $contact->created_at->toIso8601String(),
                'balance' => (float) $contact->balance(),
            ],
            'statement' => $this->ledger->statement($contact, $period['from'] ?? null, $period['to'] ?? null),
            'period' => ['from' => $period['from'] ?? null, 'to' => $period['to'] ?? null],
            'unpaidBills' => $this->unpaidBills($contact, $kind),
            'registers' => ContactPaymentController::openSessions(),
            'paymentMethods' => collect(ContactPayment::METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function edit(Request $request, Contact $contact): Response
    {
        $kind = $this->kind($request);

        return Inertia::render('Contacts/Form', [
            ...$this->formOptions($kind),
            'contact' => [
                ...$contact->only(['id', 'code', 'type', 'name', 'company_name', 'phone', 'alt_phone', 'email', 'gstin', 'state_code',
                    'address', 'city', 'pincode', 'customer_group_id', 'credit_limit', 'payment_terms_days', 'notes', 'is_active', 'is_walk_in']),
                'date_of_birth' => $contact->date_of_birth?->toDateString(),
                'anniversary' => $contact->anniversary?->toDateString(),
                'opening_balance' => (float) $this->ledger->openingBalance($contact),
            ],
        ]);
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $this->contacts->update($contact, $request->validated());

        return to_route($this->kind($request).'s.show', $contact)->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($request->user()->can($contact->isSupplier() ? 'suppliers.manage' : 'customers.manage'), 403);
        $this->contacts->delete($contact);

        return to_route($this->kind($request).'s.index')->with('success', "{$contact->name} deleted.");
    }

    /** Bills with an amount still due: sales for customers, purchases for suppliers. */
    private function unpaidBills(Contact $contact, string $kind): array
    {
        if ($contact->is_walk_in) {
            return [];
        }

        return $kind === Contact::CUSTOMER
            ? Sale::where('customer_id', $contact->id)->where('status', 'completed')->where('due_amount', '>', 0)->orderBy('sold_at')
                ->get(['id', 'invoice_no', 'sold_at', 'grand_total', 'due_amount'])
                ->map(fn ($s) => ['id' => $s->id, 'number' => $s->invoice_no, 'date' => $s->sold_at->toDateString(), 'total' => (float) $s->grand_total,
                    'due' => (float) $s->due_amount, 'url' => route('sales.show', $s)])->all()
            : Purchase::where('supplier_id', $contact->id)->where('due_amount', '>', 0)->orderBy('received_date')
                ->get(['id', 'reference_no', 'supplier_invoice_no', 'received_date', 'grand_total', 'due_amount'])
                ->map(fn ($p) => ['id' => $p->id, 'number' => $p->supplier_invoice_no ?: $p->reference_no, 'date' => $p->received_date->toDateString(),
                    'total' => (float) $p->grand_total, 'due' => (float) $p->due_amount, 'url' => route('purchases.show', $p),
                    'overdue' => $contact->payment_terms_days !== null && $p->received_date->copy()->addDays($contact->payment_terms_days)->isPast()])->all();
    }

    private function kind(Request $request): string
    {
        return $request->route('kind') === Contact::SUPPLIER ? Contact::SUPPLIER : Contact::CUSTOMER;
    }

    private function formOptions(string $kind): array
    {
        return [
            'kind' => $kind,
            'states' => IndianStates::options(),
            'groups' => CustomerGroup::orderBy('name')->get()->map(fn ($g) => [
                'value' => $g->id,
                'label' => $g->name.' ('.($g->price_type === 'wholesale' ? 'wholesale price' : 'retail price').((float) $g->discount_percent ? ", {$g->discount_percent}% off" : '').')',
            ]),
            'defaultStateCode' => current_branch()->get()?->state_code ?? settings('business.state_code'),
            'canBoth' => auth()->user()->can('customers.manage') && auth()->user()->can('suppliers.manage'),
        ];
    }

    /** Birthdays / anniversaries (month-day) within the next 30 days, year-agnostic. */
    private function celebratingSoon(Builder $q, string $column): Builder
    {
        $days = collect(range(0, 30))->map(fn ($i) => now()->addDays($i)->format('m-d'))->all();
        $expr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%m-%d', {$column})"
            : "DATE_FORMAT({$column}, '%m-%d')";

        return $q->whereNotNull($column)->whereIn(DB::raw($expr), $days);
    }
}
