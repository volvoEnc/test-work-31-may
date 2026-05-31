<?php

use App\Jobs\DispatchDueProxyChecksJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DispatchDueProxyChecksJob, (string) config('proxy-manager.check.queue'))
    ->name('proxy-manager:dispatch-due-checks')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('proxy-checks:prune')
    ->daily()
    ->onOneServer();
