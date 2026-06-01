<?php

namespace App\Application\Proxies\Data;

use App\Enums\ProxyScheme;

final readonly class CreateProxyCommand
{
    public function __construct(
        public ?string $name,
        public ProxyScheme $scheme,
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
    ) {}

    /**
     * @return array{name: string|null, scheme: ProxyScheme, host: string, port: int, username: string|null, password: string|null}
     */
    public function toPersistenceArray(): array
    {
        return [
            'name' => $this->name,
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
