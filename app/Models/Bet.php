<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bet extends Model
{
    public const STATUS_FOUND = 'found';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PUBLISHED = 'published';

    public const RESULTS = ['win', 'loss', 'refund', 'pending'];

    protected $fillable = [
        'fingerprint', 'status', 'sport', 'event_name', 'home_team', 'away_team', 'tournament',
        'starts_at', 'external_event_id', 'market', 'telegram_odds', 'primary_odds',
        'reserve_odds', 'selected_odds', 'selected_odds_source', 'ai_score', 'ai_reason',
        'telegram_sources', 'search_sources', 'odds_snapshot', 'odds_checked_at', 'publication_bot_id',
        'publication_text',
        'telegram_message_id', 'published_at', 'result', 'result_note', 'result_checked_at',
        'result_message_id', 'result_sent_at', 'edit_history',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $bet): void {
            $bet->publication_guard = in_array($bet->status, [self::STATUS_PUBLISHING, self::STATUS_PUBLISHED], true)
                ? $bet->fingerprint
                : null;
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'odds_checked_at' => 'datetime', 'published_at' => 'datetime',
            'result_checked_at' => 'datetime', 'result_sent_at' => 'datetime',
            'telegram_sources' => 'array', 'search_sources' => 'array', 'odds_snapshot' => 'array', 'edit_history' => 'array',
            'telegram_odds' => 'decimal:3', 'primary_odds' => 'decimal:3',
            'reserve_odds' => 'decimal:3', 'selected_odds' => 'decimal:3',
        ];
    }

    public function publicationBot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'publication_bot_id');
    }
}
