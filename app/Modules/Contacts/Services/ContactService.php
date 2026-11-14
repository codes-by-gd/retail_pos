<?php

namespace App\Modules\Contacts\Services;

use App\Modules\Contacts\Enums\LedgerEntryType;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ContactService
{
    private const FIELDS = [
        'type', 'name', 'company_name', 'phone', 'alt_phone', 'email', 'gstin', 'state_code', 'address', 'city', 'pincode',
        'customer_group_id', 'credit_limit', 'payment_terms_days', 'date_of_birth', 'anniversary', 'notes', 'is_active',
    ];

    public function __construct(private ContactLedgerService $ledger) {}

    /** $data may include opening_balance (signed: + they owe us, − we owe them). */
    public function create(array $data): Contact
    {
        return DB::transaction(function () use ($data) {
            $contact = Contact::create([
                ...Arr::only($data, self::FIELDS),
                'credit_limit' => $data['credit_limit'] ?? 0,
                'code' => Contact::nextCode($data['type']),
            ]);

            if (! empty($data['opening_balance'])) {
                $this->ledger->setOpeningBalance($contact, $data['opening_balance']);
            }

            return $contact;
        });
    }

    public function update(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data) {
            $attributes = Arr::only($data, self::FIELDS);
            if ($contact->is_walk_in) {
                // The counter customer stays a customer and stays active.
                $attributes = [...$attributes, 'type' => Contact::CUSTOMER, 'is_active' => true, 'state_code' => null, 'gstin' => null];
            }
            $contact->update([...$attributes, 'credit_limit' => $data['credit_limit'] ?? 0]);

            if (array_key_exists('opening_balance', $data)) {
                $this->ledger->setOpeningBalance($contact, $data['opening_balance'] ?? 0);
            }

            return $contact;
        });
    }

    /** @throws \DomainException */
    public function delete(Contact $contact): void
    {
        if ($contact->is_walk_in) {
            throw new \DomainException('The walk-in customer is built in and cannot be deleted.');
        }
        if ($contact->ledger()->where('type', '!=', LedgerEntryType::Opening)->exists()) {
            throw new \DomainException("{$contact->name} has transactions. Deactivate the contact instead.");
        }

        DB::transaction(function () use ($contact) {
            $contact->ledger()->delete();
            $contact->delete();
        });
    }
}
