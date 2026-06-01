<?php

namespace App\Jobs;

use App\Actions\Proxies\ScheduleAllProxyChecksAction;
use App\Enums\ProxyCheckSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScheduleAllProxyChecksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ProxyCheckSource $source = ProxyCheckSource::Manual)
    {
        $this->onQueue((string) config('proxy-manager.check.queue'));
    }

    public function handle(ScheduleAllProxyChecksAction $scheduleAllProxyChecks): void
    {
        $scheduleAllProxyChecks->execute($this->source);
    }
}
