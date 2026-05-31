<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProxyCheckStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_online_check_status(): void
    {
        $proxyServer = $this->createProxyServer();

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
        $proxyServer = $this->createProxyServer();

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

    private function createProxyServer(): ProxyServer
    {
        return ProxyServer::create([
            'scheme' => ProxyScheme::Http,
            'host' => 'example.com',
            'port' => 8080,
            'identity_hash' => ProxyServer::identityHashFor(ProxyScheme::Http, 'example.com', 8080, null),
            'status' => ProxyStatus::Unknown,
        ]);
    }
}
