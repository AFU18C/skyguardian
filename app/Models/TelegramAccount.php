<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class TelegramAccount extends Model
{
    protected $fillable = [
        'name',
        'purpose',
        'telegram_app_id',
        'api_id',
        'api_hash',
        'login_method',
        'phone',
        'telegram_name',
        'telegram_username',
        'status',
        'is_active',
        'last_error',
        'session_payload',
        'session_saved_at',
        'connected_at',
        'last_attempt_at',
        'last_success_at',
        'flood_wait_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'session_saved_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'flood_wait_until' => 'datetime',
        ];
    }

    protected function apiId(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): string {
                if (ctype_digit((string) $value)) {
                    return (string) $value;
                }

                try {
                    return Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    return '';
                }
            },
            set: fn (mixed $value): string => Crypt::encryptString((string) $value),
        );
    }

    protected function apiHash(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): string {
                if (preg_match('/^[a-f0-9]{32}$/i', (string) $value) === 1) {
                    return (string) $value;
                }

                try {
                    return Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    return '';
                }
            },
            set: fn (mixed $value): string => Crypt::encryptString((string) $value),
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?string {
                if (blank($value)) {
                    return null;
                }

                if (preg_match('/^\+?[0-9 ()-]+$/', (string) $value) === 1) {
                    return (string) $value;
                }

                try {
                    return Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    return null;
                }
            },
            set: fn (mixed $value): ?string => blank($value)
                ? null
                : Crypt::encryptString((string) $value),
        );
    }

    protected function sessionPayload(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?string {
                if (blank($value)) {
                    return null;
                }

                try {
                    return Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    return null;
                }
            },
            set: fn (mixed $value): ?string => blank($value)
                ? null
                : Crypt::encryptString((string) $value),
        );
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    public function telegramApp(): BelongsTo
    {
        return $this->belongsTo(TelegramApp::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function sessionPath(): string
    {
        $root = config('services.telegram.runtime_path');

        if (blank($root)) {
            $root = PHP_OS_FAMILY === 'Linux'
                ? '/dev/shm/skyguardian-telegram'
                : storage_path('framework/telegram-runtime');
        }

        return rtrim((string) $root, '/').'/'.$this->id.'.madeline';
    }

    public function apiIdValue(): string
    {
        return (string) ($this->telegramApp?->api_id ?: $this->api_id);
    }

    public function apiHashValue(): string
    {
        return (string) ($this->telegramApp?->api_hash ?: $this->api_hash);
    }

    public function statusState(): string
    {
        if (! $this->is_active
            || $this->status === 'disabled'
            || ($this->relationLoaded('telegramApp') && ! $this->telegramApp?->is_active)) {
            return 'off';
        }

        if ($this->flood_wait_until?->isFuture() || in_array($this->status, [
            'waiting_code',
            'waiting_password',
            'waiting_qr',
            'reconnecting',
            'rate_limited',
        ], true)) {
            return 'waiting';
        }

        return match ($this->status) {
            'connected' => 'working',
            'error' => 'error',
            default => 'off',
        };
    }
}
