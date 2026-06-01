<?php

namespace App\Jobs;

use App\Actions\Proxies\RunProxyCheckAction;
use App\Enums\ProxyCheckSource;
use App\Queue\FailedProxyCheckLifecycleHandler;
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

    public function handle(RunProxyCheckAction $runProxyCheck): void
    {
        $runProxyCheck->execute($this->proxyId, $this->source, $this->checkGeneration, $this->checkJobToken);
    }

    public function failed(Throwable $exception): void
    {
        FailedProxyCheckLifecycleHandler::record($this->proxyId, $this->source, $this->checkJobToken, $exception);
    }
}
