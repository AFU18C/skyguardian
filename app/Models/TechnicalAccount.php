<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicalAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_api_id', 'name', 'auth_method', 'phone', 'telegram_user_id',
        'username', 'first_name', 'last_name', 'session', 'status', 'last_error',
        'last_manual_check_at', 'last_success_at', 'is_active',
    ];

    protected $hidden = ['session'];

    protected function casts(): array
    {
        return [
            'session' => 'encrypted',
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
