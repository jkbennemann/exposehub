<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A hostname a team may connect: a real domain name that is not part of this
 * install's own addresses.
 */
class CustomHostname implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? Str::lower(trim($value)) : '';

        $labels = '(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]';

        if (strlen($host) > 253 || ! preg_match('/^'.$labels.'$/', $host)) {
            $fail(__('Enter a domain name such as app.example.com, without https:// or a path.'));

            return;
        }

        foreach ($this->ownHosts() as $own) {
            if ($host === $own || str_ends_with($host, '.'.$own)) {
                $fail(__(':host belongs to this service. Use a domain of your own.', ['host' => $host]));

                return;
            }
        }
    }

    /**
     * Hostnames this install serves itself: the tunnel domain, the agent host and the dashboard.
     *
     * @return array<int, string>
     */
    private function ownHosts(): array
    {
        return array_values(array_filter(array_map(
            fn (?string $value) => Str::lower((string) (parse_url(str_contains((string) $value, '://') ? (string) $value : 'x://'.$value, PHP_URL_HOST) ?: '')),
            [config('exposehub.tunnel_domain'), config('exposehub.agent_server'), config('app.url')],
        )));
    }
}
