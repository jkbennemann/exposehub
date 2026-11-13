<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the admin audit log: who did what to whom, and when.
 *
 * @property int $id
 * @property int|null $admin_id
 * @property string $admin_name
 * @property string $admin_email
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 * @property-read User|null $admin
 */
#[Fillable(['admin_id', 'admin_name', 'admin_email', 'action', 'subject_type', 'subject_id', 'subject_label', 'details'])]
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    /**
     * Record an action. The admin's name and address and a label for the
     * subject are copied, so the entry stays readable after either is gone.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(User $admin, string $action, ?Model $subject = null, ?string $label = null, array $details = []): self
    {
        return self::create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'action' => $action,
            'subject_type' => $subject ? strtolower(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label,
            'details' => $details ?: null,
        ]);
    }

    /**
     * What the action was, in words, for the log page.
     */
    public function describe(): string
    {
        return match ($this->action) {
            'user.suspended' => __('suspended :who', ['who' => $this->subject_label]),
            'user.reactivated' => __('reactivated :who', ['who' => $this->subject_label]),
            'user.deleted' => __('deleted the account of :who', ['who' => $this->subject_label]),
            'user.admin_granted' => __('made :who an admin', ['who' => $this->subject_label]),
            'user.admin_revoked' => __('removed admin rights from :who', ['who' => $this->subject_label]),
            'user.email_verified' => __('confirmed the email address of :who', ['who' => $this->subject_label]),
            'user.verification_resent' => __('resent the verification mail to :who', ['who' => $this->subject_label]),
            'token.revoked' => __('revoked the agent token :what', ['what' => $this->subject_label]),
            'domain.removed' => __('removed the domain :what', ['what' => $this->subject_label]),
            'settings.updated' => __('changed the settings: :what', ['what' => $this->subject_label]),
            'sso.approved' => __('approved the identity provider of :what', ['what' => $this->subject_label]),
            'sso.revoked' => __('withdrew the approval of the identity provider of :what', ['what' => $this->subject_label]),
            default => $this->action,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
