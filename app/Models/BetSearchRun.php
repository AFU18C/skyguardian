<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BetSearchRun extends Model
{
    protected $fillable = ['status', 'search_mode', 'messages_found', 'bets_found', 'last_error', 'finished_at'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }
}
