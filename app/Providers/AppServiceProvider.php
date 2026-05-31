<?php

namespace App\Providers;

use App\Services\ProxyChecker\LaravelHttpProxyChecker;
use App\Services\ProxyChecker\ProxyCheckerInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProxyCheckerInterface::class, LaravelHttpProxyChecker::class);
    }

    public function boot(): void
    {
    }
}
