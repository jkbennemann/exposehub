<?php

namespace App\Actions\Account;

use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Domains\Provisioner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteAccount
{
    public function __construct(private Provisioner $provisioner)
    {
        //
    }

    /**
     * Teams the user owns that other people still belong to. While there are
     * any, the account cannot be deleted: the team would be left without an
     * owner, or deleted under its members.
     *
     * @return Collection<int, Team>
     */
    public function blockingTeams(User $user): Collection
    {
        return $user->ownedTeams()->get()
            ->filter(fn (Team $team) => $team->memberships()->where('user_id', '!=', $user->id)->exists())
            ->values();
    }

    /**
     * Delete the account, the teams only this user is in, and everything that
     * belongs to them, then confirm it by mail to the address the account had.
     * $by is the admin who did it, when it was not the person themselves.
     *
     * @throws AuthorizationException when the person owns a team other people are in
     */
    public function handle(User $user, ?User $by = null): void
    {
        $email = $user->email;
        $name = $user->name;

        [$ownTeams, $leftTeams, $revokedTokens] = DB::transaction(function () use ($user) {
            // Lock the person's teams first, before anything else is read: a
            // member joining one now has to wait until the account is gone,
            // and one who joined before is seen by the checks below.
            Team::query()
                ->whereHas('memberships', fn ($m) => $m->where('user_id', $user->id))
                ->lockForUpdate()
                ->pluck('id');

            // Checked again here, as the callers checked before the lock.
            $blocking = $this->blockingTeams($user);
            if ($blocking->isNotEmpty()) {
                throw new AuthorizationException(__(':name still owns teams other people are in (:teams). Transfer or delete those first.', [
                    'name' => $user->name, 'teams' => $blocking->pluck('name')->join(', '),
                ]));
            }

            // Every team nobody else is in goes, whatever the person's role in
            // it: left behind, it would have no member to manage it while its
            // domains and saved names stay taken.
            $ownTeams = $user->teams()
                ->whereDoesntHave('memberships', fn ($m) => $m->where('user_id', '!=', $user->id))
                ->with('customDomains')
                ->get();
            $leftTeams = $user->teams()->whereNotIn('teams.id', $ownTeams->modelKeys())->pluck('teams.name')->all();
            $revokedTokens = AgentToken::query()->where('user_id', $user->id)->usable()->count();

            // Hard deletes: a personal team carries the person's name. Tokens,
            // domains, saved addresses, networks, memberships and invitations
            // of these teams go with them.
            $ownTeams->each->forceDelete();
            $user->delete();

            return [$ownTeams, $leftTeams, $revokedTokens];
        });

        // The cluster objects behind the deleted domains, once it is certain
        // which teams went. The domains are still in memory to name them.
        foreach ($ownTeams->flatMap->customDomains as $domain) {
            try {
                $this->provisioner->remove($domain);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $deletedTeams = $ownTeams->reject(fn (Team $team) => $team->is_personal)->pluck('name')->all();
        $notice = $by !== null && $by->isNot($user)
            ? AccountNotice::accountDeletedByAdmin($revokedTokens, $deletedTeams, $leftTeams)
            : AccountNotice::accountDeleted($revokedTokens, $deletedTeams, $leftTeams);

        AccountMail::send($email, $notice->addressedTo($name));
    }
}
