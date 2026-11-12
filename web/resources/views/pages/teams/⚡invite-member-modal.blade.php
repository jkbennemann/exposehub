<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function createInvitation(): void
    {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
            // Never the owner role: an admin could otherwise invite someone as owner,
            // who could then remove the team's actual owner.
            'inviteRole' => ['required', 'string', Rule::in(array_column(TeamRole::assignable(), 'value'))],
        ]);

        // Invitations are mailed to any address from this service's sender, so
        // nobody may use them to send mail in bulk or to one address repeatedly
        // (inviting, cancelling and inviting again). Per team for the address,
        // so one person cannot stop other teams from inviting it.
        $perInviter = 'team-invitations:user:'.Auth::id();
        $perAddress = 'team-invitations:team:'.$this->team->id.':'.Str::lower($validated['inviteEmail']);
        if (RateLimiter::tooManyAttempts($perInviter, 10) || RateLimiter::tooManyAttempts($perAddress, 2)) {
            $this->addError('inviteEmail', __('Too many invitations. Try again later.'));

            return;
        }
        RateLimiter::hit($perInviter, 3600);
        RateLimiter::hit($perAddress, 86400);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => TeamRole::from($validated['inviteRole']),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        $this->dispatch('close-modal', name: 'invite-member');

        Flux::toast(variant: 'success', text: __('Invitation sent.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return TeamRole::assignable();
    }
}; ?>

<flux:modal name="invite-member" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="createInvitation" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Invite a team member') }}</flux:heading>
            <flux:subheading>{{ __('Send an invitation to join this team.') }}</flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="inviteEmail" type="email" :label="__('Email address')" required data-test="invite-email" />

            <flux:select wire:model="inviteRole" :label="__('Role')" data-test="invite-role">
                @foreach ($this->availableRoles as $role)
                    <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="invite-submit">{{ __('Send invitation') }}</flux:button>
        </div>
    </form>
</flux:modal>
