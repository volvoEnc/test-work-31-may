<?php

namespace Tests\Unit;

use App\Data\ProxyIdentity;
use App\Enums\ProxyScheme;
use App\Models\ProxyServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyServerFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_computes_identity_hash_from_proxy_identity_fields(): void
    {
        $proxy = ProxyServer::factory()->create([
            'scheme' => ProxyScheme::Socks5,
            'host' => 'Factory.EXAMPLE.com',
            'port' => 1080,
            'username' => 'ProxyUser',
            'password' => 'not-part-of-identity',
        ]);

        $this->assertSame(
            ProxyIdentity::hashFor(ProxyScheme::Socks5, 'Factory.EXAMPLE.com', 1080, 'ProxyUser'),
            $proxy->identity_hash,
        );
    }
}
