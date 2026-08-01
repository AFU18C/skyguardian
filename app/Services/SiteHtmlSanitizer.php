<?php

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

class SiteHtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', implode(',', [
            'p',
            'br',
            'strong',
            'b',
            'em',
            'i',
            'u',
            's',
            'ul',
            'ol',
            'li',
            'blockquote',
            'a[href|title|target|rel]',
            'h2',
            'h3',
            'h4',
            'table',
            'thead',
            'tbody',
            'tr',
            'th[colspan|rowspan]',
            'td[colspan|rowspan]',
            'span',
        ]));
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);
        $config->set('AutoFormat.RemoveEmpty', true);

        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->purifier->purify($html));
    }
}
