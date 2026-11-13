<?php

namespace App\Support;

/**
 * Where the source code of this install is published, as the operator set it
 * with EXPOSEHUB_SOURCE_URL, and which version of it runs here.
 */
final class Source
{
    /**
     * The address of the repository, or null when the operator hides the link.
     */
    public static function url(): ?string
    {
        $url = trim((string) config('exposehub.source_url'));

        return $url === '' ? null : $url;
    }

    /**
     * Whether the repository is on GitHub. A fork kept elsewhere gets a
     * neutral icon and wording, not GitHub's.
     */
    public static function onGitHub(): bool
    {
        $host = strtolower((string) parse_url((string) self::url(), PHP_URL_HOST));

        return $host === 'github.com' || $host === 'www.github.com';
    }

    /**
     * The address the way people say it: github.com/owner/name.
     */
    public static function name(): string
    {
        return rtrim((string) preg_replace('#^https?://(www\.)?#i', '', (string) self::url()), '/');
    }

    /**
     * The running version as shown to people: "v0.1.1" for a release, the
     * bare word for anything else, such as "dev".
     */
    public static function version(): string
    {
        $version = (string) config('exposehub.version');

        return preg_match('/^\d/', $version) === 1 ? 'v'.$version : $version;
    }
}
