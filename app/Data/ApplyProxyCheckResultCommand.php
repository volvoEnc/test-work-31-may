<?php

namespace App\Data;

use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;

final readonly class ApplyProxyCheckResultCommand
{
    public function __construct(
        public ProxyServer $proxy,
        public ProxyCheckResult $result,
        public ProxyCheckSource $source,
        public ?ProxyCheckGuard $guard = null,
    ) {}
}
