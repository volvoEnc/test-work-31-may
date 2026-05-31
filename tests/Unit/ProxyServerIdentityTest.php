<?php

namespace Tests\Unit;

use App\Enums\ProxyScheme;
use App\Models\ProxyServer;
use PHPUnit\Framework\TestCase;

class ProxyServerIdentityTest extends TestCase
{
    public function test_it_builds_identity_hash_from_normalized_scheme_host_port_and_username(): void
    {
        $left = ProxyServer::identityHashFor(ProxyScheme::Http, 'EXAMPLE.COM', 8080, 'USER');
        $right = ProxyServer::identityHashFor('http', 'example.com', 8080, 'user');

        $this->assertSame($left, $right);
    }

    public function test_it_does_not_include_password_in_identity_hash(): void
    {
        $hash = ProxyServer::identityHashFor('socks5', '2001:db8::1', 1080, null);

        $this->assertSame(64, strlen($hash));
    }
}
