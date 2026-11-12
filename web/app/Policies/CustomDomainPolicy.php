<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\CustomDomain;
use App\Models\Team;
use App\Models\User;

class CustomDomainPolicy
{
    /**
     * Connecting a domain changes what the whole team can serve, so it is for owners and admins.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageDomains);
    }

    public function update(User $user, CustomDomain $domain): bool
    {
        return $user->hasTeamPermission($domain->team, TeamPermission::ManageDomains);
    }

    public function delete(User $user, CustomDomain $domain): bool
    {
        return $user->hasTeamPermission($domain->team, TeamPermission::ManageDomains);
    }
}
