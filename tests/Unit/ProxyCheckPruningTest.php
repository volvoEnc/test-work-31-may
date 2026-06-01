<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyCheckPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_retention_days_config_is_30(): void
    {
        $this->assertSame(30, config('proxy-manager.check.retention_days'));
    }

    public function test_proxy_check_uses_laravel_mass_prunable_api(): void
    {
        $this->assertContains(MassPrunable::class, class_uses_recursive(ProxyCheck::class));
        $this->assertTrue(method_exists(ProxyCheck::class, 'prunable'));
    }

    public function test_prune_command_deletes_records_older_than_configured_retention_and_reports_count(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00:00'));

        config(['proxy-manager.check.retention_days' => 7]);

        $oldCheck = $this->createProxyCheck(now()->subDays(8));
        $boundaryCheck = $this->createProxyCheck(now()->subDays(7));
        $newCheck = $this->createProxyCheck(now()->subDays(6));

        $this->artisan('proxy-checks:prune')
            ->expectsOutput('Deleted 1 proxy check records.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('proxy_checks', ['id' => $oldCheck->id]);
        $this->assertDatabaseHas('proxy_checks', ['id' => $boundaryCheck->id]);
        $this->assertDatabaseHas('proxy_checks', ['id' => $newCheck->id]);
    }

    public function test_prunable_retention_is_clamped_to_at_least_one_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00:00'));

        config(['proxy-manager.check.retention_days' => 0]);

        $oldCheck = $this->createProxyCheck(now()->subDays(2));
        $sameDayCheck = $this->createProxyCheck(now()->subHours(12));

        $this->artisan('proxy-checks:prune')
            ->expectsOutput('Deleted 1 proxy check records.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('proxy_checks', ['id' => $oldCheck->id]);
        $this->assertDatabaseHas('proxy_checks', ['id' => $sameDayCheck->id]);
    }

    private function createProxyCheck(CarbonInterface $createdAt): ProxyCheck
    {
        $proxyServer = ProxyServer::factory()->create();

        return ProxyCheck::withoutTimestamps(fn (): ProxyCheck => ProxyCheck::forceCreate([
            'proxy_server_id' => $proxyServer->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Online,
            'started_at' => $createdAt,
            'finished_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]));
    }
}
