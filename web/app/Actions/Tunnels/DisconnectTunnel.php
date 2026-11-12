<?php

namespace App\Actions\Tunnels;

use App\Models\AgentToken;
use App\Models\TunnelDisconnect;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;

class DisconnectTunnel
{
    /**
     * Ask the tunnel server to close one agent's session. The agent stops and
     * does not reconnect; its token keeps working.
     *
     * The caller has checked that $by may act on $token and that the tunnel
     * server reported $session under it. The server checks the token again.
     * Someone whose tunnel a colleague closes gets a mail, once.
     *
     * @param  array<int, string>  $hosts  the addresses the session serves, for the mail
     */
    public function handle(User $by, AgentToken $token, string $session, array $hosts): void
    {
        $request = TunnelDisconnect::firstOrCreate(
            ['session' => $session],
            ['agent_token_id' => $token->id, 'user_id' => $by->id, 'created_at' => now()],
        );

        if ($request->wasRecentlyCreated && $token->user_id !== $by->id) {
            AccountMail::send($token->user, AccountNotice::tunnelDisconnected($hosts, $token->team, $by->name));
        }
    }
}
