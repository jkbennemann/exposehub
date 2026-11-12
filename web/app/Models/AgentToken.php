<?php

namespace App\Models;

use Database\Factories\AgentTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
