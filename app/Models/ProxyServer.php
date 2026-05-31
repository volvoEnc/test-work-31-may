<?php

namespace App\Models;

use App\Enums\ProxyCheckSource;
use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $name
 * @property ProxyScheme $scheme
 * @property string $host
 * @property int $port
 * @property string|null $username
 * @property string|null $password
 * @property string $identity_hash
 * @property ProxyStatus $status
 * @property CarbonImmutable|null $checking_started_at
 * @property string|null $check_generation
 * @property ProxyCheckSource|null $check_source
 * @property string|null $check_job_token
 * @property ProxyCheckSource|null $check_job_source
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_success_at
 * @property int|null $response_time_ms
 * @property string|null $failure_reason
 */
class ProxyServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'scheme',
        'host',
        'port',
        'username',
        'password',
        'identity_hash',
        'status',
        'checking_started_at',
        'check_generation',
        'check_source',
        'check_job_token',
        'check_job_source',
        'last_checked_at',
        'last_success_at',
        'response_time_ms',
        'failure_reason',
    ];

    protected $hidden = [
        'password',
        'identity_hash',
        'check_generation',
        'check_source',
        'check_job_token',
        'check_job_source',
    ];

    protected function casts(): array
    {
        return [
            'scheme' => ProxyScheme::class,
            'status' => ProxyStatus::class,
            'check_source' => ProxyCheckSource::class,
            'check_job_source' => ProxyCheckSource::class,
            'password' => 'encrypted',
            'checking_started_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ProxyCheck::class);
    }

    public function hasCredentials(): bool
    {
        $rawPassword = $this->getRawOriginal('password') ?? ($this->getAttributes()['password'] ?? null);

        return filled($this->username) || filled($rawPassword);
    }

    public function displayAddress(): string
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$this->host.']'
            : $this->host;

        $credentials = filled($this->username) ? rawurlencode((string) $this->username).'@' : '';

        return $this->scheme->value.'://'.$credentials.$host.':'.$this->port;
    }

    public static function identityHashFor(string|ProxyScheme $scheme, string $host, int $port, ?string $username): string
    {
        $schemeValue = $scheme instanceof ProxyScheme ? $scheme->value : $scheme;

        return hash('sha256', implode('|', [
            strtolower($schemeValue),
            strtolower($host),
            (string) $port,
            strtolower($username ?? ''),
        ]));
    }
}
