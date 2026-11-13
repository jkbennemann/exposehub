<?php

namespace App\Support\Sso;

use RuntimeException;

/**
 * A sign-in through the identity provider that did not go through. The
 * message is written for the person signing in; $detail, if any, is for the
 * log only and may name what the provider sent.
 */
final class SsoException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }
}
