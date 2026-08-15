<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    protected $fillable = [
        'user_id', 'event', 'route_name', 'method', 'path', 'target_type', 'target_id',
        'ip_address', 'user_agent', 'response_status', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'response_status' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
