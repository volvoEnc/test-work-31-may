<?php

namespace App\Http\Requests\Proxy;

use App\Application\Proxies\Data\CreateProxyCommand;
use App\Enums\ProxyScheme;
use App\Rules\ProxyHostRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|\Stringable|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'scheme' => ['required', 'string', Rule::in(ProxyScheme::values())],
            'host' => ['required', 'string', 'max:255', new ProxyHostRule],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function toCommand(): CreateProxyCommand
    {
        $data = $this->validated();

        return new CreateProxyCommand(
            name: $data['name'] ?? null,
            scheme: ProxyScheme::from($data['scheme']),
            host: $data['host'],
            port: (int) $data['port'],
            username: $data['username'] ?? null,
            password: $data['password'] ?? null,
        );
    }
}
