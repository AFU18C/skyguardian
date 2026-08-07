<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelAlertCard extends Model
{
    protected $fillable = [
        'group_channel_bot_id',
        'scope_region_uid',
        'alert_type',
        'snapshot_hash',
        'telegram_message_id',
        'started_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'started_at' => UtcDateTime::class,
            'published_at' => UtcDateTime::class,
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
