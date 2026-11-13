<?php

use App\Actions\Admin\AdminActions;
use App\Concerns\RequiresAdmin;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\Sso;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Single sign-on')] #[RequiresAdmin] class extends Component {
    /**
     * @return Collection<int, SsoConnection>
     */
    #[Computed]
    public function providers(): Collection
    {
        // Those waiting come first.
        return SsoConnection::query()->with(['team', 'creator:id,name,email'])->whereHas('team')
            ->orderByRaw('approved_at is not null')->latest('updated_at')->get();
    }

    /**
     * @param  string  $seen  the fingerprint of the entry as this page showed it
     */
    public function approve(int $id, string $seen, AdminActions $actions): void
    {
        $this->act(fn (SsoConnection $provider) => $actions->approveSso(Auth::user(), $provider, $seen), $id,
            __('Approved. People can sign in through it now.'));
    }

    /**
     * The accounts that already have an address of one of the entry's
     * domains, and how many of them administer the install: what an approval
     * hands to the provider.
     *
     * @return array{accounts: int, admins: int}
     */
    public function existing(SsoConnection $provider): array
    {
        $accounts = User::query()->where('is_demo', false)->where(function ($users) use ($provider) {
            foreach (Sso::normalizeDomains($provider->domains) as $domain) {
                $users->orWhereRaw("lower(email) like ? escape '!'", ['%@'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $domain)]);
            }
        })->get(['id', 'email', 'is_admin', 'email_verified_at', 'suspended_at']);

        return ['accounts' => $accounts->count(), 'admins' => $accounts->filter(fn (User $user) => $user->isAdmin() || $user->hasOwnerAddress())->count()];
    }

    public function revoke(int $id, AdminActions $actions): void
    {
        $this->act(fn (SsoConnection $provider) => $actions->revokeSso(Auth::user(), $provider), $id,
            __('Approval withdrawn. Nobody signs in through it until it is approved again.'));
    }

    private function act(callable $action, int $id, string $done): void
    {
        try {
            $action(SsoConnection::query()->findOrFail($id));
            Flux::toast(variant: 'success', text: $done);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
        unset($this->providers);
    }
}; ?>

<x-admin.frame :title="__('Single sign-on')" :lead="__('Identity providers that teams entered for themselves. One takes effect only when you approve it here.')">
    <div class="max-w-3xl rounded-md border border-cable/70 bg-cable/10 px-4 py-3 text-sm text-zinc-800 dark:border-cable/40 dark:bg-cable/5 dark:text-zinc-200">
        <p><strong class="font-semibold">{{ __('What approving means.') }}</strong>
            {{ __('The provider then signs people in as any address of the domains listed, makes accounts for them, and makes them members of the team. Approve only when you know that the domains belong to the people behind the team and that the issuer is their provider.') }}</p>
    </div>

    <ul class="mt-6 max-w-3xl border-t border-zinc-200 dark:border-zinc-700" data-test="admin-sso-list">
        @forelse ($this->providers as $provider)
            <li class="border-b border-zinc-200 py-5 dark:border-zinc-700" wire:key="sso-{{ $provider->id }}">
                <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                    <div>
                        <p class="font-display text-xl font-semibold">{{ $provider->team->name }}</p>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($provider->creator)
                                {{ __('entered by :name (:email)', ['name' => $provider->creator->name, 'email' => $provider->creator->email]) }}
                            @endif
                        </p>
                    </div>
                    @if ($provider->approved_at)
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium text-teal-700 dark:text-live">{{ __('In force since :date', ['date' => $provider->approved_at->isoFormat('D MMM YYYY')]) }}</span>
                            <flux:button size="sm" variant="ghost" wire:click="revoke({{ $provider->id }})" wire:confirm="{{ __('Withdraw the approval? Nobody can sign in through this provider until it is approved again.') }}" data-test="admin-sso-revoke">{{ __('Withdraw') }}</flux:button>
                        </div>
                    @else
                        <flux:button size="sm" variant="primary" wire:click="approve({{ $provider->id }}, '{{ $provider->fingerprint() }}')" wire:confirm="{{ __('Approve :name at :issuer for :domains?', ['name' => $provider->name, 'issuer' => $provider->issuer, 'domains' => implode(', ', $provider->domains)]) }}" data-test="admin-sso-approve">{{ __('Approve') }}</flux:button>
                    @endif
                </div>
                <dl class="mt-3 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-6 gap-y-1.5 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Called') }}</dt><dd>{{ $provider->name }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Issuer') }}</dt><dd class="font-mono [overflow-wrap:anywhere]">{{ $provider->issuer }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Client ID') }}</dt><dd class="font-mono [overflow-wrap:anywhere]">{{ $provider->client_id }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Signs in addresses of') }}</dt><dd class="font-mono">{{ implode(', ', $provider->domains) }}</dd>
                    @php($existing = $this->existing($provider))
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Accounts there now') }}</dt>
                    <dd data-test="admin-sso-existing">
                        {{ trans_choice('{0} none with such an address yet|{1} one account already has such an address; it will sign in through this provider and join the team|[2,*] :n accounts already have such an address; they will sign in through this provider and join the team', $existing['accounts'], ['n' => $existing['accounts']]) }}
                        @if ($existing['admins'] > 0)
                            <span class="text-fault">{{ trans_choice('{1} One of them administers this service and stays out of it: an admin is never signed in by a team\'s provider.|[2,*] :n of them administer this service and stay out of it: an admin is never signed in by a team\'s provider.', $existing['admins'], ['n' => $existing['admins']]) }}</span>
                        @endif
                    </dd>
                    @unless ($provider->require_verified_email)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Mind') }}</dt><dd class="text-fault">{{ __('Takes addresses the provider has not confirmed.') }}</dd>
                    @endunless
                </dl>
            </li>
        @empty
            <li class="py-6 text-zinc-600 dark:text-zinc-300">{{ __('No team has entered an identity provider yet. A team\'s owner does that in the team\'s settings.') }}</li>
        @endforelse
    </ul>

    <p class="mt-6 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">
        {{ __('Register this redirect address with every provider: :uri', ['uri' => Sso::redirectUri()]) }}
    </p>
</x-admin.frame>
