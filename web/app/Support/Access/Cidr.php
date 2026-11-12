<?php

namespace App\Support\Access;

/**
 * IPv4 and IPv6 addresses and networks, as people type them into a network:
 * "203.0.113.7", "203.0.113.0/24", "2001:db8::/48". Normalised the way the
 * tunnel server reads them: host bits cleared, IPv4-mapped IPv6 unmapped.
 */
final class Cidr
{
    /**
     * The network in canonical form, or null when $value is not an address
     * or a network.
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        [$address, $bits] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];

        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // ::ffff:203.0.113.7 is an IPv4 address in IPv6 clothing.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
            if ($bits !== null) {
                if (! ctype_digit($bits) || (int) $bits < 96) {
                    return null;
                }
                $bits = (string) ((int) $bits - 96);
            }
        }

        $max = strlen($packed) * 8;
        if ($bits === null) {
            $bits = $max;
        } elseif (! ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }
        $bits = (int) $bits;

        $masked = '';
        for ($i = 0; $i < strlen($packed); $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $masked .= chr(ord($packed[$i]) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return inet_ntop($masked).'/'.$bits;
    }

    /**
     * Whether $address (a single address) lies in $cidr (normalised).
     */
    public static function contains(string $cidr, string $address): bool
    {
        $network = self::normalize($cidr);
        $single = self::normalize($address);
        if ($network === null || $single === null) {
            return false;
        }
        [, $bits] = explode('/', $network);
        [$addr] = explode('/', $single);

        // Masked to the network's length, an address inside it is the network.
        // Across IPv4 and IPv6 the strings never match.
        return self::normalize($addr.'/'.$bits) === $network;
    }

    /**
     * Addresses that never reach a tunnel from the internet: private,
     * loopback, link-local. A network made of them lets nobody in.
     */
    public static function isPrivate(string $cidr): bool
    {
        [$address] = explode('/', $cidr);

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public static function isEveryone(string $cidr): bool
    {
        return str_ends_with($cidr, '/0');
    }
}
