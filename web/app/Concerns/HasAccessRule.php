<?php

namespace App\Concerns;

use App\Models\Network;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A team, saved address or domain with an access rule: "anyone", "networks"
 * (only the networks attached), or for addresses "default" (the team's rule).
 */
trait HasAccessRule
{
    /**
     * @return MorphToMany<Network, $this>
     */
    public function networks(): MorphToMany
    {
        return $this->morphToMany(Network::class, 'accessible', 'access_networks');
    }

    public function isRestricted(): bool
    {
        return $this->access === 'networks';
    }
}
