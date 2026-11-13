<?php

namespace App\Support\Access;

use App\Models\CustomDomain;
use App\Models\Network;
use App\Models\SavedAddress;
use App\Models\Team;
use Generator;

/**
 * The access part of the tunnel server's snapshot: which subdomains teams
 * saved, and each team's networks and rules. The server checks every request
 * against them; see tunnel/internal/server/access.go.
 *
 * Everything is read in chunks and handed out one entry at a time: the
 * snapshot covers every team, and however much they add, building it must
 * not run out of memory. A snapshot that cannot be built leaves the tunnel
 * server on its old one, with revoked tokens still working.
 */
final class AccessSnapshot
{
    private const int CHUNK = 500;

    /** Teams come with their addresses and domains, so fewer at a time. */
    private const int TEAM_CHUNK = 50;

    /** A network holds up to 100 entries. */
    private const int NETWORK_CHUNK = 20;

    /**
     * @return Generator<int, array{hostname: string, team_id: int}>
     */
    public static function reserved(): Generator
    {
        // The demo team's sample addresses are listed like any other. Its own
        // agents get random names only, so this keeps the names from everyone:
        // nobody can serve a page on an address the demo shows its visitors.
        $addresses = SavedAddress::query()
            ->whereHas('team')
            ->select(['id', 'hostname', 'team_id'])
            ->lazyById(self::CHUNK);

        foreach ($addresses as $address) {
            yield ['hostname' => $address->hostname, 'team_id' => $address->team_id];
        }
    }

    /**
     * Teams that have networks or rules. A team without either is left out:
     * anyone may reach its tunnels. Each team's networks are a generator of
     * their own, as one team may have many.
     *
     * @return Generator<int, array{team_id: int, default: array{networks: array<int, string>}|null, networks: Generator<int, array{name: string, cidrs: array<int, string>}>, addresses: array<int, array<string, mixed>>}>
     */
    public static function teams(): Generator
    {
        $teams = Team::query()
            // Rules of the demo team are sample data too, and name addresses no agent can open.
            ->where('is_demo', false)
            ->where(fn ($q) => $q->where('access', 'networks')
                ->orWhereHas('accessNetworks')
                ->orWhereHas('savedAddresses', fn ($a) => $a->where('access', '!=', 'default'))
                ->orWhereHas('customDomains', fn ($d) => $d->whereNotNull('verified_at')->where('access', '!=', 'default')))
            ->with([
                'networks:id,name',
                'savedAddresses' => fn ($q) => $q->select(['id', 'team_id', 'hostname', 'access'])
                    ->where('access', '!=', 'default')->with('networks:id,name'),
                'customDomains' => fn ($q) => $q->select(['id', 'team_id', 'hostname', 'access'])
                    ->whereNotNull('verified_at')->where('access', '!=', 'default')->with('networks:id,name'),
            ])
            ->select(['id', 'access'])
            ->lazyById(self::TEAM_CHUNK);

        foreach ($teams as $team) {
            yield [
                'team_id' => $team->id,
                'default' => $team->access === 'networks' ? ['networks' => $team->networks->pluck('name')->values()->all()] : null,
                'networks' => self::networks($team),
                'addresses' => $team->savedAddresses->concat($team->customDomains)
                    ->map(fn (SavedAddress|CustomDomain $a) => $a->access === 'anyone'
                        ? ['hostname' => $a->hostname, 'anyone' => true]
                        : ['hostname' => $a->hostname, 'access' => ['networks' => $a->networks->pluck('name')->values()->all()]])
                    ->values()->all(),
            ];
        }
    }

    /**
     * Only the columns the snapshot needs. The notes come along inside the
     * entries column and are dropped right away, a few networks at a time.
     *
     * @return Generator<int, array{name: string, cidrs: array<int, string>}>
     */
    private static function networks(Team $team): Generator
    {
        $networks = $team->accessNetworks()
            ->select(['id', 'team_id', 'name', 'entries'])
            ->lazyById(self::NETWORK_CHUNK);

        foreach ($networks as $network) {
            /** @var Network $network */
            yield ['name' => $network->name, 'cidrs' => $network->cidrs()];
        }
    }
}
