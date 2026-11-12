<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Limits who may hold an account to the email domains the operator allows.
 * With no domains configured, every address is accepted.
 */
class AllowedEmailDomain implements ValidationRule
{
    /**
     * @param  string|null  $except  An address that passes regardless, so people who
     *                               already have an account can still save their profile.
     */
    public function __construct(private ?string $except = null)
    {
        //
    }

    /**
     * The domains accounts are limited to, lower-cased. Empty means no limit.
     *
     * @return array<int, string>
     */
    public static function domains(): array
    {
        return self::normalized(config('exposehub.allowed_email_domains'));
    }

    /**
     * The allowed domains that may be named to visitors.
     *
     * @return array<int, string>
     */
    public static function shownDomains(): array
    {
        return array_values(array_diff(self::domains(), self::normalized(config('exposehub.hidden_email_domains'))));
    }

    /**
     * @return array<int, string>
     */
    private static function normalized(mixed $domains): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $domain) => Str::lower(ltrim(trim((string) $domain), '@')),
            (array) $domains,
        )));
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domains = self::domains();

        if ($domains === [] || ! is_string($value)) {
            return;
        }

        $email = Str::lower($value);

        if ($this->except !== null && $email === Str::lower($this->except)) {
            return;
        }

        // The whole domain must match: neither a subdomain nor a lookalike suffix counts.
        if (! in_array(Str::after($email, '@'), $domains, true)) {
            $fail(self::notice());
        }
    }

    /**
     * What visitors are told about the limit: the domains that may be named,
     * or no names at all when every allowed domain is hidden.
     */
    public static function notice(): string
    {
        $shown = self::shownDomains();

        if ($shown === []) {
            return __('Accounts are limited to certain email domains.');
        }

        return __('Accounts are limited to :domains addresses.', [
            'domains' => collect($shown)->map(fn (string $domain) => '@'.$domain)->join(', ', ' '.__('and').' '),
        ]);
    }
}
