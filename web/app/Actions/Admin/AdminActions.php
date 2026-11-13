<?php

namespace App\Actions\Admin;

use App\Actions\Account\DeleteAccount;
use App\Models\AdminAction;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\SsoConnection;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Domains\Provisioner;
use App\Support\Sso\Connection;
use App\Support\Sso\Sso;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything an admin can do to other people's accounts, tokens and domains.
 *
 * The rules: nobody acts on their own account here, nobody acts on a
 * platform owner, and only owners act on other admins or hand out admin
 * rights. Every action lands in the audit log and the person it affects gets
 * a mail.
 */
class AdminActions
{
    public function __construct(private DeleteAccount $deleteAccount, private Provisioner $provisioner)
    {
        //
    }

    /**
     * Why $admin may not act on $user, or null when they may.
     */
    public function refusal(User $admin, User $user): ?string
    {
        if (! $admin->isAdmin()) {
            return __('Only admins can do this.');
        }
        if ($admin->is($user)) {
            return __('This is your own account. Use your settings for it.');
        }
        if ($user->isOwner()) {
            return __('Platform owners are set in the configuration and cannot be changed here.');
        }
        // The stored grant counts, not the current rights: an admin an owner
        // suspended, or one whose address is unconfirmed, stays the owner's to act on.
        if (($user->is_admin || $user->isAdmin()) && ! $admin->isOwner()) {
            return __('Only a platform owner can do this to another admin.');
        }

        return null;
    }

