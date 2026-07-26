<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelPublication extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'group_channel_bot_id',
        'text',
        'status',
        'scheduled_at',
        'delete_after_minutes',
        'sent_at',
        'delete_at',
        'deleted_at_telegram',
        'telegram_message_id',
        'last_error',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delete_at' => 'datetime',
            'deleted_at_telegram' => 'datetime',
            'delete_after_minutes' => 'integer',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
