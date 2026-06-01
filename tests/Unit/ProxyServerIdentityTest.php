<?php

namespace Tests\Unit;

use App\Data\ProxyEndpoint;
use App\Data\ProxyIdentity;
use App\Enums\ProxyScheme;
use App\Models\ProxyServer;
use PHPUnit\Framework\TestCase;

class ProxyServerIdentityTest extends TestCase
{
    public function test_proxy_endpoint_formats_display_address_with_encoded_username_and_ipv6_brackets(): void
    {
        $endpoint = new ProxyEndpoint(ProxyScheme::Socks5, '2001:db8::1', 1080, 'user name');

        $this->assertSame('socks5://user%20name@[2001:db8::1]:1080', $endpoint->displayAddress());
    }

    public function test_proxy_endpoint_formats_display_address_without_credentials_for_domain_host(): void
    {
        $endpoint = new ProxyEndpoint(ProxyScheme::Https, 'proxy.example', 8443);

        $this->assertSame('https://proxy.example:8443', $endpoint->displayAddress());
    }

    public function test_proxy_identity_builds_normalized_hash_without_password(): void
    {
        $left = ProxyIdentity::hashFor(ProxyScheme::Https, 'Proxy.EXAMPLE.com', 443, 'ProxyUser');
        $right = ProxyIdentity::hashFor('https', 'proxy.example.com', 443, 'proxyuser');

        $this->assertSame($left, $right);
        $this->assertSame(hash('sha256', 'https|proxy.example.com|443|proxyuser'), $left);
    }

    public function test_proxy_server_public_api_remains_compatible_with_extracted_helpers(): void
    {
        $proxyServer = new ProxyServer([
            'scheme' => ProxyScheme::Http,
            'host' => '2001:db8::5',
            'port' => '08080',
            'username' => 'User Name',
        ]);

        $this->assertSame('http://User%20Name@[2001:db8::5]:08080', $proxyServer->displayAddress());
        $this->assertSame(
            hash('sha256', 'http|2001:db8::5|8080|user name'),
            ProxyServer::identityHashFor(ProxyScheme::Http, '2001:db8::5', 8080, 'User Name'),
        );
    }

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
