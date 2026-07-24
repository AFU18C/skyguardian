<?php

namespace App\Services;

use App\Models\Source;
use danog\MadelineProto\StrTools;
use DOMDocument;
use DOMNode;

class NewsMessageFormatter
{
    /**
     * @param list<array<string, mixed>> $messages
     */
    public function passes(Source $source, array $messages): bool
    {
        $text = mb_strtolower($this->rawText($messages));

        foreach ($this->words($source->stop_words) as $word) {
            if (mb_stripos($text, $word) !== false) {
                return false;
            }
        }

        $keywords = $this->words($source->keywords);

        if ($keywords === []) {
            return true;
        }

        foreach ($keywords as $word) {
            if (mb_stripos($text, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{body: string, html: bool}
     */
    public function format(Source $source, array $messages): array
    {
        if ($source->publication_format === 'text') {
            $body = $this->cleanPlainText($this->rawText($messages));

            return [
                'body' => $this->appendCustomText($source, $body, false),
                'html' => false,
            ];
        }

        $parts = [];

        foreach ($messages as $message) {
            $text = trim((string) ($message['message'] ?? ''));

            if ($text === '') {
                continue;
            }

            $entities = is_array($message['entities'] ?? null) ? $message['entities'] : [];

            if ($entities !== [] && class_exists(StrTools::class)) {
                $parts[] = $this->cleanHtml(StrTools::entitiesToHtml($text, $entities));
            } else {
                $parts[] = htmlspecialchars($this->cleanPlainText($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $body = trim(implode("\n\n", array_filter($parts)));

        return [
            'body' => $this->appendCustomText($source, $body, true),
            'html' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function hasMedia(array $messages): bool
    {
        foreach ($messages as $message) {
            if (is_array($message['media'] ?? null)
                && in_array($message['media']['_'] ?? null, [
                    'messageMediaPhoto',
                    'messageMediaDocument',
                ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function rawText(array $messages): string
    {
        return trim(implode("\n", array_map(
            fn (array $message): string => (string) ($message['message'] ?? ''),
            $messages,
        )));
    }

    /**
     * @return list<string>
     */
    private function words(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $word): string => mb_strtolower(trim($word)),
            preg_split('/[\r\n,;]+/u', $value) ?: [],
        )));
    }

    private function cleanPlainText(string $text): string
    {
        $text = preg_replace('~(?:https?://|www\.|t\.me/)\S+~iu', '', $text) ?? $text;
        $text = preg_replace('/(^|\s)#[\p{L}\p{N}_]+/u', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function cleanHtml(string $html): string
    {
        if (! class_exists(DOMDocument::class)) {
            return htmlspecialchars(
                $this->cleanPlainText(strip_tags($html)),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('root');

        if (! $root) {
            return htmlspecialchars(
                $this->cleanPlainText(strip_tags($html)),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
        }

        $links = [];

        foreach ($root->getElementsByTagName('a') as $link) {
            $links[] = $link;
        }

        foreach ($links as $link) {
            $link->parentNode?->replaceChild($document->createTextNode($link->textContent), $link);
        }

        $this->cleanTextNodes($root);

        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    private function cleanTextNodes(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $child->nodeValue = $this->cleanPlainText((string) $child->nodeValue);
                continue;
            }

            $this->cleanTextNodes($child);
        }
    }

    private function appendCustomText(Source $source, string $body, bool $html): string
    {
        if (! $source->append_custom_text || blank($source->custom_text)) {
            return trim($body);
        }

        $custom = trim((string) $source->custom_text);

        if ($html) {
            $custom = nl2br(htmlspecialchars($custom, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return trim($body === '' ? $custom : $body."\n\n".$custom);
    }
}
