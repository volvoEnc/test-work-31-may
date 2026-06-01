<?php

namespace App\Jobs;

use App\Actions\Proxies\DispatchDueProxyChecksAction;
use App\Actions\Proxies\ResolveStaleProxyChecksAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchDueProxyChecksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue((string) config('proxy-manager.check.queue'));
    }

    public function handle(
        ResolveStaleProxyChecksAction $resolveStaleProxyChecks,
        DispatchDueProxyChecksAction $dispatchDueProxyChecks,
    ): void {
        $resolveStaleProxyChecks->execute();
        $dispatchDueProxyChecks->execute();
    }
}
