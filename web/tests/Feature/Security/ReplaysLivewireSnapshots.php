<?php

namespace Tests\Feature\Security;

use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Sends saved Livewire snapshots back to the update URL the way a browser
 * (or someone replaying a captured request) does, so the tests go through the
 * same middleware as real traffic and not around it like Livewire::test().
 */
trait ReplaysLivewireSnapshots
{
    /**
     * The snapshot of the named component in a page's HTML.
     */
    protected function snapshotOf(string $html, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $json = htmlspecialchars_decode($raw, ENT_QUOTES | ENT_SUBSTITUTE);
            if ((json_decode($json, true)['memo']['name'] ?? null) === $component) {
                return $json;
            }
        }

        $this->fail("The page has no {$component} component.");
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<array{0: string, 1?: array<int, mixed>}>  $calls  method and parameters
     * @param  array<string, string>  $headers
     */
    protected function replay(string $snapshot, array $updates = [], array $calls = [], array $headers = []): TestResponse
    {
        // Livewire skips middleware for a route it already checked in this
        // process; every replay here stands for a separate request.
        Livewire::flushState();

        return $this->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) $updates,
                'calls' => array_map(fn (array $call) => ['method' => $call[0], 'params' => $call[1] ?? [], 'metadata' => []], $calls),
            ]],
        ], ['X-Livewire' => '1', ...$headers]);
    }
}
