<?php

namespace App\Actions\Proxies;

use App\Application\Proxies\Data\ApplyProxyCheckResultCommand;
use App\Application\Proxies\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyUriFactory;
use App\Support\ProxyFailureSanitizer;
use App\Support\Proxies\ProxyCheckGuard;
use Carbon\CarbonImmutable;
use Throwable;

class RecordFailedProxyCheckAction
{
    public function __construct(
        private readonly ProxyUriFactory $uriFactory,
        private readonly ProxyFailureSanitizer $failureSanitizer,
        private readonly ApplyProxyCheckResultAction $applyResult,
    ) {}

    public function execute(
        int $proxyId,
        ProxyCheckSource $source,
        ?string $checkGeneration,
        Throwable $exception,
        ?ProxyCheckGuard $guard = null,
    ): void {
        $proxy = ProxyServer::query()->find($proxyId);

        if (! $proxy instanceof ProxyServer) {
            return;
        }

        if (! $this->isCurrentGeneration($proxy, $checkGeneration)) {
            return;
        }

        $finishedAt = CarbonImmutable::now();
        $startedAt = $proxy->checking_started_at?->toImmutable() ?? $finishedAt;
        $proxyUri = $this->uriFactory->make($proxy);
        $errorMessage = $this->failureSanitizer->sanitize($exception->getMessage(), $proxy, $proxyUri);

        $this->applyResult->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            new ProxyCheckResult(
                ProxyStatus::Offline,
                $startedAt,
                $finishedAt,
                null,
                null,
                ProxyCheckErrorCode::UnexpectedError,
                $errorMessage,
            ),
            $source,
            $guard?->withGeneration($checkGeneration) ?? ProxyCheckGuard::generation($checkGeneration),
        ));
    }

    private function isCurrentGeneration(ProxyServer $proxy, ?string $checkGeneration): bool
    {
        if ($checkGeneration !== null) {
            return $proxy->check_generation === $checkGeneration;
        }

        return blank($proxy->check_generation);
    }
}
