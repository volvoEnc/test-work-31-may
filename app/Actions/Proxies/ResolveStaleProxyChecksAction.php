<?php

namespace App\Actions\Proxies;

use App\Application\Proxies\Data\ApplyProxyCheckResultCommand;
use App\Application\Proxies\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Support\Proxies\ProxyCheckGuard;
use Carbon\CarbonImmutable;

class ResolveStaleProxyChecksAction
{
    public function __construct(private readonly ApplyProxyCheckResultAction $applyResult) {}

    public function execute(): void
    {
        $now = CarbonImmutable::now();
        $staleCutoff = $now->subSeconds((int) config('proxy-manager.check.stale_after_seconds'));

        ProxyServer::query()
            ->where('status', ProxyStatus::Checking)
            ->whereNotNull('checking_started_at')
            ->where('checking_started_at', '<=', $staleCutoff)
            ->orderBy('id')
            ->chunkById(100, function ($proxies) use ($now): void {
                foreach ($proxies as $proxy) {
                    $startedAt = $proxy->checking_started_at?->toImmutable() ?? $now;
                    $checkGeneration = $proxy->check_generation;
                    $expectedSource = $proxy->check_source;
                    $checkSource = $expectedSource ?? ProxyCheckSource::Auto;

                    $this->applyResult->execute(new ApplyProxyCheckResultCommand(
                        $proxy,
                        new ProxyCheckResult(
                            ProxyStatus::Offline,
                            $startedAt,
                            $now,
                            null,
                            null,
                            ProxyCheckErrorCode::StaleCheck,
                            'Proxy check became stale.',
                        ),
                        $checkSource,
                        ProxyCheckGuard::generation($checkGeneration)->withSource($expectedSource),
                    ));
                }
            });
    }
}
