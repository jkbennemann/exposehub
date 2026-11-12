<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Text for the Markdown of a mail that has to stay text.
 *
 * Mail lines carry names people chose themselves: their own, their team's,
 * a token's or a passkey's. Escaping them for HTML is not enough, because the
 * mail is Markdown first, and a name like "Acme [Sign in](https://…)" would
 * become a real link in a mail sent from this application. Here the Markdown
 * punctuation is written as HTML character references: Markdown reads those
 * as plain characters, the HTML mail shows them as they were typed, and the
 * plain-text mail decodes them back.
 */
final class MailText
{
    /** HTML's own special characters, then the ones Markdown builds links, images and emphasis from. */
    private const array REFERENCES = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "'" => '&#039;',
        '\\' => '&#92;',
        '[' => '&#91;',
        ']' => '&#93;',
        '(' => '&#40;',
        ')' => '&#41;',
        '!' => '&#33;',
        '*' => '&#42;',
        '_' => '&#95;',
        '`' => '&#96;',
        '#' => '&#35;',
        '|' => '&#124;',
        '~' => '&#126;',
    ];

    public static function literal(Htmlable|string|null $text): HtmlString
    {
        // Already HTML, put there on purpose by the application.
        if ($text instanceof Htmlable) {
            return new HtmlString($text->toHtml());
        }

        // One pass, so no reference is escaped a second time.
        return new HtmlString(strtr(mb_scrub($text ?? '', 'UTF-8'), self::REFERENCES));
    }
}
