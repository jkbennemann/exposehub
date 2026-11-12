<?php

namespace App\Support\Dns;

/**
 * The DNS lookups domain verification needs. An interface so tests can answer
 * them without a network.
 */
interface Resolver
{
    /**
     * The values of the TXT records at a name.
     *
     * @return array<int, string>
     */
    public function txt(string $name): array;

    /**
     * The IPv4 and IPv6 addresses a name resolves to, following CNAMEs.
     *
     * @return array<int, string>
     */
    public function addresses(string $name): array;

    /**
     * Whether a name is the top of a DNS zone, such as example.com itself.
     * DNS does not allow a CNAME record there.
     */
    public function isZoneApex(string $name): bool;
}
