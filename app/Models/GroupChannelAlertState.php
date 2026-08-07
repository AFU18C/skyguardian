<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelAlertState extends Model
{
    protected $fillable = [
        'group_channel_bot_id',
        'region_uid',
        'scope_region_uid',
        'region_name',
        'alert_type',
        'details',
        'source_alert_id',
        'started_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'source_alert_id' => 'integer',
            'started_at' => UtcDateTime::class,
            'last_seen_at' => UtcDateTime::class,
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
