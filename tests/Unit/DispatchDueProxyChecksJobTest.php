<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Jobs\DispatchDueProxyChecksJob;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
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
        $staleProxy = $this->createProxyServer([
            'host' => 'stale.example.com',
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now()->subSeconds(121),
            'last_checked_at' => now()->subHour(),
        ]);

        app(DispatchDueProxyChecksJob::class)->handle(
            app(\App\Actions\Proxies\ScheduleProxyCheckAction::class),
            app(\App\Actions\Proxies\ApplyProxyCheckResultAction::class),
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
        $neverChecked = $this->createProxyServer([
            'host' => 'never.example.com',
            'last_checked_at' => null,
            'status' => ProxyStatus::Unknown,
        ]);
        $dueProxy = $this->createProxyServer([
            'host' => 'due.example.com',
            'last_checked_at' => now()->subMinutes(6),
            'status' => ProxyStatus::Online,
        ]);
        $freshProxy = $this->createProxyServer([
            'host' => 'fresh.example.com',
            'last_checked_at' => now()->subMinute(),
            'status' => ProxyStatus::Online,
        ]);
        $checkingProxy = $this->createProxyServer([
            'host' => 'checking.example.com',
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now()->subSeconds(30),
            'last_checked_at' => now()->subHour(),
        ]);

        app(DispatchDueProxyChecksJob::class)->handle(
            app(\App\Actions\Proxies\ScheduleProxyCheckAction::class),
            app(\App\Actions\Proxies\ApplyProxyCheckResultAction::class),
        );

        Bus::assertDispatched(CheckProxyStatusJob::class, 2);
        Bus::assertDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $neverChecked->id);
        Bus::assertDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $dueProxy->id);
        Bus::assertNotDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $freshProxy->id);
        Bus::assertNotDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $checkingProxy->id);

        $this->assertSame(ProxyStatus::Checking, $neverChecked->refresh()->status);
        $this->assertSame(ProxyStatus::Checking, $dueProxy->refresh()->status);
        $this->assertSame(ProxyStatus::Online, $freshProxy->refresh()->status);
        $this->assertSame(ProxyStatus::Checking, $checkingProxy->refresh()->status);
    }

    private function createProxyServer(array $overrides = []): ProxyServer
    {
        $attributes = array_merge([
            'scheme' => ProxyScheme::Http,
            'host' => 'example.com',
            'port' => 8080,
            'username' => null,
            'password' => null,
            'identity_hash' => ProxyServer::identityHashFor(ProxyScheme::Http, 'example.com', 8080, null),
            'status' => ProxyStatus::Unknown,
        ], $overrides);

        $attributes['identity_hash'] = ProxyServer::identityHashFor(
            $attributes['scheme'],
            $attributes['host'],
            (int) $attributes['port'],
            $attributes['username'],
        );

        return ProxyServer::create($attributes);
    }
}
