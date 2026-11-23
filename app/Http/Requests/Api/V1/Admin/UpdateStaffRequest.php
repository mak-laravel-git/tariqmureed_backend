<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\RoleEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The role column of the shared admin form is free text, so accept "Super Admin" as well as "super-admin".
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('owner'))) {
            $this->merge(['owner' => Str::slug($this->input('owner'))]);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->route('id'))],
            'owner' => ['required', Rule::in(array_column(RoleEnum::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subtitle.email' => 'Enter a valid email address.',
            'owner.in' => 'The role must be one of: '.implode(', ', array_column(RoleEnum::cases(), 'value')).'.',
        ];
    }
}
