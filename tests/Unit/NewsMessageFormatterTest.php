<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\NewsMessageFormatter;
use Tests\TestCase;

class NewsMessageFormatterTest extends TestCase
{
    public function test_stop_words_have_priority_and_keywords_are_case_insensitive(): void
    {
        $source = new Source([
            'keywords' => 'важно, Запорожье',
            'stop_words' => 'реклама',
        ]);
        $formatter = new NewsMessageFormatter();

        $this->assertTrue($formatter->passes($source, [
            ['id' => 1, 'message' => 'ВАЖНО: новое сообщение'],
        ]));
        $this->assertFalse($formatter->passes($source, [
            ['id' => 2, 'message' => 'Важно, но это реклама'],
        ]));
        $this->assertFalse($formatter->passes($source, [
            ['id' => 3, 'message' => 'Обычное сообщение'],
        ]));
    }

    public function test_text_format_removes_links_and_hashtags_and_appends_custom_text(): void
    {
        $source = new Source([
            'publication_format' => 'text',
            'append_custom_text' => true,
            'custom_text' => 'SkyGuardian',
        ]);
        $formatter = new NewsMessageFormatter();

        $result = $formatter->format($source, [
            ['id' => 1, 'message' => 'Новость https://example.com #город'],
        ]);

        $this->assertFalse($result['html']);
        $this->assertSame("Новость\n\nSkyGuardian", $result['body']);
    }

    public function test_only_downloadable_telegram_media_is_treated_as_media(): void
    {
        $formatter = new NewsMessageFormatter();

        $this->assertFalse($formatter->hasMedia([
            ['id' => 1, 'media' => ['_' => 'messageMediaWebPage']],
        ]));
        $this->assertTrue($formatter->hasMedia([
            ['id' => 2, 'media' => ['_' => 'messageMediaDocument']],
        ]));
    }
}
