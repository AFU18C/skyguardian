<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsBotSetting extends Model
{
    protected $fillable = [
        'bot_name',
        'bot_token',
        'administrator_telegram_id',
        'service_status',
        'last_error',
    ];

    protected $hidden = [
        'bot_token',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
        ];
    }
}
