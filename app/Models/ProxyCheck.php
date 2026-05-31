<?php

namespace App\Models;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

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

    protected static function booted(): void
    {
        static::saving(function (self $check): void {
            $status = $check->status instanceof ProxyStatus
                ? $check->status
                : ProxyStatus::from((string) $check->status);

            if (! in_array($status, [ProxyStatus::Online, ProxyStatus::Offline], true)) {
                throw new InvalidArgumentException('Proxy check status must be online or offline.');
            }
        });
    }

    public function proxyServer(): BelongsTo
    {
        return $this->belongsTo(ProxyServer::class);
    }
}
