<?php

namespace App\Support\Sso;

/**
 * What the identity provider said about the person who signed in, taken
 * from a token whose signature, issuer, audience, nonce and times were
 * checked.
 */
final readonly class Claims
{
    public function __construct(
        /** The provider's own id for the person. It never changes and is never reused. */
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        /** When the person last proved who they are to the provider, if it says. */
        public ?int $authTime,
    ) {
        //
    }
}
