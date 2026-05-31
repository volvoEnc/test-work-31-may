<?php

namespace Tests\Unit;

use App\Enums\ProxyScheme;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyUriFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProxyUriFactoryTest extends TestCase
{
    #[DataProvider('schemeProvider')]
    public function test_it_builds_proxy_uri_for_supported_schemes(ProxyScheme $scheme, string $expectedScheme): void
    {
        $proxy = $this->proxyServer($scheme);

        $this->assertSame($expectedScheme.'://proxy.example:8080', (new ProxyUriFactory)->make($proxy));
    }

    public function test_it_rawurlencodes_credentials(): void
    {
        $proxy = $this->proxyServer(
            ProxyScheme::Http,
            username: 'user name',
            password: 'p@ss:word',
        );

        $this->assertSame('http://user%20name:p%40ss%3Aword@proxy.example:8080', (new ProxyUriFactory)->make($proxy));
    }

    public function test_it_wraps_ipv6_host_in_brackets(): void
    {
        $proxy = $this->proxyServer(ProxyScheme::Socks5, host: '2001:db8::1', port: 1080);

        $this->assertSame('socks5h://[2001:db8::1]:1080', (new ProxyUriFactory)->make($proxy));
    }

    public static function schemeProvider(): array
    {
        return [
            'http' => [ProxyScheme::Http, 'http'],
            'https' => [ProxyScheme::Https, 'https'],
            'socks4' => [ProxyScheme::Socks4, 'socks4'],
            'socks5' => [ProxyScheme::Socks5, 'socks5h'],
        ];
    }

    private function proxyServer(
        ProxyScheme $scheme,
        string $host = 'proxy.example',
        int $port = 8080,
        ?string $username = null,
        ?string $password = null,
    ): ProxyServer {
        return new ProxyServer([
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ]);
    }
}
