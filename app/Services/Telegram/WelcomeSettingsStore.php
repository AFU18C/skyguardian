<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WelcomeSettingsStore
{
    private const PATH = 'telegram/welcome-settings.json';

    public function all(): array
    {
        if (! Storage::disk('local')->exists(self::PATH)) {
            return ['secret' => Str::random(48), 'groups' => []];
        }

        $decoded = json_decode((string) Storage::disk('local')->get(self::PATH), true);
        if (! is_array($decoded)) {
            return ['secret' => Str::random(48), 'groups' => []];
        }

        if (isset($decoded['groups']) && is_array($decoded['groups'])) {
            return [
                'secret' => filled($decoded['secret'] ?? null) ? (string) $decoded['secret'] : Str::random(48),
                'groups' => array_values(array_map(fn (array $group): array => $this->normalize($group), $decoded['groups'])),
            ];
        }

        if (filled($decoded['chat'] ?? null)) {
            $legacy = $this->normalize(array_merge($decoded, [
                'id' => (string) Str::uuid(),
                'title' => (string) ($decoded['chat'] ?? 'Telegram-группа'),
                'type' => 'group',
            ]));

            $data = [
                'secret' => filled($decoded['secret'] ?? null) ? (string) $decoded['secret'] : Str::random(48),
                'groups' => [$legacy],
            ];
            $this->write($data);

            return $data;
        }

        return ['secret' => Str::random(48), 'groups' => []];
    }

    public function groups(): array
    {
        return $this->all()['groups'];
    }

    public function find(string $id): ?array
    {
        foreach ($this->groups() as $group) {
            if (($group['id'] ?? null) === $id) {
                return $group;
            }
        }

        return null;
    }

    public function add(array $group): array
    {
        $data = $this->all();
        $group['id'] = (string) Str::uuid();
        $group = $this->normalize($group);
        $data['groups'][] = $group;
        $this->write($data);

        return $group;
    }

    public function update(string $id, array $changes): ?array
    {
        $data = $this->all();

        foreach ($data['groups'] as $index => $group) {
            if (($group['id'] ?? null) !== $id) {
                continue;
            }

            $data['groups'][$index] = $this->normalize(array_replace($group, $changes));
            $this->write($data);

            return $data['groups'][$index];
        }

        return null;
    }

    public function delete(string $id): bool
    {
        $data = $this->all();
        $before = count($data['groups']);
        $data['groups'] = array_values(array_filter(
            $data['groups'],
            fn (array $group): bool => ($group['id'] ?? null) !== $id,
        ));

        if (count($data['groups']) === $before) {
            return false;
        }

        $this->write($data);

        return true;
    }

    public function secret(): string
    {
        $data = $this->all();
        if (blank($data['secret'])) {
            $data['secret'] = Str::random(48);
            $this->write($data);
        }

        return (string) $data['secret'];
    }

    private function write(array $data): void
    {
        Storage::disk('local')->put(
            self::PATH,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function normalize(array $group): array
    {
        return array_replace([
            'id' => (string) Str::uuid(),
            'chat' => '',
            'chat_id' => '',
            'title' => 'Telegram-группа',
            'type' => 'group',
            'bot' => 'news',
            'enabled' => false,
            'message' => 'Добро пожаловать, {name}! Рады видеть вас в группе {group}.',
            'delete_join_messages' => false,
            'delete_leave_messages' => false,
            'delete_pinned_messages' => false,
            'delete_group_changes' => false,
            'filter_enabled' => false,
            'forbidden_words' => '',
            'forbidden_links' => '',
            'filter_action' => 'delete_warn',
            'antispam_enabled' => false,
            'new_member_minutes' => 30,
            'block_links_for_new' => true,
            'message_limit' => 6,
            'message_window_seconds' => 30,
            'antispam_action' => 'delete_warn',
            'mute_minutes' => 10,
        ], $group);
    }
}
