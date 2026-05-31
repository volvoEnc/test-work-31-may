<?php

namespace App\Jobs;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CheckProxyStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor;

    public function __construct(
        public readonly int $proxyId,
        public readonly ProxyCheckSource $source = ProxyCheckSource::Auto,
    ) {
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

        $proxy->forceFill([
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now(),
        ])->save();

        $result = $checker->check($proxy->refresh());

        $applyResult->execute($proxy, $result, $this->source);
    }

    public function failed(Throwable $exception): void
    {
        $proxy = ProxyServer::query()->find($this->proxyId);

        if (! $proxy instanceof ProxyServer) {
            return;
        }

        $finishedAt = CarbonImmutable::now();
        $startedAt = $proxy->checking_started_at?->toImmutable() ?? $finishedAt;

        app(ApplyProxyCheckResultAction::class)->execute(
            $proxy,
            new ProxyCheckResult(
                ProxyStatus::Offline,
                $startedAt,
                $finishedAt,
                null,
                null,
                ProxyCheckErrorCode::UnexpectedError,
                $exception->getMessage(),
            ),
            $this->source,
        );
    }
}
