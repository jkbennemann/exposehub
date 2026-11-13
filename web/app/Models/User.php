<?php

namespace App\Models;

use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use SensitiveParameter;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property bool $is_admin
 * @property bool $is_demo a visitor of the demo, or the keeper of its sample data
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_demo' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * A platform owner: a verified address listed in EXPOSEHUB_ADMIN_EMAILS.
     * Unverified accounts never count, so nobody becomes owner by registering
     * an owner's address they cannot read mail for.
     */
    public function isOwner(): bool
    {
        return $this->email_verified_at !== null && $this->hasOwnerAddress();
    }

    /**
     * The address is listed in EXPOSEHUB_ADMIN_EMAILS, confirmed or not.
     */
    public function hasOwnerAddress(): bool
    {
        return in_array(Str::lower($this->email), config('exposehub.admin_emails', []), true);
    }

    /**
     * May use the admin area: an owner, or someone an owner made admin.
     * A suspended account is nothing at all, and neither is one of the demo.
     */
    public function isAdmin(): bool
    {
        return ! $this->is_demo && ! $this->isSuspended() && ($this->isOwner() || ($this->is_admin && $this->email_verified_at !== null));
    }

    /**
     * An account of the demo has an address made up for it. Nothing is ever
     * mailed to it, whatever asks for it, so that the demo cannot be used to
     * send mail anywhere.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! $this->is_demo) {
            parent::sendEmailVerificationNotification();
        }
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        if (! $this->is_demo) {
            parent::sendPasswordResetNotification($token);
        }
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Who this account is at the identity provider, once it has signed in
     * through single sign-on.
     *
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * @return HasMany<AgentToken, $this>
     */
    public function agentTokens(): HasMany
    {
        return $this->hasMany(AgentToken::class);
    }

    /**
     * The requests to this person's tunnels. Admins only ever count them.
     *
     * @return HasMany<RequestLog, $this>
     */
    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
