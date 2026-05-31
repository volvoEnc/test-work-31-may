<?php

namespace App\Jobs;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Actions\Proxies\ScheduleProxyCheckAction;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
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

    public function handle(ScheduleProxyCheckAction $scheduleProxyCheck, ApplyProxyCheckResultAction $applyResult): void
    {
        $now = CarbonImmutable::now();
        $staleCutoff = $now->subSeconds((int) config('proxy-manager.check.stale_after_seconds'));
        $dueCutoff = $now->subMinutes((int) config('proxy-manager.check.interval_minutes'));
        $staleProxyIds = [];

        ProxyServer::query()
            ->where('status', ProxyStatus::Checking)
            ->whereNotNull('checking_started_at')
            ->where('checking_started_at', '<=', $staleCutoff)
            ->orderBy('id')
            ->chunkById(100, function ($proxies) use ($applyResult, $now, &$staleProxyIds): void {
                foreach ($proxies as $proxy) {
                    $staleProxyIds[] = $proxy->id;
                    $startedAt = $proxy->checking_started_at?->toImmutable() ?? $now;

                    $applyResult->execute(
                        $proxy,
                        new ProxyCheckResult(
                            ProxyStatus::Offline,
                            $startedAt,
                            $now,
                            null,
                            null,
                            ProxyCheckErrorCode::StaleCheck,
                            'Proxy check became stale.',
                        ),
                        ProxyCheckSource::Auto,
                    );
                }
            });

        ProxyServer::query()
            ->where(function ($query) use ($dueCutoff): void {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', $dueCutoff);
            })
            ->where(function ($query) use ($staleCutoff): void {
                $query->where('status', '!=', ProxyStatus::Checking)
                    ->orWhereNull('checking_started_at')
                    ->orWhere('checking_started_at', '<=', $staleCutoff);
            })
            ->when($staleProxyIds !== [], fn ($query) => $query->whereNotIn('id', $staleProxyIds))
            ->orderBy('id')
            ->chunkById(100, function ($proxies) use ($scheduleProxyCheck): void {
                foreach ($proxies as $proxy) {
                    $scheduleProxyCheck->execute($proxy, ProxyCheckSource::Auto);
                }
            });
    }
}
