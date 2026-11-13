<?php

namespace App\Actions\Sso;

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentity;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Sso\Claims;
use App\Support\Sso\Connection;
use App\Support\Sso\SsoException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SignInThroughProvider
{
    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * The account of the person the identity provider signed in: the one
     * already tied to them, an existing one with their address that gets tied
     * to them now, or a new one.
     *
     * An account is found by the provider's id for the person. Only at the
     * first sign-in is the address looked at, and then only when the provider
     * answers for its domain and says the address is confirmed, and when the
     * existing account has confirmed that address itself. Otherwise someone
     * could put a colleague's address on an account of their own and wait for
     * the colleague to be signed in to it.
     *
     * @throws SsoException
     */
    public function handle(Connection $connection, Claims $claims): User
    {
        try {
            [$user, $linked] = $this->accountFor($connection, $claims);
        } catch (UniqueConstraintViolationException) {
            // Two first sign-ins of one person at once: the other one made the account.
            throw new SsoException(__('Signing in did not work. Try again.'));
        }

        if ($linked) {
            AccountMail::send($user, AccountNotice::ssoLinked($connection->name));
        }
        $this->joinTeam($connection, $user);

        return $user;
    }

    /**
     * @return array{User, bool} the account, and whether an existing one was tied to the person just now
     *
     * @throws SsoException
     * @throws UniqueConstraintViolationException
     */
    private function accountFor(Connection $connection, Claims $claims): array
    {
        return DB::transaction(function () use ($connection, $claims) {
            $identity = UserIdentity::query()
                ->where('issuer', $connection->issuer)
                ->where('subject', $claims->subject)
                ->lockForUpdate()
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
                $this->refuseIfSuspended($user);
                $this->refuseOwners($connection, $user->email);
                $this->refuseAdminsOfTheInstall($connection, $user);
                // The provider answers for certain domains only. An account it
                // made that has since left them is not its to sign in to.
                if (! $connection->answersFor($user->email)) {
                    throw new SsoException(__('Your account here no longer has an address :name answers for. Ask the people who run this service.', ['name' => $connection->name]));
                }
                $this->followAddress($connection, $user, $claims);
                $identity->update(['signed_in_at' => now(), 'reminded_at' => null]);

                return [$user, false];
            }

            $email = $this->addressOf($connection, $claims);
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
            $linked = $user !== null;

            if ($user === null) {
                $user = $this->create($claims, $email);
            } else {
                $this->refuseIfSuspended($user);
                $this->refuseAdminsOfTheInstall($connection, $user);
                if ($user->is_demo || $user->identities()->where('issuer', $connection->issuer)->exists()) {
                    // The address was someone else's at the provider before. Their account is not handed on.
                    throw new SsoException(__('An account with your address already belongs to another person at :name. Ask the people who run this service to sort it out.', ['name' => $connection->name]));
                }
                if ($user->email_verified_at === null) {
                    throw new SsoException(__('An account with your address exists but never confirmed that address. Ask the people who run this service to remove it; then sign in again.'));
                }
            }

            $user->identities()->create(['issuer' => $connection->issuer, 'subject' => $claims->subject, 'signed_in_at' => now()]);

            return [$user, $linked];
        });
    }

    /**
     * Signing in through a team's provider is what makes someone a member of
     * that team. Whoever the provider lets in belongs to it: someone removed
     * from the team here is back at their next sign-in, until the provider
     * stops letting them in.
     */
    private function joinTeam(Connection $connection, User $user): void
    {
        $team = $connection->teamId === null ? null : Team::query()->find($connection->teamId);
        if ($team === null) {
            return;
        }

        $joined = DB::transaction(function () use ($team, $user) {
            Team::query()->whereKey($team->id)->lockForUpdate()->first();
            if ($team->memberships()->where('user_id', $user->id)->exists()) {
                return false;
            }
            $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

            return true;
        });

        // Someone new to the team starts there, not in the personal team made for them a moment ago.
        if ($joined) {
            $user->switchTeam($team);
        }
    }

    /**
     * The address a first sign-in is matched by.
     *
     * @throws SsoException
     */
    private function addressOf(Connection $connection, Claims $claims): string
    {
        if ($claims->email === null) {
            throw new SsoException(__(':name did not say what your email address is. Ask the people who run this service to let it share the address.', ['name' => Str::ucfirst($connection->name)]));
        }
        if ($connection->requireVerifiedEmail && ! $claims->emailVerified) {
            throw new SsoException(__(':name has not confirmed your email address yet. Confirm it there, then sign in again.', ['name' => Str::ucfirst($connection->name)]));
        }
        if (! $connection->answersFor($claims->email)) {
            throw new SsoException(__('You are signed in to :name as :email, an address this service does not take from it.', ['name' => $connection->name, 'email' => $claims->email]));
        }
        $this->refuseOwners($connection, $claims->email);

        return $claims->email;
    }

    /**
     * The people who run the install are who they are without the provider:
     * their addresses are the way in when it is down, and whoever holds one
     * with a confirmed address runs the install. So the provider's word never
     * signs anyone in as one of them, makes such an account, or moves an
     * account to such an address.
     *
     * @throws SsoException
     */
    private function refuseOwners(Connection $connection, string $email): void
    {
        if ($this->isOwnerAddress($email)) {
            throw new SsoException(__('This address belongs to the people who run this service. It signs in with its password or passkey, not with :name.', ['name' => $connection->name]));
        }
    }

    /**
     * A team's provider is somebody's own, approved for the team's domains.
     * It does not get to sign anyone in as an admin of the whole install,
     * whatever address that admin has.
     *
     * @throws SsoException
     */
    private function refuseAdminsOfTheInstall(Connection $connection, User $user): void
    {
        if ($connection->teamId !== null && $user->is_admin) {
            throw new SsoException(__('This account administers the service. It signs in with its password or passkey, not with :name.', ['name' => $connection->name]));
        }
    }

    private function isOwnerAddress(string $email): bool
    {
        return in_array(Str::lower($email), config('exposehub.admin_emails', []), true);
    }

    private function create(Claims $claims, string $email): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $claims->name ?? Str::before($email, '@'),
            'email' => $email,
            // Nobody knows it, and such an account may not sign in with one anyway.
            'password' => Str::random(48),
            'email_verified_at' => now(),
        ])->save();

        $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

        return $user;
    }

    /**
     * @throws SsoException
     */
    private function refuseIfSuspended(User $user): void
    {
        if ($user->isSuspended()) {
            throw new SsoException(__('This account is suspended. Ask the people who run this service if you think this is a mistake.'));
        }
    }

    /**
     * Take over a changed address from the provider, when it is one the
     * provider answers for, confirmed, and not somebody else's here.
     */
    private function followAddress(Connection $connection, User $user, Claims $claims): void
    {
        $email = $claims->email;
        if ($email === null || $email === Str::lower($user->email) || ! $connection->answersFor($email) || $this->isOwnerAddress($email)
            || ($connection->requireVerifiedEmail && ! $claims->emailVerified)
            || User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
            return;
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
    }
}
