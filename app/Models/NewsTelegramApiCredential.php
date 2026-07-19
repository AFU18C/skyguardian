<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsTelegramApiCredential extends Model
{
    protected $fillable = ['label', 'api_id', 'api_hash', 'is_primary'];

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
        return $this->hasMany(NewsTechnicalTelegramAccount::class);
    }
}
