<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class UniqueConstraintViolationDetector
{
    public static function detects(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '23505') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate entry');
    }
}
