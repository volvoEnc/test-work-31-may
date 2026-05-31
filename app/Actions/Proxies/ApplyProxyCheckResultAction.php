<?php

namespace App\Actions\Proxies;

use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use App\Support\ProxyFailureSanitizer;
use Illuminate\Support\Facades\DB;

class ApplyProxyCheckResultAction
{
    public function __construct(private readonly ProxyFailureSanitizer $failureSanitizer) {}

    public function execute(
        ProxyServer $proxy,
        ProxyCheckResult $result,
        ProxyCheckSource $source,
        ?string $expectedGeneration = null,
        bool $guardGeneration = false,
        ?ProxyCheckSource $expectedSource = null,
        bool $guardSource = false,
        ?string $expectedJobToken = null,
        bool $guardJobToken = false,
    ): void {
        DB::transaction(function () use ($proxy, $result, $source, $expectedGeneration, $guardGeneration, $expectedSource, $guardSource, $expectedJobToken, $guardJobToken): void {
            /** @var ProxyServer $lockedProxy */
            $lockedProxy = ProxyServer::query()
                ->lockForUpdate()
                ->findOrFail($proxy->id);

            if ($guardGeneration && $lockedProxy->check_generation !== $expectedGeneration) {
                return;
            }

            if ($guardSource && $lockedProxy->check_source !== $expectedSource) {
                return;
            }

            if ($guardJobToken && $lockedProxy->check_job_token !== $expectedJobToken) {
                return;
            }

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
                'source' => $source,
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
