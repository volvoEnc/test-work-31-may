<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\LaravelHttpProxyChecker;
use App\Services\ProxyChecker\ProxyUriFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LaravelHttpProxyCheckerTest extends TestCase
{
    public function test_it_returns_online_when_check_url_returns_success_status(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');
        config()->set('proxy-manager.check.success_status_codes', [204]);

        Http::fake([
            'https://check.example/' => Http::response('', 204),
        ]);

        $result = (new LaravelHttpProxyChecker(new ProxyUriFactory))->check($this->proxyServer());

        $this->assertSame(ProxyStatus::Online, $result->status);
        $this->assertSame(204, $result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->errorMessage);
        $this->assertIsInt($result->responseTimeMs);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://check.example/';
        });
    }

    public function test_it_returns_offline_timeout_when_request_times_out(): void
    {
        config()->set('proxy-manager.check.url', 'https://check.example/');

        Http::fake([
            'https://check.example/' => fn () => throw new ConnectionException('Connection timed out after 3000 milliseconds'),
        ]);

        $result = (new LaravelHttpProxyChecker(new ProxyUriFactory))->check($this->proxyServer());

        $this->assertSame(ProxyStatus::Offline, $result->status);
        $this->assertNull($result->httpStatus);
        $this->assertSame(ProxyCheckErrorCode::Timeout, $result->errorCode);
        $this->assertSame('Connection timed out after 3000 milliseconds', $result->errorMessage);
        $this->assertIsInt($result->responseTimeMs);
    }

    private function proxyServer(): ProxyServer
    {
        return new ProxyServer([
            'scheme' => ProxyScheme::Http,
            'host' => 'proxy.example',
            'port' => 8080,
        ]);
    }
}
