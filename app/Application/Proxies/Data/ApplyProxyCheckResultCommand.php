<?php

namespace App\Application\Proxies\Data;

use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;
use App\Support\Proxies\ProxyCheckGuard;

final readonly class ApplyProxyCheckResultCommand
{
    public function __construct(
        public ProxyServer $proxy,
        public ProxyCheckResult $result,
        public ProxyCheckSource $source,
        public ?ProxyCheckGuard $guard = null,
    ) {}
}
