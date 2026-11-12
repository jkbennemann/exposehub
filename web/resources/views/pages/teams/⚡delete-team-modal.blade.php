<?php

use App\Data\UserTeam;
use App\Models\Team;
use App\Models\LogGrant;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Domains\Provisioner;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public string $deleteName = '';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    #[Computed]
    public function deleteConfirmLabel(): string
    {
        return __('Type ":name" to confirm', ['name' => $this->team->name]);
    }

    public function deleteTeam(Provisioner $provisioner): void
    {
        Gate::authorize('delete', $this->team);

        $validated = $this->validate([
            'deleteName' => ['required', 'string'],
        ]);

        if ($validated['deleteName'] !== $this->team->name) {
            $this->addError('deleteName', __('The team name does not match.'));

            return;
        }

        $user = Auth::user();

        $fallbackTeam = $user->isCurrentTeam($this->team)
            ? $user->fallbackTeam($this->team)
            : null;

        $others = $this->team->members()->where('users.id', '!=', $user->id)->get();

        // The certificate setup of the team's domains goes first, while the rows still name it.
        foreach ($this->team->customDomains as $domain) {
            try {
                $provisioner->remove($domain);
            } catch (Throwable $e) {
                report($e);
            }
        }

        DB::transaction(function () use ($user) {
            User::where('current_team_id', $this->team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchTeam($affectedUser->personalTeam()));

            $this->team->invitations()->delete();
            $this->team->memberships()->delete();
            // Domains are deleted outright, so another team can connect them later.
            $this->team->customDomains()->delete();
            // The team row is only soft-deleted, so the database never cascades
            // to these. Saved names have to be free for other teams to save.
            // Networks go first and take their rule links with them.
            $this->team->accessNetworks()->delete();
            $this->team->savedAddresses()->delete();
            LogGrant::forgetTeam($this->team);
            $this->team->delete();
        });

        foreach ($others as $member) {
            AccountMail::send($member, AccountNotice::teamDeleted($this->team->name, $user->name));
        }

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Flux::toast(variant: 'success', text: __('Team deleted.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function otherTeams(): Collection
    {
        return Auth::user()->toUserTeams();
    }
}; ?>

<flux:modal name="delete-team" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="deleteTeam" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure?') }}</flux:heading>
            <flux:subheading>
                {{ __('This action cannot be undone. This will permanently delete the team ":name".', ['name' => $team->name]) }}
            </flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="deleteName" :label="$this->deleteConfirmLabel" required data-test="delete-team-name" />
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="submit" data-test="delete-team-confirm">
                {{ __('Delete team') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
