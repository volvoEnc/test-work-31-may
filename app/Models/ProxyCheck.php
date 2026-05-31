<?php

namespace App\Models;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'proxy_server_id',
        'source',
        'status',
        'started_at',
        'finished_at',
        'response_time_ms',
        'http_status',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'source' => ProxyCheckSource::class,
            'status' => ProxyStatus::class,
            'error_code' => ProxyCheckErrorCode::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function proxyServer(): BelongsTo
    {
        return $this->belongsTo(ProxyServer::class);
    }
}
