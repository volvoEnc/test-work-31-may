<?php

namespace App\Actions\Proxies;

use App\Application\Proxies\Data\UpdateProxyCommand;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use App\Exceptions\DuplicateProxyException;
use App\Models\ProxyServer;
use App\Support\UniqueConstraintViolationDetector;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type SensitiveKey 'scheme'|'host'|'port'|'username'|'password'
 */
class UpdateProxyAction
{
    /**
     * @var list<SensitiveKey>
     */
    private const SENSITIVE_KEYS = ['scheme', 'host', 'port', 'username', 'password'];

    public function __construct(private readonly ScheduleProxyCheckAction $scheduleProxyCheck) {}

    public function execute(ProxyServer $proxy, UpdateProxyCommand $command): ProxyServer
    {
        $data = $command->toPersistenceArray();
        $currentPassword = $command->has('password') ? $this->safeCurrentPassword($proxy) : null;
        $passwordUnreadable = $currentPassword === false;
        $passwordChanged = $command->has('password')
            && ($passwordUnreadable || $currentPassword !== $this->normalizeNullableString($command->value('password')));
        $sensitiveChanged = $this->hasSensitiveChange($proxy, $command, $passwordChanged);

        if ($command->has('password') && ! $passwordChanged) {
            unset($data['password']);
        }

        $nextScheme = $command->has('scheme') ? $command->value('scheme') : $proxy->scheme;
        $nextHost = $command->has('host') ? $command->value('host') : $proxy->host;
        $nextPort = $command->has('port') ? (int) $command->value('port') : (int) $proxy->port;
        $nextUsername = $command->has('username') ? $command->value('username') : $proxy->username;
        $data['identity_hash'] = ProxyServer::identityHashFor($nextScheme, $nextHost, $nextPort, $nextUsername);

        if ($sensitiveChanged) {
            $data['status'] = ProxyStatus::Unknown;
            $data['last_checked_at'] = null;
            $data['response_time_ms'] = null;
            $data['failure_reason'] = null;
        }

        return DB::transaction(function () use ($proxy, $data, $sensitiveChanged, $passwordUnreadable): ProxyServer {
            try {
                if ($passwordUnreadable && array_key_exists('password', $data) && $data['password'] !== null) {
                    $attributes = $proxy->getAttributes();
                    $attributes['password'] = null;
                    $proxy->setRawAttributes($attributes, true);
                }

                $proxy->fill($data)->save();
            } catch (QueryException $exception) {
                if (UniqueConstraintViolationDetector::detects($exception)) {
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

    private function hasSensitiveChange(ProxyServer $proxy, UpdateProxyCommand $command, bool $passwordChanged): bool
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (! $command->has($key)) {
                continue;
            }

            if ($key === 'password') {
                return $passwordChanged;
            }

            if ($this->sensitiveValue($proxy, $key) !== $this->normalizeSensitiveValue($key, $command->value($key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  SensitiveKey  $key
     */
    private function sensitiveValue(ProxyServer $proxy, string $key): mixed
    {
        return match ($key) {
            'scheme' => $this->normalizeScheme($proxy->scheme),
            'host' => $proxy->host,
            'port' => (int) $proxy->port,
            'username' => $this->normalizeNullableString($proxy->username),
            'password' => $this->safeCurrentPassword($proxy),
        };
    }

    /**
     * @param  SensitiveKey  $key
     */
    private function normalizeSensitiveValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'scheme' => $this->normalizeScheme($value),
            'host' => (string) $value,
            'port' => (int) $value,
            'username', 'password' => $this->normalizeNullableString($value),
        };
    }

    private function safeCurrentPassword(ProxyServer $proxy): string|null|false
    {
        try {
            return $this->normalizeNullableString($proxy->password);
        } catch (DecryptException) {
            return false;
        }
    }

    private function normalizeScheme(mixed $scheme): string
    {
        return $scheme instanceof ProxyScheme ? $scheme->value : (string) $scheme;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = $value === null ? null : (string) $value;

        return $value === '' ? null : $value;
    }
}
