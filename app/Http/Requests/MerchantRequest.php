<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'owner_email' => strtolower(trim((string) $this->input('owner_email'))),
            'slug' => strtolower(trim((string) $this->input('slug'))),
            'upi_vpa' => strtolower(trim((string) $this->input('upi_vpa'))),
            'return_url_allowlist' => array_values(array_unique(preg_split('/[\s,]+/', strtolower(trim((string) $this->input('return_domains'))), -1, PREG_SPLIT_NO_EMPTY))),
        ]);
    }

    public function rules(): array
    {
        return [
            'default_locale' => ['sometimes', 'in:en,hi,mr'],
            'heartbeat_timeout_seconds' => ['sometimes', 'integer', 'between:60,600'],
            'healthy_heartbeats_to_resume' => ['sometimes', 'integer', 'between:2,10'],
            'breaker_consecutive_threshold' => ['sometimes', 'integer', 'between:1,20'],
            'breaker_rolling_threshold' => ['sometimes', 'integer', 'between:1,100'],
            'breaker_window_minutes' => ['sometimes', 'integer', 'between:1,120'],
            'breaker_cooldown_minutes' => ['sometimes', 'integer', 'between:1,1440'],
            'dispute_escalation_hours' => ['sometimes', 'integer', 'between:1,168'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('merchants')->ignore($this->route('merchant'))],
            'upi_vpa' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9._-]{2,}@[a-z0-9.-]{2,}$/'],
            'support_phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'return_domains' => ['nullable', 'string', 'max:2000'],
            'return_url_allowlist' => ['array', 'max:20'],
            'return_url_allowlist.*' => ['string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'owner_name' => [$this->isMethod('post') ? 'required' : 'exclude', 'string', 'max:120'],
            'owner_email' => [$this->isMethod('post') ? 'required' : 'exclude', 'email', 'max:255', Rule::unique('users', 'email')],
            'order_expiry_minutes' => ['required', 'integer', 'between:1,60'],
            'auto_confirm_order_cap_minor' => ['required', 'integer', 'between:1,100000000'],
            'auto_confirm_day_cap_minor' => ['required', 'integer', 'gte:auto_confirm_order_cap_minor', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'return_url_allowlist.*.regex' => 'Use exact domains such as shop.example.com, without a scheme, path, port or wildcard.',
            'support_phone.regex' => 'Enter 10–15 digits, optionally starting with +, without spaces.',
            'upi_vpa.regex' => 'Enter a valid UPI ID such as shop@kotak.',
        ];
    }
}
