<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyServer;

class ScheduleProxyCheckAction
{
    public function execute(ProxyServer $proxy, ProxyCheckSource $source): void
    {
        $proxy->forceFill([
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now(),
        ])->save();

        CheckProxyStatusJob::dispatch($proxy->id, $source);
    }
}
