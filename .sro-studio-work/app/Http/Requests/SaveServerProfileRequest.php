<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveServerProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:512'],
            'account_database' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'shard_database' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'log_database' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'proxy_database' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'icon_root' => ['nullable', 'string', 'max:2048'],
            'encrypt_connection' => ['nullable', 'boolean'],
            'trust_server_certificate' => ['nullable', 'boolean'],
        ];
    }
}
