<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRule extends Model
{
    use HasFactory;

    protected $fillable = ['source_id', 'key', 'value', 'is_active', 'priority'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
