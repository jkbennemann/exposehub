<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * One of the operator's legal texts, read from a Markdown file.
 */
enum LegalPage: string
{
    case Imprint = 'imprint';
    case Privacy = 'privacy';

    public function title(): string
    {
        return match ($this) {
            self::Imprint => __('Imprint'),
            self::Privacy => __('Privacy'),
        };
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * The text as HTML. Raw HTML in the file is dropped, so the page can only
     * contain what Markdown itself produces.
     */
    public function html(): string
    {
        return Str::markdown((string) file_get_contents($this->path()), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * The pages the operator has provided.
     *
     * @return array<int, self>
     */
    public static function published(): array
    {
        return array_values(array_filter(self::cases(), fn (self $page) => $page->exists()));
    }

    private function path(): string
    {
        return rtrim((string) config('exposehub.legal_path'), '/').'/'.$this->value.'.md';
    }
}
