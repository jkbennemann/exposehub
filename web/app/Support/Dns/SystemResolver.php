<?php

namespace App\Support\Dns;

class SystemResolver implements Resolver
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['txt'] ?? null, $records)));
    }

    public function addresses(string $name): array
    {
        $records = @dns_get_record($name, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }

    public function isZoneApex(string $name): bool
    {
        // An SOA record exists only at the top of a zone.
        return (@dns_get_record($name, DNS_SOA) ?: []) !== [];
    }
}
