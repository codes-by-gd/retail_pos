<?php

namespace App\Modules\Settings\Http\Requests;

use App\Rules\Gstin;
use App\Support\Gst\IndianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('business.gstin')) {
            $this->merge(['business' => [...$this->input('business'), 'gstin' => strtoupper(trim($this->input('business.gstin')))]]);
        }
    }

    public function rules(): array
    {
        return [
            'business.name' => ['required', 'string', 'max:150'],
            'business.legal_name' => ['nullable', 'string', 'max:150'],
            'business.gstin' => ['nullable', 'string', new Gstin],
            'business.state_code' => ['required', Rule::in(IndianStates::codes())],
            'business.address' => ['nullable', 'string', 'max:255'],
            'business.city' => ['nullable', 'string', 'max:100'],
            'business.pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'business.phone' => ['nullable', 'string', 'max:20'],
            'business.email' => ['nullable', 'email', 'max:150'],
            'business.financial_year_start' => ['required', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/'],
            'invoice.prefix' => ['nullable', 'string', 'max:4', 'regex:/^[A-Za-z0-9\-]+$/'],
            'invoice.footer' => ['nullable', 'string', 'max:500'],
            'invoice.terms' => ['nullable', 'string', 'max:2000'],
            'tax.prices_include_tax' => ['required', 'boolean'],
            'tax.round_off' => ['required', 'boolean'],
            'pos.allow_negative_stock' => ['required', 'boolean'],
            'pos.beep_on_scan' => ['required', 'boolean'],
            'pos.receipt_size' => ['required', Rule::in(['58mm', '80mm', 'a4'])],
            'pos.print_after_sale' => ['required', 'boolean'],
            'pos.accept_card' => ['required', 'boolean'],
            'pos.accept_upi' => ['required', 'boolean'],
            'alerts.discount_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'alerts.cash_difference' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000000'],
            'alerts.email_enabled' => ['sometimes', 'required', 'boolean'],
            'loyalty.enabled' => ['sometimes', 'required', 'boolean'],
            'loyalty.earn_per' => ['sometimes', 'required', 'numeric', 'min:1', 'max:100000'],
            'loyalty.point_value' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:1000'],
            'loyalty.min_redeem' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'loyalty.max_redeem_percent' => ['sometimes', 'required', 'numeric', 'min:1', 'max:100'],
            'loyalty.expiry_months' => ['sometimes', 'required', 'integer', 'min:0', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return ['business.gstin' => 'GSTIN', 'business.state_code' => 'state', 'business.name' => 'business name'];
    }

    /** ['business' => ['name' => 'X']] → ['business.name' => 'X'] */
    public function flattened(): array
    {
        $out = [];
        foreach ($this->validated() as $group => $values) {
            foreach ($values as $key => $value) {
                $out["{$group}.{$key}"] = $value;
            }
        }

        return $out;
    }

    /** The first two digits of a GSTIN must match the selected state. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $gstin = $this->input('business.gstin');
            $state = $this->input('business.state_code');
            if ($gstin && $state && ! $validator->errors()->has('business.gstin') && substr($gstin, 0, 2) !== $state) {
                $validator->errors()->add('business.gstin', 'GSTIN state code ('.substr($gstin, 0, 2).') does not match the selected state.');
            }
        }];
    }
}
