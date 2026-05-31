<?php

namespace App\Actions\Proxies;

use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use Illuminate\Support\Facades\DB;

class ApplyProxyCheckResultAction
{
    public function execute(ProxyServer $proxy, ProxyCheckResult $result, ProxyCheckSource $source): void
    {
        DB::transaction(function () use ($proxy, $result, $source): void {
            /** @var ProxyServer $lockedProxy */
            $lockedProxy = ProxyServer::query()
                ->lockForUpdate()
                ->findOrFail($proxy->id);

            $errorMessage = $this->sanitize($result->errorMessage);
            $failureReason = $this->failureReason($result, $errorMessage);

            $lockedProxy->forceFill([
                'status' => $result->status,
                'checking_started_at' => null,
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

        return $result->errorCode?->value ?? 'Proxy check failed.';
    }

    private function sanitize(?string $message): ?string
    {
        if (! filled($message)) {
            return null;
        }

        $sanitized = preg_replace('/:\/\/[^\s\/@]+@/u', '://***@', (string) $message) ?? (string) $message;
        $sanitized = preg_replace('/(^|\s)(?![a-z][a-z0-9+.-]*:\/\/)[^\s:\/]+:[^\s@]+@/ui', '$1***@', $sanitized) ?? $sanitized;

        return mb_strimwidth($sanitized, 0, 500, '');
    }
}
