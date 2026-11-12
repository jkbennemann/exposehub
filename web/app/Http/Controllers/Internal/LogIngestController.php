<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AgentToken;
use App\Models\RequestLog;
use App\Support\ServiceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class LogIngestController extends Controller
{
    /** Bounds the decompressed batch, so a broken sender cannot exhaust memory. */
    private const MAX_BYTES = 32 << 20;

    /**
     * Store a batch of request logs from the tunnel server.
     *
     * Each event names the agent token that opened the tunnel; that token's
     * team and person become the log's team and owner. Events for tokens that
     * no longer exist, because their person or team was deleted, are dropped.
     */
    public function __invoke(Request $request): JsonResponse
    {
        ServiceStatus::logsReceived();

        $raw = $request->getContent();
        if (Str::lower((string) $request->header('Content-Encoding')) === 'gzip') {
            $raw = @gzdecode($raw, self::MAX_BYTES + 1);
        }
        if (! is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'the batch is not readable or too large'], 413);
        }

        $events = json_decode($raw, true)['events'] ?? null;
        if (! is_array($events)) {
            return new JsonResponse(['error' => 'expected {"events": [...]}'], 422);
        }

        $tokens = AgentToken::query()
            ->whereIn('id', collect($events)->pluck('token_id')->filter(fn ($id) => is_string($id) && ctype_digit($id))->unique()->all())
            ->whereHas('team')
            ->get(['id', 'team_id', 'user_id'])
            ->keyBy('id');

        $rows = [];
        foreach ($events as $event) {
            $token = $tokens->get((int) ($event['token_id'] ?? 0));
            if ($token === null || ! is_array($event)) {
                continue;
            }
            try {
                $started = Carbon::parse((string) ($event['started'] ?? 'now'));
            } catch (Throwable) {
                continue;
            }
            $rows[] = [
                'team_id' => $token->team_id,
                'user_id' => $token->user_id,
                'agent_token_id' => $token->id,
                'host' => Str::limit((string) ($event['host'] ?? ''), 253, ''),
                'method' => Str::upper(Str::limit((string) ($event['method'] ?? ''), 16, '')),
                'path' => Str::limit((string) ($event['path'] ?? '/'), 2048, ''),
                'status' => max(0, min(999, (int) ($event['status'] ?? 0))),
                'duration_ms' => max(0, (float) ($event['duration_ms'] ?? 0)),
                'request_bytes' => max(0, (int) ($event['request_bytes'] ?? 0)),
                'response_bytes' => max(0, (int) ($event['response_bytes'] ?? 0)),
                'remote' => isset($event['remote']) ? Str::limit((string) $event['remote'], 45, '') : null,
                'started_at' => $started->utc()->format('Y-m-d H:i:s.v'),
                'details' => is_array($event['details'] ?? null) ? json_encode($event['details']) : null,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            RequestLog::query()->insert($chunk);
        }

        return new JsonResponse(['accepted' => count($rows)], 202);
    }
}
