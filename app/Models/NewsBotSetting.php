<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsBotSetting extends Model
{
    protected $fillable = [
        'bot_token',
        'administrator_telegram_id',
        'service_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
        ];
    }
}
