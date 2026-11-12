<?php

namespace App\Notifications\Account;

use App\Models\Team;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A mail about something that changed in a person's account or teams.
 *
 * Every change that affects how someone signs in, what their credentials can
 * do or which teams they are in gets one, so a change they did not make does
 * not go unnoticed. Each kind is built by a named constructor below.
 */
final class AccountNotice extends Notification
{
    /** Who the greeting names, when the recipient is only an address. */
    private ?string $recipientName = null;

    /**
     * @param  array<int, string>  $lines
     * @param  array{0: string, 1: string}|null  $action  label and URL
     */
    private function __construct(
        public readonly string $kind,
        private readonly string $subject,
        private readonly array $lines,
        private readonly ?array $action = null,
        private readonly ?string $ifNotYou = null,
    ) {
        //
    }

    /**
     * @param  array<int, string>  $deletedTeams  shared teams deleted with the account
     * @param  array<int, string>  $leftTeams  teams the person was a member of
     */
    public static function accountDeleted(int $revokedTokens, array $deletedTeams, array $leftTeams): self
    {
        $lines = [__('Your exposehub account and the personal data in it have been deleted.')];

        if ($revokedTokens > 0) {
            $lines[] = trans_choice('{1} Your agent token was revoked and any agent using it was disconnected.|[2,*] Your :count agent tokens were revoked and any agents using them were disconnected.', $revokedTokens, ['count' => $revokedTokens]);
        }
        if ($deletedTeams !== []) {
            $lines[] = __('These teams were deleted with it, including their tokens and domains: :teams.', ['teams' => implode(', ', $deletedTeams)]);
        }
        if ($leftTeams !== []) {
            $lines[] = __('You are no longer a member of: :teams.', ['teams' => implode(', ', $leftTeams)]);
        }
        $lines[] = __('This is the last mail you receive from us. Nothing of the account can be restored.');

        return new self('account-deleted', __('Your exposehub account was deleted'), $lines,
            ifNotYou: self::contact(__('If you did not delete your account, someone else had access to it.')));
    }

    public static function passwordChanged(bool $viaReset): self
    {
        return new self('password-changed', __('Your exposehub password was changed'), [
            $viaReset
                ? __('The password of your account was reset through the "forgot password" link.')
                : __('The password of your account was changed in the security settings.'),
        ], [__('Reset your password'), route('password.request')],
            __('If you did not do this, reset your password now and check your agent tokens.'));
    }

    public static function emailChanged(string $newEmail): self
    {
        return new self('email-changed', __('The email address of your exposehub account was changed'), [
            __('Your account now uses :email. Mail about the account goes there from now on, and this address no longer signs in.', ['email' => self::mask($newEmail)]),
        ], ifNotYou: self::contact(__('If you did not do this, someone else has access to your account.')));
    }

    public static function twoFactorEnabled(): self
    {
        return new self('two-factor-enabled', __('Two-factor sign-in is on'), [
            __('Signing in to your account now also asks for a code from your authenticator app. Keep the recovery codes somewhere safe.'),
        ], [__('Open security settings'), route('security.edit')],
            __('If you did not do this, reset your password now.'));
    }

    public static function twoFactorDisabled(): self
    {
        return new self('two-factor-disabled', __('Two-factor sign-in is off'), [
            __('Signing in to your account no longer asks for a code from your authenticator app.'),
        ], [__('Open security settings'), route('security.edit')],
            __('If you did not do this, reset your password now and turn two-factor sign-in on again.'));
    }

    public static function twoFactorFailures(int $failures): self
    {
        return new self('two-factor-failures', __('Wrong two-factor codes for your account'), [
            __('Someone entered your password correctly, then :count wrong two-factor codes in a row. They have to enter the password again before they can try more.', ['count' => $failures]),
        ], [__('Reset your password'), route('password.request')],
            __('If this was not you, someone knows your password: reset it now.'));
    }

