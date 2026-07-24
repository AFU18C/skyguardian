<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class TelegramApp extends Model
{
    protected $fillable = [
        'purpose',
        'name',
        'api_id',
        'api_hash',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function apiId(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function apiHash(): Attribute
    {
        return $this->encryptedAttribute();
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    public function statusState(): string
    {
        if (! $this->is_active) {
            return 'off';
        }

        if ($this->accounts->contains(fn (TelegramAccount $account): bool => $account->statusState() === 'error')) {
            return 'error';
        }

        if ($this->accounts->contains(fn (TelegramAccount $account): bool => $account->statusState() === 'waiting')) {
            return 'waiting';
        }

        if ($this->accounts->contains(fn (TelegramAccount $account): bool => $account->statusState() === 'working')) {
            return 'working';
        }

        return 'off';
    }

    private function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): string {
                if (blank($value)) {
                    return '';
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
}
