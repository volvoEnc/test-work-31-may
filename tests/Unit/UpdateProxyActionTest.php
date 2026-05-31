<?php

namespace Tests\Unit;

use App\Actions\Proxies\UpdateProxyAction;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class UpdateProxyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_only_update_schedules_fresh_generation_without_changing_identity_hash(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        Bus::fake();
        $proxy = $this->createProxyServer([
            'username' => 'proxy-user',
            'password' => 'old-password',
            'status' => ProxyStatus::Online,
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
            && $job->checkGeneration === $updatedProxy->check_generation);
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
