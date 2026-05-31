<?php

namespace App\Jobs;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Actions\Proxies\RecordFailedProxyCheckAction;
use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class CheckProxyStatusJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor;

    public readonly string $checkJobToken;

    public function __construct(
        public readonly int $proxyId,
        public readonly ProxyCheckSource $source = ProxyCheckSource::Auto,
        public readonly ?string $checkGeneration = null,
        ?string $checkJobToken = null,
    ) {
        $this->checkJobToken = $checkJobToken ?? (string) Str::uuid();
        $this->uniqueFor = (int) config('proxy-manager.check.unique_for_seconds');
        $this->onQueue((string) config('proxy-manager.check.queue'));
    }

    public function uniqueId(): string
    {
        return 'proxy:'.$this->proxyId;
    }

    public function handle(ProxyCheckerInterface $checker, ApplyProxyCheckResultAction $applyResult): void
    {
        $proxy = ProxyServer::query()->find($this->proxyId);

        if (! $proxy instanceof ProxyServer) {
            return;
        }

        $currentGeneration = $proxy->check_generation;
        $persistedSource = $proxy->check_source;

        if (blank($currentGeneration)) {
            return;
        }

        $currentSource = $persistedSource ?? $this->source;

        if (! $this->claimCurrentGeneration($currentGeneration, $persistedSource)) {
            return;
        }

        try {
            $result = $checker->check($proxy->refresh());
        } catch (Throwable $exception) {
            app(RecordFailedProxyCheckAction::class)->execute(
                $this->proxyId,
                $currentSource,
                $currentGeneration,
                $exception,
                $persistedSource,
                true,
                $this->checkJobToken,
                true,
            );

            throw $exception;
        }

        $applyResult->execute(
            $proxy,
            $result,
            $currentSource,
            $currentGeneration,
            true,
            $persistedSource,
            true,
            $this->checkJobToken,
            true,
        );
    }

    public function failed(Throwable $exception): void
    {
        $proxy = ProxyServer::query()->find($this->proxyId);

        if (! $proxy instanceof ProxyServer || blank($proxy->check_generation)) {
            return;
        }

        if ($proxy->check_job_token !== $this->checkJobToken) {
            return;
        }

        $claimedSource = $proxy->check_job_source;

        if ($proxy->check_source !== $claimedSource) {
            return;
        }

        app(RecordFailedProxyCheckAction::class)->execute(
            $this->proxyId,
            $claimedSource ?? $this->source,
            $proxy->check_generation,
            $exception,
            $claimedSource,
            true,
            $this->checkJobToken,
            true,
        );
    }

    private function claimCurrentGeneration(string $currentGeneration, ?ProxyCheckSource $persistedSource): bool
    {
        $query = ProxyServer::query()
            ->whereKey($this->proxyId)
            ->where('check_generation', $currentGeneration);

        if ($persistedSource instanceof ProxyCheckSource) {
            $query->where('check_source', $persistedSource);
        } else {
            $query->whereNull('check_source');
        }

        $claimed = (clone $query)
            ->whereNull('check_job_token')
            ->update([
                'check_job_token' => $this->checkJobToken,
                'check_job_source' => $persistedSource,
            ]) === 1;

        if ($claimed) {
            return true;
        }

        $reclaimed = (clone $query)
            ->where('check_job_token', $this->checkJobToken)
            ->update(['check_job_source' => $persistedSource]) === 1;

        return $reclaimed;
    }
}
