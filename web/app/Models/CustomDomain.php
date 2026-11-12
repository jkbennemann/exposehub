<?php

namespace App\Models;

use App\Concerns\HasAccessRule;
use Database\Factories\CustomDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hostname of the team's own that its agents may open tunnels on.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $hostname
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $routed_at
 * @property string $access default, anyone or networks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'user_id', 'hostname', 'verification_token', 'verified_at', 'routed_at', 'access'])]
class CustomDomain extends Model
{
    /** @use HasFactory<CustomDomainFactory> */
    use HasAccessRule, HasFactory;

    /**
     * The name of the TXT record that proves control of the domain.
     */
    public function txtName(): string
    {
        return '_exposehub.'.$this->hostname;
    }

    /**
     * The value that TXT record must carry.
     */
    public function txtValue(): string
    {
        return 'exposehub-verify='.$this->verification_token;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether the team has proven ownership and visitors of the domain reach this install.
     */
    public function isConnected(): bool
    {
        return $this->isVerified() && $this->routed_at !== null;
    }

    /**
     * @param  Builder<CustomDomain>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('custom_domains.verified_at');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'routed_at' => 'datetime',
        ];
    }
}
