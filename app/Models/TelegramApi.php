<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramApi extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'api_id', 'api_hash', 'is_active'];

    protected $hidden = ['api_hash'];

    protected function casts(): array
    {
        return [
            'api_id' => 'integer',
            'api_hash' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function technicalAccounts(): HasMany
    {
        return $this->hasMany(TechnicalAccount::class);
    }
}
