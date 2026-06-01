<?php

namespace Tests\Unit;

use App\Actions\Proxies\ApplyProxyCheckResultAction;
use App\Actions\Proxies\RecordFailedProxyCheckAction;
use App\Actions\Proxies\RunProxyCheckAction;
use App\Application\Proxies\Data\ProxyCheckResult;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Jobs\CheckProxyStatusJob;
use App\Models\ProxyCheck;
use App\Models\ProxyServer;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use App\Services\ProxyChecker\ProxyUriFactory;
use App\Support\ProxyFailureSanitizer;
use App\Support\Proxies\ProxyCheckGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class CheckProxyStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_coalesces_uniqueness_by_proxy_until_processing(): void
    {
        $job = new CheckProxyStatusJob(123, ProxyCheckSource::Manual, 'queued-generation');

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame('proxy:123', $job->uniqueId());
    }

    private function handleJob(CheckProxyStatusJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_job_dependencies_are_explicit_without_service_locator_calls(): void
    {
        $handle = new ReflectionMethod(CheckProxyStatusJob::class, 'handle');
        $parameterTypes = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            $handle->getParameters(),
        );
        $jobSource = file_get_contents(app_path('Jobs/CheckProxyStatusJob.php'));

        $this->assertSame([RunProxyCheckAction::class], $parameterTypes);
        $this->assertStringNotContainsString(
            'app(',
            $jobSource,
        );
        $this->assertStringNotContainsString(
            'resolve(',
            $jobSource,
        );
        $this->assertStringNotContainsString(
            'claimCurrentGeneration',
            $jobSource,
        );
        $this->assertStringNotContainsString(
            'ProxyCheckerInterface',
            $jobSource,
        );
    }

    public function test_handle_passes_queued_generation_to_action_as_contract_metadata(): void
    {
        $job = new CheckProxyStatusJob(123, ProxyCheckSource::Manual, 'queued-generation', 'queued-token');
        $runProxyCheck = Mockery::mock(RunProxyCheckAction::class);

        $runProxyCheck
            ->shouldReceive('execute')
            ->once()
            ->with(123, ProxyCheckSource::Manual, 'queued-generation', 'queued-token');

        $job->handle($runProxyCheck);
    }

    public function test_failed_lifecycle_service_locator_is_kept_out_of_the_domain_action(): void
    {
        $actionSource = file_get_contents(app_path('Actions/Proxies/RecordFailedProxyCheckLifecycleAction.php'));
        $jobSource = file_get_contents(app_path('Jobs/CheckProxyStatusJob.php'));

        $this->assertStringNotContainsString('static function record', $actionSource);
        $this->assertStringNotContainsString('app(self::class)', $actionSource);
        $this->assertStringContainsString('FailedProxyCheckLifecycleHandler', $jobSource);
    }

    public function test_stale_payload_generation_job_checks_current_active_generation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'new-generation',
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $checkedGeneration = null;

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$checkedGeneration): ProxyCheckerInterface {
            return new class($checkedGeneration) implements ProxyCheckerInterface
            {
                public function __construct(private ?string &$checkedGeneration) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->checkedGeneration = $proxy->check_generation;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(app(CheckProxyStatusJob::class, [
            'proxyId' => $proxy->id,
            'source' => ProxyCheckSource::Manual,
            'checkGeneration' => 'old-generation',
        ]));

        $proxy->refresh();
        $this->assertSame('new-generation', $checkedGeneration);
        $this->assertSame(ProxyStatus::Online, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertTrue(CarbonImmutable::parse('2026-05-31 12:00:01')->equalTo($proxy->last_checked_at));
        $this->assertSame(10, $proxy->response_time_ms);
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertSame(ProxyStatus::Online, $check->status);
    }

    public function test_stale_payload_source_uses_current_active_generation_source(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'stale-success-token';
        $claimedToken = null;
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'new-generation',
            'check_source' => ProxyCheckSource::Manual,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$claimedToken): ProxyCheckerInterface {
            return new class($claimedToken) implements ProxyCheckerInterface
            {
                public function __construct(private ?string &$claimedToken) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->claimedToken = $proxy->check_job_token;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'old-generation', $jobToken));

        $check = ProxyCheck::query()->sole();
        $this->assertSame($jobToken, $claimedToken);
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertNull($proxy->refresh()->check_source);
        $this->assertNull($proxy->check_job_token);
        $this->assertNull($proxy->check_job_source);
    }

    public function test_job_does_not_claim_or_process_if_source_changes_before_claim(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'check_job_token' => null,
            'check_job_source' => null,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $sourceChanged = false;
        $checkerWasCalled = false;

        DB::listen(function ($query) use ($proxy, &$sourceChanged): void {
            if ($sourceChanged || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'proxy_servers')) {
                return;
            }

            $sourceChanged = true;
            ProxyServer::query()
                ->whereKey($proxy->id)
                ->update(['check_source' => ProxyCheckSource::Manual]);
        });

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$checkerWasCalled): ProxyCheckerInterface {
            return new class($checkerWasCalled) implements ProxyCheckerInterface
            {
                public function __construct(private bool &$checkerWasCalled) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->checkerWasCalled = true;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', 'claim-source-token'));

        $proxy->refresh();
        $this->assertTrue($sourceChanged);
        $this->assertFalse($checkerWasCalled);
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertNull($proxy->check_job_token);
        $this->assertNull($proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_job_does_not_steal_existing_job_token_claim_for_same_generation_and_source(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'check_job_token' => 'other-worker-token',
            'check_job_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $checkerWasCalled = false;

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$checkerWasCalled): ProxyCheckerInterface {
            return new class($checkerWasCalled) implements ProxyCheckerInterface
            {
                public function __construct(private bool &$checkerWasCalled) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->checkerWasCalled = true;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', 'current-worker-token'));

        $proxy->refresh();
        $this->assertFalse($checkerWasCalled);
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_source);
        $this->assertSame('other-worker-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_result_does_not_apply_if_source_changes_during_checker_call(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy): ProxyCheckerInterface {
            return new class($proxy->id) implements ProxyCheckerInterface
            {
                public function __construct(private readonly int $proxyId) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_source' => ProxyCheckSource::Manual]);

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', 'apply-source-token'));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertSame('apply-source-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_success_result_does_not_apply_if_job_token_changes_during_checker_call(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy): ProxyCheckerInterface {
            return new class($proxy->id) implements ProxyCheckerInterface
            {
                public function __construct(private readonly int $proxyId) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_job_token' => 'replacement-token']);

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', 'original-token'));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_source);
        $this->assertSame('replacement-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_failure_result_does_not_apply_if_source_changes_during_checker_call(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);
        $exception = new RuntimeException('Source changed before failure.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy, $exception): ProxyCheckerInterface {
            return new class($proxy->id, $exception) implements ProxyCheckerInterface
            {
                public function __construct(
                    private readonly int $proxyId,
                    private readonly RuntimeException $exception,
                ) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_source' => ProxyCheckSource::Manual]);

                    throw $this->exception;
                }
            };
        });

        try {
            $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', 'failed-source-token'));
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertSame('failed-source-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_rehydrated_failed_lifecycle_does_not_record_after_handle_exception_when_source_changes_during_checker_call(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'failed-lifecycle-source-token';
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);
        $exception = new RuntimeException('Source changed before lifecycle failure.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy, $exception): ProxyCheckerInterface {
            return new class($proxy->id, $exception) implements ProxyCheckerInterface
            {
                public function __construct(
                    private readonly int $proxyId,
                    private readonly RuntimeException $exception,
                ) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_source' => ProxyCheckSource::Manual]);

                    throw $this->exception;
                }
            };
        });

        $job = new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', $jobToken);

        try {
            $this->handleJob($job);
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', $jobToken))
            ->failed($exception);

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertSame($jobToken, $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_failure_result_does_not_apply_if_job_token_changes_during_checker_call(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);
        $exception = new RuntimeException('Token changed before failure.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy, $exception): ProxyCheckerInterface {
            return new class($proxy->id, $exception) implements ProxyCheckerInterface
            {
                public function __construct(
                    private readonly int $proxyId,
                    private readonly RuntimeException $exception,
                ) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_job_token' => 'replacement-token']);

                    throw $this->exception;
                }
            };
        });

        try {
            $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', 'original-token'));
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_source);
        $this->assertSame('replacement-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Auto, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_legacy_null_source_is_claimed_and_processed_when_it_remains_null(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'legacy-null-source-token';
        $claimedToken = null;
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subMinute(),
            'check_generation' => 'current-generation',
            'check_source' => null,
            'check_job_token' => null,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$claimedToken): ProxyCheckerInterface {
            return new class($claimedToken) implements ProxyCheckerInterface
            {
                public function __construct(private ?string &$claimedToken) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->claimedToken = $proxy->check_job_token;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Manual, 'current-generation', $jobToken));

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame($jobToken, $claimedToken);
        $this->assertSame(ProxyStatus::Online, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertNull($proxy->check_source);
        $this->assertNull($proxy->check_job_token);
        $this->assertNull($proxy->check_job_source);
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
    }

    public function test_job_without_active_generation_does_not_check_apply_or_create_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->create([
            'status' => ProxyStatus::Unknown,
            'checking_started_at' => null,
            'check_generation' => null,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $checkerWasCalled = false;

        $this->app->bind(ProxyCheckerInterface::class, function () use (&$checkerWasCalled): ProxyCheckerInterface {
            return new class($checkerWasCalled) implements ProxyCheckerInterface
            {
                public function __construct(private bool &$checkerWasCalled) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    $this->checkerWasCalled = true;

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(app(CheckProxyStatusJob::class, [
            'proxyId' => $proxy->id,
            'source' => ProxyCheckSource::Auto,
            'checkGeneration' => null,
        ]));

        $proxy->refresh();
        $this->assertFalse($checkerWasCalled);
        $this->assertSame(ProxyStatus::Unknown, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_current_generation_result_is_ignored_if_proxy_gets_new_generation_during_check(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy): ProxyCheckerInterface {
            return new class($proxy->id) implements ProxyCheckerInterface
            {
                public function __construct(private readonly int $proxyId) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update(['check_generation' => 'new-generation']);

                    return new ProxyCheckResult(
                        ProxyStatus::Online,
                        CarbonImmutable::parse('2026-05-31 12:00:00'),
                        CarbonImmutable::parse('2026-05-31 12:00:01'),
                        10,
                        204,
                        null,
                        null,
                    );
                }
            };
        });

        $this->handleJob(app(CheckProxyStatusJob::class, [
            'proxyId' => $proxy->id,
            'source' => ProxyCheckSource::Auto,
            'checkGeneration' => 'current-generation',
        ]));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('new-generation', $proxy->check_generation);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_stale_payload_generation_job_records_failure_against_current_generation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'new-generation',
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);
        $exception = new RuntimeException('Checker exploded.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($exception): ProxyCheckerInterface {
            return new class($exception) implements ProxyCheckerInterface
            {
                public function __construct(private readonly RuntimeException $exception) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    throw $this->exception;
                }
            };
        });

        $job = new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Manual, 'old-generation');

        try {
            $this->handleJob($job);
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertNotNull($proxy->last_checked_at);
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertSame(ProxyStatus::Offline, $check->status);
        $this->assertSame('Checker exploded.', $check->error_message);

        $job->failed($exception);

        $this->assertSame(1, ProxyCheck::query()->count());
    }

    public function test_failed_records_current_active_generation_and_source_when_claimed_token_matches(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'claimed-failure-token';
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'new-generation',
            'check_source' => ProxyCheckSource::Manual,
            'check_job_token' => $jobToken,
            'check_job_source' => ProxyCheckSource::Manual,
            'last_checked_at' => null,
            'response_time_ms' => null,
        ]);

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Manual, 'old-generation', $jobToken))
            ->failed(new RuntimeException('Worker failed outside handle.'));

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertNull($proxy->check_source);
        $this->assertNull($proxy->check_job_source);
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertSame('Worker failed outside handle.', $check->error_message);
    }

    public function test_failed_records_current_source_when_stale_payload_source_has_matching_token(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'stale-source-failure-token';
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Manual,
            'check_job_token' => $jobToken,
            'check_job_source' => ProxyCheckSource::Manual,
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'current-generation', $jobToken))
            ->failed(new RuntimeException('Worker failed after stale payload source.'));

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertNull($proxy->check_source);
        $this->assertNull($proxy->check_job_token);
        $this->assertNull($proxy->check_job_source);
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertSame(ProxyStatus::Offline, $check->status);
        $this->assertSame('Worker failed after stale payload source.', $check->error_message);
        $this->assertNull($proxy->response_time_ms);
        $this->assertSame($proxy->failure_reason, $check->error_message);
    }

    public function test_failed_does_not_record_if_job_token_changes_before_failure_apply(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'claimed-failure-token';
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Manual,
            'check_job_token' => $jobToken,
            'check_job_source' => ProxyCheckSource::Manual,
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);

        $this->app->bind(
            RecordFailedProxyCheckAction::class,
            fn () => new class(app(ProxyUriFactory::class), app(ProxyFailureSanitizer::class), app(ApplyProxyCheckResultAction::class)) extends RecordFailedProxyCheckAction
            {
                public function execute(
                    int $proxyId,
                    ProxyCheckSource $source,
                    ?string $checkGeneration,
                    \Throwable $exception,
                    ?ProxyCheckGuard $guard = null,
                ): void {
                    ProxyServer::query()
                        ->whereKey($proxyId)
                        ->update(['check_job_token' => 'replacement-token']);

                    parent::execute(
                        $proxyId,
                        $source,
                        $checkGeneration,
                        $exception,
                        $guard,
                    );
                }
            },
        );

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Manual, 'current-generation', $jobToken))
            ->failed(new RuntimeException('Worker failed after token moved.'));

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('current-generation', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertSame('replacement-token', $proxy->check_job_token);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_job_source);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_rehydrated_failed_job_does_not_record_failure_after_proxy_was_rescheduled_to_new_generation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'generation-a-token';
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'generation-a',
            'check_source' => ProxyCheckSource::Auto,
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);
        $exception = new RuntimeException('Generation A failed after reschedule.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy, $exception): ProxyCheckerInterface {
            return new class($proxy->id, $exception) implements ProxyCheckerInterface
            {
                public function __construct(
                    private readonly int $proxyId,
                    private readonly RuntimeException $exception,
                ) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update([
                            'status' => ProxyStatus::Checking,
                            'checking_started_at' => CarbonImmutable::parse('2026-05-31 12:00:00'),
                            'check_generation' => 'generation-b',
                            'check_source' => ProxyCheckSource::Manual,
                            'check_job_token' => null,
                        ]);

                    throw $this->exception;
                }
            };
        });

        $job = new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'generation-a', $jobToken);

        try {
            $this->handleJob($job);
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'generation-a', $jobToken))
            ->failed($exception);

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('generation-b', $proxy->check_generation);
        $this->assertSame(ProxyCheckSource::Manual, $proxy->check_source);
        $this->assertNull($proxy->check_job_token);
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_failed_ignores_legacy_null_payload_after_handle_cleared_current_generation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
            'check_source' => ProxyCheckSource::Manual,
        ]);
        $exception = new RuntimeException('Checker exploded.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($exception): ProxyCheckerInterface {
            return new class($exception) implements ProxyCheckerInterface
            {
                public function __construct(private readonly RuntimeException $exception) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    throw $this->exception;
                }
            };
        });

        $job = new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, null);

        try {
            $this->handleJob($job);
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $job->failed($exception);

        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyCheckSource::Manual, $check->source);
        $this->assertSame(1, ProxyCheck::query()->count());
    }

    public function test_failed_check_does_not_overwrite_proxy_rescheduled_during_processing(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $proxy = ProxyServer::factory()->checking()->create([
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'generation-a',
            'last_checked_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ]);
        $exception = new RuntimeException('Generation A failed too late.');

        $this->app->bind(ProxyCheckerInterface::class, function () use ($proxy, $exception): ProxyCheckerInterface {
            return new class($proxy->id, $exception) implements ProxyCheckerInterface
            {
                public function __construct(
                    private readonly int $proxyId,
                    private readonly RuntimeException $exception,
                ) {}

                public function check(ProxyServer $proxy): ProxyCheckResult
                {
                    ProxyServer::query()
                        ->whereKey($this->proxyId)
                        ->update([
                            'status' => ProxyStatus::Checking,
                            'checking_started_at' => CarbonImmutable::parse('2026-05-31 12:00:00'),
                            'check_generation' => 'generation-b',
                            'check_job_token' => null,
                        ]);

                    throw $this->exception;
                }
            };
        });

        $job = new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Auto, 'generation-a');

        try {
            $this->handleJob($job);
            $this->fail('The proxy check exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $job->failed($exception);

        $proxy->refresh();
        $this->assertSame(ProxyStatus::Checking, $proxy->status);
        $this->assertSame('generation-b', $proxy->check_generation);
        $this->assertTrue(CarbonImmutable::parse('2026-05-31 12:00:00')->equalTo($proxy->checking_started_at));
        $this->assertNull($proxy->last_checked_at);
        $this->assertNull($proxy->response_time_ms);
        $this->assertNull($proxy->failure_reason);
        $this->assertSame(0, ProxyCheck::query()->count());
    }

    public function test_failed_sanitizes_raw_and_encoded_credentials_before_persisting(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31 12:00:00'));
        $jobToken = 'sanitize-failure-token';
        $proxy = ProxyServer::factory()->checking()->create([
            'username' => 'raw user',
            'password' => 'p@ss:word',
            'checking_started_at' => now()->subSecond(),
            'check_generation' => 'current-generation',
            'check_job_token' => $jobToken,
        ]);
        $message = 'Failure for http://raw%20user:p%40ss%3aword@example.com and raw user / p@ss:word token p%40ss%3aword '.str_repeat('x', 520);

        (new CheckProxyStatusJob($proxy->id, ProxyCheckSource::Manual, 'current-generation', $jobToken))
            ->failed(new RuntimeException($message));

        $proxy->refresh();
        $check = ProxyCheck::query()->sole();
        $this->assertSame(ProxyStatus::Offline, $proxy->status);
        $this->assertNull($proxy->check_generation);
        $this->assertLessThanOrEqual(500, mb_strlen((string) $check->error_message));
        $this->assertStringNotContainsString('raw user', (string) $check->error_message);
        $this->assertStringNotContainsString('p@ss:word', (string) $check->error_message);
        $this->assertStringNotContainsString('raw%20user', (string) $check->error_message);
        $this->assertStringNotContainsString('p%40ss%3aword', (string) $check->error_message);
        $this->assertStringContainsString('://***@', (string) $check->error_message);
        $this->assertSame($proxy->failure_reason, $check->error_message);
    }
}
