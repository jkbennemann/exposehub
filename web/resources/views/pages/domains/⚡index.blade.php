<?php

use App\Actions\Domains\VerifyCustomDomain;
use App\Concerns\RequiresTeamMembership;
use App\Models\CustomDomain;
use App\Models\Team;
use App\Rules\CustomHostname;
use App\Support\Domains\Provisioner;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Domains')] #[RequiresTeamMembership] class extends Component {
    /** The team this page was opened for. Locked so a request cannot swap it. */
    #[Locked]
    public int $teamId;

    public string $hostname = '';

    /** What the last DNS check found missing, per domain. */
    public array $problems = [];

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
    public function canManage(): bool
    {
        return Gate::allows('create', [CustomDomain::class, $this->team]);
    }

    /**
     * @return Collection<int, CustomDomain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->team->customDomains()->orderBy('hostname')->get();
    }

    public function addDomain(): void
    {
        Gate::authorize('create', [CustomDomain::class, $this->team]);

        $this->hostname = Str::lower(trim($this->hostname));

        $this->validate([
            'hostname' => [
                'required',
                'string',
                new CustomHostname,
                Rule::unique(CustomDomain::class)->where('team_id', $this->teamId),
            ],
        ], ['hostname.unique' => __('This domain is already on the list.')]);

        // Every team's domains go to the tunnel server in one shared snapshot,
        // and the page looks up DNS for each one that is not connected yet.
        $limit = (int) config('exposehub.limits.domains_per_team');
        if ($this->team->customDomains()->count() >= $limit) {
            $this->addError('hostname', __('A team can have up to :n domains.', ['n' => $limit]));

            return;
        }

        $this->team->customDomains()->create([
            'user_id' => Auth::id(),
            'hostname' => $this->hostname,
            'verification_token' => Str::lower(Str::random(32)),
        ]);

        $this->reset('hostname');
        unset($this->domains);

        Flux::modal('add-domain')->close();
        Flux::toast(variant: 'success', text: __('Domain added.'));
    }

    public function checkDns(int $domainId, VerifyCustomDomain $verify, Provisioner $provisioner): void
    {
        $domain = $this->team->customDomains()->findOrFail($domainId);

        Gate::authorize('update', $domain);

        $problem = $domain->isVerified() ? null : $verify->handle($domain);

        if ($problem === null) {
            $pointsHere = $verify->pointsHere($domain);
            $domain->update(['routed_at' => $pointsHere ? now() : null]);

            if (! $pointsHere) {
                $problem = __(':host does not point to this service yet. DNS changes can take a few minutes to spread.', ['host' => $domain->hostname]);
            }
        }

        if ($problem === null) {
            try {
                $provisioner->provision($domain);
            } catch (Throwable $e) {
                report($e);
                $problem = __('The DNS records are right, but setting up the certificate failed. Check again in a minute.');
            }
        }

        $this->problems[$domainId] = $problem;
        unset($this->domains);

        if ($problem === null) {
            Flux::toast(variant: 'success', text: __('Domain connected.'));
        }
    }

    /**
     * The records that send visitors of each unconnected domain here.
     *
     * @return array<int, array<int, array{type: string, name: string, value: string}>>
     */
    #[Computed]
    public function routingRecords(): array
    {
        $verify = app(VerifyCustomDomain::class);

        return $this->domains
            ->reject(fn (CustomDomain $domain) => $domain->isConnected())
            // Every Livewire request renders the page again. Remember the answer
            // for a while so a render does not wait on one DNS lookup per domain.
            ->mapWithKeys(fn (CustomDomain $domain) => [$domain->id => Cache::remember(
                'domain-routing-records:'.$domain->hostname,
                now()->addMinutes(10),
                fn () => $verify->routingRecords($domain),
            )])
            ->all();
    }

    public function removeDomain(int $domainId, Provisioner $provisioner): void
    {
        $domain = $this->team->customDomains()->findOrFail($domainId);

        Gate::authorize('delete', $domain);

        try {
            $provisioner->remove($domain);
        } catch (Throwable $e) {
            // The domain is still removed: the tunnel server stops serving it either way.
            report($e);
        }

        $domain->delete();
        unset($this->problems[$domainId], $this->domains);

        Flux::modal("remove-domain-{$domainId}")->close();
        Flux::toast(variant: 'success', text: __('Domain removed.'));
    }
}; ?>

