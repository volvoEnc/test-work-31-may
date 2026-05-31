<?php

namespace App\Data;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProxyCheckResult
{
    public function __construct(
        public ProxyStatus $status,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public ?int $responseTimeMs,
        public ?int $httpStatus,
        public ?ProxyCheckErrorCode $errorCode,
        public ?string $errorMessage,
    ) {
        if (! in_array($this->status, [ProxyStatus::Online, ProxyStatus::Offline], true)) {
            throw new InvalidArgumentException('Proxy check result status must be online or offline.');
        }
    }
}
