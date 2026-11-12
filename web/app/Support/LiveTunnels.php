<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The agents connected right now, as the tunnel server last reported them,
 * narrowed to the tokens a person may see.
 */
final class LiveTunnels
{
    /**
     * The reported sessions opened with one of the given tokens, oldest first.
     *
     * @param  array<int, int>  $tokenIds
     * @return Collection<int, array{id: string|null, token_id: int, hosts: array<int, string>, protected: array<int, string>, restricted: array<int, string>, agent_version: string|null, since: Carbon}>
     */
    public static function ofTokens(array $tokenIds): Collection
    {
        $wanted = array_flip($tokenIds);
        $reported = ServiceStatus::report()['tunnels'] ?? [];
        $tunnels = [];

        foreach (is_array($reported) ? $reported : [] as $t) {
            $tokenId = is_array($t) ? ($t['token_id'] ?? null) : null;
            $connectedAt = is_array($t) ? ($t['connected_at'] ?? null) : null;
            // Static tokens have no id here; they belong to no person.
            if (! is_string($tokenId) || ! ctype_digit($tokenId) || ! isset($wanted[(int) $tokenId]) || ! is_string($connectedAt)) {
                continue;
            }
            $id = $t['id'] ?? null;
            $version = $t['agent_version'] ?? null;

            $tunnels[] = [
                'id' => is_string($id) && $id !== '' ? $id : null,
                'token_id' => (int) $tokenId,
                'hosts' => self::names($t['hosts'] ?? null),
                'protected' => self::names($t['protected'] ?? null),
                'restricted' => self::names($t['restricted'] ?? null),
                'agent_version' => is_string($version) && $version !== '' ? $version : null,
                'since' => Carbon::parse($connectedAt),
            ];
        }

        return collect($tunnels);
    }

    /**
     * @return array<int, string>
     */
    private static function names(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * The agent version the tunnel server hands out, if it says.
     */
    public static function offeredAgent(): ?string
    {
        $release = ServiceStatus::report()['agent_release'] ?? null;

        return is_string($release) && $release !== '' ? $release : null;
    }
}
