<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * /agents.md: how a coding agent (Claude Code, Cursor, Codex and others) puts
 * a local service or folder online with this install, in Markdown, with the
 * install's own addresses filled in. People hand an agent one sentence that
 * points here; the dashboard's Overview offers that sentence to copy.
 *
 * It names nothing that /docs does not already show to anyone. The
 * dashboard's own address comes from APP_URL, so a forged Host header
 * cannot put another address in front of an agent.
 */
class AgentGuide extends Controller
{
    public function __invoke(): Response
    {
        $agent = rtrim((string) config('exposehub.agent_server'), '/');

        return response()
            ->view('agents.guide', [
                // The configured address, not the request's Host header.
                'dashboard' => rtrim((string) config('app.url'), '/'),
                'agent' => $agent,
                'domain' => (string) config('exposehub.tunnel_domain'),
                'scheme' => parse_url($agent, PHP_URL_SCHEME) ?: 'https',
            ])
            ->header('Content-Type', 'text/markdown; charset=UTF-8')
            // Not public: the response carries the session cookies of the
            // web middleware, which a shared cache must never hand on.
            ->header('Cache-Control', 'no-store, private');
    }
}
