<?php

namespace Tests\Unit;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckProxyStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_generation_job_does_not_check_apply_or_create_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = $this->createProxyServer([
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'new-generation',
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $checkerWasCalled = false;

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$checkerWasCalled): ProxyCheckerInterface {
            return new class($checkerWasCalled) implements ProxyCheckerInterface
            {
                public function __construct(private bool &$checkerWasCalled) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->checkerWasCalled = true;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        app(CheckProxyStatusJob::class, [
            'proxyId' => $proxy->id,
            'source' => ProxyCheckSource::Manual,
            'checkGeneration' => 'old-generation',
        ])->handle(app(ProxyCheckerInterface::class), app(ApplyProxyCheckResultAction::class));

        $proxy->refresh();
        $this->assertFalse($checkerWasCalled);
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('new-generation', $proxy->check_generation);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_legacy_null_generation_job_result_is_ignored_if_proxy_gets_generation_during_check(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = $this->createProxyServer([
            'status' => ProxyStatus::Unknown,
            'checking_started_at' => null,
            'check_generation' => null,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy): ProxyCheckerInterface {
            return new class($proxy->id) implements ProxyCheckerInterface
            {
                public function __construct(private readonly int $proxyId) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_generation' => 'new-generation']);

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        app(CheckProxyStatusJob::class, [
            'proxyId' => $proxy->id,
            'source' => ProxyCheckSource::Auto,
            'checkGeneration' => null,
        ])->handle(app(ProxyCheckerInterface::class), app(ApplyProxyCheckResultAction::class));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('new-generation', $proxy->check_generation);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_failed_sanitizes_raw_and_encoded_credentials_before_persisting(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = $this->createProxyServer([
            'username' => 'raw user',
            'password' => 'p@ss:word',
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
        ]);
        $message = 'Failure for http://raw%20user:p%40ss%3aword@example.com and raw user / p@ss:word token p%40ss%3aword '.str_repeat('x', 520);

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Manual, 'current-generation'))
            ->failed(new RuntimeException($message));

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertLessThanOrEqual(500, mb_strlen((string) $check->error_message));
        $this->assertStringNotContainsString('raw user', (string) $check->error_message);
        $this->assertStringNotContainsString('p@ss:word', (string) $check->error_message);
        $this->assertStringNotContainsString('raw%20user', (string) $check->error_message);
        $this->assertStringNotContainsString('p%40ss%3aword', (string) $check->error_message);
        $this->assertStringContainsString('://***@', (string) $check->error_message);
        $this->assertSame($proxy->failure_reason, $check->error_message);
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
