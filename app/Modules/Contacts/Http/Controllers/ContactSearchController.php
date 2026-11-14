<?php

namespace App\Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\ContactService;
use App\Rules\Gstin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** JSON endpoints used by the POS and purchase screens. */
class ContactSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in([Contact::CUSTOMER, Contact::SUPPLIER])],
        ]);
        $kind = $data['kind'] ?? Contact::CUSTOMER;
        abort_unless($request->user()->canAny($kind === Contact::SUPPLIER ? ['suppliers.view', 'purchases.manage'] : ['customers.view', 'pos.access']), 403);

        $q = trim($data['q'] ?? '');
        if ($q === '') {
            return response()->json(['data' => []]);
        }
        $digits = preg_replace('/\D+/', '', $q);

        $contacts = Contact::query()->ofType($kind)->active()->select('contacts.*')->withBalance()->with('group')
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('company_name', 'like', "%{$q}%")
                ->orWhere('code', $q)
                ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('phone', 'like', "{$digits}%")->orWhere('phone', 'like', "%{$digits}")))
            // exact phone match first
            ->orderByRaw('CASE WHEN phone = ? THEN 0 ELSE 1 END', [Contact::normalizePhone($q) ?? ''])
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json(['data' => $contacts->map(fn (Contact $c) => $this->present($c))]);
    }

    /** Quick add from the POS: name + phone (+ optional GSTIN). */
    public function quickStore(Request $request, ContactService $service): JsonResponse
    {
        abort_unless($request->user()->can('customers.manage'), 403);

        $request->merge([
            'phone' => Contact::normalizePhone($request->input('phone')),
            'gstin' => $request->filled('gstin') ? strtoupper(trim($request->input('gstin'))) : null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'regex:/^\d{6,15}$/', Rule::unique('contacts', 'phone')],
            'gstin' => ['nullable', new Gstin],
        ], ['phone.unique' => 'A customer with this phone number already exists.', 'phone.regex' => 'Enter a valid phone number.']);

        $contact = $service->create([
            ...$data,
            'type' => Contact::CUSTOMER,
            'state_code' => $data['gstin'] ? substr($data['gstin'], 0, 2) : null,
            'is_active' => true,
        ]);
        $contact->setAttribute('balance', 0)->load('group');

        return response()->json(['data' => $this->present($contact)], 201);
    }

    private function present(Contact $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'company_name' => $c->company_name,
            'phone' => $c->phone,
            'gstin' => $c->gstin,
            'state_code' => $c->state_code,
            'balance' => (float) ($c->balance ?? 0),
            'credit_limit' => (float) $c->credit_limit,
            'price_type' => $c->group?->price_type ?? 'retail',
            'discount_percent' => (float) ($c->group?->discount_percent ?? 0),
            'is_walk_in' => $c->is_walk_in,
        ];
    }
}
