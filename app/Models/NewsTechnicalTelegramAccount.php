<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsTechnicalTelegramAccount extends Model
{
    protected $fillable = [
        'news_telegram_api_credential_id',
        'label',
        'phone',
        'name',
        'username',
        'telegram_id',
        'status',
        'is_primary',
        'last_error',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'is_primary' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function telegramApiCredential(): BelongsTo
    {
        return $this->belongsTo(NewsTelegramApiCredential::class, 'news_telegram_api_credential_id');
    }

    public function sessionKey(): string
    {
        return 'news_account_'.$this->getKey();
    }
}
