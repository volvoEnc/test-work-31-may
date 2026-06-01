<?php

namespace App\Queue;

use App\Actions\Proxies\RecordFailedProxyCheckLifecycleAction;
use App\Enums\ProxyCheckSource;
use Throwable;

class FailedProxyCheckLifecycleHandler
{
    public static function record(int $proxyId, ProxyCheckSource $source, string $checkJobToken, Throwable $exception): void
    {
        app(RecordFailedProxyCheckLifecycleAction::class)->execute($proxyId, $source, $checkJobToken, $exception);
    }
}
