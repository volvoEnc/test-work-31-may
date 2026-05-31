<?php

namespace Tests\Unit;

use App\Enums\ProxyScheme;
use App\Models\ProxyServer;
use App\Queries\ProxyIndexQuery;
use App\Support\ProxyIndexSortOptions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyIndexQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_index_sort_options_are_centralized(): void
    {
        $this->assertTrue(class_exists(ProxyIndexSortOptions::class));
        $this->assertSame(['created_at', 'last_checked_at', 'status', 'host'], ProxyIndexSortOptions::allowedSorts());
        $this->assertSame(['asc', 'desc'], ProxyIndexSortOptions::allowedDirections());
        $this->assertSame('created_at', ProxyIndexSortOptions::defaultSort());
        $this->assertSame('desc', ProxyIndexSortOptions::defaultDirection());
        $this->assertSame('created_at', ProxyIndexSortOptions::normalizeSort('host; DROP TABLE proxy_servers'));
        $this->assertSame('desc', ProxyIndexSortOptions::normalizeDirection('sideways'));
    }

    public function test_it_filters_searches_sorts_and_paginates_proxies(): void
    {
        ProxyServer::factory()->online()->create([
            'name' => 'Primary Needle',
            'host' => 'zeta.example.com',
            'scheme' => ProxyScheme::Http,
        ]);
        ProxyServer::factory()->online()->create([
            'host' => 'alpha-needle.example.com',
            'scheme' => ProxyScheme::Http,
        ]);
        ProxyServer::factory()->online()->create([
            'host' => 'beta-needle.example.com',
            'scheme' => ProxyScheme::Socks5,
        ]);
        ProxyServer::factory()->offline()->create([
            'host' => 'gamma.example.com',
            'username' => 'needle-user',
            'scheme' => ProxyScheme::Http,
        ]);

        $paginator = (new ProxyIndexQuery)->paginate([
            'search' => 'needle',
            'scheme' => 'http',
            'status' => 'online',
            'sort' => 'host',
            'direction' => 'asc',
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->assertSame(2, $paginator->total());
        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertSame(
            ['alpha-needle.example.com', 'zeta.example.com'],
            $paginator->getCollection()->pluck('host')->all(),
        );
    }

    public function test_it_defaults_invalid_sort_and_direction_when_called_directly(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        ProxyServer::factory()->create(['host' => 'older.example.com']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:01'));
        ProxyServer::factory()->create(['host' => 'newer.example.com']);
        CarbonImmutable::setTestNow();

        $paginator = (new ProxyIndexQuery)->paginate([
            'sort' => 'host; DROP TABLE proxy_servers',
            'direction' => 'sideways',
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->assertSame(
            ['newer.example.com', 'older.example.com'],
            $paginator->getCollection()->pluck('host')->all(),
        );
    }
}
