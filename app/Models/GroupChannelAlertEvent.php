<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelAlertEvent extends Model
{
    public const KIND_START = 'start';

    public const KIND_END = 'end';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'group_channel_bot_id',
        'event_key',
        'kind',
        'region_uid',
        'region_name',
        'alert_type',
        'event_at',
        'status',
        'attempts',
        'sending_started_at',
        'sent_at',
        'last_error',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'event_at' => UtcDateTime::class,
            'attempts' => 'integer',
            'sending_started_at' => UtcDateTime::class,
            'sent_at' => UtcDateTime::class,
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
