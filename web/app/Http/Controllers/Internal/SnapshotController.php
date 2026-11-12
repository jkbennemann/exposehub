<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\TunnelDisconnect;
use App\Support\Access\AccessSnapshot;
use App\Support\ServiceStatus;
use Generator;
use HashContext;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Traversable;

class SnapshotController extends Controller
{
    /** Rows read per query. */
    private const int CHUNK = 500;

    /** Up to this much of the body stays in memory; the rest goes to a temporary file. */
    private const int IN_MEMORY = 2 << 20;

    /** Written out in pieces of about this size. */
    private const int WRITE_SIZE = 64 << 10;

    /**
     * Everything the tunnel server needs to decide who may connect and which
     * of their own domains each team may serve.
     *
     * The server keeps its own copy and polls for changes, so it keeps working
     * while this application is down. The ETag lets a poll that finds nothing
     * new go without a response body.
     *
     * The snapshot covers every team, so it is read in chunks and written to
     * a temporary stream as it goes, never held in memory as a whole. It is
     * complete before the first byte is sent: if building it fails, the
     * server gets an error and keeps its old copy, never half a snapshot.
     * Sending it then copies the stream out in pieces of WRITE_SIZE.
     */
    public function __invoke(Request $request): Response
    {
        $body = fopen('php://temp/maxmemory:'.self::IN_MEMORY, 'w+b');
        if ($body === false) {
            throw new RuntimeException('Cannot open a temporary stream for the snapshot.');
        }
        $hash = hash_init('sha256');
        $buffer = '';

        $this->write($body, $hash, $buffer, [
            'tokens' => $this->tokens(),
            'domains' => $this->domains(),
            'reserved' => AccessSnapshot::reserved(),
            'teams' => AccessSnapshot::teams(),
            'disconnects' => $this->disconnects(),
        ]);
        $this->flush($body, $hash, $buffer);
        $size = (int) ftell($body);

        $response = new StreamedResponse(
            fn () => $this->send($body, $size),
            200,
            ['Content-Type' => 'application/json', 'Content-Length' => (string) $size],
        );
        $response->setEtag(hash_final($hash));

        if ($response->isNotModified($request)) {
            fclose($body);
            // The server already has this snapshot; telling it so is the whole answer.
            ServiceStatus::snapshotFetched();
        }

        return $response;
    }

    /**
     * Sends the built body a piece at a time. fpassthru would hand PHP the
     * whole file in one write, and with output buffering on, all of it would
     * sit in the buffer at once. Only a body sent in full counts as fetched:
     * the admin area must not show the server as served while it is not.
     *
     * @param  resource  $body
     */
    private function send($body, int $size): void
    {
        try {
            rewind($body);
            $sent = 0;
            while ($sent < $size) {
                $piece = fread($body, self::WRITE_SIZE);
                if ($piece === false || $piece === '') {
                    throw new RuntimeException("The snapshot could not be read back after {$sent} of {$size} bytes.");
                }
                echo $piece;
                $sent += strlen($piece);
                $this->flushOutput();
            }
        } finally {
            fclose($body);
        }

        if (connection_aborted() === 0) {
            ServiceStatus::snapshotFetched();
        }
    }

    /**
     * Passes what is in PHP's output buffer on, so it never holds more than a
     * piece of the body.
     */
    private function flushOutput(): void
    {
        $status = ob_get_status();
        if ($status !== [] && ($status['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE) !== 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * @return Generator<int, array{id: int, hash: string, team_id: int, team: string, user_id: int}>
     */
    private function tokens(): Generator
    {
        $tokens = AgentToken::query()
            ->usable()
            ->join('teams', 'teams.id', '=', 'agent_tokens.team_id')
            ->select([
                'agent_tokens.id',
                'agent_tokens.hash',
                'agent_tokens.team_id',
                'agent_tokens.user_id',
                'teams.slug as team',
            ])
            ->lazyById(self::CHUNK, 'agent_tokens.id', 'id');

        foreach ($tokens as $token) {
            yield [
                'id' => $token->id,
                'hash' => $token->hash,
                'team_id' => $token->team_id,
                'team' => $token->getAttribute('team'),
                'user_id' => $token->user_id,
            ];
        }
    }

    /**
     * The sessions people closed on the Tunnels page in the last minutes. The
     * server closes each one that still runs under the named token.
     *
     * @return Generator<int, array{session: string, token_id: int}>
     */
    private function disconnects(): Generator
    {
        $requests = TunnelDisconnect::query()->open()->select(['id', 'session', 'agent_token_id'])->lazyById(self::CHUNK);

        foreach ($requests as $request) {
            yield ['session' => $request->session, 'token_id' => $request->agent_token_id];
        }
    }

    /**
     * @return Generator<int, array{hostname: string, team_id: int}>
     */
    private function domains(): Generator
    {
        $domains = CustomDomain::query()
            ->verified()
            ->whereHas('team')
            ->select(['custom_domains.id', 'hostname', 'team_id'])
            ->lazyById(self::CHUNK, 'custom_domains.id', 'id');

        foreach ($domains as $domain) {
            yield ['hostname' => $domain->hostname, 'team_id' => $domain->team_id];
        }
    }

    /**
     * Writes $value as JSON. Generators and other iterators become lists and
     * are written entry by entry, also inside arrays.
     *
     * @param  resource  $body
     */
    private function write($body, HashContext $hash, string &$buffer, mixed $value): void
    {
        if ($value instanceof Traversable) {
            $buffer .= '[';
            $first = true;
            foreach ($value as $item) {
                $buffer .= $first ? '' : ',';
                $first = false;
                $this->write($body, $hash, $buffer, $item);
                if (strlen($buffer) >= self::WRITE_SIZE) {
                    $this->flush($body, $hash, $buffer);
                }
            }
            $buffer .= ']';

            return;
        }

        if (is_array($value) && $this->streams($value)) {
            $list = array_is_list($value);
            $buffer .= $list ? '[' : '{';
            $first = true;
            foreach ($value as $key => $item) {
                $buffer .= ($first ? '' : ',').($list ? '' : json_encode((string) $key, JSON_THROW_ON_ERROR).':');
                $first = false;
                $this->write($body, $hash, $buffer, $item);
            }
            $buffer .= $list ? ']' : '}';

            return;
        }

        $buffer .= json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Whether $value holds an iterator somewhere, which json_encode cannot write.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function streams(array $value): bool
    {
        foreach ($value as $item) {
            if ($item instanceof Traversable || (is_array($item) && $this->streams($item))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  resource  $body
     */
    private function flush($body, HashContext $hash, string &$buffer): void
    {
        if ($buffer === '') {
            return;
        }
        if (fwrite($body, $buffer) !== strlen($buffer)) {
            throw new RuntimeException('Cannot write the snapshot to its temporary stream.');
        }
        hash_update($hash, $buffer);
        $buffer = '';
    }
}
