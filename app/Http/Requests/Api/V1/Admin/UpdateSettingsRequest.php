<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'websiteName' => ['sometimes', 'required', 'string', 'max:120'],
            'contactEmail' => ['sometimes', 'nullable', 'email', 'max:190'],
            'contactAddress' => ['sometimes', 'nullable', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'defaultStatus' => ['sometimes', 'required', Rule::in(['Draft', 'Pending', 'Published'])],
            'timezone' => ['sometimes', 'required', 'string', 'max:60'],
            'requireApproval' => ['sometimes', 'boolean'],
            'notifyEditors' => ['sometimes', 'boolean'],
            'gaMeasurementId' => ['sometimes', 'nullable', 'regex:/^G-[A-Z0-9]{4,20}$/'],
            'adsenseClientId' => ['sometimes', 'nullable', 'regex:/^ca-pub-\d{10,20}$/'],
            'consentMode' => ['sometimes', 'required', Rule::in(['builtin', 'google'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gaMeasurementId.regex' => 'The Google Analytics Measurement ID must look like G-XXXXXXXXXX.',
            'adsenseClientId.regex' => 'The AdSense publisher ID must look like ca-pub-1234567890123456.',
        ];
    }
}
