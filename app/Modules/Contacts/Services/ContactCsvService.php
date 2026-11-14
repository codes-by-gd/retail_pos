<?php

namespace App\Modules\Contacts\Services;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\CustomerGroup;
use App\Rules\Gstin;
use App\Support\Gst\IndianStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV import/export of customers or suppliers. Rows with an existing `code` update that contact. */
class ContactCsvService
{
    public const COLUMNS = [
        'code', 'name', 'company_name', 'phone', 'alt_phone', 'email', 'gstin', 'state', 'address', 'city', 'pincode',
        'group', 'credit_limit', 'payment_terms_days', 'date_of_birth', 'anniversary', 'opening_balance', 'notes',
    ];

    public function __construct(private ContactService $contacts, private ContactLedgerService $ledger) {}

    public function export(string $kind): StreamedResponse
    {
        return response()->streamDownload(function () use ($kind) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [...array_diff(self::COLUMNS, ['opening_balance']), 'balance'], escape: '');

            Contact::query()->ofType($kind)->where('is_walk_in', false)->select('contacts.*')->withBalance()->with('group')
                ->orderBy('code')->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $c) {
                        fputcsv($out, [
                            $c->code, $c->name, $c->company_name, $c->phone, $c->alt_phone, $c->email, $c->gstin, $c->state_code,
                            $c->address, $c->city, $c->pincode, $c->group?->name, $c->credit_limit, $c->payment_terms_days,
                            $c->date_of_birth?->toDateString(), $c->anniversary?->toDateString(), $c->notes, $c->balance,
                        ], escape: '');
                    }
                });

            fclose($out);
        }, "{$kind}s-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function template(string $kind): StreamedResponse
    {
        return response()->streamDownload(function () use ($kind) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS, escape: '');
            fputcsv($out, $kind === Contact::SUPPLIER
                ? ['', 'Ramesh Shah', 'Shah Textiles', '9824012345', '', 'sales@shahtex.in', '', '24', 'Ring Road', 'Surat', '395002', '', '', '30', '', '', '-25000', 'Fabric supplier']
                : ['', 'Priya Patel', '', '9876543210', '', 'priya@example.com', '', '24', 'Alkapuri', 'Vadodara', '390007', 'Regular', '0', '', '1992-08-14', '', '0', ''],
                escape: '');
            fclose($out);
        }, "{$kind}s-import-template.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return array{created: int, updated: int, errors: list<array{row: int, message: string}>} */
    public function import(string $path, string $kind): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle, escape: '') ?: []);
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        if (! in_array('name', $header, true)) {
            fclose($handle);
            $result['errors'][] = ['row' => 1, 'message' => 'Header row must include "name". Download the template.'];

            return $result;
        }

        $groups = CustomerGroup::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $row = array_map(fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null, [...array_fill_keys(self::COLUMNS, null), ...$row]);

            try {
                DB::transaction(function () use ($row, $kind, $groups, &$result) {
                    $existing = $row['code'] ? Contact::where('code', $row['code'])->first() : null;
                    $data = $this->validated($row, $kind, $groups, $existing);

                    if ($existing) {
                        $this->contacts->update($existing, $data);
                        $result['updated']++;
                    } else {
                        $this->contacts->create($data);
                        $result['created']++;
                    }
                });
            } catch (\Throwable $e) {
                $result['errors'][] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }
        fclose($handle);

        return $result;
    }

    private function validated(array $row, string $kind, $groups, ?Contact $existing): array
    {
        if ($existing && $existing->is_walk_in) {
            throw new \InvalidArgumentException('The walk-in customer cannot be imported.');
        }

        $state = $row['state'];
        if ($state && ! ctype_digit($state)) {
            $state = collect(IndianStates::ALL)->search(fn ($n) => mb_strtolower($n) === mb_strtolower($state));
            $state = $state === false ? $row['state'] : str_pad((string) $state, 2, '0', STR_PAD_LEFT);
        } elseif ($state) {
            $state = str_pad($state, 2, '0', STR_PAD_LEFT);
        }
        $gstin = $row['gstin'] ? strtoupper($row['gstin']) : null;

        $data = [
            ...$row,
            'type' => $existing?->type ?? $kind,
            'phone' => Contact::normalizePhone($row['phone']),
            'alt_phone' => Contact::normalizePhone($row['alt_phone']),
            'gstin' => $gstin,
            'state_code' => $state ?: ($gstin ? substr($gstin, 0, 2) : null),
            'customer_group_id' => $row['group'] ? ($groups[mb_strtolower($row['group'])] ?? throw new \InvalidArgumentException("Unknown customer group \"{$row['group']}\".")) : null,
            'is_active' => true,
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => [$kind === Contact::CUSTOMER ? 'required' : 'nullable', 'regex:/^\d{6,15}$/', Rule::unique('contacts', 'phone')->ignore($existing)],
            'alt_phone' => ['nullable', 'regex:/^\d{6,15}$/'],
            'email' => ['nullable', 'email'],
            'gstin' => ['nullable', new Gstin],
            'state_code' => ['nullable', Rule::in(IndianStates::codes())],
            'pincode' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'anniversary' => ['nullable', 'date'],
            'opening_balance' => ['nullable', 'numeric'],
        ], [
            'phone.unique' => 'Phone :input already belongs to another contact.',
            'phone.required' => 'Phone is required for customers.',
            'phone.regex' => 'Invalid phone number.',
            'state_code.in' => 'Unknown state.',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }
        if ($gstin && $data['state_code'] && substr($gstin, 0, 2) !== $data['state_code']) {
            throw new \InvalidArgumentException('State does not match the GSTIN.');
        }

        // Opening balance only on new contacts; existing balances are left alone.
        if ($existing) {
            unset($data['opening_balance']);
        }

        return $data;
    }
}
