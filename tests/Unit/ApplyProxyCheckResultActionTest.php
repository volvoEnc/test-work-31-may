<?php

namespace Tests\Unit;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Application\Proxies\Data\ApplyProxyCheckResultCommand;
use App\Application\Proxies\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use App\Support\Proxies\ProxyCheckGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApplyProxyCheckResultActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_check_guard_allows_and_denies_expected_null_fields(): void
    {
        $proxy = ProxyServer::factory()->checking()->create([
            'check_generation' => null,
            'check_source' => null,
            'check_job_token' => null,
        ]);

        $guard = ProxyCheckGuard::generation(null)
            ->withSource(null)
            ->withJobToken(null);

        $this->assertTrue($guard->allows($proxy->refresh()));

        $proxy->forceFill(['check_job_token' => 'claimed-token'])->save();

        $this->assertFalse($guard->allows($proxy->refresh()));
    }

    public function test_proxy_check_guard_rejects_conflicting_generation_expectations(): void
    {
        $guard = ProxyCheckGuard::generation('current-generation')
            ->withGeneration('current-generation');

        $this->expectException(InvalidArgumentException::class);

        $guard->withGeneration('stale-generation');
    }

    public function test_it_updates_proxy_and_creates_online_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_source' => ProxyCheckSource::Manual,
            'check_job_token' => 'claimed-token',
            'check_job_source' => ProxyCheckSource::Manual,
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

        app(ApplyProxyCheckResultAction::class)->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            ProxyCheckSource::Manual,
        ));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Online, $proxy->status);
        $this->assertNull($proxy->checking_started_at);
        $this->assertNull($proxy->check_generation);
        $this->assertNull($proxy->check_source);
        $this->assertNull($proxy->check_job_token);
        $this->assertNull($proxy->check_job_source);
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
        $proxy = ProxyServer::factory()->checking()->create([
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

        app(ApplyProxyCheckResultAction::class)->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            ProxyCheckSource::Auto,
        ));

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

    public function test_guarded_stale_expected_generation_does_not_overwrite_proxy_or_create_history(): void
    {
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'new-generation',
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $result = new ProxyCheckResult(
            ProxyStatus::Offline,
            CarbonImmutable::parse('2026-05-31 12:00:00'),
            CarbonImmutable::parse('2026-05-31 12:00:01'),
            null,
            null,
            ProxyCheckErrorCode::ConnectionFailed,
            'Old check failed.',
        );

        app(ApplyProxyCheckResultAction::class)->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            ProxyCheckSource::Auto,
            ProxyCheckGuard::generation('old-generation'),
        ));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('new-generation', $proxy->check_generation);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_guarded_null_expected_generation_does_not_apply_after_proxy_receives_generation(): void
    {
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'new-generation',
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $result = new ProxyCheckResult(
            ProxyStatus::Online,
            CarbonImmutable::parse('2026-05-31 12:00:00'),
            CarbonImmutable::parse('2026-05-31 12:00:01'),
            25,
            204,
            null,
            null,
        );

        app(ApplyProxyCheckResultAction::class)->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            ProxyCheckSource::Auto,
            ProxyCheckGuard::generation(null),
        ));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('new-generation', $proxy->check_generation);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_guarded_stale_expected_source_does_not_overwrite_proxy_or_create_history(): void
    {
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Manual,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $result = new ProxyCheckResult(
            ProxyStatus::Online,
            CarbonImmutable::parse('2026-05-31 12:00:00'),
            CarbonImmutable::parse('2026-05-31 12:00:01'),
            25,
            204,
            null,
            null,
        );

        app(ApplyProxyCheckResultAction::class)->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            ProxyCheckSource::Auto,
            ProxyCheckGuard::generation('current-generation')->withSource(ProxyCheckSource::Auto),
        ));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_guarded_stale_expected_job_token_does_not_overwrite_proxy_or_create_history(): void
    {
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Manual,
            'check_job_token' => 'replacement-token',
            'check_job_source' => ProxyCheckSource::Manual,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $result = new ProxyCheckResult(
            ProxyStatus::Online,
            CarbonImmutable::parse('2026-05-31 12:00:00'),
            CarbonImmutable::parse('2026-05-31 12:00:01'),
            25,
            204,
            null,
            null,
        );

        app(ApplyProxyCheckResultAction::class)->execute(new ApplyProxyCheckResultCommand(
            $proxy,
            $result,
            ProxyCheckSource::Manual,
            ProxyCheckGuard::generation('current-generation')
                ->withSource(ProxyCheckSource::Manual)
                ->withJobToken('original-token'),
        ));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertSame('replacement-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }
}