    public static function passkeyAdded(string $name): self
    {
        return new self('passkey-added', __('A passkey was added to your account'), [
            __('The passkey ":name" can now sign in to your account without a password.', ['name' => $name]),
        ], [__('Open security settings'), route('security.edit')],
            __('If you did not add it, remove it in the security settings and reset your password.'));
    }

    public static function passkeyRemoved(string $name): self
    {
        return new self('passkey-removed', __('A passkey was removed from your account'), [
            __('The passkey ":name" can no longer sign in to your account.', ['name' => $name]),
        ], [__('Open security settings'), route('security.edit')],
            __('If you did not remove it, reset your password now.'));
    }

    public static function agentTokenCreated(string $tokenName, string $prefix, Team $team): self
    {
        return new self('agent-token-created', __('A new agent token was created'), [
            __('The token ":name" (:prefix…) lets an agent open tunnels for :team.', ['name' => $tokenName, 'prefix' => $prefix, 'team' => $team->name]),
        ], [__('Review agent tokens'), route('tokens.index', ['current_team' => $team->slug])],
            __('If you did not create it, revoke it now and reset your password.'));
    }

    public static function agentTokenRevoked(string $tokenName, Team $team, string $revokedBy): self
    {
        return new self('agent-token-revoked', __('Your agent token was revoked'), [
            __(':person revoked your agent token ":name" for :team.', ['person' => $revokedBy, 'name' => $tokenName, 'team' => $team->name]),
            __('Agents using it were disconnected and cannot reconnect. Create a new token if you still need one.'),
        ], [__('Review agent tokens'), route('tokens.index', ['current_team' => $team->slug])]);
    }

    /**
     * @param  array<int, string>  $hosts  the addresses the closed tunnel served
     */
    public static function tunnelDisconnected(array $hosts, Team $team, string $disconnectedBy): self
    {
        return new self('tunnel-disconnected', __('Your tunnel was disconnected'), [
            __(':person disconnected your tunnel :hosts in :team.', ['person' => $disconnectedBy, 'hosts' => implode(', ', $hosts), 'team' => $team->name]),
            __('The agent has stopped and does not reconnect. Your token still works: start the agent again when you need the tunnel.'),
        ], [__('Open tunnels'), route('tunnels.index', ['current_team' => $team->slug])]);
    }

    public static function logsShared(string $owner, Team $team): self
    {
        return new self('logs-shared', __(':person shared their request logs with you', ['person' => $owner]), [
            __(':person lets you see the request logs of their tunnels in :team: each request, its status and timing, for the last :days days.', ['person' => $owner, 'team' => $team->name, 'days' => (int) config('exposehub.log_retention_days')]),
        ], [__('Open request logs'), route('logs.index', ['current_team' => $team->slug])]);
    }

    public static function removedFromTeam(string $team, string $removedBy): self
    {
        return new self('removed-from-team', __('You were removed from :team', ['team' => $team]), [
            __(':person removed you from the team :team.', ['person' => $removedBy, 'team' => $team]),
            __('Your agent tokens for this team no longer work, and its addresses and domains are no longer yours to use.'),
        ]);
    }

    public static function teamDeleted(string $team, string $deletedBy): self
    {
        return new self('team-deleted', __('The team :team was deleted', ['team' => $team]), [
            __(':person deleted the team :team.', ['person' => $deletedBy, 'team' => $team]),
            __('Its agent tokens no longer work, and its tunnels and domains were removed.'),
        ]);
    }

    public static function accountSuspended(?string $reason): self
    {
        $lines = [
            __('Your exposehub account was suspended by an administrator. You cannot sign in, and your agent tokens stopped working, which disconnected your tunnels.'),
        ];
        if ($reason !== null && $reason !== '') {
            $lines[] = __('Reason given: :reason', ['reason' => $reason]);
        }
        $lines[] = __('Your account, teams and tokens are kept. When the suspension is lifted, everything works again as before.');

        return new self('account-suspended', __('Your exposehub account was suspended'), $lines,
            ifNotYou: self::contact(__('If you think this is a mistake, say so.')));
    }

