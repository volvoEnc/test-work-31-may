<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;

class DispatchDueProxyChecksAction
{
    public function __construct(private readonly ScheduleProxyCheckAction $scheduleProxyCheck) {}

    public function execute(): void
    {
        $dueCutoff = CarbonImmutable::now()
            ->subMinutes((int) config('proxy-manager.check.interval_minutes'));

        ProxyServer::query()
            ->where(function ($query) use ($dueCutoff): void {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', $dueCutoff);
            })
            ->where(function ($query): void {
                $query->where('status', '!=', ProxyStatus::Checking)
                    ->orWhereNull('checking_started_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($proxies): void {
                foreach ($proxies as $proxy) {
                    $this->scheduleProxyCheck->execute($proxy, ProxyCheckSource::Auto);
                }
            });
    }
}
