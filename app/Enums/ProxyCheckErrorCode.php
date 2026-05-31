<?php

namespace App\Enums;

enum ProxyCheckErrorCode: string
{
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case ProxyAuthFailed = 'proxy_auth_failed';
    case BadStatus = 'bad_status';
    case SslError = 'ssl_error';
    case DnsError = 'dns_error';
    case StaleCheck = 'stale_check';
    case UnexpectedError = 'unexpected_error';
}
