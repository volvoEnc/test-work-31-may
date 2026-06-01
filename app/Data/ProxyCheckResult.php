<?php

namespace App\Data;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyStatus;
use Carbon\CarbonImmutable;

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
        PersistedProxyCheckStatus::assertAllows($this->status);
    }
}
