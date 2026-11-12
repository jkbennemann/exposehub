<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * A named list of addresses and ranges a team allows its tunnels to be reached
 * from, such as the exit addresses of a company VPN.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $name
 * @property array<int, array{cidr: string, note: string|null}> $entries
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'created_by', 'name', 'entries'])]
class Network extends Model
{
    /**
     * @return array<int, string>
     */
    public function cidrs(): array
    {
        return array_values(array_map(fn (array $entry) => $entry['cidr'], $this->entries));
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The saved addresses using this network.
     *
     * @return MorphToMany<SavedAddress, $this>
     */
    public function savedAddresses(): MorphToMany
    {
        return $this->morphedByMany(SavedAddress::class, 'accessible', 'access_networks');
    }

    /**
     * @return MorphToMany<CustomDomain, $this>
     */
    public function customDomains(): MorphToMany
    {
        return $this->morphedByMany(CustomDomain::class, 'accessible', 'access_networks');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entries' => 'array'];
    }
}
