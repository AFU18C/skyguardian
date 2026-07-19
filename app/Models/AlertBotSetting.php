<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertBotSetting extends Model
{
    protected $fillable = [
        'technical_phone',
        'technical_name',
        'technical_username',
        'technical_telegram_id',
        'technical_status',
        'bot_token',
        'administrator_telegram_id',
        'bot_status',
        'source_chat',
        'source_status',
        'destination_chat',
        'destination_status',
        'autopublish_enabled',
        'text_processing_enabled',
        'service_status',
        'last_received_at',
        'last_published_at',
        'last_error',
        'extra_settings',
    ];

    protected function casts(): array
    {
        return [
            'technical_phone' => 'encrypted',
            'bot_token' => 'encrypted',
            'autopublish_enabled' => 'boolean',
            'text_processing_enabled' => 'boolean',
            'last_received_at' => 'datetime',
            'last_published_at' => 'datetime',
            'extra_settings' => 'array',
        ];
    }
}
