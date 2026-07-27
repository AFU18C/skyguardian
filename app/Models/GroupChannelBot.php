<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupChannelBot extends Model
{
    use HasFactory;

    public const MODULES = [
        'publications' => 'Публикации',
        'drafts' => 'Черновики',
        'scheduled_publications' => 'Отложенные публикации',
        'auto_delete_publications' => 'Автоудаление публикаций',
        'polls' => 'Опросы и голосования',
        'bulk_delete' => 'Массовое удаление',
        'technical_account_bulk_delete' => 'Удаление через техаккаунт',
        'antispam' => 'Антиспам',
        'welcome' => 'Приветствие новых пользователей',
        'subscription_check' => 'Проверка подписки',
        'join_requests' => 'Заявки на вступление',
        'human_verification' => 'Проверка новых участников',
        'warnings' => 'Предупреждения и наказания',
        'newcomer_restrictions' => 'Ограничение новичков',
        'slow_mode' => 'Медленный режим',
    ];

    protected $fillable = [
        'bot_name', 'bot_token', 'token_fingerprint', 'webhook_secret',
        'webhook_registered_at', 'webhook_last_error', 'last_update_at',
        'admin_id', 'group_name', 'group_link', 'chat_type', 'chat_id',
        'bot_username', 'status', 'permissions', 'last_error',
        'last_manual_check_at', 'is_active', 'module_settings',
        'last_test_message_at', 'last_test_message_error',
    ];

    protected $hidden = ['bot_token', 'webhook_secret'];

    protected $attributes = [
        'chat_type' => 'group',
        'status' => 'not_checked',
        'is_active' => true,
        'module_settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'permissions' => 'array',
            'module_settings' => 'array',
            'last_manual_check_at' => 'datetime',
            'last_test_message_at' => 'datetime',
            'webhook_registered_at' => 'datetime',
            'last_update_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function publications(): HasMany
    {
        return $this->hasMany(GroupChannelPublication::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GroupChannelMessage::class);
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(GroupChannelUserState::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(GroupChannelJoinRequest::class);
    }

    public function technicalDeleteTasks(): HasMany
    {
        return $this->hasMany(GroupChannelTechnicalDeleteTask::class);
    }

    public function moduleEnabled(string $module): bool
    {
        return (bool) $this->moduleSetting($module, 'enabled', false);
    }

    public function moduleSetting(string $module, ?string $key = null, mixed $default = null): mixed
    {
        $settings = array_replace_recursive(
            self::defaultModuleSettings()[$module] ?? [],
            data_get($this->module_settings, $module, []),
        );

        return $key === null ? $settings : data_get($settings, $key, $default);
    }

    public static function defaultModuleSettings(): array
    {
        $defaults = collect(array_keys(self::MODULES))
            ->mapWithKeys(fn (string $module): array => [$module => ['enabled' => false]])
            ->all();

        $defaults['antispam'] += [
            'delete_links' => false,
            'delete_new_member_messages' => false,
            'new_member_minutes' => 10,
            'forbidden_words' => [],
            'message_limit' => 0,
            'message_limit_period_seconds' => 60,
            'block_duplicates' => false,
            'max_mentions' => 0,
            'delete_short_messages' => false,
            'min_length' => 2,
            'suspicious_symbols' => false,
        ];
        $defaults['welcome'] += [
            'text' => '',
            'photo' => null,
            'buttons' => [],
            'rules' => '',
            'delete_after_minutes' => null,
        ];
        $defaults['subscription_check'] += ['channels' => []];
        $defaults['join_requests'] += [
            'auto_approve' => false,
            'auto_decline_bots' => true,
        ];
        $defaults['human_verification'] += [
            'mode' => 'button',
            'question' => '',
            'answer' => '',
            'timeout_minutes' => 5,
        ];
        $defaults['warnings'] += [
            'mute_after' => 3,
            'mute_minutes' => 60,
            'ban_after' => 4,
        ];
        $defaults['newcomer_restrictions'] += [
            'minutes' => 10,
            'block_links' => true,
            'block_files' => false,
            'block_messages' => false,
        ];
        $defaults['slow_mode'] += [
            'messages' => 0,
            'period_seconds' => 60,
        ];

        return $defaults;
    }
}
