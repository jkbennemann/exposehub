<?php

namespace App\Support\Logs;

use App\Models\RequestLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers above the request log, for whatever the filters select. The
 * percentiles use the same definition as the agent's inspector, so the two
 * agree on the same requests.
 */
final class LogStats
{
    /**
     * @param  Builder<RequestLog>  $query  the filtered logs, unordered
     */
    public function __construct(private Builder $query)
    {
        //
    }

    /**
     * @return array{count: int, errors: int, p50: float|null, p95: float|null}
     */
    public function summary(): array
    {
        $totals = (clone $this->query)->toBase()
            ->selectRaw('count(*) as total, coalesce(sum(case when status >= 400 then 1 else 0 end), 0) as errors')
            ->first();
        $count = (int) $totals->total;

        return [
            'count' => $count,
            'errors' => (int) $totals->errors,
            'p50' => $this->percentile($count, 0.50),
            'p95' => $this->percentile($count, 0.95),
        ];
    }

    /**
     * The paths asked for most, without their query strings.
     *
     * @return array<int, array{path: string, count: int}>
     */
    public function busiestPaths(int $limit = 3): array
    {
        $path = $this->driver() === 'pgsql'
            ? "split_part(path, '?', 1)"
            : "case when instr(path, '?') > 0 then substr(path, 1, instr(path, '?') - 1) else path end";

        return (clone $this->query)->toBase()
            ->selectRaw("{$path} as bare, count(*) as hits")
            ->groupBy('bare')
            ->orderByDesc('hits')
            ->orderBy('bare')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['path' => (string) $row->bare, 'count' => (int) $row->hits])
            ->all();
    }

    /**
     * Requests per time slot between $from and $to, and how many of them
     * failed. Slots without requests are included, with zeros.
     *
     * @return array<int, array{start: CarbonInterface, total: int, errors: int}>
     */
    public function timeline(CarbonInterface $from, CarbonInterface $to, int $slots): array
    {
        $size = max(1, intdiv($to->getTimestamp() - $from->getTimestamp(), $slots));
        $epoch = $this->driver() === 'pgsql'
            ? 'extract(epoch from started_at)'
            : "cast(strftime('%s', started_at) as integer)";

        $counts = (clone $this->query)->toBase()
            ->selectRaw("cast(floor(({$epoch} - ?) / ?) as integer) as slot, count(*) as total, coalesce(sum(case when status >= 400 then 1 else 0 end), 0) as errors", [$from->getTimestamp(), $size])
            ->groupBy('slot')
            ->get()
            ->keyBy('slot');

        $out = [];
        for ($i = 0; $i < $slots; $i++) {
            $row = $counts->get($i);
            $out[] = [
                'start' => $from->copy()->addSeconds($i * $size),
                'total' => (int) ($row->total ?? 0),
                'errors' => (int) ($row->errors ?? 0),
            ];
        }

        return $out;
    }

    private function percentile(int $count, float $p): ?float
    {
        if ($count === 0) {
            return null;
        }
        $value = (clone $this->query)->toBase()
            ->orderBy('duration_ms')
            ->offset(min($count - 1, (int) floor($p * $count)))
            ->limit(1)
            ->value('duration_ms');

        return $value === null ? null : (float) $value;
    }

    private function driver(): string
    {
        return DB::connection($this->query->getModel()->getConnectionName())->getDriverName();
    }
}
