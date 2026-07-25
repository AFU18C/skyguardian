<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupChannelBot extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_name', 'bot_token', 'admin_id', 'group_name', 'group_link',
        'chat_type', 'chat_id', 'bot_username', 'status', 'permissions',
        'last_error', 'last_manual_check_at', 'is_active',
    ];

    protected $hidden = ['bot_token'];

    protected $attributes = [
        'chat_type' => 'group',
        'status' => 'not_checked',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'permissions' => 'array',
            'last_manual_check_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