<section class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
        <div>
            <h1 class="page-title">{{ __('Domains') }}</h1>
            <p class="mt-3 max-w-[58ch] text-zinc-600 dark:text-zinc-300">
                {{ __('Connect a domain of your own and :team can open tunnels on it, in place of an address under :domain.', ['team' => $this->team->name, 'domain' => config('exposehub.tunnel_domain')]) }}
            </p>
        </div>

        @if ($this->canManage)
            <flux:modal.trigger name="add-domain">
                <flux:button variant="primary" icon="plus" x-data="" x-on:click.prevent="$dispatch('open-modal', 'add-domain')" data-test="domains-add-button">
                    {{ __('Add domain') }}
                </flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    <div class="mt-10 border-t border-zinc-200 dark:border-zinc-700">
        @forelse ($this->domains as $domain)
            <div class="border-b border-zinc-200 py-5 dark:border-zinc-700" data-test="domain-row" wire:key="domain-{{ $domain->id }}">
                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                    <div class="min-w-0">
                        <div class="font-mono text-lg font-medium [overflow-wrap:anywhere]">{{ $domain->hostname }}</div>
                        <div class="mt-0.5 flex items-center gap-2 text-sm">
                            @if ($domain->isConnected())
                                <span class="size-2 rounded-full bg-live"></span>
                                <span class="text-zinc-600 dark:text-zinc-300" data-test="domain-status">{{ __('Connected') }}</span>
                            @elseif ($domain->isVerified())
                                <span class="size-2 rounded-full bg-cable"></span>
                                <span class="text-zinc-600 dark:text-zinc-300" data-test="domain-status">{{ __('Ownership confirmed, not pointing here yet') }}</span>
                            @else
                                <span class="size-2 rounded-full border-2 border-zinc-400"></span>
                                <span class="text-zinc-600 dark:text-zinc-300" data-test="domain-status">{{ __('Waiting for DNS') }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($this->canManage)
                        <div class="flex items-center gap-1">
                            <flux:button variant="filled" size="sm" wire:click="checkDns({{ $domain->id }})" data-test="domain-check-button">
                                {{ __('Check DNS') }}
                            </flux:button>
                            <flux:modal.trigger :name="'remove-domain-'.$domain->id">
                                <flux:button variant="ghost" size="sm" x-data="" x-on:click.prevent="$dispatch('open-modal', 'remove-domain-{{ $domain->id }}')" data-test="domain-remove-button">
                                    {{ __('Remove') }}
                                </flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif
                </div>

                @if (! empty($problems[$domain->id]))
                    <p class="mt-3 text-sm text-fault" data-test="domain-problem">{{ $problems[$domain->id] }}</p>
                @endif

                @if (! $domain->isConnected())
                    @php $records = $this->routingRecords[$domain->id] ?? []; @endphp
                    <div class="mt-4 text-sm">
                        <p class="text-zinc-600 dark:text-zinc-300">
                            {{ $domain->isVerified()
                                ? __('Create this record where the DNS of this domain is managed, then check again.')
                                : __('Create these records where the DNS of this domain is managed, then check again.') }}
                        </p>
                        <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-[4rem_minmax(0,1fr)_minmax(0,1fr)]" data-test="domain-records">
                            @unless ($domain->isVerified())
                                <dt class="font-medium">TXT</dt>
                                <dd class="font-mono [overflow-wrap:anywhere]">{{ $domain->txtName() }}</dd>
                                <dd class="font-mono [overflow-wrap:anywhere]">{{ $domain->txtValue() }}</dd>
                            @endunless

                            @foreach ($records as $record)
                                <dt class="font-medium">{{ $record['type'] }}</dt>
                                <dd class="font-mono [overflow-wrap:anywhere]">{{ $record['name'] }}</dd>
                                <dd class="font-mono [overflow-wrap:anywhere]">{{ $record['value'] }}</dd>
                            @endforeach
                        </dl>

                        @if (collect($records)->contains(fn ($record) => $record['type'] !== 'CNAME'))
                            <p class="mt-3 max-w-[70ch] text-zinc-600 dark:text-zinc-300">
                                {{ __('This is the top of a domain, where DNS does not allow a CNAME record, so it gets the address itself. If your DNS provider offers ALIAS, ANAME or CNAME flattening, point that at :target instead: it keeps working if the address ever changes.', ['target' => config('exposehub.custom_domain_target')]) }}
                            </p>
                        @endif
                    </div>
                @else
                    <x-command class="mt-4" :command="'exposehub http 8000 --hostname '.$domain->hostname.' --auth name:password'" />
                    <p class="mt-3 max-w-[70ch] text-sm text-zinc-600 dark:text-zinc-300">{{ __('Keep the password. A domain of your own is listed publicly the moment its certificate is issued, and scanners start asking it for files such as .env within seconds.') }}</p>
                    @if (app(\App\Support\Domains\Provisioner::class)->issuesCertificates())
                        <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ __('The certificate is issued within about a minute of connecting. Until then a browser shows a certificate warning.') }}</p>
                    @endif
                @endif
            </div>

            <flux:modal :name="'remove-domain-'.$domain->id" focusable class="max-w-lg">
                <form wire:submit="removeDomain({{ $domain->id }})" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Remove :host', ['host' => $domain->hostname]) }}</flux:heading>
                        <flux:subheading>{{ __('Tunnels open on this domain are closed. Your DNS records stay as they are until you delete them.') }}</flux:subheading>
                    </div>

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Keep domain') }}</flux:button>
                        </flux:modal.close>

                        <flux:button variant="danger" type="submit" data-test="domain-remove-confirm">{{ __('Remove domain') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @empty
            <div class="border-b border-zinc-200 py-12 dark:border-zinc-700" data-test="domains-empty">
                <p class="font-display text-xl font-semibold">{{ __('No domains yet') }}</p>
                <p class="mt-1 text-zinc-600 dark:text-zinc-300">
                    {{ $this->canManage ? __('Add one to open tunnels on an address of your own.') : __('A team owner or admin can add one.') }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($this->canManage)
    <flux:modal name="add-domain" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="addDomain" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add a domain') }}</flux:heading>
                <flux:subheading>{{ __('Use a subdomain you can set DNS records for, such as dev.example.com.') }}</flux:subheading>
            </div>

            <flux:input wire:model="hostname" :label="__('Domain')" placeholder="dev.example.com" type="text" required autofocus data-test="add-domain-hostname" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit" data-test="add-domain-submit">{{ __('Add domain') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endif
</section>
