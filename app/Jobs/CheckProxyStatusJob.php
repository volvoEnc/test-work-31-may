<?php

namespace App\Jobs;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use App\Services\ProxyChecker\ProxyUriFactory;
use App\Support\ProxyFailureSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CheckProxyStatusJob implements ShouldBeUnique, ShouldQueue
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
        public readonly ?string $checkGeneration = null,
    ) {
        $this->uniqueFor = (int) config('proxy-manager.check.unique_for_seconds');
        $this->onQueue((string) config('proxy-manager.check.queue'));
    }

    public function uniqueId(): string
    {
        return 'proxy:'.$this->proxyId.':'.($this->checkGeneration ?? 'legacy');
    }

    public function handle(ProxyCheckerInterface $checker, ApplyProxyCheckResultAction $applyResult): void
    {
        $proxy = ProxyServer::query()->find($this->proxyId);

        if (! $proxy instanceof ProxyServer) {
            return;
        }

        if (! $this->isCurrentGeneration($proxy)) {
            return;
        }

        if ($this->checkGeneration === null) {
            $proxy->forceFill([
                'status' => ProxyStatus::Checking,
                'checking_started_at' => now(),
            ])->save();
        }

        $result = $checker->check($proxy->refresh());

        $applyResult->execute($proxy, $result, $this->source, $this->checkGeneration, true);
    }

    public function failed(Throwable $exception): void
    {
        $proxy = ProxyServer::query()->find($this->proxyId);

        if (! $proxy instanceof ProxyServer) {
            return;
        }

        if (! $this->isCurrentGeneration($proxy)) {
            return;
        }

        $finishedAt = CarbonImmutable::now();
        $startedAt = $proxy->checking_started_at?->toImmutable() ?? $finishedAt;
        $proxyUri = app(ProxyUriFactory::class)->make($proxy);
        $errorMessage = app(ProxyFailureSanitizer::class)->sanitize($exception->getMessage(), $proxy, $proxyUri);

        app(ApplyProxyCheckResultAction::class)->execute(
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
            $this->source,
            $this->checkGeneration,
            true,
        );
    }

    private function isCurrentGeneration(ProxyServer $proxy): bool
    {
        if ($this->checkGeneration !== null) {
            return $proxy->check_generation === $this->checkGeneration;
        }

        return blank($proxy->check_generation);
    }
}
