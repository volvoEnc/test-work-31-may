<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

class DuplicateProxyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Proxy already exists.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'host' => ['A proxy with the same scheme, host, port and username already exists.'],
            ],
        ], Response::HTTP_CONFLICT);
    }
}
