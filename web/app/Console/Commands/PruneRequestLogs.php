<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('logs:prune')]
#[Description('Delete request logs older than the retention period')]
class PruneRequestLogs extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('exposehub.log_retention_days'));
        $deleted = 0;

        // In slices, so a large backlog never holds one long lock.
        do {
            $ids = RequestLog::query()->where('started_at', '<', $cutoff)->limit(5000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : RequestLog::query()->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());

        $this->info("Deleted {$deleted} request logs older than {$cutoff->toDateTimeString()} UTC.");

        return self::SUCCESS;
    }
}
