<?php

namespace App\Concerns;

use Attribute;
use Illuminate\Support\Facades\Auth;
use Livewire\Attribute as LivewireAttribute;

/**
 * For Livewire components of a team page, which keep the team in a locked
 * $teamId: checks on every request that the signed-in person still belongs to
 * that team, so someone removed from it cannot keep working through a page
 * that is still open.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RequiresTeamMembership extends LivewireAttribute
{
    /**
     * A request for an open page: before any action runs.
     */
    public function hydrate(): void
    {
        $this->check();
    }

    /**
     * Opening the page: once mount() has chosen the team.
     */
    public function dehydrate(): void
    {
        $this->check();
    }

    private function check(): void
    {
        $teamId = data_get($this->getComponent(), 'teamId');

        abort_unless(
            is_int($teamId) && (Auth::user()?->teamMemberships()->where('team_id', $teamId)->exists() ?? false),
            403,
        );
    }
}
