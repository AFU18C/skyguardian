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

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const TYPE_TEXT = 'text';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_VIDEO = 'video';

    public const TYPE_ALBUM = 'album';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_POLL = 'poll';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_PHOTO,
        self::TYPE_VIDEO,
        self::TYPE_ALBUM,
        self::TYPE_DOCUMENT,
        self::TYPE_POLL,
    ];

    protected $fillable = [
        'group_channel_bot_id',
        'type',
        'text',
        'media_paths',
        'buttons',
        'reactions',
        'poll',
        'disable_notification',
        'status',
        'scheduled_at',
        'sending_started_at',
        'delete_after_minutes',
        'sent_at',
        'delete_at',
        'deleted_at_telegram',
        'deletion_attempts',
        'next_delete_attempt_at',
        'delete_failed_at',
        'telegram_message_id',
        'telegram_message_ids',
        'last_error',
    ];

    protected $attributes = [
        'type' => self::TYPE_TEXT,
        'status' => self::STATUS_DRAFT,
        'disable_notification' => false,
    ];

    protected function casts(): array
    {
        return [
            'media_paths' => 'array',
            'buttons' => 'array',
            'reactions' => 'array',
            'poll' => 'array',
            'telegram_message_ids' => 'array',
            'disable_notification' => 'boolean',
            'scheduled_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'delete_at' => 'datetime',
            'deleted_at_telegram' => 'datetime',
            'deletion_attempts' => 'integer',
            'next_delete_attempt_at' => 'datetime',
            'delete_failed_at' => 'datetime',
            'delete_after_minutes' => 'integer',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
