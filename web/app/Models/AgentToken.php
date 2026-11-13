<?php

namespace App\Models;

use App\Support\Sso\Connection;
use App\Support\Sso\Sso;
use Carbon\CarbonInterface;
use Database\Factories\AgentTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A credential an agent uses to open tunnels on behalf of a team.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property string $prefix
 * @property string $hash
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 */
#[Fillable(['team_id', 'user_id', 'name', 'prefix', 'hash', 'revoked_at'])]
#[Hidden(['hash'])]
class AgentToken extends Model
{
    /** @use HasFactory<AgentTokenFactory> */
    use HasFactory;

    public const string PREFIX = 'eh_';

    /**
     * The hash under which a token is stored and under which the tunnel server looks it up.
     */
    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Tokens an agent may connect with right now: not revoked, team not
     * deleted, the person who created the token is still on the team and
     * their account is not suspended.
     *
     * With single sign-on, a token of an account the identity provider
     * answers for also needs a recent sign-in through the provider: a token
     * outlives the browser session, so this is what takes the tunnels from
     * someone the provider no longer lets in. See usableUnderSso().
     *
     * @param  Builder<AgentToken>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query
            ->whereNull('agent_tokens.revoked_at')
            ->whereHas('team')
            ->whereHas('user', fn (Builder $users) => $users->whereNull('suspended_at'))
            ->whereExists(fn ($members) => $members
                ->selectRaw('1')
                ->from('team_members')
                ->whereColumn('team_members.team_id', 'agent_tokens.team_id')
                ->whereColumn('team_members.user_id', 'agent_tokens.user_id'));

        if (($cutoff = Sso::tokenCutoff()) !== null) {
            $query->where(fn (Builder $tokens) => self::usableUnderSso($tokens, $cutoff));
        }
    }

    /**
     * The single sign-on part of scopeUsable(). A token passes when its
     * account
     *
     * - signed in through a provider since $cutoff, or
     * - was never tied to a provider and is none of their business: an
     *   address of another domain, or one of the people who run the install,
     *   who keep their password as a way in.
     *
     * An account a provider answers for that has not signed in through it
     * yet passes too, until that provider has been in force for as long as
     * tokens last. Switching one on must not close every tunnel that minute.
     *
     * @param  Builder<AgentToken>  $tokens
     */
    private static function usableUnderSso(Builder $tokens, CarbonInterface $cutoff): void
    {
        $connections = Sso::connections();
        if ($connections->isEmpty()) {
            return;
        }
        $tied = fn () => DB::table('user_identities')
            ->selectRaw('1')
            ->whereColumn('user_identities.user_id', 'agent_tokens.user_id')
            ->whereIn('user_identities.issuer', $connections->pluck('issuer')->unique()->all());
        // Domains whose provider has been in force for longer than tokens last.
        $overdue = $connections
            ->filter(fn (Connection $connection) => $connection->activeSince->lessThan($cutoff))
            ->flatMap(fn (Connection $connection) => $connection->domains)
            ->unique()->values()->all();

        $tokens->whereExists($tied()->where('user_identities.signed_in_at', '>=', $cutoff));

        $tokens->orWhere(function (Builder $never) use ($tied, $overdue) {
            $never->whereNotExists($tied());

            if ($overdue === []) {
                return;
            }
            $never->whereHas('user', fn (Builder $users) => $users->where(function (Builder $outside) use ($overdue) {
                $outside->where(function (Builder $otherDomain) use ($overdue) {
                    foreach ($overdue as $domain) {
                        $otherDomain->whereRaw("lower(email) not like ? escape '!'", ['%@'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $domain)]);
                    }
                })->orWhereIn(DB::raw('lower(email)'), config('exposehub.admin_emails') ?: ['']);
            }));
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }
}
