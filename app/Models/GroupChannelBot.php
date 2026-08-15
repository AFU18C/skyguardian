<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupChannelBot extends Model
{
    use HasFactory;

    public const MODULE_ALERT_PUBLICATIONS = 'alert_publications';

    public const MODULES = [
        'publications' => 'Публикации',
        self::MODULE_ALERT_PUBLICATIONS => 'Публикация тревог',
        'drafts' => 'Черновики',
        'scheduled_publications' => 'Отложенные публикации',
        'auto_delete_publications' => 'Автоудаление публикаций',
        'polls' => 'Опросы и голосования',
        'bulk_delete' => 'Массовое удаление',
        'technical_account_bulk_delete' => 'Удаление через техаккаунт',
        'system_messages' => 'Удаление системных сообщений',
        'antispam' => 'Антиспам',
        'welcome' => 'Приветствие новых пользователей',
        'subscription_check' => 'Проверка подписки',
        'join_requests' => 'Заявки на вступление',
        'human_verification' => 'Проверка новых участников',
        'warnings' => 'Предупреждения и наказания',
        'newcomer_restrictions' => 'Ограничение новичков',
        'slow_mode' => 'Медленный режим',
    ];

    public const ALERT_REGIONS = [
        '29' => 'Автономна Республіка Крим',
        '8' => 'Волинська область',
        '4' => 'Вінницька область',
        '9' => 'Дніпропетровська область',
        '28' => 'Донецька область',
        '10' => 'Житомирська область',
        '11' => 'Закарпатська область',
        '12' => 'Запорізька область',
        '13' => 'Івано-Франківська область',
        '31' => 'м. Київ',
        '14' => 'Київська область',
        '15' => 'Кіровоградська область',
        '16' => 'Луганська область',
        '27' => 'Львівська область',
        '17' => 'Миколаївська область',
        '18' => 'Одеська область',
        '19' => 'Полтавська область',
        '5' => 'Рівненська область',
        '30' => 'м. Севастополь',
        '20' => 'Сумська область',
        '21' => 'Тернопільська область',
        '22' => 'Харківська область',
        '23' => 'Херсонська область',
        '3' => 'Хмельницька область',
        '24' => 'Черкаська область',
        '26' => 'Чернівецька область',
        '25' => 'Чернігівська область',
    ];

    public const ALERT_TYPES = [
        'air_raid' => 'Повітряна тривога',
        'artillery_shelling' => 'Загроза артилерійського обстрілу',
        'urban_fights' => 'Вуличні бої',
        'chemical' => 'Хімічна загроза',
        'nuclear' => 'Радіаційна загроза',
    ];

    public const LEGACY_ALERT_START_TEMPLATE = "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 {region}\n⚠️ {threat_type}\n🕒 Початок: {time}";

    public const DEFAULT_ALERT_START_TEMPLATE = "🚨 {headline}\n\n📍 {oblast}\n\n🔴 СТАТУС: АКТИВНА\n{territories}\n\n🔄 Оновлено: {updated}";

    public const LEGACY_ALERT_END_TEMPLATE = "✅ ВІДБІЙ ТРИВОГИ\n\n📍 {region}\n🕒 Відбій: {time}";

    public const DEFAULT_ALERT_END_TEMPLATE = "✅ ВІДБІЙ ТРИВОГИ\n\n{clear_blocks}";

    public const DEFAULT_ALERT_MAP_BUTTON_TEXT = '🗺 Мапа тривог України';

    public const DEFAULT_ALERT_MAP_BUTTON_URL = 'https://skyguardian.pp.ua/';

    protected $fillable = [
        'bot_name', 'bot_token', 'alerts_api_token', 'alerts_api_token_fingerprint',
        'token_fingerprint', 'webhook_secret',
        'webhook_registered_at', 'webhook_last_error', 'last_update_at',
        'admin_id', 'group_name', 'group_link', 'chat_type', 'chat_id',
        'bot_username', 'status', 'permissions', 'last_error',
        'last_manual_check_at', 'is_active', 'module_settings',
        'last_test_message_at', 'last_test_message_error',
        'alerts_api_initialized_at', 'alerts_api_last_checked_at',
        'alerts_api_last_success_at', 'alerts_api_last_error',
    ];

    protected $hidden = [
        'bot_token',
        'alerts_api_token',
        'webhook_secret',
    ];

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
            'alerts_api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'permissions' => 'array',
            'module_settings' => 'array',
            'last_manual_check_at' => 'datetime',
            'last_test_message_at' => 'datetime',
            'webhook_registered_at' => 'datetime',
            'last_update_at' => 'datetime',
            'alerts_api_initialized_at' => 'datetime',
            'alerts_api_last_checked_at' => 'datetime',
            'alerts_api_last_success_at' => 'datetime',
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

    public function alertStates(): HasMany
    {
        return $this->hasMany(GroupChannelAlertState::class);
    }

    public function alertEvents(): HasMany
    {
        return $this->hasMany(GroupChannelAlertEvent::class);
    }

    public function alertCards(): HasMany
    {
        return $this->hasMany(GroupChannelAlertCard::class);
    }

    public function moduleEnabled(string $module): bool
    {
        return (bool) $this->moduleSetting($module, 'enabled', false);
    }

    public function moduleSetting(string $module, ?string $key = null, mixed $default = null): mixed
    {
        $stored = data_get($this->module_settings, $module, []);
        $stored = is_array($stored) ? $stored : [];
        $settings = array_replace_recursive(
            self::defaultModuleSettings()[$module] ?? [],
            $stored,
        );

        if ($module === self::MODULE_ALERT_PUBLICATIONS) {
            foreach (['region_uids', 'alert_types'] as $listKey) {
                if (array_key_exists($listKey, $stored) && is_array($stored[$listKey])) {
                    $settings[$listKey] = array_values($stored[$listKey]);
                }
            }
        }

        return $key === null ? $settings : data_get($settings, $key, $default);
    }

    public static function defaultModuleSettings(): array
    {
        $defaults = collect(array_keys(self::MODULES))
            ->mapWithKeys(fn (string $module): array => [$module => ['enabled' => false]])
            ->all();

        $defaults[self::MODULE_ALERT_PUBLICATIONS] += [
            'all_ukraine' => true,
            'region_uids' => array_keys(self::ALERT_REGIONS),
            'alert_types' => array_keys(self::ALERT_TYPES),
            'publish_start' => true,
            'publish_end' => true,
            'disable_notification' => false,
            'map_button_enabled' => true,
            'map_button_text' => self::DEFAULT_ALERT_MAP_BUTTON_TEXT,
            'map_button_url' => self::DEFAULT_ALERT_MAP_BUTTON_URL,
            'start_template' => self::DEFAULT_ALERT_START_TEMPLATE,
            'end_template' => self::DEFAULT_ALERT_END_TEMPLATE,
        ];
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
        $defaults['system_messages'] += [
            'member_events' => true,
            'pinned_messages' => true,
            'chat_changes' => true,
            'video_chats' => true,
            'forum_topics' => true,
            'other_events' => true,
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
