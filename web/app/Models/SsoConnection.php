<?php

namespace App\Models;

use App\Support\Sso\Sso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team's own identity provider, as its owner entered it.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $issuer
 * @property string $client_id
 * @property string $client_secret
 * @property array<int, string> $domains
 * @property bool $require_verified_email
 * @property int|null $created_by
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'name', 'issuer', 'client_id', 'client_secret', 'domains', 'require_verified_email', 'created_by'])]
#[Hidden(['client_secret'])]
class SsoConnection extends Model
{
    /**
     * In force: approved by a platform admin, and its team still exists.
     *
     * @param  Builder<SsoConnection>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('approved_at')->whereHas('team');
    }

    /**
     * What a platform admin answers for by approving: this team, a provider
     * of this name at this issuer, for these domains, on these terms. Any
     * change to one of them is a different thing to approve. The client's id
     * and secret are not part of it: they change when a secret is renewed,
     * and stay with the same provider.
     *
     * @var array<int, string>
     */
    public const array APPROVED = ['name', 'issuer', 'domains', 'require_verified_email'];

    /**
     * A fingerprint of what is approved, so that an approval can name what
     * the admin was shown and be refused if that has changed since.
     */
    public function fingerprint(): string
    {
        $domains = Sso::normalizeDomains($this->domains);
        sort($domains);

        return hash('sha256', (string) json_encode([$this->team_id, $this->name, $this->issuer, $domains, $this->require_verified_email]));
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'domains' => 'array',
            'require_verified_email' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }
}
