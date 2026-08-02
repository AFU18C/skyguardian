<?php

namespace App\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class SiteHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'a', 'h2', 'h3', 'h4',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button',
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="sg-sanitize-root">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '';
        }

        $root = (new DOMXPath($document))->query('//*[@id="sg-sanitize-root"]')->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;

            if ($node instanceof DOMComment) {
                $parent->removeChild($node);
                $node = $next;

                continue;
            }

            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);

                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                    $node = $next;

                    continue;
                }

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->cleanChildren($node);
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    $node = $next;

                    continue;
                }

                $this->cleanElement($node, $tag);
                $this->cleanChildren($node);
            }

            $node = $next;
        }
    }

    private function cleanElement(DOMElement $element, string $tag): void
    {
        $safeAttributes = [];

        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));
            if ($this->isSafeUrl($href)) {
                $safeAttributes['href'] = $href;
            }

            $title = trim($element->getAttribute('title'));
            if ($title !== '') {
                $safeAttributes['title'] = mb_substr($title, 0, 300);
            }

            if (strtolower($element->getAttribute('target')) === '_blank') {
                $safeAttributes['target'] = '_blank';
                $safeAttributes['rel'] = 'noreferrer noopener nofollow';
            }
        }

        if (in_array($tag, ['th', 'td'], true)) {
            foreach (['colspan', 'rowspan'] as $attribute) {
                $value = $element->getAttribute($attribute);
                if (ctype_digit($value) && (int) $value >= 1 && (int) $value <= 20) {
                    $safeAttributes[$attribute] = $value;
                }
            }
        }

        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }

        foreach ($safeAttributes as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }
}
