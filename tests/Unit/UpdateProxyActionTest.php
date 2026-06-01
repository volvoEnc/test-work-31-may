<?php

namespace Tests\Unit;

use App\Actions\Proxies\ScheduleProxyCheckAction;
use App\Actions\Proxies\UpdateProxyAction;
use App\Application\Proxies\Data\UpdateProxyCommand;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class UpdateProxyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_only_update_schedules_fresh_generation_without_changing_identity_hash(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        Bus::fake();
        $proxy = ProxyServer::factory()->online()->create([
            'username' => 'proxy-user',
            'password' => 'old-password',
            'last_checked_at' => now()->subMinute(),
            'response_time_ms' => 42,
            'failure_reason' => 'old failure',
        ]);
        $identityHash = $proxy->identity_hash;

        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy, new UpdateProxyCommand([
            'password' => 'new-password',
        ]));

        $this->assertSame($identityHash, $updatedProxy->identity_hash);
        $this->assertSame(ProxyStatus::Checking, $updatedProxy->status);
        $this->assertNull($updatedProxy->last_checked_at);
        $this->assertNull($updatedProxy->response_time_ms);
        $this->assertNull($updatedProxy->failure_reason);
        $this->assertNotNull($updatedProxy->checking_started_at);
        $this->assertNotNull($updatedProxy->check_generation);

        Bus::assertDispatched(CheckProxyStatusJob::class, fn (CheckProxyStatusJob $job): bool => $job->proxyId === $updatedProxy->id
            && $job->source === ProxyCheckSource::Manual
            && $job->checkGeneration === $updatedProxy->check_generation
            && $job->afterCommit === true);
    }

    public function test_sensitive_update_schedules_check_inside_database_transaction(): void
    {
        $proxy = ProxyServer::factory()->online()->create([
            'password' => 'old-password',
        ]);
        $expectedTransactionLevel = DB::transactionLevel() + 1;
        $action = new UpdateProxyAction(new class($expectedTransactionLevel) extends ScheduleProxyCheckAction
        {
            public function __construct(private readonly int $expectedTransactionLevel) {}

            public function execute(ProxyServer $proxy, ProxyCheckSource $source): void
            {
                Assert::assertSame(ProxyCheckSource::Manual, $source);
                Assert::assertGreaterThanOrEqual($this->expectedTransactionLevel, DB::transactionLevel());
            }
        });

        $action->execute($proxy, new UpdateProxyCommand([
            'password' => 'new-password',
        ]));
    }

    public function test_repeating_existing_sensitive_values_does_not_schedule_check_or_reset_status(): void
    {
        Bus::fake();
        $lastCheckedAt = CarbonImmutable::parse('2026-05-31 12:00:00');
        $proxy = ProxyServer::factory()->online()->create([
            'scheme' => 'http',
            'host' => 'same-sensitive.example.com',
            'port' => 8080,
            'username' => 'same-user',
            'password' => 'same-password',
            'last_checked_at' => $lastCheckedAt,
            'response_time_ms' => 42,
            'failure_reason' => 'kept failure',
        ]);

        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy, new UpdateProxyCommand([
            'scheme' => 'http',
            'host' => 'same-sensitive.example.com',
            'port' => 8080,
            'username' => 'same-user',
            'password' => 'same-password',
        ]));

        $this->assertSame(ProxyStatus::Online, $updatedProxy->status);
        $this->assertTrue($lastCheckedAt->equalTo($updatedProxy->last_checked_at));
        $this->assertSame(42, $updatedProxy->response_time_ms);
        $this->assertSame('kept failure', $updatedProxy->failure_reason);
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_unchanged_password_does_not_dirty_proxy_or_reencrypt_password(): void
    {
        Bus::fake();
        $createdAt = CarbonImmutable::parse('2026-05-31 11:00:00');
        $this->travelTo($createdAt);
        $proxy = ProxyServer::factory()->online()->create([
            'password' => 'same-password',
        ]);
        $rawPassword = $proxy->getRawOriginal('password');
        $updatedAt = $proxy->updated_at;

        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy, new UpdateProxyCommand([
            'password' => 'same-password',
        ]));

        $this->assertSame($rawPassword, $updatedProxy->getRawOriginal('password'));
        $this->assertTrue($updatedAt->equalTo($updatedProxy->updated_at));
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_explicit_null_password_when_current_password_is_null_does_not_schedule_check(): void
    {
        Bus::fake();
        $proxy = ProxyServer::factory()->online()->create([
            'password' => null,
            'last_checked_at' => CarbonImmutable::parse('2026-05-31 12:00:00'),
            'response_time_ms' => 42,
            'failure_reason' => 'kept failure',
        ]);

        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy, new UpdateProxyCommand([
            'password' => null,
        ]));

        $this->assertSame(ProxyStatus::Online, $updatedProxy->status);
        $this->assertSame(42, $updatedProxy->response_time_ms);
        $this->assertSame('kept failure', $updatedProxy->failure_reason);
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_invalid_legacy_password_can_be_overwritten_and_schedules_check(): void
    {
        Bus::fake();
        $proxy = ProxyServer::factory()->online()->create([
            'password' => 'old-password',
        ]);
        DB::table('proxy_servers')
            ->where('id', $proxy->id)
            ->update(['password' => 'legacy-raw-password']);

        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy->fresh(), new UpdateProxyCommand([
            'password' => 'new-password',
        ]));

        $this->assertSame('new-password', $updatedProxy->password);
        $this->assertSame(ProxyStatus::Checking, $updatedProxy->status);
        Bus::assertDispatched(CheckProxyStatusJob::class);
    }

    public function test_invalid_legacy_password_can_be_cleared_and_schedules_check(): void
    {
        Bus::fake();
        $proxy = ProxyServer::factory()->online()->create([
            'password' => 'old-password',
        ]);
        DB::table('proxy_servers')
            ->where('id', $proxy->id)
            ->update(['password' => 'legacy-raw-password']);

        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy->fresh(), new UpdateProxyCommand([
            'password' => null,
        ]));

        $this->assertNull($updatedProxy->password);
        $this->assertNull($updatedProxy->getRawOriginal('password'));
        $this->assertSame(ProxyStatus::Checking, $updatedProxy->status);
        Bus::assertDispatched(CheckProxyStatusJob::class);
    }
}
