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
            'socialX' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'socialFacebook' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'socialInstagram' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'socialYoutube' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'socialTiktok' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'socialWhatsapp' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'seoSiteUrl' => ['sometimes', 'nullable', 'url:http,https', 'max:190'],
            'seoTitleTemplate' => ['sometimes', 'required', 'string', 'max:100', 'regex:/%s/'],
            'seoDefaultImage' => ['sometimes', 'nullable', 'string', 'max:500'],
            'seoTwitterHandle' => ['sometimes', 'nullable', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'],
            'seoIndexing' => ['sometimes', 'boolean'],
            'seoGoogleVerification' => ['sometimes', 'nullable', 'regex:/^[A-Za-z0-9_-]{10,120}$/'],
            'seoBingVerification' => ['sometimes', 'nullable', 'regex:/^[A-Za-z0-9_-]{10,120}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gaMeasurementId.regex' => 'The Google Analytics Measurement ID must look like G-XXXXXXXXXX.',
            'seoSiteUrl.url' => 'The website address must start with https:// (for example https://tmnsport.com).',
            'seoTitleTemplate.regex' => 'The title format must contain %s, where the page title goes (for example %s | TMN Sport).',
            'seoTwitterHandle.regex' => 'The X (Twitter) handle looks like @tmnsport.',
            'seoGoogleVerification.regex' => 'Paste only the verification code, not the whole meta tag.',
            'seoBingVerification.regex' => 'Paste only the verification code, not the whole meta tag.',
            'adsenseClientId.regex' => 'The AdSense publisher ID must look like ca-pub-1234567890123456.',
            'socialX.url' => 'The X link must be a full address starting with https://.',
            'socialFacebook.url' => 'The Facebook link must be a full address starting with https://.',
            'socialInstagram.url' => 'The Instagram link must be a full address starting with https://.',
            'socialYoutube.url' => 'The YouTube link must be a full address starting with https://.',
            'socialTiktok.url' => 'The TikTok link must be a full address starting with https://.',
            'socialWhatsapp.url' => 'The WhatsApp link must be a full address starting with https://.',
        ];
    }
}
