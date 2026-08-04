<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelAlertState extends Model
{
    protected $fillable = [
        'group_channel_bot_id',
        'region_uid',
        'region_name',
        'alert_type',
        'source_alert_id',
        'started_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'source_alert_id' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
