<?php

namespace App\Actions\Proxies;

use App\Data\ApplyProxyCheckResultCommand;
use App\Data\ProxyCheckGuard;
use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use Throwable;

class RunProxyCheckAction
{
    public function __construct(
        private readonly ProxyCheckerInterface $checker,
        private readonly ApplyProxyCheckResultAction $applyResult,
        private readonly RecordFailedProxyCheckAction $recordFailedProxyCheck,
    ) {}

    public function execute(int $proxyId, ProxyCheckSource $source, string $checkJobToken): void
    {
        $proxy = ProxyServer::query()->find($proxyId);

        if (! $proxy instanceof ProxyServer) {
            return;
        }

        $currentGeneration = $proxy->check_generation;
        $persistedSource = $proxy->check_source;

        if (blank($currentGeneration)) {
            return;
        }

        $currentSource = $persistedSource ?? $source;

        if (! $this->claimCurrentGeneration($proxyId, $currentGeneration, $persistedSource, $checkJobToken)) {
            return;
        }

        try {
            $result = $this->checker->check($proxy->refresh());
        } catch (Throwable $exception) {
            $this->recordFailedProxyCheck->execute(
                $proxyId,
                $currentSource,
                $currentGeneration,
                $exception,
                ProxyCheckGuard::generation($currentGeneration)
                    ->withSource($persistedSource)
                    ->withJobToken($checkJobToken),
            );

            throw $exception;
        }

        $this->applyResult->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            $currentSource,
            ProxyCheckGuard::generation($currentGeneration)
                ->withSource($persistedSource)
                ->withJobToken($checkJobToken),
        ));
    }

    private function claimCurrentGeneration(
        int $proxyId,
        string $currentGeneration,
        ?ProxyCheckSource $persistedSource,
        string $checkJobToken,
    ): bool {
        $query = ProxyServer::query()
            ->whereKey($proxyId)
            ->where('check_generation', $currentGeneration);

        if ($persistedSource instanceof ProxyCheckSource) {
            $query->where('check_source', $persistedSource);
        } else {
            $query->whereNull('check_source');
        }

        $claimed = (clone $query)
            ->whereNull('check_job_token')
            ->update([
                'check_job_token' => $checkJobToken,
                'check_job_source' => $persistedSource,
            ]) === 1;

        if ($claimed) {
            return true;
        }

        return (clone $query)
            ->where('check_job_token', $checkJobToken)
            ->update(['check_job_source' => $persistedSource]) === 1;
    }
}
