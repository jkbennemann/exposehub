<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the dashboard knows about the tunnel server, for the admin area: when
 * it last fetched its tokens, last sent request logs, and its last status
 * report (version, connected agents). Kept in the cache only, for minutes,
 * never in the database.
 */
final class ServiceStatus
{
    private const SNAPSHOT = 'exposehub.status.snapshot_at';

    private const LOGS = 'exposehub.status.logs_at';

    private const REPORT = 'exposehub.status.report';

    /** A report older than this means the server is not reporting. */
    public const STALE_AFTER = 60;

    public static function snapshotFetched(): void
    {
        // Every poll would be a cache write; once every 30 s is plenty to tell.
        $last = Cache::get(self::SNAPSHOT);
        if ($last === null || now()->getTimestamp() - $last >= 30) {
            Cache::put(self::SNAPSHOT, now()->getTimestamp(), now()->addHour());
        }
    }

    public static function logsReceived(): void
    {
        Cache::put(self::LOGS, now()->getTimestamp(), now()->addDay());
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function reported(array $report): void
    {
        Cache::put(self::REPORT, ['received_at' => now()->getTimestamp()] + $report, now()->addMinutes(5));
    }

    public static function lastSnapshot(): ?Carbon
    {
        return self::time(Cache::get(self::SNAPSHOT));
    }

    public static function lastLogs(): ?Carbon
    {
        return self::time(Cache::get(self::LOGS));
    }

    /**
     * The last report, or null when none came in recently.
     *
     * @return array<string, mixed>|null
     */
    public static function report(): ?array
    {
        $report = Cache::get(self::REPORT);

        return is_array($report) ? $report : null;
    }

    public static function reportIsFresh(): bool
    {
        $report = self::report();

        return $report !== null && now()->getTimestamp() - (int) $report['received_at'] <= self::STALE_AFTER;
    }

    /**
     * Whether the tunnel server says its token list is out of date: it could
     * not refresh it from this dashboard for longer than it allows, so it
     * refuses dashboard tokens and has closed the tunnels opened with them.
     */
    public static function tokensStale(): bool
    {
        return (self::report()['tokens_stale'] ?? false) === true;
    }

    /**
     * When the tunnel server last refreshed its token list, as it reported.
     */
    public static function tokensRefreshedAt(): ?Carbon
    {
        $at = self::report()['tokens_refreshed_at'] ?? null;

        return is_string($at) ? Carbon::parse($at) : null;
    }

    private static function time(mixed $timestamp): ?Carbon
    {
        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
