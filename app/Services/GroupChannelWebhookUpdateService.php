<?php

namespace App\Services;

use App\Models\GroupChannelBot;

class GroupChannelWebhookUpdateService
{
    public function __construct(private readonly GroupChannelWebhookService $webhook) {}

    public function handle(GroupChannelBot $bot, array $update): void
    {
        if (isset($update['channel_post']) && is_array($update['channel_post'])) {
            $update['message'] = $update['channel_post'];
        }

        if (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
            $update['edited_message'] = $update['edited_channel_post'];
        }

        $this->webhook->handle($bot, $update);
    }
}
