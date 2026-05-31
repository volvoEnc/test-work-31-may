<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProxyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scheme' => $this->scheme->value,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'has_credentials' => $this->hasCredentials(),
            'display_address' => $this->displayAddress(),
            'status' => $this->status->value,
            'checking_started_at' => $this->checking_started_at?->toJSON(),
            'last_checked_at' => $this->last_checked_at?->toJSON(),
            'last_success_at' => $this->last_success_at?->toJSON(),
            'response_time_ms' => $this->response_time_ms,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
