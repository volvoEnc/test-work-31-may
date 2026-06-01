<?php

namespace Tests\Unit;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Actions\Proxies\DispatchDueProxyChecksAction;
use App\Actions\Proxies\ResolveStaleProxyChecksAction;
use App\Data\ApplyProxyCheckResultCommand;
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
use ReflectionMethod;
use Tests\TestCase;

class DispatchDueProxyChecksJobTest extends TestCase
{
    use RefreshDatabase;

    private function handleJob(DispatchDueProxyChecksJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_job_delegates_stale_resolution_and_due_dispatch_without_sql_id_garland(): void
    {
        $handle = new ReflectionMethod(DispatchDueProxyChecksJob::class, 'handle');
        $parameterTypes = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            $handle->getParameters(),
        );
        $jobSource = file_get_contents(app_path('Jobs/DispatchDueProxyChecksJob.php'));

        $this->assertSame([ResolveStaleProxyChecksAction::class, DispatchDueProxyChecksAction::class], $parameterTypes);
        $this->assertStringNotContainsString('$staleProxyIds', $jobSource);
        $this->assertStringNotContainsString('whereNotIn', $jobSource);
    }

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

        $this->handleJob(app(DispatchDueProxyChecksJob::class));

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

    public function test_it_records_manual_stale_check_with_manual_source(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        config([
            'proxy-manager.check.stale_after_seconds' => 120,
            'proxy-manager.check.interval_minutes' => 5,
        ]);
        Bus::fake();
        $staleProxy = ProxyServer::factory()->checking()->create([
            'host' => 'manual-stale.example.com',
            'checking_started_at' => now()->subSeconds(121),
            'check_generation' => 'manual-generation',
            'check_source' => ProxyCheckSource::Manual,
            'check_job_token' => 'stale-token',
            'check_job_source' => ProxyCheckSource::Manual,
            'last_checked_at' => now()->subHour(),
        ]);

        $this->handleJob(app(DispatchDueProxyChecksJob::class));

        $staleProxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $staleProxy->status);
        $this->assertNull($staleProxy->check_source);
        $this->assertNull($staleProxy->check_job_token);
        $this->assertNull($staleProxy->check_job_source);
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
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
            'check_job_token' => 'stale-due-token',
            'check_job_source' => ProxyCheckSource::Manual,
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

        $this->handleJob(app(DispatchDueProxyChecksJob::class));

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
        $this->assertNull($dueProxy->check_job_token);
        $this->assertNull($dueProxy->check_job_source);
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

        $this->app->instance(
            ApplyProxyCheckResultAction::class,
            new class(app(ProxyFailureSanitizer::class)) extends ApplyProxyCheckResultAction
            {
                public function execute(
                    ApplyProxyCheckResultCommand $command,
                ): void {
                    $command->proxy->forceFill([
                        'status' => ProxyStatus::Checking,
                        'checking_started_at' => now(),
                        'check_generation' => 'new-generation',
                    ])->save();

                    parent::execute($command);
                }
            },
        );

        $this->handleJob(app(DispatchDueProxyChecksJob::class));

        $staleProxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $staleProxy->status);
        $this->assertSame('new-generation', $staleProxy->check_generation);
        $this->assertNull($staleProxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_stale_resolution_does_not_overwrite_proxy_if_source_changes_before_apply(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        config([
            'proxy-manager.check.stale_after_seconds' => 120,
            'proxy-manager.check.interval_minutes' => 5,
        ]);
        Bus::fake();
        $staleProxy = ProxyServer::factory()->checking()->create([
            'host' => 'manual-stale-race.example.com',
            'checking_started_at' => now()->subSeconds(121),
            'check_generation' => 'stale-generation',
            'check_source' => ProxyCheckSource::Manual,
            'last_checked_at' => now()->subHour(),
        ]);

        $this->app->instance(
            ApplyProxyCheckResultAction::class,
            new class(app(ProxyFailureSanitizer::class)) extends ApplyProxyCheckResultAction
            {
                public function execute(
                    ApplyProxyCheckResultCommand $command,
                ): void {
                    $command->proxy->forceFill([
                        'check_source' => ProxyCheckSource::Auto,
                    ])->save();

                    parent::execute($command);
                }
            },
        );

        $this->handleJob(app(DispatchDueProxyChecksJob::class));

        $staleProxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $staleProxy->status);
        $this->assertSame('stale-generation', $staleProxy->check_generation);
        $this->assertSame(ProxyCheckSource::Auto, $staleProxy->check_source);
        $this->assertNull($staleProxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }
}
