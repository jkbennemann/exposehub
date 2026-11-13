<?php

use App\Actions\Tokens\IssueAgentToken;
use App\Concerns\RequiresTeamMembership;
use App\Models\AgentToken;
use App\Models\Team;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agent tokens')] #[RequiresTeamMembership] class extends Component {
    /** The team this page was opened for. Locked so a request cannot swap it. */
    #[Locked]
    public int $teamId;

    public string $name = '';

    /** The token just created. It exists only here, until the page is left. */
    public ?string $plainToken = null;

    public function mount(): void
    {
        $this->teamId = Auth::user()->currentTeam->id;
    }

    #[Computed]
    public function team(): Team
    {
        return Team::findOrFail($this->teamId);
    }

    #[Computed]
    public function seesAll(): bool
    {
        return Gate::allows('viewAll', [AgentToken::class, $this->team]);
    }

    /**
     * @return Collection<int, AgentToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return $this->team->agentTokens()
            ->usable()
            ->when(! $this->seesAll, fn ($query) => $query->where('user_id', Auth::id()))
            ->with('user:id,name')
            ->latest('id')
            ->get();
    }

    public function createToken(IssueAgentToken $issue): void
    {
        $allowed = Gate::inspect('create', [AgentToken::class, $this->team]);
        // A limit that was reached is said in the form; anything else is refused outright.
        if ($allowed->denied() && $allowed->message() !== null) {
            $this->addError('name', $allowed->message());

            return;
        }
        $allowed->authorize();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        [, $this->plainToken] = $issue->handle(Auth::user(), $this->team, $validated['name']);

        $this->reset('name');
        unset($this->tokens);

        Flux::modal('create-token')->close();

        Flux::toast(variant: 'success', text: __('Token created.'));
    }

    public function revokeToken(int $tokenId): void
    {
        $token = $this->team->agentTokens()->findOrFail($tokenId);

        Gate::authorize('revoke', $token);

        $token->update(['revoked_at' => now()]);
        unset($this->tokens);

        if ($token->user_id !== Auth::id()) {
            AccountMail::send($token->user, AccountNotice::agentTokenRevoked($token->name, $this->team, Auth::user()->name));
        }

        Flux::modal("revoke-token-{$tokenId}")->close();

        Flux::toast(variant: 'success', text: __('Token revoked.'));
    }

    public function dismissToken(): void
    {
        $this->plainToken = null;
    }
}; ?>

<section class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
        <div>
            <h1 class="page-title">{{ __('Agent tokens') }}</h1>
            <p class="mt-3 max-w-[58ch] text-zinc-600 dark:text-zinc-300">
                {{ __('A token lets the agent on a machine open tunnels for :team. Create one per machine, so you can revoke a single one when a laptop is lost.', ['team' => $this->team->name]) }}
            </p>
        </div>

        <flux:modal.trigger name="create-token">
            <flux:button variant="primary" icon="plus" x-data="" x-on:click.prevent="$dispatch('open-modal', 'create-token')" data-test="tokens-create-button">
                {{ __('Create token') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    @if ($plainToken)
        {{-- Shown once: only the hash is stored, so this cannot be displayed again. --}}
        <div class="mt-8 rounded-md bg-zinc-900 p-5 text-zinc-100 dark:bg-zinc-950 dark:ring-1 dark:ring-cable/60" data-test="token-reveal" x-data="{ copied: false }">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-xl font-semibold">{{ __('Copy this token now') }}</h2>
                <span class="text-sm text-zinc-300">{{ __('It is shown once and cannot be recovered.') }}</span>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <code class="min-w-0 flex-1 font-mono text-lg [overflow-wrap:anywhere] text-cable" data-test="token-plain">{{ $plainToken }}</code>
                <button
                    type="button"
                    class="shrink-0 rounded bg-cable px-3 py-1.5 font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cable"
                    x-on:click="navigator.clipboard.writeText(@js($plainToken)); copied = true; setTimeout(() => copied = false, 2000)"
                    x-text="copied ? @js(__('Copied')) : @js(__('Copy token'))"
                >{{ __('Copy token') }}</button>
            </div>

            <p class="mt-5 text-sm text-zinc-300">{{ __('Then log in on the machine and paste the token when it asks. No agent yet? Install it first:') }} <code class="font-mono text-zinc-100">curl -fsSL {{ config('exposehub.agent_server') }}/install.sh | sh</code></p>
            <code class="mt-1 block font-mono text-[0.9375rem] leading-relaxed [overflow-wrap:anywhere] text-zinc-100">exposehub login</code>

            <div class="mt-5">
                <button type="button" wire:click="dismissToken" class="text-sm font-medium text-zinc-300 underline underline-offset-4 hover:text-white" data-test="token-dismiss">
                    {{ __('I have copied it') }}
                </button>
            </div>
        </div>
    @endif

    <div class="mt-10 border-t border-zinc-200 dark:border-zinc-700">
        @forelse ($this->tokens as $token)
            <div class="grid grid-cols-[1fr_auto] items-center gap-x-6 gap-y-1 border-b border-zinc-200 py-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1.2fr)_minmax(0,1.6fr)_auto] dark:border-zinc-700" data-test="token-row" wire:key="token-{{ $token->id }}">
                <div class="min-w-0 truncate font-medium">{{ $token->name }}</div>

                <div class="font-mono text-[0.9375rem] text-zinc-600 max-sm:order-3 dark:text-zinc-300">{{ $token->prefix }}…</div>

                <div class="min-w-0 truncate text-sm text-zinc-500 max-sm:order-4 max-sm:col-span-2 dark:text-zinc-400">
                    @if ($this->seesAll)
                        {{ __(':name, :when', ['name' => $token->user->name, 'when' => $token->created_at->diffForHumans()]) }}
                    @else
                        {{ __('Created :when', ['when' => $token->created_at->diffForHumans()]) }}
                    @endif
                </div>

                <div class="max-sm:order-2">
                    <flux:modal.trigger :name="'revoke-token-'.$token->id">
                        <flux:button variant="ghost" size="sm" x-data="" x-on:click.prevent="$dispatch('open-modal', 'revoke-token-{{ $token->id }}')" data-test="token-revoke-button">
                            {{ __('Revoke') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </div>

            <flux:modal :name="'revoke-token-'.$token->id" focusable class="max-w-lg">
                <form wire:submit="revokeToken({{ $token->id }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Revoke :name', ['name' => $token->name]) }}</flux:heading>
                        <flux:subheading>
                            {{ __('Agents using this token are disconnected and cannot reconnect. This cannot be undone.') }}
                        </flux:subheading>
                    </div>

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Keep token') }}</flux:button>
                        </flux:modal.close>

                        <flux:button variant="danger" type="submit" data-test="token-revoke-confirm">
                            {{ __('Revoke token') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        @empty
            <div class="border-b border-zinc-200 py-12 dark:border-zinc-700" data-test="tokens-empty">
                <p class="font-display text-xl font-semibold">{{ __('No tokens yet') }}</p>
                <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('Create one to connect the agent on your machine.') }}</p>
            </div>
        @endforelse
    </div>

    <flux:modal name="create-token" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createToken" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create an agent token') }}</flux:heading>
                <flux:subheading>{{ __('Name it after the machine it will live on.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Work laptop')" type="text" required autofocus data-test="create-token-name" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit" data-test="create-token-submit">
                    {{ __('Create token') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