    public static function accountReactivated(): self
    {
        return new self('account-reactivated', __('Your exposehub account is active again'), [
            __('An administrator lifted the suspension of your account. You can sign in again, and your agent tokens work again.'),
        ], [__('Sign in'), route('login')]);
    }

    /**
     * @param  array<int, string>  $deletedTeams  shared teams deleted with the account
     * @param  array<int, string>  $leftTeams  teams the person was a member of
     */
    public static function accountDeletedByAdmin(int $revokedTokens, array $deletedTeams, array $leftTeams): self
    {
        $lines = [__('An administrator deleted your exposehub account and the personal data in it.')];

        if ($revokedTokens > 0) {
            $lines[] = trans_choice('{1} Your agent token was revoked and any agent using it was disconnected.|[2,*] Your :count agent tokens were revoked and any agents using them were disconnected.', $revokedTokens, ['count' => $revokedTokens]);
        }
        if ($deletedTeams !== []) {
            $lines[] = __('These teams were deleted with it, including their tokens and domains: :teams.', ['teams' => implode(', ', $deletedTeams)]);
        }
        if ($leftTeams !== []) {
            $lines[] = __('You are no longer a member of: :teams.', ['teams' => implode(', ', $leftTeams)]);
        }
        $lines[] = __('This is the last mail you receive from us. Nothing of the account can be restored.');

        return new self('account-deleted-by-admin', __('Your exposehub account was deleted'), $lines,
            ifNotYou: self::contact(__('If you think this is a mistake, say so.')));
    }

    public static function adminGranted(string $grantedBy): self
    {
        return new self('admin-granted', __('You are now an exposehub admin'), [
            __(':person made you an admin. You can now open the admin area: people, teams, live tunnels, settings and the audit log.', ['person' => $grantedBy]),
            __('Everything you do there is recorded in the audit log, with your name.'),
        ], [__('Open the admin area'), route('admin.overview')],
            self::contact(__('If you did not expect this, say so.')));
    }

    public static function adminRevoked(string $revokedBy): self
    {
        return new self('admin-revoked', __('You are no longer an exposehub admin'), [
            __(':person removed your admin rights. Your account, teams and tokens are unchanged.', ['person' => $revokedBy]),
        ]);
    }

    public static function emailVerifiedByAdmin(): self
    {
        return new self('email-verified-by-admin', __('Your email address was confirmed'), [
            __('An administrator confirmed your email address, so you can use your exposehub account now.'),
        ], [__('Sign in'), route('login')]);
    }

    public static function domainRemovedByAdmin(string $hostname, Team $team): self
    {
        return new self('domain-removed-by-admin', __('The domain :domain was removed', ['domain' => $hostname]), [
            __('An administrator removed the domain :domain from :team. Tunnels can no longer use it.', ['domain' => $hostname, 'team' => $team->name]),
        ], [__('Review domains'), route('domains.index', ['current_team' => $team->slug])],
            self::contact(__('If you think this is a mistake, say so.')));
    }

    /**
     * The same notice, greeting the given name.
     */
    public function addressedTo(string $name): self
    {
        $notice = clone $this;
        $notice->recipientName = $name;

        return $notice;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        $name = $this->recipientName ?? (isset($notifiable->name) && is_string($notifiable->name) ? $notifiable->name : null);
        if ($name !== null) {
            $mail->greeting(__('Hello :name,', ['name' => $name]));
        }
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->action !== null) {
            $mail->action($this->action[0], $this->action[1]);
        }
        if ($this->ifNotYou !== null) {
            $mail->line($this->ifNotYou);
        }

        return $mail->salutation(__('exposehub'));
    }

    /**
     * Who to turn to when an account is gone or out of reach. The operator's
     * address only if one is configured.
     */
    private static function contact(string $sentence): string
    {
        $contact = (string) config('exposehub.contact_email');

        return $contact === ''
            ? $sentence.' '.__('Tell whoever runs exposehub for your team.')
            : $sentence.' '.__('Write to :address.', ['address' => $contact]);
    }

    /**
     * Shows enough of an address to recognise it, without handing it out in full.
     */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
