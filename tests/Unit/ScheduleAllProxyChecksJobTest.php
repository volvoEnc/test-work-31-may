<?php

namespace Tests\Unit;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Jobs\ScheduleAllProxyChecksJob;
use App\Models\ProxyServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ScheduleAllProxyChecksJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_individual_manual_proxy_checks_when_handled(): void
    {
        Bus::fake();
        $firstProxy = ProxyServer::factory()->online()->create(['host' => 'first-manual.example.com']);
        $secondProxy = ProxyServer::factory()->offline()->create(['host' => 'second-manual.example.com']);

        app()->call([app(ScheduleAllProxyChecksJob::class), 'handle']);

        Bus::assertDispatched(CheckProxyStatusJob::class, 2);
        Bus::assertDispatched(CheckProxyStatusJob::class, function (CheckProxyStatusJob $job) use ($firstProxy): bool {
            return $job->proxyId === $firstProxy->id
                && $job->source === ProxyCheckSource::Manual
                && filled($job->checkGeneration)
                && $job->checkGeneration === $firstProxy->refresh()->check_generation
                && $job->afterCommit === true;
        });
        Bus::assertDispatched(CheckProxyStatusJob::class, function (CheckProxyStatusJob $job) use ($secondProxy): bool {
            return $job->proxyId === $secondProxy->id
                && $job->source === ProxyCheckSource::Manual
                && filled($job->checkGeneration)
                && $job->checkGeneration === $secondProxy->refresh()->check_generation
                && $job->afterCommit === true;
        });

        $this->assertSame(ProxyStatus::Checking, $firstProxy->refresh()->status);
        $this->assertSame(ProxyStatus::Checking, $secondProxy->refresh()->status);
        $this->assertSame(ProxyCheckSource::Manual, $firstProxy->check_source);
        $this->assertSame(ProxyCheckSource::Manual, $secondProxy->check_source);
    }
}
