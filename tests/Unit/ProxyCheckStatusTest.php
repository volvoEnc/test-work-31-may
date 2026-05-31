<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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

    public function test_it_rejects_checking_check_status(): void
    {
        $proxyServer = ProxyServer::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Proxy check status must be online or offline.');

        ProxyCheck::create([
            'proxy_server_id' => $proxyServer->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Checking,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function test_it_rejects_unknown_check_status(): void
    {
        $proxyServer = ProxyServer::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Proxy check status must be online or offline.');

        ProxyCheck::create([
            'proxy_server_id' => $proxyServer->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Unknown,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function test_database_rejects_invalid_check_status_when_bypassing_eloquent(): void
    {
        $proxyServer = ProxyServer::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('proxy_checks')->insert([
            'proxy_server_id' => $proxyServer->id,
            'source' => ProxyCheckSource::Manual->value,
            'status' => ProxyStatus::Checking->value,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
