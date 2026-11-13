<?php

namespace App\Actions\Sso;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use App\Models\UserIdentity;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Sso\Claims;
use App\Support\Sso\Sso;
use App\Support\Sso\SsoException;
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
    public function handle(Claims $claims): User
    {
        [$user, $linked] = DB::transaction(function () use ($claims) {
            $identity = UserIdentity::query()
                ->where('issuer', Sso::issuer())
                ->where('subject', $claims->subject)
                ->lockForUpdate()
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
                $this->refuseIfSuspended($user);
                $this->followAddress($user, $claims);
                $identity->update(['signed_in_at' => now(), 'reminded_at' => null]);

                return [$user, false];
            }

            $email = $this->addressOf($claims);
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
            $linked = $user !== null;

            if ($user === null) {
                $user = $this->create($claims, $email);
            } else {
                $this->refuseIfSuspended($user);
                if ($user->is_demo || $user->identities()->where('issuer', Sso::issuer())->exists()) {
                    // The address was someone else's at the provider before. Their account is not handed on.
                    throw new SsoException(__('An account with your address already belongs to another person at :name. Ask the people who run this service to sort it out.', ['name' => Sso::name()]));
                }
                if ($user->email_verified_at === null) {
                    throw new SsoException(__('An account with your address exists but never confirmed that address. Ask the people who run this service to remove it; then sign in again.'));
                }
            }

            $user->identities()->create(['issuer' => Sso::issuer(), 'subject' => $claims->subject, 'signed_in_at' => now()]);

            return [$user, $linked];
        });

        if ($linked) {
            AccountMail::send($user, AccountNotice::ssoLinked(Sso::name()));
        }

        return $user;
    }

    /**
     * The address a first sign-in is matched by.
     *
     * @throws SsoException
     */
    private function addressOf(Claims $claims): string
    {
        if ($claims->email === null) {
            throw new SsoException(__(':name did not say what your email address is. Ask the people who run this service to let it share the address.', ['name' => Str::ucfirst(Sso::name())]));
        }
        if (config('exposehub.sso.require_verified_email') && ! $claims->emailVerified) {
            throw new SsoException(__(':name has not confirmed your email address yet. Confirm it there, then sign in again.', ['name' => Str::ucfirst(Sso::name())]));
        }
        if (! Sso::answersFor($claims->email)) {
            throw new SsoException(__('You are signed in to :name as :email, an address this service does not take from it.', ['name' => Sso::name(), 'email' => $claims->email]));
        }

        return $claims->email;
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
    private function followAddress(User $user, Claims $claims): void
    {
        $email = $claims->email;
        if ($email === null || $email === Str::lower($user->email) || ! Sso::answersFor($email)
            || (config('exposehub.sso.require_verified_email') && ! $claims->emailVerified)
            || User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
            return;
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
    }
}
