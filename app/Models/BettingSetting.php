<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BettingSetting extends Model
{
    protected $fillable = [
        'technical_account_id', 'publication_bot_id', 'keywords', 'freshness_hours',
        'minimum_ai_score', 'maximum_results', 'primary_source_name', 'primary_source_url',
        'reserve_source_name', 'reserve_source_url', 'found_retention_days',
        'rejected_retention_days', 'completed_retention_days',
    ];

    protected function casts(): array
    {
        return ['keywords' => 'array'];
    }

    public function technicalAccount(): BelongsTo
    {
        return $this->belongsTo(TechnicalAccount::class);
    }

    public function publicationBot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'publication_bot_id');
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'keywords' => ['ставка дня', 'прогноз на футбол', 'прогноз на матч', 'тотал больше', 'тотал меньше', 'обе забьют', 'П1', 'П2', 'фора', 'двойной шанс', 'экспресс', 'коэффициент', 'уверенная ставка'],
        ]);
    }
}
