<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupChannelBot extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_name',
        'bot_token',
        'admin_id',
        'group_name',
        'group_link',
        'is_active',
    ];

    protected $hidden = ['bot_token'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
