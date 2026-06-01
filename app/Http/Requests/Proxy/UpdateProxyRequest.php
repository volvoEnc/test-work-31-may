<?php

namespace App\Http\Requests\Proxy;

use App\Application\Proxies\Data\UpdateProxyCommand;
use App\Enums\ProxyScheme;
use App\Rules\ProxyHostRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProxyRequest extends FormRequest
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
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'scheme' => ['sometimes', 'required', 'string', Rule::in(ProxyScheme::values())],
            'host' => ['sometimes', 'required', 'string', 'max:255', new ProxyHostRule],
            'port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    public function toCommand(): UpdateProxyCommand
    {
        $data = $this->validated();

        if (array_key_exists('scheme', $data)) {
            $data['scheme'] = ProxyScheme::from($data['scheme']);
        }

        if (array_key_exists('port', $data)) {
            $data['port'] = (int) $data['port'];
        }

        return new UpdateProxyCommand($data);
    }
}
