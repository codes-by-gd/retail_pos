<?php

namespace App\Modules\Contacts\Http\Requests;

use App\Modules\Contacts\Models\Contact;
use App\Rules\Gstin;
use App\Support\Gst\IndianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->input('type', $this->route('kind'));
        $user = $this->user();

        // "both" needs both permissions; changing an existing contact also needs rights for what it already is.
        $needs = match ($type) {
            Contact::SUPPLIER => ['suppliers.manage'],
            Contact::BOTH => ['customers.manage', 'suppliers.manage'],
            default => ['customers.manage'],
        };
        if ($contact = $this->route('contact')) {
            $needs[] = $contact->isSupplier() ? 'suppliers.manage' : 'customers.manage';
        }

        return collect($needs)->every(fn ($p) => $user->can($p));
    }

    protected function prepareForValidation(): void
    {
        $gstin = $this->filled('gstin') ? strtoupper(trim($this->input('gstin'))) : null;

        $this->merge([
            'phone' => Contact::normalizePhone($this->input('phone')),
            'alt_phone' => Contact::normalizePhone($this->input('alt_phone')),
            'gstin' => $gstin,
            // A GSTIN decides the state; fill it in when not chosen.
            'state_code' => $this->input('state_code') ?: ($gstin ? substr($gstin, 0, 2) : null),
        ]);
    }

    public function rules(): array
    {
        $contact = $this->route('contact');

        return [
            'type' => ['required', Rule::in([Contact::CUSTOMER, Contact::SUPPLIER, Contact::BOTH])],
            'name' => ['required', 'string', 'max:150'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'regex:/^\d{6,15}$/', Rule::unique('contacts', 'phone')->ignore($contact)],
            'alt_phone' => ['nullable', 'regex:/^\d{6,15}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'gstin' => ['nullable', 'string', new Gstin],
            'state_code' => ['nullable', Rule::in(IndianStates::codes())],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'customer_group_id' => ['nullable', Rule::exists('customer_groups', 'id')],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'anniversary' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'opening_balance' => ['nullable', 'numeric', 'min:-9999999999', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number (6–15 digits).',
            'phone.unique' => 'Another contact already has this phone number.',
            'alt_phone.regex' => 'Enter a valid phone number (6–15 digits).',
            'pincode.regex' => 'Enter a 6-digit PIN code.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $gstin = $this->input('gstin');
            $state = $this->input('state_code');
            if ($gstin && $state && ! $validator->errors()->has('gstin') && substr($gstin, 0, 2) !== $state) {
                $validator->errors()->add('state_code', 'The state must match the GSTIN ('.substr($gstin, 0, 2).').');
            }
            if ($this->input('type') === Contact::CUSTOMER && ! $this->input('phone') && ! $this->route('contact')?->is_walk_in) {
                $validator->errors()->add('phone', 'A phone number is needed to find the customer at the counter.');
            }
        }];
    }
}
