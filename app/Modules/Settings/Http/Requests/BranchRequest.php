<?php

namespace App\Modules\Settings\Http\Requests;

use App\Rules\Gstin;
use App\Support\Gst\IndianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'gstin' => $this->filled('gstin') ? strtoupper(trim($this->input('gstin'))) : null,
        ]);
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:6', 'regex:/^[A-Z0-9]+$/', Rule::unique('branches', 'code')->ignore($branch)],
            'gstin' => ['nullable', 'string', new Gstin],
            'state_code' => ['required', Rule::in(IndianStates::codes())],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'The code may only contain letters and numbers.'];
    }

    /** The first two digits of a GSTIN must match the selected state. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $gstin = $this->input('gstin');
            $state = $this->input('state_code');
            if ($gstin && $state && ! $validator->errors()->has('gstin') && substr($gstin, 0, 2) !== $state) {
                $validator->errors()->add('gstin', 'GSTIN state code ('.substr($gstin, 0, 2).') does not match the selected state.');
            }
        }];
    }
}
