<?php

namespace App\Http\Requests\Proxy;

use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Support\ProxyIndexSortOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(ProxyStatus::values())],
            'scheme' => ['nullable', 'string', Rule::in(ProxyScheme::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(ProxyIndexSortOptions::allowedSorts())],
            'direction' => ['nullable', 'string', Rule::in(ProxyIndexSortOptions::allowedDirections())],
        ];
    }
}
