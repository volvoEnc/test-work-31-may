<?php

namespace Tests\Unit;

use App\Actions\Proxies\RecordFailedProxyCheckAction;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RecordFailedProxyCheckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_sanitized_failed_check_for_the_current_generation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'username' => 'raw user',
            'password' => 'p@ss:word',
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
        ]);
        $message = 'Failure for http://raw%20user:p%40ss%3aword@example.com and raw user / p@ss:word';

        app(RecordFailedProxyCheckAction::class)->execute(
            $proxy->id,
            ProxyCheckSource::Manual,
            'current-generation',
            new RuntimeException($message),
        );

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();

        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertStringNotContainsString('raw user', (string) $check->error_message);
        $this->assertStringNotContainsString('p@ss:word', (string) $check->error_message);
        $this->assertStringNotContainsString('raw%20user', (string) $check->error_message);
        $this->assertStringNotContainsString('p%40ss%3aword', (string) $check->error_message);
        $this->assertSame($proxy->failure_reason, $check->error_message);
    }

    public function test_it_ignores_stale_generation_failures(): void
    {
        $proxy = ProxyServer::factory()->checking()->create([
            'check_generation' => 'new-generation',
        ]);

        app(RecordFailedProxyCheckAction::class)->execute(
            $proxy->id,
            ProxyCheckSource::Auto,
            'old-generation',
            new RuntimeException('Queue failure'),
        );

        $this->assertSame(ProxyStatus::Checking, $proxy->refresh()->status);
        $this->assertSame('new-generation', $proxy->check_generation);
        $this->assertSame(0, ProxyCheck::query()->count());
    }
}
