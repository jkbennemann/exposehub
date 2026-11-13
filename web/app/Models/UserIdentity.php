<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account's identity at the identity provider (single sign-on).
 *
 * @property int $id
 * @property int $user_id
 * @property string $issuer
 * @property string $subject
 * @property Carbon $signed_in_at
 * @property Carbon|null $reminded_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'issuer', 'subject', 'signed_in_at', 'reminded_at'])]
class UserIdentity extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_in_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }
}
