<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelJoinRequest;
use App\Models\GroupChannelPublication;
use App\Models\GroupChannelUserState;
use App\Models\GroupChannelWebhookUpdate;
use App\Models\User;
use App\Services\GroupChannelPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GroupChannelBotFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_token_can_be_added_for_different_chats_with_separate_disabled_modules(): void
    {
        $user = User::factory()->create();
        $token = '123456:abcdefghijklmnopqrstuvwxyz';

        foreach ([1, 2] as $index) {
            $this->actingAs($user)->post(route('admin.group-channel.store'), [
                'bot_name' => 'Manager Bot',
                'bot_token' => $token,
                'admin_id' => '100500',
                'group_name' => 'Test group '.$index,
                'group_link' => 'https://t.me/test_group_'.$index,
                'chat_type' => 'group',
                'is_active' => '1',
            ])->assertRedirect();
        }

        $bots = GroupChannelBot::query()->orderBy('id')->get();
        $this->assertCount(2, $bots);
        $this->assertSame($bots[0]->token_fingerprint, $bots[1]->token_fingerprint);
        $this->assertSame($bots[0]->webhook_secret, $bots[1]->webhook_secret);

        foreach ($bots as $bot) {
            foreach (array_keys(GroupChannelBot::MODULES) as $module) {
                $this->assertFalse($bot->moduleEnabled($module));
            }
        }
    }

    public function test_admin_can_send_text_publication_from_enabled_chat(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['publications']);

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 777],
            ]),
        ]);

        $this->actingAs($user)->post(route('admin.group-channel.publications.store', $bot), [
            'type' => GroupChannelPublication::TYPE_TEXT,
            'text' => 'Тестовая публикация',
            'action' => 'send',
        ])->assertRedirect();

        $publication = GroupChannelPublication::query()->firstOrFail();
        $this->assertSame(GroupChannelPublication::STATUS_SENT, $publication->status);
        $this->assertSame('777', $publication->telegram_message_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '-100500'
            && $request['text'] === 'Тестовая публикация');
    }

    public function test_poll_options_are_sent_as_input_poll_option_objects(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['publications', 'polls']);

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 778],
            ]),
        ]);

        $this->actingAs($user)->post(route('admin.group-channel.publications.store', $bot), [
            'type' => GroupChannelPublication::TYPE_POLL,
            'poll_question' => 'Выберите вариант',
            'poll_options' => "Первый\nВторой",
            'poll_type' => 'regular',
            'poll_is_anonymous' => '1',
            'action' => 'send',
        ])->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/sendPoll')) {
                return false;
            }

            return json_decode((string) $request['options'], true) === [
                ['text' => 'Первый'],
                ['text' => 'Второй'],
            ];
        });
    }

    public function test_webhook_requires_telegram_secret_header(): void
    {
        $bot = $this->botWithModules(['antispam']);

        $this->postJson(route('group-channel.webhook', [
            'fingerprint' => $bot->token_fingerprint,
            'secret' => $bot->webhook_secret,
        ]), [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
            ],
        ])->assertForbidden();
    }

    public function test_webhook_antispam_deletes_link_and_stores_message(): void
    {
        $bot = $this->botWithModules(['antispam'], [
            'antispam' => ['delete_links' => true],
        ]);

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 1,
                'message' => [
                    'message_id' => 55,
                    'date' => now()->timestamp,
                    'chat' => ['id' => -100500, 'type' => 'supergroup'],
                    'from' => ['id' => 99, 'is_bot' => false, 'username' => 'tester'],
                    'text' => 'https://example.com',
                    'entities' => [['type' => 'url', 'offset' => 0, 'length' => 19]],
                ],
            ])->assertOk();

        $this->assertDatabaseHas('group_channel_messages', [
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '55',
            'has_link' => true,
            'matched_rule' => 'links',
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage')
            && (string) $request['message_id'] === '55');
    }

    public function test_system_messages_module_deletes_join_notice_after_processing_member(): void
    {
        $bot = $this->botWithModules(['system_messages']);

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 10,
                'message' => [
                    'message_id' => 80,
                    'date' => now()->timestamp,
                    'chat' => ['id' => -100500, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'new_chat_members' => [[
                        'id' => 105,
                        'is_bot' => false,
                        'first_name' => 'Иван',
                    ]],
                ],
            ])->assertOk();

        $this->assertDatabaseHas('group_channel_user_states', [
            'group_channel_bot_id' => $bot->id,
            'telegram_user_id' => '105',
        ]);
        $this->assertDatabaseHas('group_channel_messages', [
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '80',
            'matched_rule' => 'system_member_events',
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage')
            && (string) $request['message_id'] === '80');
    }

    public function test_system_messages_module_respects_disabled_event_category(): void
    {
        $bot = $this->botWithModules(['system_messages'], [
            'system_messages' => ['pinned_messages' => false],
        ]);

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 11,
                'message' => [
                    'message_id' => 81,
                    'date' => now()->timestamp,
                    'chat' => ['id' => -100500, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'pinned_message' => ['message_id' => 79],
                ],
            ])->assertOk();

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage'));
        $this->assertDatabaseHas('group_channel_messages', [
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '81',
            'matched_rule' => null,
        ]);
    }

    public function test_direct_join_without_required_subscription_is_removed(): void
    {
        $bot = $this->botWithModules(['subscription_check', 'welcome'], [
            'subscription_check' => ['channels' => ['@required_channel']],
            'welcome' => ['text' => 'Добро пожаловать'],
        ]);

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => 'left']]);
            }

            return Http::response(['ok' => true, 'result' => true]);
        });

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 3,
                'message' => [
                    'message_id' => 56,
                    'date' => now()->timestamp,
                    'chat' => ['id' => -100500, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'new_chat_members' => [[
                        'id' => 102,
                        'is_bot' => false,
                        'first_name' => 'Пётр',
                    ]],
                ],
            ])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/banChatMember')
            && (string) $request['user_id'] === '102');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/unbanChatMember')
            && (string) $request['user_id'] === '102');
        $this->assertDatabaseMissing('group_channel_user_states', [
            'group_channel_bot_id' => $bot->id,
            'telegram_user_id' => '102',
        ]);
    }

    public function test_join_request_can_be_approved_from_admin_panel(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['join_requests']);

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 2,
                'chat_join_request' => [
                    'chat' => ['id' => -100500, 'type' => 'supergroup'],
                    'from' => [
                        'id' => 101,
                        'is_bot' => false,
                        'first_name' => 'Иван',
                        'username' => 'ivan',
                    ],
                    'date' => now()->timestamp,
                ],
            ])->assertOk();

        $joinRequest = GroupChannelJoinRequest::query()->firstOrFail();
        $this->assertSame(GroupChannelJoinRequest::STATUS_PENDING, $joinRequest->status);

        $this->actingAs($user)
            ->post(route('admin.group-channel.join-requests.approve', [$bot, $joinRequest]))
            ->assertRedirect();

        $this->assertSame(
            GroupChannelJoinRequest::STATUS_APPROVED,
            $joinRequest->fresh()->status,
        );
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/approveChatJoinRequest'));
    }

    public function test_processed_webhook_update_is_not_applied_twice(): void
    {
        $bot = $this->botWithModules(['antispam'], [
            'antispam' => ['delete_links' => true],
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        $payload = [
            'update_id' => 500,
            'message' => [
                'message_id' => 501,
                'date' => now()->timestamp,
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'from' => ['id' => 99, 'is_bot' => false],
                'text' => 'https://example.com',
                'entities' => [['type' => 'url', 'offset' => 0, 'length' => 19]],
            ],
        ];
        $url = route('group-channel.webhook', [
            'fingerprint' => $bot->token_fingerprint,
            'secret' => $bot->webhook_secret,
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson($url, $payload)
            ->assertOk();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson($url, $payload)
            ->assertOk();

        $this->assertDatabaseCount('group_channel_webhook_updates', 1);
        $this->assertDatabaseHas('group_channel_webhook_updates', [
            'telegram_update_id' => 500,
            'status' => GroupChannelWebhookUpdate::STATUS_PROCESSED,
            'attempts' => 1,
        ]);
        Http::assertSentCount(1);
    }

    public function test_failed_webhook_update_is_retried_instead_of_acknowledged(): void
    {
        $bot = $this->botWithModules(['antispam'], [
            'antispam' => ['delete_links' => true],
        ]);
        $payload = [
            'update_id' => 510,
            'message' => [
                'message_id' => 511,
                'date' => now()->timestamp,
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'from' => ['id' => 99, 'is_bot' => false],
                'text' => 'https://example.com',
                'entities' => [['type' => 'url', 'offset' => 0, 'length' => 19]],
            ],
        ];
        $url = route('group-channel.webhook', [
            'fingerprint' => $bot->token_fingerprint,
            'secret' => $bot->webhook_secret,
        ]);

        Http::fake(['*' => Http::response([
            'ok' => false,
            'description' => 'Temporary Telegram error',
        ], 500)]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson($url, $payload)
            ->assertStatus(500);
        $this->assertDatabaseHas('group_channel_webhook_updates', [
            'telegram_update_id' => 510,
            'status' => GroupChannelWebhookUpdate::STATUS_FAILED,
            'attempts' => 1,
        ]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson($url, $payload)
            ->assertOk();
        $this->assertDatabaseHas('group_channel_webhook_updates', [
            'telegram_update_id' => 510,
            'status' => GroupChannelWebhookUpdate::STATUS_PROCESSED,
            'attempts' => 2,
        ]);
    }

    public function test_expired_verification_callback_does_not_verify_user(): void
    {
        $bot = $this->botWithModules(['human_verification']);
        $state = GroupChannelUserState::query()->create([
            'group_channel_bot_id' => $bot->id,
            'telegram_user_id' => '700',
            'joined_at' => now()->subMinutes(10),
            'verification_expires_at' => now()->subMinute(),
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 520,
                'callback_query' => [
                    'id' => 'callback-520',
                    'from' => ['id' => 700],
                    'data' => 'sg_verify:'.$bot->id.':700',
                    'message' => ['chat' => ['id' => -100500]],
                ],
            ])->assertOk();

        $this->assertNull($state->fresh()->verified_at);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/banChatMember'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'Время проверки истекло.');
    }

    public function test_sent_publication_cannot_be_sent_again(): void
    {
        $bot = $this->botWithModules(['publications']);
        $publication = $bot->publications()->create([
            'type' => GroupChannelPublication::TYPE_TEXT,
            'text' => 'Одна публикация',
            'status' => GroupChannelPublication::STATUS_DRAFT,
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 800]])]);
        $service = app(GroupChannelPublicationService::class);

        $service->send($publication);

        try {
            $service->send($publication->fresh());
            $this->fail('Повторная отправка должна быть заблокирована.');
        } catch (RuntimeException $e) {
            $this->assertSame('Публикация уже отправлена.', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_album_deletion_continues_after_already_deleted_message(): void
    {
        $bot = $this->botWithModules(['publications', 'auto_delete_publications']);
        $publication = $bot->publications()->create([
            'type' => GroupChannelPublication::TYPE_ALBUM,
            'text' => 'Альбом',
            'status' => GroupChannelPublication::STATUS_SENT,
            'sent_at' => now(),
            'telegram_message_id' => '901',
            'telegram_message_ids' => ['901', '902'],
        ]);
        Http::fake(function (Request $request) {
            if ((string) $request['message_id'] === '901') {
                return Http::response([
                    'ok' => false,
                    'description' => 'Bad Request: message to delete not found',
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => true]);
        });

        app(GroupChannelPublicationService::class)->delete($publication);

        $publication->refresh();
        $this->assertNotNull($publication->deleted_at_telegram);
        $this->assertSame([], $publication->telegram_message_ids);
        Http::assertSentCount(2);
    }

    private function botWithModules(array $enabled, array $overrides = []): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        foreach ($enabled as $module) {
            $settings[$module]['enabled'] = true;
        }
        $settings = array_replace_recursive($settings, $overrides);

        return GroupChannelBot::query()->create([
            'bot_name' => 'Manager Bot',
            'bot_token' => '123456:abcdefghijklmnopqrstuvwxyz',
            'token_fingerprint' => hash('sha256', '123456:abcdefghijklmnopqrstuvwxyz'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Test group',
            'group_link' => 'https://t.me/test_group',
            'chat_type' => 'supergroup',
            'chat_id' => '-100500',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
