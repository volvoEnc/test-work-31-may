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

        $this->assertSame(hash('sha256', 'socks5|2001:db8::1|1080|'), $hash);
    }

    public function test_it_detects_raw_stored_password_without_decrypting_it(): void
    {
        $proxyServer = new ProxyServer;
        $proxyServer->setRawAttributes([
            'username' => null,
            'password' => 'not-valid-encrypted-ciphertext',
        ], true);

        $this->assertTrue($proxyServer->hasCredentials());
    }
}
