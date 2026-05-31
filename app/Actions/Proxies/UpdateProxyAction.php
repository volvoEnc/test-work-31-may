<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Exceptions\DuplicateProxyException;
use App\Models\ProxyServer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateProxyAction
{
    private const SENSITIVE_KEYS = ['scheme', 'host', 'port', 'username', 'password'];

    public function __construct(private readonly ScheduleProxyCheckAction $scheduleProxyCheck) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(ProxyServer $proxy, array $data): ProxyServer
    {
        $sensitiveChanged = $this->hasSensitiveKey($data);

        if (! array_key_exists('password', $data)) {
            unset($data['password']);
        }

        $nextScheme = $data['scheme'] ?? $proxy->scheme;
        $nextHost = $data['host'] ?? $proxy->host;
        $nextPort = (int) ($data['port'] ?? $proxy->port);
        $nextUsername = array_key_exists('username', $data) ? $data['username'] : $proxy->username;
        $data['identity_hash'] = ProxyServer::identityHashFor($nextScheme, $nextHost, $nextPort, $nextUsername);

        if ($sensitiveChanged) {
            $data['status'] = ProxyStatus::Unknown;
            $data['last_checked_at'] = null;
            $data['response_time_ms'] = null;
            $data['failure_reason'] = null;
        }

        return DB::transaction(function () use ($proxy, $data, $sensitiveChanged): ProxyServer {
            try {
                $proxy->fill($data)->save();
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception)) {
                    throw new DuplicateProxyException;
                }

                throw $exception;
            }

            if ($sensitiveChanged) {
                $this->scheduleProxyCheck->execute($proxy, ProxyCheckSource::Manual);
            }

            return $proxy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasSensitiveKey(array $data): bool
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
