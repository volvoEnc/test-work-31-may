<?php

namespace Tests\Unit;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyProxyCheckResultActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_proxy_and_creates_online_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = $this->createProxyServer([
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now()->subSecond(),
            'failure_reason' => 'Previous failure.',
        ]);
        $startedAt = CarbonImmutable::parse('2026-05-31 11:59:58');
        $finishedAt = CarbonImmutable::parse('2026-05-31 11:59:59');

        $result = new ProxyCheckResult(
            ProxyStatus::Online,
            $startedAt,
            $finishedAt,
            123,
            204,
            null,
            null,
        );

        app(ApplyProxyCheckResultAction::class)->execute($proxy, $result, ProxyCheckSource::Manual);

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Online, $proxy->status);
        $this->assertNull($proxy->checking_started_at);
        $this->assertTrue($finishedAt->equalTo($proxy->last_checked_at));
        $this->assertTrue($finishedAt->equalTo($proxy->last_success_at));
        $this->assertSame(123, $proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);

        $check = ProxyCheck::query()->sole();
        $this->assertTrue($proxy->is($check->proxyServer));
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertSame(ProxyStatus::Online, $check->status);
        $this->assertTrue($startedAt->equalTo($check->started_at));
        $this->assertTrue($finishedAt->equalTo($check->finished_at));
        $this->assertSame(123, $check->response_time_ms);
        $this->assertSame(204, $check->http_status);
        $this->assertNull($check->error_code);
        $this->assertNull($check->error_message);
    }

    public function test_it_sanitizes_offline_failure_messages_before_persisting(): void
    {
        $proxy = $this->createProxyServer([
            'status' => ProxyStatus::Checking,
            'username' => 'raw-user',
            'password' => 'raw-pass',
            'checking_started_at' => now()->subMinute(),
        ]);
        $message = 'http://raw-user:raw-pass@example.com:8080 https://encoded%40user:p%40ss@example.com '.str_repeat('x', 520);
        $result = new ProxyCheckResult(
            ProxyStatus::Offline,
            CarbonImmutable::parse('2026-05-31 12:00:00'),
            CarbonImmutable::parse('2026-05-31 12:00:02'),
            null,
            null,
            ProxyCheckErrorCode::ConnectionFailed,
            $message,
        );

        app(ApplyProxyCheckResultAction::class)->execute($proxy, $result, ProxyCheckSource::Auto);

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertSame(ProxyCheckErrorCode::ConnectionFailed, $check->error_code);
        $this->assertNotNull($proxy->failure_reason);
        $this->assertSame($proxy->failure_reason, $check->error_message);
        $this->assertLessThanOrEqual(500, mb_strlen($check->error_message));
        $this->assertStringNotContainsString('raw-user:raw-pass', $check->error_message);
        $this->assertStringNotContainsString('encoded%40user:p%40ss', $check->error_message);
        $this->assertStringContainsString('://***@', $check->error_message);
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