    public function suspend(User $admin, User $user, ?string $reason): void
    {
        $this->authorize($admin, $user);
        $reason = $reason !== null ? Str::limit(trim($reason), 500, '') : null;

        $user->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => $reason ?: null,
            // Remember-me cookies stop working, and sessions in the database go.
            'remember_token' => Str::random(60),
        ])->save();
        $this->endSessions($user);

        AdminAction::record($admin, 'user.suspended', $user, $this->label($user), array_filter(['reason' => $reason]));
        AccountMail::send($user, AccountNotice::accountSuspended($reason));
    }

    public function reactivate(User $admin, User $user): void
    {
        $this->authorize($admin, $user, allowSuspendedAdmin: true);

        $user->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        AdminAction::record($admin, 'user.reactivated', $user, $this->label($user));
        AccountMail::send($user, AccountNotice::accountReactivated());
    }

    /**
     * Delete the account like the person could themselves. Refused while they
     * own a team other people are in: deleting it would take it from them.
     */
    public function delete(User $admin, User $user): void
    {
        $this->authorize($admin, $user, allowSuspendedAdmin: true);

        $blocking = $this->deleteAccount->blockingTeams($user);
        if ($blocking->isNotEmpty()) {
            throw new AuthorizationException(__(':name still owns teams other people are in (:teams). Transfer or delete those first.', [
                'name' => $user->name, 'teams' => $blocking->pluck('name')->join(', '),
            ]));
        }

        $label = $this->label($user);
        $this->deleteAccount->handle($user, $admin);
        AdminAction::record($admin, 'user.deleted', null, $label);
    }

    public function grantAdmin(User $admin, User $user): void
    {
        $this->requireOwner($admin);
        abort_if($user->isSuspended(), 422, __('Reactivate the account first.'));
        abort_if($user->email_verified_at === null, 422, __('The email address has to be confirmed first.'));

        $user->forceFill(['is_admin' => true])->save();

        AdminAction::record($admin, 'user.admin_granted', $user, $this->label($user));
        AccountMail::send($user, AccountNotice::adminGranted($admin->name));
    }

    public function revokeAdmin(User $admin, User $user): void
    {
        $this->requireOwner($admin);
        abort_if($user->isOwner(), 422, __('Platform owners are set in the configuration and cannot be changed here.'));

        $user->forceFill(['is_admin' => false])->save();

        AdminAction::record($admin, 'user.admin_revoked', $user, $this->label($user));
        AccountMail::send($user, AccountNotice::adminRevoked($admin->name));
    }

    public function verifyEmail(User $admin, User $user): void
    {
        $this->authorize($admin, $user);
        if ($user->hasVerifiedEmail()) {
            return;
        }
        // A confirmed owner address makes a platform owner. Only reading the
        // mail sent to it may do that, never an admin, owners included.
        if ($user->hasOwnerAddress()) {
            throw new AuthorizationException(__('A platform owner address can only be confirmed through the link mailed to it.'));
        }
        // An account with a confirmed address of the identity provider's
        // domains is tied to whoever the provider signs in with that address.
        // Confirmed by an admin, an account someone else made would be theirs.
        if (($connection = Sso::forEmail($user->email)) !== null) {
            throw new AuthorizationException(__('Addresses of this domain are confirmed by signing in with :name.', ['name' => $connection->name]));
        }

        $user->markEmailAsVerified();

        AdminAction::record($admin, 'user.email_verified', $user, $this->label($user));
        AccountMail::send($user, AccountNotice::emailVerifiedByAdmin());
    }

    /**
     * Put a team's identity provider into force. From then on it signs
     * people in as any address of its domains and makes them members of the
     * team, so this is the moment someone answers for the domains being the
     * team's to claim.
     */
    public function approveSso(User $admin, SsoConnection $connection): void
    {
        $this->authorizeAdmin($admin);
        if ($connection->approved_at !== null) {
            return;
        }

        $domains = Sso::normalizeDomains($connection->domains);
        $taken = Sso::connections()
            ->reject(fn (Connection $other) => $other->teamId === $connection->team_id)
            ->flatMap(fn (Connection $other) => $other->domains)
            ->intersect($domains);
        if ($taken->isNotEmpty()) {
            throw new AuthorizationException(__('Another identity provider already answers for :domains. A domain belongs to one provider.', ['domains' => $taken->join(', ')]));
        }

        $connection->forceFill(['approved_at' => now(), 'approved_by' => $admin->id])->save();

        AdminAction::record($admin, 'sso.approved', $connection, $connection->team->name, [
            'issuer' => $connection->issuer, 'domains' => $domains, 'unconfirmed_addresses' => ! $connection->require_verified_email,
        ]);
    }

    /**
     * Take a team's identity provider out of force. Accounts of its domains
     * go back to signing in with a password; they ask for a new one by mail.
     */
    public function revokeSso(User $admin, SsoConnection $connection): void
    {
        $this->authorizeAdmin($admin);
        if ($connection->approved_at === null) {
            return;
        }

        $connection->forceFill(['approved_at' => null, 'approved_by' => null])->save();

        AdminAction::record($admin, 'sso.revoked', $connection, $connection->team->name, ['issuer' => $connection->issuer]);
    }

    private function authorizeAdmin(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(__('Only an admin may do this.'));
        }
    }

    public function resendVerification(User $admin, User $user): void
    {
        $this->authorize($admin, $user);
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();

        AdminAction::record($admin, 'user.verification_resent', $user, $this->label($user));
    }

    public function revokeToken(User $admin, AgentToken $token): void
    {
        $this->requireAdmin($admin);
        if ($token->revoked_at !== null) {
            return;
        }

        $token->update(['revoked_at' => now()]);

        AdminAction::record($admin, 'token.revoked', $token, "{$token->name} ({$token->prefix}…)", [
            'team' => $token->team->name, 'owner' => $token->user->email,
        ]);
        AccountMail::send($token->user, AccountNotice::agentTokenRevoked($token->name, $token->team, __('An administrator')));
    }

    public function removeDomain(User $admin, CustomDomain $domain): void
    {
        $this->requireAdmin($admin);
        $team = $domain->team;

        try {
            $this->provisioner->remove($domain);
        } catch (Throwable $e) {
            // The domain is still removed: the tunnel server stops serving it either way.
            report($e);
        }
        $domain->delete();

        AdminAction::record($admin, 'domain.removed', $domain, $domain->hostname, ['team' => $team->name]);
        $owner = $team->owner();
        if ($owner instanceof User) {
            AccountMail::send($owner, AccountNotice::domainRemovedByAdmin($domain->hostname, $team));
        }
    }

    private function authorize(User $admin, User $user, bool $allowSuspendedAdmin = false): void
    {
        // A suspended admin is not an admin any more, so an owner can still
        // reactivate or delete them.
        $refusal = $allowSuspendedAdmin && $user->isSuspended() && $admin->isOwner() && ! $user->isOwner() && $admin->isNot($user)
            ? null
            : $this->refusal($admin, $user);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
    }

    private function requireAdmin(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(__('Only admins can do this.'));
        }
    }

    private function requireOwner(User $admin): void
    {
        if (! $admin->isOwner()) {
            throw new AuthorizationException(__('Only a platform owner can hand out or take away admin rights.'));
        }
    }

    private function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    private function label(User $user): string
    {
        return "{$user->name} <{$user->email}>";
    }
}
