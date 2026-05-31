<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Exceptions\DuplicateProxyException;
use App\Models\ProxyServer;
use Illuminate\Database\QueryException;

class CreateProxyAction
{
    public function __construct(private readonly ScheduleProxyCheckAction $scheduleProxyCheck)
    {
    }

    public function execute(array $data): ProxyServer
    {
        $data['identity_hash'] = ProxyServer::identityHashFor(
            $data['scheme'],
            $data['host'],
            (int) $data['port'],
            $data['username'] ?? null,
        );
        $data['status'] = ProxyStatus::Unknown;

        try {
            $proxy = ProxyServer::create($data);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new DuplicateProxyException();
            }

            throw $exception;
        }

        $this->scheduleProxyCheck->execute($proxy, ProxyCheckSource::Manual);

        return $proxy->refresh();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
