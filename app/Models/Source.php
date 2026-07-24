<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_account_id',
        'name',
        'type',
        'identifier',
        'peer_id',
        'is_active',
        'publication_identifier',
        'publication_format',
        'check_interval_seconds',
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
        ];
    }

    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    public function statusState(): string
    {
        if (! $this->is_active) {
            return 'off';
        }

        if ($this->last_error || $this->telegramAccount?->status !== 'connected') {
            return 'error';
        }

        return 'working';
    }
}
