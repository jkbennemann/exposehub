<?php

namespace App\Support\Sso;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * One identity provider that is in force: the install's own, set in the
 * deployment (EXPOSEHUB_SSO_*), or a team's, entered by its owner and
 * approved by a platform admin.
 */
final readonly class Connection
{
    /** The key of the install's own connection. */
    public const string INSTALL = 'install';

    /**
     * @param  string  $key  "install", or "team-<id>"
     * @param  array<int, string>  $domains  the email domains it answers for, lower-cased
     * @param  int|null  $teamId  the team people join by signing in through it, if it is a team's
     * @param  CarbonInterface  $activeSince  when it came into force
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $issuer,
        public string $clientId,
        public string $clientSecret,
        public array $domains,
        public string $scopes,
        public bool $requireVerifiedEmail,
        public ?int $teamId,
        public CarbonInterface $activeSince,
    ) {
        //
    }

    /**
     * Whether this provider answers for the address.
     */
    public function answersFor(string $email): bool
    {
        return str_contains($email, '@') && in_array(Str::lower(Str::afterLast($email, '@')), $this->domains, true);
    }
}
