<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramApiCredential extends Model
{
    protected $fillable = ['label', 'api_id', 'api_hash', 'is_primary'];

    protected $hidden = ['api_id', 'api_hash'];

    protected static function booted(): void
    {
        static::saved(function (TelegramApiCredential $credential): void {
            if (! TelegramApiCredential::query()->where('is_primary', true)->exists()) {
                $credential->updateQuietly(['is_primary' => true]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'api_id' => 'encrypted',
            'api_hash' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    public function technicalAccounts(): HasMany
    {
        return $this->hasMany(TechnicalTelegramAccount::class);
    }
}
