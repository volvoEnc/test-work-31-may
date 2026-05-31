<?php

namespace App\Services\ProxyChecker;

use App\Data\ProxyCheckResult;
use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyStatus;
use App\Models\ProxyServer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class LaravelHttpProxyChecker implements ProxyCheckerInterface
{
    public function __construct(private readonly ProxyUriFactory $uriFactory)
    {
    }

    public function check(ProxyServer $proxy): ProxyCheckResult
    {
        $startedAt = CarbonImmutable::now();
        $started = hrtime(true);

        try {
            $response = Http::connectTimeout((int) config('proxy-manager.check.connect_timeout_seconds'))
                ->timeout((int) config('proxy-manager.check.timeout_seconds'))
                ->withOptions([
                    'proxy' => $this->uriFactory->make($proxy),
                    'allow_redirects' => false,
                ])
                ->get((string) config('proxy-manager.check.url'));

            $finishedAt = CarbonImmutable::now();
            $responseTimeMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $httpStatus = $response->status();
            $successCodes = config('proxy-manager.check.success_status_codes', [200, 204, 301, 302]);

            if (in_array($httpStatus, $successCodes, true)) {
                return new ProxyCheckResult(
                    ProxyStatus::Online,
                    $startedAt,
                    $finishedAt,
                    $responseTimeMs,
                    $httpStatus,
                    null,
                    null,
                );
            }

            return new ProxyCheckResult(
                ProxyStatus::Offline,
                $startedAt,
                $finishedAt,
                $responseTimeMs,
                $httpStatus,
                ProxyCheckErrorCode::BadStatus,
                "Check URL returned HTTP {$httpStatus}.",
            );
        } catch (ConnectionException $exception) {
            return $this->failure($startedAt, $started, $this->classifyConnectionError($exception), $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->failure($startedAt, $started, ProxyCheckErrorCode::UnexpectedError, $exception->getMessage());
        }
    }

    private function failure(CarbonImmutable $startedAt, int $started, ProxyCheckErrorCode $code, string $message): ProxyCheckResult
    {
        return new ProxyCheckResult(
            ProxyStatus::Offline,
            $startedAt,
            CarbonImmutable::now(),
            (int) round((hrtime(true) - $started) / 1_000_000),
            null,
            $code,
            $this->sanitize($message),
        );
    }

    private function classifyConnectionError(ConnectionException $exception): ProxyCheckErrorCode
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => ProxyCheckErrorCode::Timeout,
            str_contains($message, '407') || str_contains($message, 'proxy authentication') => ProxyCheckErrorCode::ProxyAuthFailed,
            str_contains($message, 'ssl') || str_contains($message, 'certificate') => ProxyCheckErrorCode::SslError,
            str_contains($message, 'could not resolve') || str_contains($message, 'name lookup') => ProxyCheckErrorCode::DnsError,
            default => ProxyCheckErrorCode::ConnectionFailed,
        };
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/:\/\/[^@\s]+@/', '://***@', $message) ?? $message;

        return mb_strimwidth($message, 0, 500, '');
    }
}
