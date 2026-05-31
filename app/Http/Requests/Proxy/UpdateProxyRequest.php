<?php

namespace App\Http\Requests\Proxy;

use App\Enums\ProxyScheme;
use App\Rules\ProxyHostRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'scheme' => ['sometimes', 'required', 'string', Rule::in(ProxyScheme::values())],
            'host' => ['sometimes', 'required', 'string', 'max:255', new ProxyHostRule],
            'port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
