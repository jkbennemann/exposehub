<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;

class AgentTokenPolicy
{
    /**
     * Every member may create tokens for themselves on a team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user sees the tokens of all team members, not only their own.
     */
    public function viewAll(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageTokens);
    }

    /**
     * Members revoke their own tokens; owners and admins revoke anyone's.
     */
    public function revoke(User $user, AgentToken $token): bool
    {
        if (! $user->belongsToTeam($token->team)) {
            return false;
        }

        return $token->user_id === $user->id
            || $user->hasTeamPermission($token->team, TeamPermission::ManageTokens);
    }

    /**
     * Whoever may revoke a token may also close a tunnel opened with it,
     * which does less: the token keeps working.
     */
    public function disconnect(User $user, AgentToken $token): bool
    {
        return $this->revoke($user, $token);
    }
}
