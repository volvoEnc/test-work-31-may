<?php

namespace App\Data;

use App\Enums\ProxyScheme;

final readonly class ProxyIdentity
{
    private string $scheme;

    public function __construct(
        string|ProxyScheme $scheme,
        private string $host,
        private int $port,
        private ?string $username = null,
    ) {
        $this->scheme = $scheme instanceof ProxyScheme ? $scheme->value : $scheme;
    }

    public static function hashFor(string|ProxyScheme $scheme, string $host, int $port, ?string $username): string
    {
        return (new self($scheme, $host, $port, $username))->hash();
    }

    public function hash(): string
    {
        return hash('sha256', implode('|', [
            strtolower($this->scheme),
            strtolower($this->host),
            (string) $this->port,
            strtolower($this->username ?? ''),
        ]));
    }
}
