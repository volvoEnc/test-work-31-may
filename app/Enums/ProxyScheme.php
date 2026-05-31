<?php

namespace App\Enums;

enum ProxyScheme: string
{
    case Http = 'http';
    case Https = 'https';
    case Socks4 = 'socks4';
    case Socks5 = 'socks5';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
