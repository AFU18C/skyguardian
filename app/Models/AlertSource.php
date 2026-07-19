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
        'last_error',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'autopublish_enabled' => 'boolean',
            'text_processing_enabled' => 'boolean',
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