<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class TechnicalAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_api_id', 'name', 'auth_method', 'phone', 'telegram_user_id',
        'username', 'first_name', 'last_name', 'session', 'auth_data', 'auth_expires_at',
        'status', 'last_error', 'last_manual_check_at', 'last_success_at', 'is_active',
    ];

    protected $hidden = ['session', 'auth_data'];

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (static::query()->count() >= config('skyguardian.limits.technical_accounts', 20)) {
                throw new RuntimeException('Достигнут лимит технических аккаунтов.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'session' => 'encrypted',
            'auth_data' => 'encrypted:array',
            'auth_expires_at' => 'datetime',
            'telegram_user_id' => 'integer',
            'last_manual_check_at' => 'datetime',
            'last_success_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function telegramApi(): BelongsTo
    {
        return $this->belongsTo(TelegramApi::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}