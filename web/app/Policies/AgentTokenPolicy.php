<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AgentTokenPolicy
{
    /**
     * Every member may create tokens for themselves on a team. A visitor of
     * the demo gets a few: each one can hold a tunnel open.
     */
    public function create(User $user, Team $team): Response
    {
        if (! $user->belongsToTeam($team)) {
            return Response::deny();
        }

        $limit = (int) config('exposehub.demo.tokens_per_visitor');
        if ($user->is_demo && $team->agentTokens()->where('user_id', $user->id)->whereNull('revoked_at')->count() >= $limit) {
            return Response::deny(__('The demo gives each visitor :n tokens. Revoke one to make another.', ['n' => $limit]));
        }

        return Response::allow();
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
