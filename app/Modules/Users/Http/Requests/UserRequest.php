<?php

namespace App\Modules\Users\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        // Only admins may edit an admin account.
        return $this->user()->can('users.manage')
            && ($target === null || ! $target->isAdmin() || $this->user()->isAdmin());
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $creating = $user === null;

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$creating ? 'required' : 'nullable', 'confirmed', Password::defaults()],
            'pin' => ['nullable', 'digits_between:4,6'],
            'is_active' => ['required', 'boolean'],
            'role' => [
                'required',
                Rule::exists('roles', 'name'),
                // Only admins may create or edit admins.
                Rule::when(! $this->user()->isAdmin(), [Rule::notIn([Permissions::ADMIN_ROLE])]),
            ],
            'branch_ids' => ['array', Rule::requiredIf($this->input('role') !== Permissions::ADMIN_ROLE), 'min:1'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_ids.required' => 'Assign the user to at least one branch.',
            'role.not_in' => 'Only an Admin can assign the Admin role.',
        ];
    }

    /** Attributes for User::create/update; blank password/PIN means "keep current". */
    public function userAttributes(): array
    {
        $data = $this->safe()->only(['name', 'email', 'phone', 'is_active']);

        if ($this->filled('password')) {
            $data['password'] = $this->validated('password');
        }
        if ($this->filled('pin')) {
            $data['pin'] = $this->validated('pin');
        }

        return $data;
    }
}
