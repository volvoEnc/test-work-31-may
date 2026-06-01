<?php

namespace App\Application\Proxies\Data;

use App\Enums\ProxyScheme;
use InvalidArgumentException;

final readonly class UpdateProxyCommand
{
    private const ALLOWED_FIELDS = ['name', 'scheme', 'host', 'port', 'username', 'password'];

    /**
     * @var array{name?: string|null, scheme?: ProxyScheme, host?: string, port?: int, username?: string|null, password?: string|null}
     */
    private array $fields;

    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(array $fields)
    {
        $unknownFields = array_diff(array_keys($fields), self::ALLOWED_FIELDS);

        if ($unknownFields !== []) {
            throw new InvalidArgumentException('Unknown proxy update fields: '.implode(', ', $unknownFields));
        }

        $this->fields = $fields;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function value(string $field): mixed
    {
        if (! $this->has($field)) {
            throw new InvalidArgumentException("Proxy update field [{$field}] was not provided.");
        }

        return $this->fields[$field];
    }

    /**
     * @return array{name?: string|null, scheme?: ProxyScheme, host?: string, port?: int, username?: string|null, password?: string|null}
     */
    public function toPersistenceArray(): array
    {
        return $this->fields;
    }
}
