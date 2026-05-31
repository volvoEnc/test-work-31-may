<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProxyCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toJSON(),
            'finished_at' => $this->finished_at?->toJSON(),
            'response_time_ms' => $this->response_time_ms,
            'http_status' => $this->http_status,
            'error_code' => $this->error_code?->value,
            'error_message' => $this->error_message,
        ];
    }
}
