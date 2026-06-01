<?php

namespace App\Exceptions;

use App\Enums\ProxyStatus;
use DomainException;

final class InvalidProxyCheckStatusException extends DomainException
{
    public function __construct(public readonly ProxyStatus $status)
    {
        parent::__construct('Proxy check persisted status must be online or offline.');
    }
}
