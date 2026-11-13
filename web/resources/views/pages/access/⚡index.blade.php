<?php

use App\Concerns\RequiresTeamMembership;
use App\Enums\TeamPermission;
use App\Models\CustomDomain;
use App\Models\Network;
use App\Models\SavedAddress;
use App\Models\Team;
use App\Support\Access\Cidr;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Access')] #[RequiresTeamMembership] class extends Component {
    #[Locked]
    public int $teamId;

    /** The address this browser connects from, for "Add my address". */
    #[Locked]
    public string $myAddress = '';

    public string $name = '';

    // The rule being edited: what it belongs to, and its new value.
    #[Locked]
    public ?string $ruleFor = null;

    #[Locked]
    public ?int $ruleId = null;

    public string $ruleAccess = 'anyone';

    /** @var array<int, int|string> */
    public array $ruleNetworks = [];

    // The network being edited, or a new one.
    #[Locked]
    public ?int $networkId = null;

    public string $networkName = '';

    public string $networkEntries = '';

    public function mount(): void
    {
        $this->teamId = Auth::user()->currentTeam->id;
        $this->myAddress = (string) request()->ip();
    }

    #[Computed]
    public function team(): Team
    {
        return Team::with('networks:id,name')->findOrFail($this->teamId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->hasTeamPermission($this->team, TeamPermission::ManageAccess);
    }

    /**
     * @return Collection<int, Network>
     */
    #[Computed]
    public function networkList(): Collection
    {
        return $this->team->accessNetworks()->withCount(['savedAddresses', 'customDomains'])->orderBy('name')->get();
    }

    /**
     * @return Collection<int, SavedAddress>
     */
    #[Computed]
    public function addresses(): Collection
    {
        return $this->team->savedAddresses()->with(['networks:id,name', 'user:id,name'])->orderBy('hostname')->get();
    }

    /**
     * @return Collection<int, CustomDomain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->team->customDomains()->whereNotNull('verified_at')->with('networks:id,name')->orderBy('hostname')->get();
    }

    public function domainSuffix(): string
    {
        return '.'.config('exposehub.tunnel_domain');
    }

    /**
     * In words: who may reach an address under this rule.
     */
    public function describe(Model $holder): string
    {
        $team = $this->team;
        if ($holder->access === 'default') {
            return $team->access === 'networks'
                ? __('Team default: only from :networks', ['networks' => $this->names($team)])
                : __('Team default: anyone');
        }

        return $holder->access === 'networks' ? __('Only from :networks', ['networks' => $this->names($holder)]) : __('Anyone');
    }

    public function restricts(Model $holder): bool
    {
        return $holder->access === 'networks' || ($holder->access === 'default' && $this->team->access === 'networks');
    }

    private function names(Model $holder): string
    {
        $names = $holder->networks->pluck('name');

        return $names->isEmpty() ? __('no network yet, so nobody') : $names->join(', ', __(' and '));
    }

    /**
     * May the signed-in person change the rule of this saved address?
     *
     * Not public: a public method can be called from the browser with any
     * id, and the answer would tell which saved addresses exist in other teams.
     */
    protected function mayEdit(SavedAddress $address): bool
    {
        return $this->canManage || $address->user_id === Auth::id();
    }

    public function saveAddress(): void
    {
        // A saved name is kept from every other team, which a visitor without an account must not be able to do.
        if (Auth::user()->is_demo) {
            $this->addError('name', __('Saving an address is not part of the demo. Create an account to keep a name for your team.'));

            return;
        }

        $this->name = Str::lower(trim($this->name));
        $hostname = $this->name.$this->domainSuffix();
        $this->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
        ], ['name.regex' => __('Use letters, digits and hyphens, not at the start or end.')]);

        if ($hostname === parse_url((string) config('exposehub.agent_server'), PHP_URL_HOST)) {
            $this->addError('name', __('This name belongs to the tunnel server.'));

            return;
        }
        if (SavedAddress::where('hostname', $hostname)->exists()) {
            $this->addError('name', __('This address is already saved, by your team or another one.'));

            return;
        }
        if ($this->team->savedAddresses()->count() >= SavedAddress::LIMIT) {
            $this->addError('name', __('A team can save up to :n addresses.', ['n' => SavedAddress::LIMIT]));

            return;
        }
        // Per person too, across teams: otherwise a new team brings a fresh allowance.
        $perUser = (int) config('exposehub.limits.saved_addresses_per_user');
        if (SavedAddress::where('user_id', Auth::id())->count() >= $perUser) {
            $this->addError('name', __('You can save up to :n addresses across all your teams.', ['n' => $perUser]));

            return;
        }

        $this->team->savedAddresses()->create(['user_id' => Auth::id(), 'hostname' => $hostname, 'access' => 'default']);
        $this->reset('name');
        unset($this->addresses);
        Flux::toast(variant: 'success', text: __('Address saved. Only your team can open tunnels on it now.'));
    }

    public function removeAddress(int $id): void
    {
        $address = $this->team->savedAddresses()->findOrFail($id);
        abort_unless($this->mayEdit($address), 403);
        $address->delete();
        unset($this->addresses);
        Flux::modal("remove-address-{$id}")->close();
        Flux::toast(variant: 'success', text: __('Address removed. Anyone can take the name once it is free.'));
    }

    /**
     * Open the rule form for the team default ("team"), a saved address or a domain.
     */
    public function editRule(string $for, ?int $id = null): void
    {
        $holder = $this->holder($for, $id);
        $this->ruleFor = $for;
        $this->ruleId = $id;
        $this->ruleAccess = $holder->access;
        $this->ruleNetworks = $holder->networks->pluck('id')->all();
        $this->resetErrorBag();
        Flux::modal('rule')->show();
    }

    public function saveRule(): void
    {
        $holder = $this->holder((string) $this->ruleFor, $this->ruleId);
        $choices = $this->ruleFor === 'team' ? ['anyone', 'networks'] : ['default', 'anyone', 'networks'];
        $this->validate([
            'ruleAccess' => ['required', Rule::in($choices)],
            'ruleNetworks' => ['array'],
            'ruleNetworks.*' => ['integer', Rule::exists('networks', 'id')->where('team_id', $this->teamId)],
        ]);
        if ($this->ruleAccess === 'networks' && $this->ruleNetworks === []) {
            $this->addError('ruleNetworks', __('Choose at least one network.'));

            return;
        }
        // Owners and admins decide whether the team is limited; members cannot
        // open an address the team keeps closed.
        if (! $this->canManage && $this->ruleAccess === 'anyone' && $this->team->access === 'networks') {
            $this->addError('ruleAccess', __('Your team limits its tunnels to its networks. A team owner or admin can open an address to anyone.'));

            return;
        }

        $holder->update(['access' => $this->ruleAccess]);
        $holder->networks()->sync($this->ruleAccess === 'networks' ? array_map('intval', $this->ruleNetworks) : []);

        unset($this->team, $this->addresses, $this->domains, $this->networkList);
        Flux::modal('rule')->close();
        Flux::toast(variant: 'success', text: __('Saved. Running tunnels follow within seconds.'));
    }

    private function holder(string $for, ?int $id): Team|SavedAddress|CustomDomain
    {
        return match ($for) {
            'team' => tap($this->team, fn () => abort_unless($this->canManage, 403)),
            'address' => tap($this->team->savedAddresses()->with('networks:id')->findOrFail($id), fn (SavedAddress $a) => abort_unless($this->mayEdit($a), 403)),
            'domain' => tap($this->team->customDomains()->whereNotNull('verified_at')->with('networks:id')->findOrFail($id), fn () => abort_unless($this->canManage, 403)),
            default => abort(404),
        };
    }

    public function editNetwork(?int $id = null): void
    {
        abort_unless($this->canManage, 403);
        $network = $id ? $this->team->accessNetworks()->findOrFail($id) : null;
        $this->networkId = $network?->id;
        $this->networkName = $network->name ?? '';
        $this->networkEntries = $network
            ? collect($network->entries)->map(fn ($e) => $e['cidr'].($e['note'] ? '   # '.$e['note'] : ''))->join("\n")
            : '';
        $this->resetErrorBag();
        Flux::modal('network')->show();
    }

    public function addMyAddress(): void
    {
        $line = $this->myAddress.'   # '.__('added from :name\'s connection', ['name' => Auth::user()->name]);
        $this->networkEntries = trim($this->networkEntries) === '' ? $line : rtrim($this->networkEntries)."\n".$line;
    }

    public function saveNetwork(): void
    {
        abort_unless($this->canManage, 403);
        $this->networkName = Str::trim($this->networkName);
        $this->validate([
            'networkName' => ['required', 'string', 'max:100',
                // The tunnel server and the --allow flag find a network by its
                // name ignoring case, so two names may not differ in case only.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $taken = $this->team->accessNetworks()
                        ->when($this->networkId, fn ($query) => $query->whereKeyNot($this->networkId))
                        ->pluck('name')
                        ->contains(fn (string $name) => Str::lower(Str::trim($name)) === Str::lower((string) $value));
                    if ($taken) {
                        $fail(__('Your team already has a network with this name.'));
                    }
                }],
            'networkEntries' => ['required', 'string', 'max:20000'],
        ]);

        // Every team's networks go to the tunnel server in one shared snapshot.
        $limit = (int) config('exposehub.limits.networks_per_team');
        if (! $this->networkId && $this->team->accessNetworks()->count() >= $limit) {
            $this->addError('networkName', __('A team can have up to :n networks.', ['n' => $limit]));

            return;
        }

        $entries = [];
        foreach (preg_split('/\R/', $this->networkEntries) as $i => $line) {
            [$value, $note] = array_pad(explode('#', $line, 2), 2, null);
            if (trim($value) === '') {
                continue;
            }
            $cidr = Cidr::normalize($value);
            if ($cidr === null) {
                $this->addError('networkEntries', __('Line :n: ":value" is not an address or a range like 203.0.113.0/24.', ['n' => $i + 1, 'value' => trim($value)]));

                return;
            }
            if (Cidr::isEveryone($cidr)) {
                $this->addError('networkEntries', __('Line :n: :cidr is the whole internet. Use "Anyone" in a rule instead.', ['n' => $i + 1, 'cidr' => $cidr]));

                return;
            }
            $note = $note !== null && trim($note) !== '' ? Str::limit(trim($note), 120, '') : null;
            // A repeated line keeps the note it had first, unless only the repeat has one.
            $entries[$cidr] = ['cidr' => $cidr, 'note' => ($entries[$cidr]['note'] ?? null) ?? $note];
        }
        if ($entries === []) {
            $this->addError('networkEntries', __('Add at least one address or range.'));

            return;
        }
        if (count($entries) > 100) {
            $this->addError('networkEntries', __('A network can have up to 100 addresses and ranges.'));

            return;
        }

        if ($this->networkId) {
            $this->team->accessNetworks()->findOrFail($this->networkId)->update(['name' => $this->networkName, 'entries' => array_values($entries)]);
        } else {
            $this->team->accessNetworks()->create(['name' => $this->networkName, 'entries' => array_values($entries), 'created_by' => Auth::id()]);
        }

        unset($this->networkList, $this->team, $this->addresses, $this->domains);
        Flux::modal('network')->close();
        Flux::toast(variant: 'success', text: __('Network saved. Running tunnels follow within seconds.'));
    }

    public function removeNetwork(int $id): void
    {
        abort_unless($this->canManage, 403);
        $this->team->accessNetworks()->findOrFail($id)->delete();
        unset($this->networkList, $this->team, $this->addresses, $this->domains);
        Flux::modal("remove-network-{$id}")->close();
        Flux::toast(variant: 'success', text: __('Network removed.'));
    }

    /** Lines of the network form that will never match a visitor. */
    #[Computed]
    public function privateLines(): array
    {
        return collect(preg_split('/\R/', $this->networkEntries))
            ->map(fn ($l) => Cidr::normalize(explode('#', $l, 2)[0]))
            ->filter(fn ($c) => $c !== null && Cidr::isPrivate($c))
            ->values()->all();
    }
}; ?>

