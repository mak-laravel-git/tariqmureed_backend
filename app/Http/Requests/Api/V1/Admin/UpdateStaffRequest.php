<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\RoleEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept a role by its name as shown ("Super Admin") as well as "super-admin".
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('owner'))) {
            $this->merge(['owner' => Str::slug($this->input('owner'))]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object|null>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->route('id'))],
            'owner' => ['required', Rule::in(array_column(RoleEnum::cases(), 'value'))],
            // Left empty, the account keeps its password.
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => "Enter the person's name.",
            'subtitle.required' => 'Enter an email address.',
            'subtitle.email' => 'Enter a valid email address.',
            'subtitle.unique' => 'Another account already uses this email address.',
            'owner.in' => 'The role must be one of: '.implode(', ', array_column(RoleEnum::cases(), 'value')).'.',
            'password.required' => 'Enter a password for the new account.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
