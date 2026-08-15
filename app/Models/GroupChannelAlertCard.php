<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelAlertCard extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_SENDING = 'sending';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'group_channel_bot_id',
        'scope_region_uid',
        'alert_type',
        'snapshot_hash',
        'pending_snapshot_hash',
        'telegram_message_id',
        'pending_telegram_message_id',
        'delivery_status',
        'sending_started_at',
        'last_error',
        'started_at',
        'published_at',
    ];

    protected $attributes = [
        'delivery_status' => self::STATUS_SENT,
    ];

    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'pending_telegram_message_id' => 'integer',
            'sending_started_at' => UtcDateTime::class,
            'started_at' => UtcDateTime::class,
            'published_at' => UtcDateTime::class,
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
