<?php

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('expenses.manage');
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'gst_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'method' => ['required', Rule::in(array_keys(Expense::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_to' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
            'register_session_id' => ['nullable', 'integer'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['expense_category_id' => 'category', 'gst_amount' => 'GST amount', 'attachment' => 'bill photo'];
    }
}
