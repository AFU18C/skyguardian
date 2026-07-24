<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramAccount extends Model
{
    protected $fillable = [
        'name',
        'api_id',
        'api_hash',
        'login_method',
        'phone',
        'telegram_name',
        'telegram_username',
        'status',
        'last_error',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'api_id' => 'encrypted',
            'api_hash' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    public function sessionPath(): string
    {
        return storage_path('app/telegram/accounts/'.$this->id.'/session.madeline');
    }

    public function statusState(): string
    {
        return match ($this->status) {
            'connected' => 'working',
            'error' => 'error',
            default => 'off',
        };
    }
}
