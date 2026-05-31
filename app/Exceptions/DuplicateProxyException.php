<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicateProxyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Proxy already exists.');
    }
}
