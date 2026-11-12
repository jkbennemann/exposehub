<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Support\ServiceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StatusReportController extends Controller
{
    /** The longest agent version kept; agents choose the value themselves. */
    private const int AGENT_VERSION_MAX = 64;

    /**
     * The tunnel server's report about itself and its connected agents, every
     * few seconds. Validated and kept in the cache for the admin area.
     *
     * Each tunnel is checked on its own: one agent sending something odd must
     * not cost the admin area the whole report, and with it every other
     * team's tunnels. A tunnel that does not fit is left out and logged.
     */
    public function __invoke(Request $request): Response
    {
        $report = $request->validate([
            'server_version' => ['required', 'string', 'max:64'],
            'agent_release' => ['nullable', 'string', 'max:64'],
            'started_at' => ['required', 'date'],
            'request_logs' => ['required', 'boolean'],
            // Sent since the server stops accepting dashboard tokens when it
            // cannot refresh its token list; an older server leaves both out.
            'tokens_stale' => ['sometimes', 'boolean'],
            'tokens_refreshed_at' => ['sometimes', 'nullable', 'date'],
            'tunnels' => ['present', 'array', 'max:5000'],
        ]);
        $report['tokens_stale'] = (bool) ($report['tokens_stale'] ?? false);
        $report['tokens_refreshed_at'] ??= null;

        $tunnels = [];
        $dropped = [];
        foreach ($report['tunnels'] as $i => $tunnel) {
            if (! is_array($tunnel)) {
                $dropped[$i] = ['tunnel'];

                continue;
            }
            // The version is whatever the agent says it is. Cut short, the
            // tunnel still shows up.
            if (is_string($tunnel['agent_version'] ?? null)) {
                $tunnel['agent_version'] = Str::substr($tunnel['agent_version'], 0, self::AGENT_VERSION_MAX);
            }

            $validator = Validator::make($tunnel, [
                // Names the session, so one can be closed from the Tunnels
                // page. Older servers do not send it.
                'id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[0-9a-f]+\z/'],
                'token_id' => ['present', 'nullable', 'string', 'max:32'],
                'account' => ['required', 'string', 'max:255'],
                'hosts' => ['required', 'array', 'max:16'],
                'hosts.*' => ['string', 'max:253'],
                'protected' => ['present', 'array', 'max:16'],
                'protected.*' => ['string', 'max:253'],
                'restricted' => ['sometimes', 'array', 'max:16'],
                'restricted.*' => ['string', 'max:253'],
                'agent_version' => ['nullable', 'string', 'max:'.self::AGENT_VERSION_MAX],
                'connected_at' => ['required', 'date'],
            ]);
            if ($validator->fails()) {
                $dropped[$i] = $validator->errors()->keys();

                continue;
            }
            $tunnels[] = $validator->validated() + ['agent_version' => null, 'id' => null];
        }

        if ($dropped !== []) {
            Log::warning('The tunnel server reported tunnels the dashboard cannot read; they are left out of the admin area.', [
                'count' => count($dropped),
                'fields' => array_slice($dropped, 0, 10, true),
            ]);
        }

        $report['tunnels'] = $tunnels;
        ServiceStatus::reported($report);

        return response()->noContent();
    }
}
