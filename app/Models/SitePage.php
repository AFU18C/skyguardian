<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class SitePage extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'title',
        'slug',
        'heading',
        'excerpt',
        'status',
        'is_system',
        'system_key',
        'show_in_menu',
        'menu_label',
        'menu_order',
        'open_in_new_tab',
        'published_at',
        'featured_image_path',
        'social_image_path',
        'seo_title',
        'seo_description',
        'blocks',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'show_in_menu' => 'boolean',
        'open_in_new_tab' => 'boolean',
        'published_at' => 'datetime',
        'blocks' => 'array',
    ];

    public function menuItem(): HasOne
    {
        return $this->hasOne(SiteMenuItem::class);
    }

    public function scopeVisible(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= now();

        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $builder) use ($moment): void {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', $moment);
            });
    }

    public function publicUrl(): string
    {
        return $this->system_key === 'home' ? url('/') : url('/'.$this->slug);
    }

    public function effectiveHeading(): string
    {
        return $this->heading ?: $this->title;
    }

    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_PUBLISHED && $this->published_at?->isFuture()) {
            return 'Запланирована';
        }

        return match ($this->status) {
            self::STATUS_PUBLISHED => 'Опубликована',
            self::STATUS_HIDDEN => 'Скрыта',
            default => 'Черновик',
        };
    }
}
