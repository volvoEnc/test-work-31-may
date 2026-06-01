<?php

namespace App\Actions\Proxies;

use App\Data\ApplyProxyCheckResultCommand;
use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Support\ProxyFailureSanitizer;
use Illuminate\Support\Facades\DB;

class ApplyProxyCheckResultAction
{
    public function __construct(private readonly ProxyFailureSanitizer $failureSanitizer) {}

    public function execute(ApplyProxyCheckResultCommand $command): void
    {
        DB::transaction(function () use ($command): void {
            /** @var ProxyServer $lockedProxy */
            $lockedProxy = ProxyServer::query()
                ->lockForUpdate()
                ->findOrFail($command->proxy->id);

            if ($command->guard?->allows($lockedProxy) === false) {
                return;
            }

            $result = $command->result;
            $errorMessage = $this->failureSanitizer->sanitize($result->errorMessage, $lockedProxy);
            $failureReason = $this->failureReason($result, $errorMessage);

            $lockedProxy->forceFill([
                'status' => $result->status,
                'checking_started_at' => null,
                'check_generation' => null,
                'check_source' => null,
                'check_job_token' => null,
                'check_job_source' => null,
                'last_checked_at' => $result->finishedAt,
                'response_time_ms' => $result->responseTimeMs,
                'failure_reason' => $result->status === ProxyStatus::Offline ? $failureReason : null,
            ]);

            if ($result->status === ProxyStatus::Online) {
                $lockedProxy->last_success_at = $result->finishedAt;
            }

            $lockedProxy->save();

            $lockedProxy->checks()->create([
                'source' => $command->source,
                'status' => $result->status,
                'started_at' => $result->startedAt,
                'finished_at' => $result->finishedAt,
                'response_time_ms' => $result->responseTimeMs,
                'http_status' => $result->httpStatus,
                'error_code' => $result->errorCode,
                'error_message' => $errorMessage,
            ]);
        });
    }

    private function failureReason(ProxyCheckResult $result, ?string $errorMessage): string
    {
        if (filled($errorMessage)) {
            return $errorMessage;
        }

        if ($result->errorCode instanceof ProxyCheckErrorCode) {
            return $result->errorCode->value;
        }

        return 'Proxy check failed.';
    }
}
