<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelUserState extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_channel_bot_id',
        'telegram_user_id',
        'warnings',
        'joined_at',
        'verified_at',
        'verification_answer',
        'verification_expires_at',
        'muted_until',
        'last_message_at',
        'window_started_at',
        'window_message_count',
        'last_text_hash',
    ];

    protected $attributes = [
        'warnings' => 0,
        'window_message_count' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $state): void {
            if (! $state->joined_at && ! $state->verification_expires_at && ! $state->verified_at) {
                $state->verified_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'warnings' => 'integer',
            'window_message_count' => 'integer',
            'joined_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'muted_until' => 'datetime',
            'last_message_at' => 'datetime',
            'window_started_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }
}
