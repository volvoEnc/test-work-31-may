<?php

namespace App\Actions\Proxies;

use App\Application\Proxies\Data\CreateProxyCommand;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Exceptions\DuplicateProxyException;
use App\Models\ProxyServer;
use App\Support\UniqueConstraintViolationDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateProxyAction
{
    public function __construct(private readonly ScheduleProxyCheckAction $scheduleProxyCheck) {}

    public function execute(CreateProxyCommand $command): ProxyServer
    {
        $data = $command->toPersistenceArray();
        $data['identity_hash'] = ProxyServer::identityHashFor(
            $data['scheme'],
            $data['host'],
            (int) $data['port'],
            $data['username'] ?? null,
        );
        $data['status'] = ProxyStatus::Unknown;

        return DB::transaction(function () use ($data): ProxyServer {
            try {
                $proxy = ProxyServer::create($data);
            } catch (QueryException $exception) {
                if (UniqueConstraintViolationDetector::detects($exception)) {
                    throw new DuplicateProxyException;
                }

                throw $exception;
            }

            $this->scheduleProxyCheck->execute($proxy, ProxyCheckSource::Manual);

            return $proxy->refresh();
        });
    }
}
