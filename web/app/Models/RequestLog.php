<?php

namespace App\Models;

use Database\Factories\RequestLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request to a tunnel, as the tunnel server reported it.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $agent_token_id
 * @property string $host
 * @property string $method
 * @property string $path
 * @property int $status
 * @property float $duration_ms
 * @property int $request_bytes
 * @property int $response_bytes
 * @property string|null $remote
 * @property Carbon $started_at
 * @property array<string, mixed>|null $details
 * @property-read User $user
 * @property-read AgentToken|null $agentToken
 */
class RequestLog extends Model
{
    /** @use HasFactory<RequestLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * The logs a person may see in a team: their own, and those of members who
     * shared theirs with them. Sharing ends when either side leaves the team.
     *
     * @param  Builder<RequestLog>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer, Team $team): void
    {
        $query->where('request_logs.team_id', $team->id)
            ->where(fn (Builder $q) => $q
                ->where('request_logs.user_id', $viewer->id)
                ->orWhereIn('request_logs.user_id', LogGrant::query()
                    ->select('owner_id')
                    ->where('team_id', $team->id)
                    ->where('viewer_id', $viewer->id)
                    ->whereExists(fn ($m) => $m->selectRaw('1')->from('team_members')
                        ->whereColumn('team_members.team_id', 'log_grants.team_id')
                        ->whereColumn('team_members.user_id', 'log_grants.owner_id'))));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AgentToken, $this>
     */
    public function agentToken(): BelongsTo
    {
        return $this->belongsTo(AgentToken::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'details' => 'array',
            'duration_ms' => 'float',
        ];
    }
}
