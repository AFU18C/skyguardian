<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsPublication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source_id',
        'telegram_account_id',
        'telegram_message_id',
        'grouped_id',
        'message_ids',
        'source_peer_id',
        'destination_peer_id',
        'dedupe_key',
        'status',
        'attempts',
        'available_at',
        'last_attempt_at',
        'published_at',
        'destination_message_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'available_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }
}
