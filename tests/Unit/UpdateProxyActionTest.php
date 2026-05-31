<?php

namespace Tests\Unit;

use App\Actions\Proxies\ScheduleProxyCheckAction;
use App\Actions\Proxies\UpdateProxyAction;
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

        $updatedProxy = app(UpdateProxyAction::class)->execute($proxy, [
            'password' => 'new-password',
        ]);

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

        $action->execute($proxy, [
            'password' => 'new-password',
        ]);
    }
}
