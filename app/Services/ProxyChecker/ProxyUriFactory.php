<?php

namespace App\Services\ProxyChecker;

use App\Enums\ProxyScheme;
use App\Models\ProxyServer;

class ProxyUriFactory
{
    public function make(ProxyServer $proxy): string
    {
        $scheme = match ($proxy->scheme) {
            ProxyScheme::Http => 'http',
            ProxyScheme::Https => 'https',
            ProxyScheme::Socks4 => 'socks4',
            ProxyScheme::Socks5 => 'socks5h',
        };

        $host = filter_var($proxy->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$proxy->host.']'
            : $proxy->host;

        $credentials = '';

        if (filled($proxy->username) || filled($proxy->password)) {
            $credentials = rawurlencode((string) $proxy->username);

            if (filled($proxy->password)) {
                $credentials .= ':'.rawurlencode((string) $proxy->password);
            }

            $credentials .= '@';
        }

        return "{$scheme}://{$credentials}{$host}:{$proxy->port}";
    }
}
