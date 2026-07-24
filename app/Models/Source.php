<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_account_id',
        'purpose',
        'name',
        'type',
        'identifier',
        'peer_id',
        'is_active',
        'publication_identifier',
        'publication_peer_id',
        'publication_format',
        'check_interval_seconds',
        'last_message_id',
        'next_check_at',
        'last_checked_at',
        'last_success_at',
        'last_manual_checked_at',
        'flood_wait_until',
        'is_available',
        'resume_from_latest',
        'consecutive_failures',
        'keywords',
        'stop_words',
        'append_custom_text',
        'custom_text',
        'remove_links',
        'remove_hashtags',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'append_custom_text' => 'boolean',
            'remove_links' => 'boolean',
            'remove_hashtags' => 'boolean',
            'is_available' => 'boolean',
            'resume_from_latest' => 'boolean',
            'last_message_id' => 'integer',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_manual_checked_at' => 'datetime',
            'flood_wait_until' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(NewsPublication::class);
    }

    public function isDue(): bool
    {
        return $this->is_active
            && (! $this->next_check_at || $this->next_check_at->isPast());
    }

    public function intervalLabel(): string
    {
        $seconds = (int) $this->check_interval_seconds;

        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            return intdiv($seconds, 3600).' ч.';
        }

        if ($seconds >= 60 && $seconds % 60 === 0) {
            return intdiv($seconds, 60).' мин.';
        }

        return $seconds.' сек.';
    }

    public function statusState(): string
    {
        if (! $this->is_active) {
            return 'off';
        }

        if (! $this->telegramAccount) {
            return 'off';
        }

        $accountState = $this->telegramAccount->statusState();

        if ($this->flood_wait_until?->isFuture() || $accountState === 'waiting') {
            return 'waiting';
        }

        if ($accountState === 'off') {
            return 'off';
        }

        if ($this->last_error || $accountState === 'error') {
            return 'error';
        }

        return 'working';
    }
}
