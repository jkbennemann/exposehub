<?php

namespace App\Models;

use App\Concerns\HasAccessRule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A subdomain a team keeps for itself. No other team can open a tunnel on it,
 * and it carries an access rule.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $hostname
 * @property string $access default, anyone or networks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $user
 */
#[Fillable(['team_id', 'user_id', 'hostname', 'access'])]
class SavedAddress extends Model
{
    use HasAccessRule;

    /** How many addresses one team may save. */
    public const int LIMIT = 25;

    /** The subdomain part, "shop" of shop.example.com. */
    public function name(): string
    {
        return strstr($this->hostname, '.', true) ?: $this->hostname;
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
}