<section class="max-w-4xl">
    <div>
        <h1 class="page-title">{{ __('Access') }}</h1>
        <p class="mt-3 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
            {{ __('Who can reach the tunnels of :team. Limit an address to networks such as your company VPN, and requests from anywhere else are turned away at the tunnel server, before they reach anyone\'s machine.', ['team' => $this->team->name]) }}
        </p>
    </div>

    {{-- The team's rule for everything without one of its own. --}}
    <div class="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-md border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800/50" data-test="access-default">
        <div class="flex items-center gap-4">
            <x-access-mark :restricted="$this->team->access === 'networks'" />
            <div>
                <p class="font-display text-lg font-semibold">{{ __('Team default') }}</p>
                <p class="text-zinc-600 dark:text-zinc-300">
                    {{ $this->team->access === 'networks' ? __('Only from :networks', ['networks' => $this->team->networks->pluck('name')->join(', ') ?: __('no network yet, so nobody')]) : __('Anyone') }}
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('for every address without a rule of its own, random ones too') }}</span>
                </p>
            </div>
        </div>
        @if ($this->canManage)
            <flux:button wire:click="editRule('team')" data-test="access-default-edit">{{ __('Change') }}</flux:button>
        @endif
    </div>

    {{-- Saved addresses --}}
    <section class="mt-12">
        <h2 class="font-display text-2xl font-semibold">{{ __('Saved addresses') }}</h2>
        <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('A saved name is your team\'s: nobody else can open a tunnel on it, and it keeps its rule. Good for addresses you share or give to webhooks.') }}</p>

        @if (auth()->user()->is_demo)
            <p class="mt-4 max-w-[62ch] text-sm text-zinc-500 dark:text-zinc-400" data-test="address-demo-note">{{ __('In the demo, addresses are random and cannot be saved. The two below are sample data.') }}</p>
        @else
        <form wire:submit="saveAddress" class="mt-4 flex flex-wrap items-start gap-3">
            <div class="flex min-w-0 flex-1 items-center rounded-md border border-zinc-200 bg-white pr-3 font-mono text-sm focus-within:ring-2 focus-within:ring-cable dark:border-zinc-700 dark:bg-zinc-900">
                <input wire:model="name" type="text" placeholder="shop" aria-label="{{ __('Name') }}" autocomplete="off" spellcheck="false"
                    class="min-w-0 flex-1 bg-transparent px-3 py-2 outline-none" data-test="address-name">
                <span class="text-zinc-500 dark:text-zinc-400">{{ $this->domainSuffix() }}</span>
            </div>
            <flux:button type="submit" variant="primary" data-test="address-save">{{ __('Save address') }}</flux:button>
        </form>
        @endif
        @error('name')<p class="mt-2 text-sm text-fault">{{ $message }}</p>@enderror

        <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="address-list">
            @forelse ($this->addresses as $address)
                <li class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-zinc-200 py-3 dark:border-zinc-700" wire:key="addr-{{ $address->id }}">
                    <x-access-mark :restricted="$this->restricts($address)" />
                    <div class="min-w-0">
                        <p class="truncate font-mono">{{ $address->hostname }}</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->describe($address) }}@if ($address->user){{ __(', saved by :name', ['name' => $address->user->name]) }}@endif</p>
                    </div>
                    @if ($this->mayEdit($address))
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="editRule('address', {{ $address->id }})">{{ __('Rule') }}</flux:button>
                            <flux:modal.trigger :name="'remove-address-'.$address->id"><flux:button size="sm" variant="ghost">{{ __('Remove') }}</flux:button></flux:modal.trigger>
                        </div>
                        <flux:modal :name="'remove-address-'.$address->id" class="max-w-lg">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Remove :address', ['address' => $address->hostname]) }}</flux:heading>
                                    <flux:subheading>{{ __('Its rule goes with it. A tunnel running on it stays up; once it stops, anyone can take the name.') }}</flux:subheading>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <flux:modal.close><flux:button variant="filled">{{ __('Keep it') }}</flux:button></flux:modal.close>
                                    <flux:button variant="danger" wire:click="removeAddress({{ $address->id }})">{{ __('Remove address') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endif
                </li>
            @empty
                <li class="border-b border-zinc-200 py-6 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('No saved addresses yet.') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- The team's own domains --}}
    @if ($this->domains->isNotEmpty())
        <section class="mt-12">
            <h2 class="font-display text-2xl font-semibold">{{ __('Your domains') }}</h2>
            <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="access-domains">
                @foreach ($this->domains as $domain)
                    <li class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 border-b border-zinc-200 py-3 dark:border-zinc-700" wire:key="dom-{{ $domain->id }}">
                        <x-access-mark :restricted="$this->restricts($domain)" />
                        <div class="min-w-0">
                            <p class="truncate font-mono">{{ $domain->hostname }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->describe($domain) }}</p>
                        </div>
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" wire:click="editRule('domain', {{ $domain->id }})">{{ __('Rule') }}</flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Networks --}}
    <section class="mt-12">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-display text-2xl font-semibold">{{ __('Networks') }}</h2>
                <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('Named lists of addresses and ranges, for example the exit addresses of your company VPN. Change one here and every rule that uses it follows.') }}</p>
            </div>
            @if ($this->canManage)
                <flux:button icon="plus" wire:click="editNetwork" data-test="network-add">{{ __('Add network') }}</flux:button>
            @endif
        </div>
        <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="network-list">
            @forelse ($this->networkList as $network)
                <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-zinc-200 py-3 dark:border-zinc-700" wire:key="net-{{ $network->id }}">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $network->name }}</p>
                        <p class="truncate font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ collect($network->entries)->pluck('cidr')->take(4)->join('  ') }}@if (count($network->entries) > 4) {{ __('and :n more', ['n' => count($network->entries) - 4]) }}@endif</p>
                    </div>
                    @if ($this->canManage)
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="editNetwork({{ $network->id }})">{{ __('Edit') }}</flux:button>
                            <flux:modal.trigger :name="'remove-network-'.$network->id"><flux:button size="sm" variant="ghost">{{ __('Remove') }}</flux:button></flux:modal.trigger>
                        </div>
                        <flux:modal :name="'remove-network-'.$network->id" class="max-w-lg">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Remove :name', ['name' => $network->name]) }}</flux:heading>
                                    <flux:subheading>
                                        @php $uses = $network->saved_addresses_count + $network->custom_domains_count; @endphp
                                        {{ $uses > 0
                                            ? trans_choice('{1} One address is limited to it. Without it, that address lets nobody in until you choose another network.|[2,*] :count addresses are limited to it. Without it, they let nobody in until you choose other networks.', $uses, ['count' => $uses])
                                            : __('No address uses it.') }}
                                    </flux:subheading>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <flux:modal.close><flux:button variant="filled">{{ __('Keep it') }}</flux:button></flux:modal.close>
                                    <flux:button variant="danger" wire:click="removeNetwork({{ $network->id }})">{{ __('Remove network') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endif
                </li>
            @empty
                <li class="border-b border-zinc-200 py-6 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                    {{ $this->canManage ? __('No networks yet. Add one with your VPN\'s addresses to limit tunnels to it.') : __('No networks yet. A team owner or admin can add them.') }}
                </li>
            @endforelse
        </ul>
    </section>

    {{-- The command line --}}
    <section class="mt-12">
        <h2 class="font-display text-2xl font-semibold">{{ __('From the command line') }}</h2>
        <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{!! __('Limit one run further with :flag: a network\'s name, an address or a range. It adds to the rules here and never opens what they keep closed.', ['flag' => '<code class="font-mono text-[0.95em]">--allow</code>']) !!}</p>
        <div class="mt-4 space-y-3">
            <x-command :command="'exposehub http 8000 --allow \''.($this->networkList->first()->name ?? 'Office VPN').'\''" />
            <x-command command="exposehub http shop=8000 api=3000 --allow 203.0.113.0/24" />
        </div>
    </section>

    {{-- One form for every rule: the team default, an address or a domain. --}}
    <flux:modal name="rule" class="max-w-lg">
        <form wire:submit="saveRule" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    @if ($ruleFor === 'team') {{ __('Team default') }}
                    @elseif ($ruleFor === 'address') {{ $this->addresses->firstWhere('id', $ruleId)?->hostname }}
                    @else {{ $this->domains->firstWhere('id', $ruleId)?->hostname }}
                    @endif
                </flux:heading>
                <flux:subheading>{{ __('Who may reach it. Requests from elsewhere get a page saying the tunnel is limited to certain networks.') }}</flux:subheading>
            </div>

            <flux:radio.group wire:model.live="ruleAccess" data-test="rule-access">
                @if ($ruleFor !== 'team')
                    <flux:radio value="default" :label="__('Team default')" :description="$this->team->access === 'networks' ? __('Only from :n', ['n' => $this->team->networks->pluck('name')->join(', ')]) : __('Anyone')" />
                @endif
                <flux:radio value="anyone" :label="__('Anyone')" :description="__('Needed for webhooks from outside, for example a payment provider.')" />
                <flux:radio value="networks" :label="__('Only from these networks')" />
            </flux:radio.group>
            @error('ruleAccess')<p class="text-sm text-fault">{{ $message }}</p>@enderror

            @if ($ruleAccess === 'networks')
                <div class="space-y-2 pl-7">
                    @forelse ($this->networkList as $network)
                        <flux:checkbox wire:model="ruleNetworks" :value="$network->id" :label="$network->name" />
                    @empty
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Your team has no networks yet.') }}</p>
                    @endforelse
                    @error('ruleNetworks')<p class="text-sm text-fault">{{ $message }}</p>@enderror
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="rule-save">{{ __('Save rule') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    @php $entriesPlaceholder = implode(PHP_EOL, ['203.0.113.10   # '.__('Münster VPN exit'), '198.51.100.0/24   # '.__('Berlin office'), '2001:db8:42::/48']); @endphp
    <flux:modal name="network" class="max-w-xl">
        <form wire:submit="saveNetwork" class="space-y-6">
            <flux:heading size="lg">{{ $networkId ? __('Edit network') : __('Add a network') }}</flux:heading>
            <flux:input wire:model="networkName" :label="__('Name')" :placeholder="__('Office VPN')" data-test="network-name" />
            <div>
                <flux:textarea wire:model.live.debounce.400ms="networkEntries" :label="__('Addresses and ranges')" rows="6" class="font-mono"
                    :placeholder="$entriesPlaceholder" data-test="network-entries" />
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('One per line, IPv4 or IPv6, a single address or a range. Anything after # is a note.') }}</p>
                @error('networkEntries')<p class="mt-2 text-sm text-fault">{{ $message }}</p>@enderror
                @if ($this->privateLines)
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-200">{{ trans_choice('{1} :list never matches: visitors reach tunnels from the internet, not from a private network. Use your VPN\'s public exit addresses.|[2,*] :list never match: visitors reach tunnels from the internet, not from a private network. Use your VPN\'s public exit addresses.', count($this->privateLines), ['list' => implode(', ', $this->privateLines)]) }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($myAddress)
                    <button type="button" wire:click="addMyAddress" class="text-sm font-medium underline decoration-cable decoration-2 underline-offset-4" data-test="network-add-mine">
                        {{ __('Add my address (:ip)', ['ip' => $myAddress]) }}
                    </button>
                @endif
                <div class="ml-auto flex gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="network-save">{{ __('Save network') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</section>
