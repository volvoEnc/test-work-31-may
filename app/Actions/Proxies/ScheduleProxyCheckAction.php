<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyServer;
use Illuminate\Support\Str;

class ScheduleProxyCheckAction
{
    public function execute(ProxyServer $proxy, ProxyCheckSource $source): void
    {
        $checkGeneration = (string) Str::uuid();

        $proxy->forceFill([
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now(),
            'check_generation' => $checkGeneration,
            'check_source' => $source,
            'check_job_token' => null,
            'check_job_source' => null,
        ])->save();

        CheckProxyStatusJob::dispatch($proxy->id, $source, $checkGeneration)->afterCommit();
    }
}
