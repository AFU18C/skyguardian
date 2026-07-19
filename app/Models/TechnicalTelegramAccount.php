<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalTelegramAccount extends Model
{
    protected $fillable = [
        'telegram_api_credential_id',
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

    protected static function booted(): void
    {
        static::deleting(function (TechnicalTelegramAccount $account): void {
            AlertSource::query()
                ->where('reader_account_id', $account->getKey())
                ->update([
                    'reader_account_id' => null,
                    'autopublish_enabled' => false,
                    'source_status' => 'not_checked',
                    'last_error' => 'Технический аккаунт чтения удалён. Выберите новый аккаунт.',
                ]);

            AlertSource::query()
                ->where('publisher_account_id', $account->getKey())
                ->update([
                    'publisher_account_id' => null,
                    'autopublish_enabled' => false,
                    'destination_status' => 'not_checked',
                    'last_error' => 'Технический аккаунт публикации удалён. Выберите новый аккаунт.',
                ]);
        });
    }

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
        return $this->belongsTo(TelegramApiCredential::class);
    }

    public function sessionKey(): string
    {
        return 'account_'.$this->getKey();
    }
}
