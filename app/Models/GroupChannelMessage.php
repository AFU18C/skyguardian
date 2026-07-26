<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_channel_bot_id',
        'telegram_message_id',
        'telegram_user_id',
        'username',
        'text',
        'has_link',
        'matched_rule',
        'telegram_created_at',
        'delete_at',
        'deleted_at_telegram',
    ];

    protected function casts(): array
    {
        return [
            'has_link' => 'boolean',
            'telegram_created_at' => 'datetime',
            'delete_at' => 'datetime',
            'deleted_at_telegram' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
