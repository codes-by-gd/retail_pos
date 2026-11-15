<?php

namespace App\Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactPayment;
use App\Modules\Contacts\Services\PaymentService;
use App\Modules\Pos\Models\RegisterSession;
use App\Support\AmountInWords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class ContactPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'method' => ['nullable', Rule::in(array_keys(ContactPayment::METHODS))],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:50'],
        ]);

        $query = ContactPayment::query()->where('branch_id', current_branch()->id())
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('date', '<=', $d))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference_no', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%"))));

        $totals = (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as received, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as paid")->first();

        return Inertia::render('Payments/Index', [
            'payments' => $query->with(['contact:id,name,company_name,type', 'user:id,name'])->withCount('allocations')->latest('date')->latest('id')
                ->paginate(25)->withQueryString()
                ->through(fn (ContactPayment $p) => [
                    'id' => $p->id, 'reference_no' => $p->reference_no, 'date' => $p->date->toDateString(), 'direction' => $p->direction,
                    'method' => ContactPayment::METHODS[$p->method], 'reference' => $p->reference, 'amount' => (float) $p->amount,
                    'unallocated' => (float) $p->unallocated, 'contact' => $p->contact->company_name ?: $p->contact->name,
                    'contact_id' => $p->contact_id, 'contact_kind' => $p->direction === 'in' ? 'customers' : 'suppliers',
                    'user' => $p->user->name, 'note' => $p->note,
                ]),
            'filters' => $filters,
            'totals' => ['received' => (float) $totals->received, 'paid' => (float) $totals->paid],
            'methods' => collect(ContactPayment::METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function store(Request $request, Contact $contact, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'method' => ['required', Rule::in(array_keys(ContactPayment::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
            'register_session_id' => ['nullable', 'integer'],
        ]);

        $session = ! empty($data['register_session_id'])
            ? RegisterSession::whereKey($data['register_session_id'])->where('branch_id', current_branch()->id())->first()
            : null;

        $payment = $payments->record($contact, $data['direction'], $data, $request->user(), current_branch()->get(), $session);

        return back()->with('success', ($data['direction'] === 'in' ? 'Payment received' : 'Payment recorded')." · {$payment->reference_no}");
    }

    public function print(ContactPayment $contactPayment): View
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $contactPayment->branch_id), 403);
        $contactPayment->load(['contact', 'branch', 'user:id,name', 'allocations.allocatable']);

        return view('print.payment', [
            'payment' => $contactPayment,
            'business' => settings()->group('business'),
            'gstin' => $contactPayment->branch->gstin ?: settings('business.gstin'),
            'words' => AmountInWords::rupees($contactPayment->amount),
            'method' => ContactPayment::METHODS[$contactPayment->method],
        ]);
    }

    /** Open register shifts of the current branch (for "cash taken into drawer"). */
    public static function openSessions(): array
    {
        return RegisterSession::where('branch_id', current_branch()->id())->where('status', 'open')->with('register:id,name')->get()
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->register->name} (open since {$s->opened_at->format('d M, h:i A')})"])->all();
    }
}
