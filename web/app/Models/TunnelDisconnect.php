<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone's request to close one agent's session, made on the Tunnels page.
 *
 * The tunnel server reads the open requests with its next snapshot and closes
 * the session. A request stays open for a few minutes, long enough for a
 * server that was briefly out of reach, and is deleted after that.
 *
 * @property int $id
 * @property string $session
 * @property int $agent_token_id
 * @property int $user_id
 * @property Carbon $created_at
 * @property-read AgentToken $token
 */
#[Fillable(['session', 'agent_token_id', 'user_id', 'created_at'])]
class TunnelDisconnect extends Model
{
    /** How long a request is passed to the tunnel server. */
    public const int OPEN_MINUTES = 5;

    public $timestamps = false;

    /**
     * Requests the tunnel server should still act on.
     *
     * @param  Builder<TunnelDisconnect>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('tunnel_disconnects.created_at', '>=', now()->subMinutes(self::OPEN_MINUTES));
    }

    /**
     * Requests the tunnel server no longer hears about.
     *
     * @param  Builder<TunnelDisconnect>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('tunnel_disconnects.created_at', '<', now()->subMinutes(self::OPEN_MINUTES));
    }

    /**
     * @return BelongsTo<AgentToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(AgentToken::class, 'agent_token_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
