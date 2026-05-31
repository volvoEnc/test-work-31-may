<?php

namespace Database\Factories;

use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyServer>
 */
class ProxyServerFactory extends Factory
{
    protected $model = ProxyServer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => null,
            'scheme' => ProxyScheme::Http,
            'host' => fake()->unique()->domainName(),
            'port' => 8080,
            'username' => null,
            'password' => null,
            'identity_hash' => '',
            'status' => ProxyStatus::Unknown,
            'checking_started_at' => null,
            'check_generation' => null,
            'last_checked_at' => null,
            'last_success_at' => null,
            'response_time_ms' => null,
            'failure_reason' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ProxyServer $proxyServer): void {
            $proxyServer->identity_hash = ProxyServer::identityHashFor(
                $proxyServer->scheme,
                $proxyServer->host,
                (int) $proxyServer->port,
                $proxyServer->username,
            );
        });
    }

    public function online(): static
    {
        return $this->state(fn (): array => [
            'status' => ProxyStatus::Online,
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'response_time_ms' => 100,
            'failure_reason' => null,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (): array => [
            'status' => ProxyStatus::Offline,
            'last_checked_at' => now(),
            'last_success_at' => null,
            'response_time_ms' => null,
            'failure_reason' => 'Connection failed.',
        ]);
    }

    public function checking(): static
    {
        return $this->state(fn (): array => [
            'status' => ProxyStatus::Checking,
            'checking_started_at' => now(),
            'check_generation' => fake()->uuid(),
        ]);
    }
}
