<?php

namespace App\Support;

use App\Models\ProxyServer;

class ProxyFailureSanitizer
{
    public function sanitize(?string $message, ?ProxyServer $proxy = null, ?string $proxyUri = null): ?string
    {
        if (! filled($message)) {
            return null;
        }

        $message = (string) $message;
        $redactions = array_filter([$proxyUri], fn (?string $value): bool => filled($value));

        if ($proxy instanceof ProxyServer) {
            foreach ([$proxy->username, $proxy->password] as $credential) {
                if (! filled($credential)) {
                    continue;
                }

                $credential = (string) $credential;
                $redactions[] = $credential;
                $redactions[] = rawurlencode($credential);
            }
        }

        $redactions = array_values(array_unique($redactions));
        usort($redactions, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($redactions as $redaction) {
            $message = str_replace($redaction, '***', $message);
        }

        $message = preg_replace('/:\/\/[^\s\/@]+@/u', '://***@', $message) ?? $message;
        $message = preg_replace('/(^|\s)(?![a-z][a-z0-9+.-]*:\/\/)[^\s:\/]+:[^\s@]+@/ui', '$1***@', $message) ?? $message;

        return mb_strimwidth($message, 0, 500, '');
    }
}
