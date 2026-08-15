<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'mfa_secret', 'mfa_recovery_codes', 'mfa_enabled_at'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_ADMINISTRATOR, self::ROLE_OPERATOR, self::ROLE_VIEWER];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'array',
            'mfa_enabled_at' => 'datetime',
        ];
    }

    public function mfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null && is_string($this->mfa_secret) && $this->mfa_secret !== '';
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_OPERATOR => 'Оператор',
            self::ROLE_VIEWER => 'Наблюдатель',
            default => 'Администратор',
        };
    }
}
