<?php

namespace Tests\Unit;

use App\Jobs\DispatchDueProxyChecksJob;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionFunction;
use ReflectionProperty;
use Tests\TestCase;

class ProxyCheckSchedulerTest extends TestCase
{
    public function test_due_proxy_check_dispatcher_runs_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->description === 'proxy-manager:dispatch-due-checks'
                && $event->getExpression() === '* * * * *')
            ->values();

        $this->assertCount(1, $events, 'Expected exactly one due proxy check dispatcher scheduled every minute.');

        $event = $events->first();

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertInstanceOf(DispatchDueProxyChecksJob::class, $this->scheduledJob($event));
    }

    private function scheduledJob(CallbackEvent $event): object|string|null
    {
        $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);

        return (new ReflectionFunction($callback))->getClosureUsedVariables()['job'] ?? null;
    }
}
