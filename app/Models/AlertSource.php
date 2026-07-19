<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSource extends Model
{
    protected $fillable = [
        'label',
        'source_chat',
        'destination_chat',
        'reader_account_id',
        'publisher_account_id',
        'autopublish_enabled',
        'text_processing_enabled',
        'source_status',
        'destination_status',
        'destination_type',
        'publish_as',
        'last_source_message_id',
        'last_received_at',
        'last_published_at',
        'last_error',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'autopublish_enabled' => 'boolean',
            'text_processing_enabled' => 'boolean',
            'last_source_message_id' => 'integer',
            'last_received_at' => 'datetime',
            'last_published_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function readerAccount(): BelongsTo
    {
        return $this->belongsTo(TechnicalTelegramAccount::class, 'reader_account_id');
    }

    public function publisherAccount(): BelongsTo
    {
        return $this->belongsTo(TechnicalTelegramAccount::class, 'publisher_account_id');
    }
}
