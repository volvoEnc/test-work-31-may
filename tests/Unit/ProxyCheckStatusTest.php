<?php

namespace Tests\Unit;

use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Exceptions\InvalidProxyCheckStatusException;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProxyCheckStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_online_check_status(): void
    {
        $proxyServer = ProxyServer::factory()->create();

        $check = ProxyCheck::create([
            'proxy_server_id' => $proxyServer->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Online,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->assertTrue($check->exists);
        $this->assertSame(ProxyStatus::Online, $check->status);
    }

    public function test_proxy_check_result_rejects_checking_as_persisted_status(): void
    {
        $this->assertRejectedPersistedStatus(ProxyStatus::Checking);
    }

    public function test_proxy_check_result_rejects_unknown_as_persisted_status(): void
    {
        $this->assertRejectedPersistedStatus(ProxyStatus::Unknown);
    }

    public function test_proxy_check_result_accepts_online_and_offline_persisted_statuses(): void
    {
        $this->assertSame(ProxyStatus::Online, $this->proxyCheckResultForStatus(ProxyStatus::Online)->status);
        $this->assertSame(ProxyStatus::Offline, $this->proxyCheckResultForStatus(ProxyStatus::Offline)->status);
    }

    public function test_database_rejects_non_terminal_check_statuses(): void
    {
        $proxyServer = ProxyServer::factory()->create();

        try {
            ProxyCheck::create([
                'proxy_server_id' => $proxyServer->id,
                'source' => ProxyCheckSource::Manual,
                'status' => ProxyStatus::Checking,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $this->fail('Database accepted a non-terminal proxy check status.');
        } catch (QueryException) {
            $this->assertSame(0, ProxyCheck::query()->count());
        }
    }

    public function test_proxy_check_status_column_uses_string_storage(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_05_31_113038_create_proxy_checks_table.php'));

        $this->assertStringContainsString("\$table->string('status', 20);", $migration);
        $this->assertStringNotContainsString("\$table->enum('status'", $migration);
    }

    public function test_proxy_check_status_column_is_string_backed_after_migrations(): void
    {
        $this->assertContains(Schema::getColumnType('proxy_checks', 'status'), ['string', 'varchar']);
    }

    public function test_proxy_check_status_has_forward_migration_to_string_storage(): void
    {
        $forwardMigrations = glob(database_path('migrations/*_change_proxy_checks_status_to_string.php'));

        $this->assertNotEmpty($forwardMigrations);

        $migration = file_get_contents($forwardMigrations[0]);

        $this->assertStringContainsString("Schema::table('proxy_checks'", $migration);
        $this->assertStringContainsString("\$table->string('status', 20)->change();", $migration);
    }

    public function test_proxy_checks_migration_does_not_duplicate_sqlite_create_table_sql(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_05_31_113038_create_proxy_checks_table.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/DB::statement\s*\(\s*["\']\s*CREATE\s+TABLE\s+proxy_checks\b/is',
            $migration,
        );
    }

    private function proxyCheckResultForStatus(ProxyStatus $status): ProxyCheckResult
    {
        return new ProxyCheckResult(
            $status,
            CarbonImmutable::parse('2026-05-31 12:00:00'),
            CarbonImmutable::parse('2026-05-31 12:00:01'),
            null,
            null,
            null,
            null,
        );
    }

    private function assertRejectedPersistedStatus(ProxyStatus $status): void
    {
        $this->expectException(InvalidProxyCheckStatusException::class);
        $this->expectExceptionMessage('Proxy check persisted status must be online or offline.');

        $this->proxyCheckResultForStatus($status);
    }
}
