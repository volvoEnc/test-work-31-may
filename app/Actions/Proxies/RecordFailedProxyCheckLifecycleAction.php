<?php

namespace App\Actions\Proxies;

use App\Support\Proxies\ProxyCheckGuard;
use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;
use Throwable;

class RecordFailedProxyCheckLifecycleAction
{
    public function __construct(
        private readonly RecordFailedProxyCheckAction $recordFailedProxyCheck,
    ) {}

    public function execute(int $proxyId, ProxyCheckSource $source, string $checkJobToken, Throwable $exception): void
    {
        $proxy = ProxyServer::query()->find($proxyId);

        if (! $proxy instanceof ProxyServer || blank($proxy->check_generation)) {
            return;
        }

        if ($proxy->check_job_token !== $checkJobToken) {
            return;
        }

        $claimedSource = $proxy->check_job_source;

        if ($proxy->check_source !== $claimedSource) {
            return;
        }

        $this->recordFailedProxyCheck->execute(
            $proxyId,
            $claimedSource ?? $source,
            $proxy->check_generation,
            $exception,
            ProxyCheckGuard::generation($proxy->check_generation)
                ->withSource($claimedSource)
                ->withJobToken($checkJobToken),
        );
    }
}
