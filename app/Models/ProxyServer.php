<?php

namespace App\Models;

use App\Enums\ProxyScheme;
use App\Enums\ProxyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'last_checked_at',
        'last_success_at',
        'response_time_ms',
        'failure_reason',
    ];

    protected $hidden = [
        'password',
        'identity_hash',
    ];

    protected function casts(): array
    {
        return [
            'scheme' => ProxyScheme::class,
            'status' => ProxyStatus::class,
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
