<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\LaravelHttpProxyChecker;
use App\Services\ProxyChecker\ProxyUriFactory;
use App\Support\ProxyFailureSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LaravelHttpProxyCheckerTest extends TestCase
{
    public function test_it_returns_online_when_check_url_returns_success_status(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');
        config()->set('proxy-manager.check.success_status_codes', [204]);

        $capturedOptions = null;

        Http::fake([
            'https://check.example/' => function ($request, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return Http::response('', 204);
            },
        ]);

        $result = $this->checker()->check($this->proxyServer());

        $this->assertSame(ProxyStatus::Online, $result->status);
        $this->assertSame(204, $result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->errorMessage);
        $this->assertIsInt($result->responseTimeMs);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://check.example/';
        });

        $this->assertSame('http://proxy.example:8080', $capturedOptions['proxy'] ?? null);
        $this->assertFalse($capturedOptions['allow_redirects'] ?? null);
    }

    public function test_it_returns_offline_timeout_when_request_times_out(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');

        Http::fake([
            'https://check.example/' => fn () => throw new ConnectionException('Connection timed out after 3000 milliseconds'),
        ]);

        $result = $this->checker()->check($this->proxyServer());

        $this->assertSame(ProxyStatus::Offline, $result->status);
        $this->assertNull($result->httpStatus);
        $this->assertSame(ProxyCheckErrorCode::Timeout, $result->errorCode);
        $this->assertSame('Connection timed out after 3000 milliseconds', $result->errorMessage);
        $this->assertIsInt($result->responseTimeMs);
    }

    public function test_it_classifies_http_407_response_as_proxy_auth_failed(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');

        Http::fake([
            'https://check.example/' => Http::response('Proxy Authentication Required', 407),
        ]);

        $result = $this->checker()->check($this->proxyServer());

        $this->assertSame(ProxyStatus::Offline, $result->status);
        $this->assertSame(407, $result->httpStatus);
        $this->assertSame(ProxyCheckErrorCode::ProxyAuthFailed, $result->errorCode);
        $this->assertSame('Proxy authentication failed.', $result->errorMessage);
    }

    public function test_it_sanitizes_proxy_credentials_from_exception_messages(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');

        $proxy = $this->proxyServer(username: 'user name', password: 'p@ss:word');
        $proxyUri = (new ProxyUriFactory)->make($proxy);

        Http::fake([
            'https://check.example/' => fn () => throw new ConnectionException(
                "Failed using {$proxyUri} with user name and p@ss:word against user%20name:p%40ss%3Aword@proxy.example"
            ),
        ]);

        $result = $this->checker()->check($proxy);

        $this->assertSame(ProxyStatus::Offline, $result->status);
        $this->assertStringNotContainsString($proxyUri, $result->errorMessage);
        $this->assertStringNotContainsString('user name', $result->errorMessage);
        $this->assertStringNotContainsString('p@ss:word', $result->errorMessage);
        $this->assertStringNotContainsString('user%20name', $result->errorMessage);
        $this->assertStringNotContainsString('p%40ss%3Aword', $result->errorMessage);
    }

    public function test_container_resolved_checker_uses_bound_failure_sanitizer(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');

        $this->app->bind(ProxyFailureSanitizer::class, fn (): ProxyFailureSanitizer => new class extends ProxyFailureSanitizer
        {
            public function sanitize(?string $message, ?ProxyServer $proxy = null, ?string $proxyUri = null): ?string
            {
                return 'sanitized-by-container';
            }
        });

        Http::fake([
            'https://check.example/' => fn () => throw new ConnectionException('raw failure details'),
        ]);

        $result = app(LaravelHttpProxyChecker::class)->check($this->proxyServer());

        $this->assertSame(ProxyStatus::Offline, $result->status);
        $this->assertSame('sanitized-by-container', $result->errorMessage);
    }

    private function proxyServer(?string $username = null, ?string $password = null): ProxyServer
    {
        return new ProxyServer([
            'scheme' => ProxyScheme::Http,
            'host' => 'proxy.example',
            'port' => 8080,
            'username' => $username,
            'password' => $password,
        ]);
    }

    private function checker(): LaravelHttpProxyChecker
    {
        return app(LaravelHttpProxyChecker::class);
    }
}
