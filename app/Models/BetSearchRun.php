<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BetSearchRun extends Model
{
    protected $fillable = [
        'status', 'search_mode', 'progress_percent', 'status_message', 'started_at',
        'messages_found', 'bets_found', 'last_error', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
