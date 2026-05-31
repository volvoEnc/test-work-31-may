<?php

namespace Tests\Unit;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Actions\Proxies\ScheduleProxyCheckAction;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Jobs\DispatchDueProxyChecksJob;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use App\Support\ProxyFailureSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DispatchDueProxyChecksJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_stale_checking_proxies_offline_without_rescheduling_them(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        config([
            'proxy-manager.check.stale_after_seconds' => 120,
            'proxy-manager.check.interval_minutes' => 5,
        ]);
        Bus::fake();
        $staleProxy = ProxyServer::factory()->checking()->create([
            'host' => 'stale.example.com',
            'checking_started_at' => now()->subSeconds(121),
            'last_checked_at' => now()->subHour(),
        ]);

        app(DispatchDueProxyChecksJob::class)->handle(
            app(ScheduleProxyCheckAction::class),
            app(ApplyProxyCheckResultAction::class),
        );

        $staleProxy->refresh();
        $this->assertSame(ProxyStatus::Offline, $staleProxy->status);
        $this->assertNull($staleProxy->checking_started_at);
        $this->assertSame('Proxy check became stale.', $staleProxy->failure_reason);

        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $check->status);
        $this->assertSame(ProxyCheckSource::Auto, $check->source);
        $this->assertSame(ProxyCheckErrorCode::StaleCheck, $check->error_code);
        $this->assertSame('Proxy check became stale.', $check->error_message);
        $this->assertTrue(now()->subSeconds(121)->toImmutable()->equalTo($check->started_at));

        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_dispatches_due_proxies_and_skips_fresh_checking_proxies(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        config([
            'proxy-manager.check.stale_after_seconds' => 120,
            'proxy-manager.check.interval_minutes' => 5,
        ]);
        Bus::fake();
        $neverChecked = ProxyServer::factory()->create([
            'host' => 'never.example.com',
            'last_checked_at' => null,
            'status' => ProxyStatus::Unknown,
        ]);
        $dueProxy = ProxyServer::factory()->online()->create([
            'host' => 'due.example.com',
            'last_checked_at' => now()->subMinutes(6),
        ]);
        $freshProxy = ProxyServer::factory()->online()->create([
            'host' => 'fresh.example.com',
            'last_checked_at' => now()->subMinute(),
        ]);
        $checkingProxy = ProxyServer::factory()->checking()->create([
            'host' => 'checking.example.com',
            'checking_started_at' => now()->subSeconds(30),
            'last_checked_at' => now()->subHour(),
        ]);

        app(DispatchDueProxyChecksJob::class)->handle(
            app(ScheduleProxyCheckAction::class),
            app(ApplyProxyCheckResultAction::class),
        );

        Bus::assertDispatched(CheckProxyStatusJob::class, 2);
        Bus::assertDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $neverChecked->id
            && filled($job->checkGeneration)
            && $job->checkGeneration === $neverChecked->refresh()->check_generation);
        Bus::assertDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $dueProxy->id
            && filled($job->checkGeneration)
            && $job->checkGeneration === $dueProxy->refresh()->check_generation);
        Bus::assertNotDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $freshProxy->id);
        Bus::assertNotDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $checkingProxy->id);

        $this->assertSame(ProxyStatus::Checking, $neverChecked->refresh()->status);
        $this->assertSame(ProxyStatus::Checking, $dueProxy->refresh()->status);
        $this->assertSame(ProxyStatus::Online, $freshProxy->refresh()->status);
        $this->assertSame(ProxyStatus::Checking, $checkingProxy->refresh()->status);
    }

    public function test_stale_resolution_does_not_overwrite_proxy_rescheduled_after_selection(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        config([
            'proxy-manager.check.stale_after_seconds' => 120,
            'proxy-manager.check.interval_minutes' => 5,
        ]);
        Bus::fake();
        $staleProxy = ProxyServer::factory()->checking()->create([
            'host' => 'stale-race.example.com',
            'checking_started_at' => now()->subSeconds(121),
            'check_generation' => 'stale-generation',
            'last_checked_at' => now()->subHour(),
        ]);

        app(DispatchDueProxyChecksJob::class)->handle(
            app(ScheduleProxyCheckAction::class),
            new class(app(ProxyFailureSanitizer::class)) extends ApplyProxyCheckResultAction
            {
                public function execute(
                    ProxyServer $proxy,
                    ProxyCheckResult $result,
                    ProxyCheckSource $source,
                    ?string $expectedGeneration = null,
                    bool $guardGeneration = false,
                ): void {
                    $proxy->forceFill([
                        'status' => ProxyStatus::Checking,
                        'checking_started_at' => now(),
                        'check_generation' => 'new-generation',
                    ])->save();

                    parent::execute($proxy, $result, $source, $expectedGeneration, $guardGeneration);
                }
            },
        );

        $staleProxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $staleProxy->status);
        $this->assertSame('new-generation', $staleProxy->check_generation);
        $this->assertNull($staleProxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }
}
