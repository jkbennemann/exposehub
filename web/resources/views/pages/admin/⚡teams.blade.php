<?php

use App\Actions\Admin\AdminActions;
use App\Concerns\RequiresAdmin;
use App\Models\CustomDomain;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Teams')] #[RequiresAdmin] class extends Component {
    use WithPagination;

    #[Url]
    public string $q = '';

    #[Url]
    public bool $personal = false;

    public ?int $open = null;

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedPersonal(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    #[Computed]
    public function teams(): LengthAwarePaginator
    {
        return Team::query()
            ->when(! $this->personal && trim($this->q) === '', fn (Builder $q) => $q->where('is_personal', false))
            ->when(trim($this->q) !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->q))).'%';
                $query->where(fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(slug) like ?', [$term])
                    ->orWhereHas('customDomains', fn (Builder $d) => $d->whereRaw('lower(hostname) like ?', [$term])));
            })
            ->withCount(['memberships', 'customDomains', 'agentTokens as active_tokens' => fn (Builder $q) => $q->usable()])
            ->orderBy('name')
            ->paginate(40);
    }

    #[Computed]
    public function detail(): ?Team
    {
        return $this->open ? Team::with([
            'members:id,name,email',
            'customDomains' => fn ($q) => $q->orderBy('hostname'),
            'agentTokens' => fn ($q) => $q->usable()->with('user:id,name')->latest('id'),
        ])->find($this->open) : null;
    }

    public function revokeToken(int $tokenId): void
    {
        $token = $this->detail?->agentTokens->firstWhere('id', $tokenId);
        abort_if($token === null, 404);
        app(AdminActions::class)->revokeToken(Auth::user(), $token);
        Flux::modal("revoke-{$tokenId}")->close();
        Flux::toast(variant: 'success', text: __('Token revoked. Its agents disconnect within seconds.'));
        unset($this->detail, $this->teams);
    }

    public function removeDomain(int $domainId): void
    {
        $domain = CustomDomain::where('team_id', $this->open)->findOrFail($domainId);
        try {
            app(AdminActions::class)->removeDomain(Auth::user(), $domain);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::modal("remove-{$domainId}")->close();
        Flux::toast(variant: 'success', text: __('Domain removed.'));
        unset($this->detail, $this->teams);
    }
}; ?>

