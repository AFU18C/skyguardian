<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicalTelegramAccount extends Model
{
    protected $fillable = [
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

    public function sessionKey(): string
    {
        return 'account_'.$this->getKey();
    }
}
