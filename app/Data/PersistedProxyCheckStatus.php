<?php

namespace App\Data;

use App\Enums\ProxyStatus;
use App\Exceptions\InvalidProxyCheckStatusException;

final readonly class PersistedProxyCheckStatus
{
    private function __construct(private ProxyStatus $status) {}

    public static function from(ProxyStatus $status): self
    {
        self::assertAllows($status);

        return new self($status);
    }

    public static function assertAllows(ProxyStatus $status): void
    {
        if (! self::allows($status)) {
            throw new InvalidProxyCheckStatusException($status);
        }
    }

    public static function allows(ProxyStatus $status): bool
    {
        return match ($status) {
            ProxyStatus::Online, ProxyStatus::Offline => true,
            default => false,
        };
    }

    public function value(): ProxyStatus
    {
        return $this->status;
    }
}
