<?php

namespace App\Enums;

enum ProxyStatus: string
{
    case Unknown = 'unknown';
    case Checking = 'checking';
    case Online = 'online';
    case Offline = 'offline';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
