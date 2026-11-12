<?php

namespace App\Actions\Tokens;

use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use Illuminate\Support\Str;

class IssueAgentToken
{
    /**
     * Create a token for the user on the team.
     *
     * The plain token is returned here and nowhere else: only its hash is stored.
     *
     * @return array{AgentToken, string}
     */
    public function handle(User $user, Team $team, string $name): array
    {
        $plain = AgentToken::PREFIX.Str::random(40);

        $token = AgentToken::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $name,
            'prefix' => substr($plain, 0, 9),
            'hash' => AgentToken::hashOf($plain),
        ]);

        AccountMail::send($user, AccountNotice::agentTokenCreated($name, $token->prefix, $team));

        return [$token, $plain];
    }
}
