<?php

namespace App\Actions\Domains;

use App\Models\CustomDomain;
use App\Support\Dns\Resolver;

class VerifyCustomDomain
{
    public function __construct(private Resolver $dns)
    {
        //
    }

    /**
     * Check the domain's DNS and mark it verified if the team's TXT record is there.
     *
     * Returns null on success, or what is still missing in words for the team.
     */
    public function handle(CustomDomain $domain): ?string
    {
        if (! in_array($domain->txtValue(), $this->dns->txt($domain->txtName()), true)) {
            return __('The TXT record :name was not found, or has a different value. DNS changes can take a few minutes to spread.', ['name' => $domain->txtName()]);
        }

        // The TXT record is specific to this team, so whoever published it controls the DNS.
        // A second team cannot take a hostname that is already connected elsewhere.
        $taken = CustomDomain::query()
            ->verified()
            ->where('hostname', $domain->hostname)
            ->whereKeyNot($domain->id)
            ->exists();

        if ($taken) {
            return __(':host is already connected to another team.', ['host' => $domain->hostname]);
        }

        $domain->update(['verified_at' => now()]);

        return null;
    }

    /**
     * Whether visitors of the domain reach this install: it must resolve to the
     * same addresses as the tunnel server, by CNAME or otherwise.
     */
    public function pointsHere(CustomDomain $domain): bool
    {
        $target = (string) config('exposehub.custom_domain_target');

        return $target !== ''
            && array_intersect($this->dns->addresses($domain->hostname), $this->dns->addresses($target)) !== [];
    }

    /**
     * The record or records that send visitors of the domain here.
     *
     * A subdomain gets a CNAME, which keeps following the tunnel server if its
     * address changes. The top of a zone cannot hold a CNAME, so it gets the
     * tunnel server's addresses as A and AAAA records.
     *
     * @return array<int, array{type: string, name: string, value: string}>
     */
    public function routingRecords(CustomDomain $domain): array
    {
        $target = (string) config('exposehub.custom_domain_target');

        if (! $this->dns->isZoneApex($domain->hostname)) {
            return [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => $target]];
        }

        return array_map(fn (string $address) => [
            'type' => str_contains($address, ':') ? 'AAAA' : 'A',
            'name' => $domain->hostname,
            'value' => $address,
        ], $this->dns->addresses($target));
    }
}
