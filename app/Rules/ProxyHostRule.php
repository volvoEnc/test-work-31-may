<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ProxyHostRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) !== $value || $value === '') {
            $fail('The proxy host must be a non-empty host without surrounding spaces.');

            return;
        }

        foreach (['://', '/', '?', '#', '@', '[', ']'] as $forbidden) {
            if (str_contains($value, $forbidden)) {
                $fail('The proxy host must not contain protocol, path, query, credentials, or brackets.');

                return;
            }
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return;
        }

        $fail('The proxy host must be a valid IPv4 address, IPv6 address, or domain.');
    }
}
