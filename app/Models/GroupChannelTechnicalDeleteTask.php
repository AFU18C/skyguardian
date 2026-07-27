<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupChannelTechnicalDeleteTask extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'group_channel_bot_id',
        'technical_account_id',
        'technical_account_name',
        'mode',
        'criteria',
        'status',
        'matched_count',
        'deleted_count',
        'failed_count',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'matched_count' => 0,
        'deleted_count' => 0,
        'failed_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'matched_count' => 'integer',
            'deleted_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(GroupChannelBot::class, 'group_channel_bot_id');
    }

    public function technicalAccount(): BelongsTo
    {
        return $this->belongsTo(TechnicalAccount::class);
    }
}
