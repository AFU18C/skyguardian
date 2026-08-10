<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetSearchRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'status',
        'search_mode',
        'requested_by_user_id',
        'attempts',
        'messages_found',
        'bets_found',
        'last_error',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
