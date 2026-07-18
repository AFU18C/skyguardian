<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'address',
        'publication_chat',
        'check_interval',
    ];

    protected function casts(): array
    {
        return [
            'check_interval' => 'integer',
        ];
    }
}
