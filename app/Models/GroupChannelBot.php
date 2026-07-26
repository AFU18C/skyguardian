<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'bot_name', 'bot_token', 'admin_id', 'group_name', 'group_link',
        'chat_type', 'chat_id', 'bot_username', 'status', 'permissions',
        'last_error', 'last_manual_check_at', 'is_active', 'module_settings',
        'last_test_message_at', 'last_test_message_error',
    ];

    protected $hidden = ['bot_token'];

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
            'is_active' => 'boolean',
        ];
    }

    public function moduleEnabled(string $module): bool
    {
        return (bool) data_get($this->module_settings, $module.'.enabled', false);
    }
}
