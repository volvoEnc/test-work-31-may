<?php

namespace App\Domain\Proxies;

use App\Enums\ProxyScheme;

final readonly class ProxyEndpoint
{
    public function __construct(
        private ProxyScheme $scheme,
        private string $host,
        private string|int $port,
        private ?string $username = null,
    ) {}

    public function displayAddress(): string
    {
        $credentials = filled($this->username)
            ? rawurlencode((string) $this->username).'@'
            : '';

        return "{$this->scheme->value}://{$credentials}{$this->displayHost()}:{$this->port}";
    }

    private function displayHost(): string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return "[{$this->host}]";
        }

        return $this->host;
    }
}
