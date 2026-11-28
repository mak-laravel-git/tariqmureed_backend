<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

/**
 * The same account details as an update, and a password is required.
 */
class StoreStaffRequest extends UpdateStaffRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string|object|null>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
