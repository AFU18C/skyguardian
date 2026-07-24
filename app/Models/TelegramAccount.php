<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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
            'connected_at' => 'datetime',
        ];
    }

    protected function apiId(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): string {
                if (ctype_digit((string) $value)) {
                    return (string) $value;
                }

                try {
                    return Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    return '';
                }
            },
            set: fn (mixed $value): string => Crypt::encryptString((string) $value),
        );
    }

    protected function apiHash(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): string {
                if (preg_match('/^[a-f0-9]{32}$/i', (string) $value) === 1) {
                    return (string) $value;
                }

                try {
                    return Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    return '';
                }
            },
            set: fn (mixed $value): string => Crypt::encryptString((string) $value),
        );
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
