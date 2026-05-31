<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use App\Queries\ProxyCheckIndexQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyCheckIndexQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scopes_latest_checks_to_the_proxy_and_paginates(): void
    {
        $proxy = ProxyServer::factory()->create();
        $otherProxy = ProxyServer::factory()->create();
        $checks = collect();

        for ($index = 0; $index < 11; $index++) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00')->addSeconds($index));
            $checks->push(ProxyCheck::create([
                'proxy_server_id' => $proxy->id,
                'source' => ProxyCheckSource::Manual,
                'status' => $index % 2 === 0 ? ProxyStatus::Online : ProxyStatus::Offline,
                'started_at' => now()->subSecond(),
                'finished_at' => now(),
            ]));
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:01:00'));
        ProxyCheck::create([
            'proxy_server_id' => $otherProxy->id,
            'source' => ProxyCheckSource::Manual,
            'status' => ProxyStatus::Online,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
        CarbonImmutable::setTestNow();

        $paginator = (new ProxyCheckIndexQuery)->paginate($proxy, [
            'per_page' => 10,
            'page' => 2,
        ]);

        $this->assertSame(11, $paginator->total());
        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame([$checks->first()->id], $paginator->getCollection()->pluck('id')->all());
        $this->assertNotContains($otherProxy->id, $paginator->getCollection()->pluck('proxy_server_id')->all());

        $firstPage = (new ProxyCheckIndexQuery)->paginate($proxy, [
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->assertSame(
            $checks->reverse()->take(10)->pluck('id')->values()->all(),
            $firstPage->getCollection()->pluck('id')->all(),
        );
    }

    public function test_it_defaults_to_twenty_checks_per_page(): void
    {
        $proxy = ProxyServer::factory()->create();

        $paginator = (new ProxyCheckIndexQuery)->paginate($proxy, []);

        $this->assertSame(20, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
    }
}
