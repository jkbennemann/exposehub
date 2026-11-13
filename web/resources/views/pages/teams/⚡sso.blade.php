<?php

use App\Actions\Sso\SaveTeamConnection;
use App\Models\SsoConnection;
use App\Models\Team;
use App\Support\Sso\Sso;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Where a team's owner enters the team's identity provider. It counts once a
 * platform admin has approved it.
 */
new class extends Component {
    #[Locked]
    public int $teamId;

    public string $name = '';

    public string $issuer = '';

    public string $clientId = '';

    /** Never filled from what is stored: a secret is written, not read. */
    public string $clientSecret = '';

    public string $domains = '';

    public bool $takesUnconfirmedAddresses = false;

    public function mount(Team $team): void
    {
        Gate::authorize('manageSso', $team);
        $this->teamId = $team->id;

        if (($stored = $this->connection) !== null) {
            $this->name = $stored->name;
            $this->issuer = $stored->issuer;
            $this->clientId = $stored->client_id;
            $this->domains = implode(', ', $stored->domains);
            $this->takesUnconfirmedAddresses = ! $stored->require_verified_email;
        }
    }

    /**
     * Someone who stopped being the owner while the page is open may no longer use it.
     */
    public function hydrate(): void
    {
        Gate::authorize('manageSso', $this->team);
    }

    #[Computed]
    public function team(): Team
    {
        return Team::findOrFail($this->teamId);
    }

    #[Computed]
    public function connection(): ?SsoConnection
    {
        return SsoConnection::query()->where('team_id', $this->teamId)->first();
    }

    public function save(SaveTeamConnection $save): void
    {
        Gate::authorize('manageSso', $this->team);

        $wasApproved = $this->connection?->approved_at !== null;
        $connection = $save->handle($this->team, Auth::user(), [
            'name' => $this->name,
            'issuer' => $this->issuer,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'domains' => $this->domains,
            'require_verified_email' => ! $this->takesUnconfirmedAddresses,
        ]);

        $this->clientSecret = '';
        $this->domains = implode(', ', $connection->domains);
        unset($this->connection);

        Flux::toast(variant: 'success', text: match (true) {
            $connection->approved_at !== null => __('Saved. Single sign-on stays in force.'),
            $wasApproved => __('Saved. What changed has to be approved again; until then single sign-on is off for this team.'),
            default => __('Saved. It takes effect once the people who run this service have approved it.'),
        });
    }

    public function remove(): void
    {
        Gate::authorize('manageSso', $this->team);

        $this->connection?->delete();
        unset($this->connection);
        $this->reset('name', 'issuer', 'clientId', 'clientSecret', 'domains', 'takesUnconfirmedAddresses');

        Flux::modal('remove-sso')->close();
        Flux::toast(variant: 'success', text: __('Single sign-on removed. Its accounts sign in with a password again; they ask for one by mail.'));
    }
}; ?>

<div class="space-y-6" data-test="team-sso">
    <div>
        <flux:heading>{{ __('Single sign-on') }}</flux:heading>
        <flux:subheading>
            {{ __('Let people sign in through your organisation\'s identity provider, such as Okta, Microsoft Entra ID or Google Workspace. Whoever it signs in with an address of your domains gets an account and becomes a member of :team.', ['team' => $this->team->name]) }}
        </flux:subheading>
    </div>

    @if ($this->connection)
        <p class="rounded-md border border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700" data-test="team-sso-status">
            @if ($this->connection->approved_at)
                <span class="font-medium text-teal-700 dark:text-live">{{ __('In force') }}</span>
                {{ __('since :date. Accounts of :domains sign in with :name only.', ['date' => $this->connection->approved_at->isoFormat('D MMMM YYYY'), 'domains' => implode(', ', $this->connection->domains), 'name' => $this->connection->name]) }}
            @else
                <span class="font-medium text-zinc-900 dark:text-white">{{ __('Waiting for approval.') }}</span>
                {{ __('The people who run this service check that the domains are yours before it takes effect. Nothing changes for anyone until then.') }}
            @endif
        </p>
    @endif

    <form wire:submit="save" class="space-y-5">
        <flux:input wire:model="name" :label="__('What the provider is called')" placeholder="Okta" required data-test="team-sso-name" />
        <flux:input wire:model="issuer" :label="__('Issuer')" placeholder="https://example.okta.com" :description="__('As your provider shows it, character for character.')" required data-test="team-sso-issuer" />
        <flux:input wire:model="clientId" :label="__('Client ID')" required autocomplete="off" data-test="team-sso-client-id" />
        <flux:input wire:model="clientSecret" type="password" :label="__('Client secret')" autocomplete="off"
            :description="$this->connection ? __('Stored. Leave empty to keep it; it is never shown again.') : __('It is stored encrypted and never shown again.')" data-test="team-sso-client-secret" />
        <flux:input wire:model="domains" :label="__('Email domains')" placeholder="example.com, example.org"
            :description="__('The domains of the addresses your provider signs in. Accounts with such an address will sign in through it only.')" required data-test="team-sso-domains" />
        <flux:checkbox wire:model="takesUnconfirmedAddresses" :label="__('My provider does not say whether an address is confirmed (Microsoft Entra ID)')"
            :description="__('Leave this off unless sign-in fails for that reason. With it on, the provider\'s word for an address is taken as it is.')" data-test="team-sso-unconfirmed" />

        <div class="rounded-md bg-zinc-100 px-4 py-3 text-sm dark:bg-zinc-800/60">
            <p class="text-zinc-600 dark:text-zinc-300">{{ __('At the provider, create an OpenID Connect web application with the authorization code flow, and register this redirect address:') }}</p>
            <p class="mt-1 font-mono [overflow-wrap:anywhere]" data-test="team-sso-redirect">{{ Sso::redirectUri() }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" data-test="team-sso-save">{{ $this->connection ? __('Save changes') : __('Save and ask for approval') }}</flux:button>
            @if ($this->connection)
                <flux:modal.trigger name="remove-sso">
                    <flux:button variant="ghost" data-test="team-sso-remove">{{ __('Remove single sign-on') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Changing the issuer or the domains has to be approved again. Changing the client ID or secret does not.') }}</p>
    </form>

    <flux:modal name="remove-sso" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove single sign-on?') }}</flux:heading>
                <flux:subheading>{{ __('People keep their accounts and stay in the team. They can no longer sign in through the provider, and get a password by asking for a reset mail.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="remove" data-test="team-sso-remove-confirm">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
