<?php

namespace App\Models;

use App\Enums\ProxyCheckErrorCode;
use App\Enums\ProxyCheckSource;
use App\Enums\ProxyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $proxy_server_id
 * @property ProxyCheckSource $source
 * @property ProxyStatus $status
 * @property ProxyCheckErrorCode|null $error_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $response_time_ms
 * @property int|null $http_status
 * @property string|null $error_message
 */
class ProxyCheck extends Model
{
    /** @use HasFactory<Factory<ProxyCheck>> */
    use HasFactory;

    use MassPrunable;

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

    /**
     * @return BelongsTo<ProxyServer, $this>
     */
    public function proxyServer(): BelongsTo
    {
        return $this->belongsTo(ProxyServer::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<', now()->subDays($this->retentionDays()));
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('proxy-manager.check.retention_days', 30));
    }
}
