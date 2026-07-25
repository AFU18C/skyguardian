<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Source extends Model
{
    use HasFactory;

    public const TYPE_NEWS = 'news';

    public const TYPE_AIR_ALERT = 'air_alert';

    protected $fillable = [
        'technical_account_id', 'type', 'name', 'source_peer', 'destination_peer',
        'is_active', 'check_interval', 'check_interval_unit', 'next_check_at',
        'last_message_id', 'status', 'last_error', 'last_manual_check_at', 'last_success_at',
    ];

    protected $attributes = [
        'is_active' => false,
        'check_interval' => 60,
        'check_interval_unit' => 'seconds',
        'status' => 'not_checked',
    ];

    protected static function booted(): void
    {
        static::creating(function (Source $source): void {
            if (static::query()->count() >= config('skyguardian.limits.sources', 40)) {
                throw new RuntimeException('Достигнут лимит источников.');
            }

            if (! in_array($source->type, [self::TYPE_NEWS, self::TYPE_AIR_ALERT], true)) {
                throw new RuntimeException('Недопустимый тип источника.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'check_interval' => 'integer',
            'next_check_at' => 'datetime',
            'last_message_id' => 'integer',
            'last_manual_check_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function technicalAccount(): BelongsTo
    {
        return $this->belongsTo(TechnicalAccount::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(SourceRule::class)->orderBy('priority');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('technical_account_id')
            ->whereHas('technicalAccount', fn (Builder $q) => $q->where('is_active', true))
            ->where(fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));
    }
}
