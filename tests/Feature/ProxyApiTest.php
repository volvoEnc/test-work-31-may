<?php

namespace Tests\Feature;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProxyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_paginated_proxy_list(): void
    {
        $this->createProxyServer(['host' => 'list.example.com']);

        $response = $this->getJson('/api/v1/proxies');

        $response
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.identity_hash')
            ->assertJsonMissingPath('data.0.check_generation');
    }

    public function test_it_filters_proxy_list_by_status_and_scheme(): void
    {
        $this->createProxyServer([
            'host' => 'http-online.example.com',
            'scheme' => ProxyScheme::Http,
            'status' => ProxyStatus::Online,
        ]);
        $this->createProxyServer([
            'host' => 'http-offline.example.com',
            'scheme' => ProxyScheme::Http,
            'status' => ProxyStatus::Offline,
        ]);
        $this->createProxyServer([
            'host' => 'socks-online.example.com',
            'scheme' => ProxyScheme::Socks5,
            'status' => ProxyStatus::Online,
        ]);

        $response = $this->getJson('/api/v1/proxies?status=online&scheme=http');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host', 'http-online.example.com')
            ->assertJsonPath('data.0.status', 'online')
            ->assertJsonPath('data.0.scheme', 'http');
    }

    public function test_it_searches_proxy_list_by_name_host_and_username(): void
    {
        $this->createProxyServer(['name' => 'Named Gateway', 'host' => 'name.example.com']);
        $this->createProxyServer(['host' => 'needle-host.example.com']);
        $this->createProxyServer(['host' => 'user.example.com', 'username' => 'needle-user']);
        $this->createProxyServer(['host' => 'miss.example.com']);

        $nameResponse = $this->getJson('/api/v1/proxies?search=Gateway');
        $hostResponse = $this->getJson('/api/v1/proxies?search=needle-host');
        $usernameResponse = $this->getJson('/api/v1/proxies?search=needle-user');

        $nameResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Named Gateway');
        $hostResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host', 'needle-host.example.com');
        $usernameResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'needle-user');
    }

    public function test_it_allows_expected_sort_fields_and_directions(): void
    {
        $this->createProxyServer([
            'host' => 'beta.example.com',
            'status' => ProxyStatus::Offline,
            'last_checked_at' => now()->subHour(),
        ]);
        $this->createProxyServer([
            'host' => 'alpha.example.com',
            'status' => ProxyStatus::Online,
            'last_checked_at' => now(),
        ]);

        $hostResponse = $this->getJson('/api/v1/proxies?sort=host&direction=asc');
        $statusResponse = $this->getJson('/api/v1/proxies?sort=status&direction=desc');
        $lastCheckedResponse = $this->getJson('/api/v1/proxies?sort=last_checked_at&direction=asc');
        $createdResponse = $this->getJson('/api/v1/proxies?sort=created_at&direction=desc');

        $hostResponse
            ->assertOk()
            ->assertJsonPath('data.0.host', 'alpha.example.com')
            ->assertJsonPath('data.1.host', 'beta.example.com');
        $statusResponse
            ->assertOk()
            ->assertJsonPath('data.0.status', 'online')
            ->assertJsonPath('data.1.status', 'offline');
        $lastCheckedResponse
            ->assertOk()
            ->assertJsonPath('data.0.host', 'beta.example.com')
            ->assertJsonPath('data.1.host', 'alpha.example.com');
        $createdResponse
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_rejects_invalid_proxy_list_query_parameters(): void
    {
        $invalidQueries = [
            'sort=port',
            'direction=sideways',
            'status=retired',
            'scheme=ftp',
            'page=0',
            'per_page=9',
            'per_page=101',
        ];

        foreach ($invalidQueries as $query) {
            $this->getJson("/api/v1/proxies?{$query}")
                ->assertUnprocessable();
        }
    }

    public function test_it_returns_pagination_metadata_and_respects_page_size(): void
    {
        for ($index = 1; $index <= 15; $index++) {
            $this->createProxyServer([
                'host' => "page-{$index}.example.com",
            ]);
        }

        $response = $this->getJson('/api/v1/proxies?per_page=10&page=2&sort=host&direction=asc');

        $response
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_it_creates_proxy_without_returning_password_and_queues_initial_check(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/v1/proxies', [
            'name' => 'Primary',
            'scheme' => 'http',
            'host' => 'create.example.com',
            'port' => 8080,
            'username' => 'proxy-user',
            'password' => 'secret',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.host', 'create.example.com')
            ->assertJsonPath('data.status', 'checking')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.identity_hash')
            ->assertJsonMissingPath('data.check_generation');

        Bus::assertDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_rejects_duplicate_proxy_identity(): void
    {
        Bus::fake();

        $this->createProxyServer([
            'scheme' => ProxyScheme::Http,
            'host' => 'duplicate.example.com',
            'port' => 8080,
            'username' => 'same-user',
        ]);

        $response = $this->postJson('/api/v1/proxies', [
            'scheme' => 'http',
            'host' => 'duplicate.example.com',
            'port' => 8080,
            'username' => 'same-user',
            'password' => 'different-password',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('message', 'Proxy already exists.')
            ->assertJsonPath('errors.host.0', 'A proxy with the same scheme, host, port and username already exists.');
    }

    public function test_it_rejects_duplicate_proxy_identity_on_update(): void
    {
        Bus::fake();
        $this->createProxyServer([
            'scheme' => ProxyScheme::Http,
            'host' => 'duplicate-update.example.com',
            'port' => 8080,
            'username' => 'same-user',
        ]);
        $proxy = $this->createProxyServer([
            'scheme' => ProxyScheme::Socks5,
            'host' => 'unique-update.example.com',
            'port' => 1080,
            'username' => 'other-user',
        ]);

        $response = $this->patchJson("/api/v1/proxies/{$proxy->id}", [
            'scheme' => 'http',
            'host' => 'duplicate-update.example.com',
            'port' => 8080,
            'username' => 'same-user',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('message', 'Proxy already exists.')
            ->assertJsonPath('errors.host.0', 'A proxy with the same scheme, host, port and username already exists.');

        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_updates_proxy_fields_and_queues_check_when_sensitive_data_changes(): void
    {
        Bus::fake();
        $proxy = $this->createProxyServer([
            'name' => 'Old',
            'port' => 8080,
            'status' => ProxyStatus::Online,
        ]);

        $response = $this->patchJson("/api/v1/proxies/{$proxy->id}", [
            'name' => 'New',
            'port' => 9090,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.port', 9090)
            ->assertJsonPath('data.status', 'checking')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.identity_hash')
            ->assertJsonMissingPath('data.check_generation');

        Bus::assertDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_preserves_password_when_omitted_from_update(): void
    {
        Bus::fake();
        $proxy = $this->createProxyServer(['password' => 'keep-me']);

        $this->patchJson("/api/v1/proxies/{$proxy->id}", [
            'name' => 'Renamed',
        ])->assertOk();

        $this->assertSame('keep-me', $proxy->refresh()->password);
        Bus::assertNotDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_clears_password_when_password_is_null(): void
    {
        Bus::fake();
        $proxy = $this->createProxyServer(['password' => 'clear-me']);

        $this->patchJson("/api/v1/proxies/{$proxy->id}", [
            'password' => null,
        ])->assertOk()
            ->assertJsonPath('data.status', 'checking');

        $this->assertNull($proxy->refresh()->password);
        Bus::assertDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_deletes_proxy_and_cascades_checks(): void
    {
        $proxy = $this->createProxyServer();
        ProxyCheck::create([
            'proxy_server_id' => $proxy->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Online,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $this->deleteJson("/api/v1/proxies/{$proxy->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('proxy_servers', ['id' => $proxy->id]);
        $this->assertDatabaseCount('proxy_checks', 0);
    }

    public function test_it_queues_manual_check_for_one_proxy(): void
    {
        Bus::fake();
        $proxy = $this->createProxyServer(['status' => ProxyStatus::Online]);

        $response = $this->postJson("/api/v1/proxies/{$proxy->id}/check");

        $response
            ->assertAccepted()
            ->assertJsonPath('data.id', $proxy->id)
            ->assertJsonPath('data.status', 'checking')
            ->assertJsonPath('data.queued', true);

        Bus::assertDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_queues_manual_checks_for_all_proxies(): void
    {
        Bus::fake();
        $this->createProxyServer(['host' => 'all.example.com']);

        $response = $this->postJson('/api/v1/proxies/check');

        $response
            ->assertAccepted()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.candidate_count', 1);

        Bus::assertDispatched(CheckProxyStatusJob::class);
    }

    public function test_it_returns_proxy_check_history(): void
    {
        $proxy = $this->createProxyServer();
        ProxyCheck::create([
            'proxy_server_id' => $proxy->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Online,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'response_time_ms' => 123,
            'http_status' => 200,
        ]);

        $response = $this->getJson("/api/v1/proxies/{$proxy->id}/checks");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'online')
            ->assertJsonPath('data.0.source', 'manual');
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    private function createProxyServer(array $overrides = []): ProxyServer
    {
        $attributes = array_merge([
            'name' => null,
            'scheme' => ProxyScheme::Http,
            'host' => 'example.com',
            'port' => 8080,
            'username' => null,
            'password' => null,
            'identity_hash' => ProxyServer::identityHashFor(ProxyScheme::Http, 'example.com', 8080, null),
            'status' => ProxyStatus::Unknown,
        ], $overrides);

        $attributes['identity_hash'] = ProxyServer::identityHashFor(
            $attributes['scheme'],
            $attributes['host'],
            (int) $attributes['port'],
            $attributes['username'],
        );

        return ProxyServer::create($attributes);
    }
}