<x-admin.frame :title="__('Teams')" :lead="__('Shared teams with their members, active tokens and domains. Open a team to revoke a token or remove a domain, for example after abuse or a leaked token.')">
    <div class="flex flex-wrap items-center gap-4">
        <label class="min-w-[16rem] flex-1">
            <span class="sr-only">{{ __('Search teams or domains') }}</span>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Search by team, slug or domain') }}"
                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="teams-search">
        </label>
        <flux:checkbox wire:model.live="personal" :label="__('Include personal teams')" />
    </div>

    <div class="mt-6 border-t border-zinc-200 dark:border-zinc-700" data-test="teams-list">
        @forelse ($this->teams as $team)
            @php $isOpen = $open === $team->id; @endphp
            <div class="border-b border-zinc-200 dark:border-zinc-700" wire:key="team-{{ $team->id }}">
                <button type="button" wire:click="toggle({{ $team->id }})" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" data-test="team-row"
                    @class(['grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 py-3 text-left hover:bg-zinc-100 sm:grid-cols-[minmax(0,1fr)_6rem_6rem_6rem] dark:hover:bg-zinc-800/60', 'bg-zinc-100 shadow-[inset_3px_0_0_var(--color-cable)] dark:bg-zinc-800/60' => $isOpen])>
                    <span class="min-w-0 pl-2">
                        <span class="font-medium">{{ $team->name }}</span>
                        <span class="ml-2 font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $team->slug }}</span>
                        @if ($team->is_personal)<span class="ml-2 text-sm text-zinc-500">{{ __('personal') }}</span>@endif
                    </span>
                    <span class="text-right text-sm tabular-nums max-sm:hidden">{{ trans_choice('{1} :count member|[0,*] :count members', $team->memberships_count, ['count' => $team->memberships_count]) }}</span>
                    <span class="text-right text-sm tabular-nums max-sm:hidden">{{ trans_choice('{1} :count token|[0,*] :count tokens', $team->active_tokens, ['count' => $team->active_tokens]) }}</span>
                    <span class="pr-2 text-right text-sm tabular-nums">{{ trans_choice('{1} :count domain|[0,*] :count domains', $team->custom_domains_count, ['count' => $team->custom_domains_count]) }}</span>
                </button>

                @if ($isOpen && $this->detail)
                    @php $d = $this->detail; @endphp
                    <div class="grid gap-x-12 gap-y-8 px-2 pt-3 pb-8 lg:grid-cols-3" data-test="team-detail">
                        <section>
                            <h3 class="font-display text-lg font-semibold">{{ __('Members') }}</h3>
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($d->members as $member)
                                    <li class="flex justify-between gap-3">
                                        <a href="{{ route('admin.person', $member) }}" wire:navigate class="truncate hover:underline">{{ $member->name }}</a>
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $member->pivot->role instanceof \BackedEnum ? $member->pivot->role->value : $member->pivot->role }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                        <section>
                            <h3 class="font-display text-lg font-semibold">{{ __('Active tokens') }}</h3>
                            <ul class="mt-2 space-y-2 text-sm">
                                @forelse ($d->agentTokens as $token)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="min-w-0 truncate">{{ $token->name }} <span class="font-mono text-zinc-500">{{ $token->prefix }}…</span> <span class="text-zinc-500">{{ $token->user?->name }}</span></span>
                                        <flux:modal.trigger :name="'revoke-'.$token->id"><flux:button size="xs" variant="ghost">{{ __('Revoke') }}</flux:button></flux:modal.trigger>
                                    </li>
                                    <flux:modal :name="'revoke-'.$token->id" class="max-w-lg">
                                        <div class="space-y-6">
                                            <div>
                                                <flux:heading size="lg">{{ __('Revoke :name', ['name' => $token->name]) }}</flux:heading>
                                                <flux:subheading>{{ __('Agents using it are disconnected within seconds and cannot reconnect. Its owner gets a mail.') }}</flux:subheading>
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <flux:modal.close><flux:button variant="filled">{{ __('Keep token') }}</flux:button></flux:modal.close>
                                                <flux:button variant="danger" wire:click="revokeToken({{ $token->id }})">{{ __('Revoke token') }}</flux:button>
                                            </div>
                                        </div>
                                    </flux:modal>
                                @empty
                                    <li class="text-zinc-500">{{ __('None.') }}</li>
                                @endforelse
                            </ul>
                        </section>
                        <section>
                            <h3 class="font-display text-lg font-semibold">{{ __('Domains') }}</h3>
                            <ul class="mt-2 space-y-2 text-sm">
                                @forelse ($d->customDomains as $domain)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="min-w-0 truncate"><span class="font-mono">{{ $domain->hostname }}</span>
                                            <span class="text-zinc-500">{{ $domain->isConnected() ? __('connected') : ($domain->isVerified() ? __('verified, routing pending') : __('waiting for DNS')) }}</span></span>
                                        <flux:modal.trigger :name="'remove-'.$domain->id"><flux:button size="xs" variant="ghost">{{ __('Remove') }}</flux:button></flux:modal.trigger>
                                    </li>
                                    <flux:modal :name="'remove-'.$domain->id" class="max-w-lg">
                                        <div class="space-y-6">
                                            <div>
                                                <flux:heading size="lg">{{ __('Remove :domain', ['domain' => $domain->hostname]) }}</flux:heading>
                                                <flux:subheading>{{ __('Tunnels on it stop working at once and its certificate is deleted. The team owner gets a mail.') }}</flux:subheading>
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <flux:modal.close><flux:button variant="filled">{{ __('Keep domain') }}</flux:button></flux:modal.close>
                                                <flux:button variant="danger" wire:click="removeDomain({{ $domain->id }})">{{ __('Remove domain') }}</flux:button>
                                            </div>
                                        </div>
                                    </flux:modal>
                                @empty
                                    <li class="text-zinc-500">{{ __('None.') }}</li>
                                @endforelse
                            </ul>
                        </section>
                    </div>
                @endif
            </div>
        @empty
            <p class="border-b border-zinc-200 py-10 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('No teams match.') }}</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $this->teams->links() }}</div>
</x-admin.frame>
