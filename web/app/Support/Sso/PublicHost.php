<?php

namespace App\Support\Sso;

/**
 * Says whether a host name leads to the public internet.
 *
 * A team's identity provider is entered by the team's owner, and the server
 * fetches from it: its discovery document, its keys, its token endpoint. A
 * host name that resolves to an address inside the network this service runs
 * in would turn that into a way to reach what is not meant to be reachable.
 */
class PublicHost
{
    /**
     * An address the host resolves to, if every address it resolves to is
     * public. Null when it does not resolve, or when any of them is private,
     * loopback, link-local or otherwise not routable from outside.
     */
    public function addressOf(string $host): ?string
    {
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $addresses = array_values(array_filter($addresses, is_string(...)));

        if ($addresses === []) {
            return null;
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return null;
            }
        }

        // The one the request is then pinned to; IPv4 first, as not every network routes IPv6.
        usort($addresses, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses[0];
    }

    public function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Shared address space of carriers (100.64.0.0/10), which the filter lets through.
        $packed = inet_pton($address);

        return ! (is_string($packed) && strlen($packed) === 4 && ord($packed[0]) === 100 && (ord($packed[1]) & 0xC0) === 64);
    }
}
