<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertBotSetting extends Model
{
    protected $fillable = [
        'telegram_bot_token',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'telegram_bot_token' => 'encrypted',
            'is_enabled' => 'boolean',
        ];
    }
}
