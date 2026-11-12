<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member letting a colleague in the same team see their request logs.
 *
 * @property int $id
 * @property int $team_id
 * @property int $owner_id
 * @property int $viewer_id
 * @property-read User $owner
 * @property-read User $viewer
 */
#[Fillable(['team_id', 'owner_id', 'viewer_id'])]
class LogGrant extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }

    /**
     * Forget a member's logs and sharing in a team, when they leave it or are removed.
     */
    public static function forgetMember(Team $team, User $user): void
    {
        RequestLog::query()->where('team_id', $team->id)->where('user_id', $user->id)->delete();
        self::query()->where('team_id', $team->id)
            ->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('viewer_id', $user->id))
            ->delete();
    }

    /**
     * Forget all logs and sharing of a team that is being deleted.
     */
    public static function forgetTeam(Team $team): void
    {
        RequestLog::query()->where('team_id', $team->id)->delete();
        self::query()->where('team_id', $team->id)->delete();
    }
}
