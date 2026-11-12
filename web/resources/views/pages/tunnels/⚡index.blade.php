<?php

use App\Actions\Tunnels\DisconnectTunnel;
use App\Concerns\RequiresTeamMembership;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\TunnelDisconnect;
use App\Support\LiveTunnels;
use App\Support\ServiceStatus;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tunnels')] #[RequiresTeamMembership] class extends Component {
    /** The team this page was opened for. Locked so a request cannot swap it. */
    #[Locked]
    public int $teamId;

    public function mount(): void
    {
        $this->teamId = Auth::user()->currentTeam->id;
    }

    #[Computed]
    public function team(): Team
    {
        return Team::findOrFail($this->teamId);
    }

    /** Owners and admins see the whole team's tunnels, members their own. */
    #[Computed]
    public function seesAll(): bool
    {
        return Gate::allows('viewAll', [AgentToken::class, $this->team]);
    }

    /** When the tunnel server last reported, or null if it has not lately. */
    #[Computed]
    public function reportedAt(): ?Carbon
    {
        $report = ServiceStatus::report();

        return $report === null ? null : Carbon::createFromTimestamp($report['received_at']);
    }

    #[Computed]
    public function fresh(): bool
    {
        return ServiceStatus::reportIsFresh();
    }

    /**
     * The team's open tunnels this person may see, each with its token and
     * whether someone has asked to close it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function tunnels(): Collection
    {
        $tokens = $this->team->agentTokens()
            ->when(! $this->seesAll, fn ($query) => $query->where('user_id', Auth::id()))
            ->with('user:id,name')
            ->get()
            ->keyBy('id');
        $rows = LiveTunnels::ofTokens($tokens->keys()->all());
        $closing = TunnelDisconnect::query()->open()
            ->whereIn('session', $rows->pluck('id')->filter()->all())
            ->pluck('session')
            ->flip();
        $offered = LiveTunnels::offeredAgent();

        return $rows->map(fn (array $t) => $t + [
            'token' => $tokens->get($t['token_id']),
            'mine' => $tokens->get($t['token_id'])->user_id === Auth::id(),
            'closing' => $t['id'] !== null && $closing->has($t['id']),
            'outdated' => $offered !== null && $t['agent_version'] !== null && $t['agent_version'] !== $offered,
            'offered' => $offered,
        ]);
    }

    /**
     * This person's own open tunnels in their other teams, as team and count:
     * a tunnel left running is as often in another team as on another machine.
     *
     * @return Collection<int, array{team: Team, count: int}>
     */
    #[Computed]
    public function elsewhere(): Collection
    {
        $tokens = AgentToken::query()
            ->where('user_id', Auth::id())
            ->where('team_id', '!=', $this->teamId)
            ->whereIn('team_id', Auth::user()->teams()->pluck('teams.id'))
            ->with('team:id,name,slug')
            ->get()
            ->keyBy('id');

        return LiveTunnels::ofTokens($tokens->keys()->all())
            ->groupBy(fn (array $t) => $tokens->get($t['token_id'])->team_id)
            ->map(fn (Collection $rows) => ['team' => $tokens->get($rows->first()['token_id'])->team, 'count' => $rows->count()])
            ->sortBy(fn (array $entry) => $entry['team']->name)
            ->values();
    }

    public function disconnect(string $session, DisconnectTunnel $disconnect): void
    {
        // Only a session the tunnel server reports under a token of this team
        // can be named; anything else is not there to close.
        $tunnel = $this->tunnels->first(fn (array $t) => $t['id'] !== null && hash_equals($t['id'], $session));
        abort_if($tunnel === null, 404);

        Gate::authorize('disconnect', $tunnel['token']);

        $disconnect->handle(Auth::user(), $tunnel['token'], $session, $tunnel['hosts']);
        unset($this->tunnels);

        Flux::modal("disconnect-{$session}")->close();

        Flux::toast(variant: 'success', text: __('Disconnecting. The agent stops within seconds.'));
    }
}; ?>

<section class="max-w-5xl">
    <h1 class="page-title">{{ __('Tunnels') }}</h1>
    <p class="mt-3 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
        @if ($this->seesAll)
            {{ __('Every agent that holds a tunnel for :team right now. Disconnect one that was left running; its token keeps working.', ['team' => $this->team->name]) }}
        @else
            {{ __('Your agents that hold a tunnel for :team right now. Disconnect one you left running on another machine; its token keeps working.', ['team' => $this->team->name]) }}
        @endif
    </p>

    <div wire:poll.15s class="mt-10">
        @if (! $this->reportedAt)
            <div class="border-y border-zinc-200 py-10 dark:border-zinc-700" data-test="tunnels-no-report">
                <p class="font-display text-xl font-semibold">{{ __('The tunnel server is not reporting') }}</p>
                <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('This list comes from the tunnel server, which has sent nothing in the last minutes. Tunnels that are open may well be working; they cannot be shown or disconnected here until it reports again.') }}</p>
            </div>
        @else
            @unless ($this->fresh)
                <p class="mb-6 rounded-md border border-fault/40 bg-fault/10 p-4 text-zinc-800 dark:text-zinc-100" data-test="tunnels-stale">
                    {{ __('The tunnel server last reported :ago. This list may be out of date.', ['ago' => $this->reportedAt->diffForHumans()]) }}
                </p>
            @endunless

            <div class="border-t border-zinc-200 dark:border-zinc-700" data-test="tunnels-list">
                @forelse ($this->tunnels as $t)
                    @php $token = $t['token']; $key = $t['id'] ?? 'token-'.$t['token_id'].'-'.$t['since']->getTimestamp(); @endphp
                    {{--
                        One row is one patch: the machine on the left, its public addresses
                        on the right, the cable between them. A tunnel that is being closed
                        shows the cable pulled: grey, dashed, the public jack empty.
                    --}}
                    <div
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-5 gap-y-3 border-b border-zinc-200 py-5 md:grid-cols-[minmax(0,17rem)_minmax(3rem,1fr)_minmax(0,22rem)_auto] dark:border-zinc-700"
                        wire:key="tunnel-{{ $key }}"
                        data-test="tunnel-row"
                    >
                        <div class="min-w-0 max-md:order-2">
                            <div class="truncate font-display text-xl leading-tight font-semibold">{{ $token->name }}</div>
                            <div class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($this->seesAll)
                                    {{ $t['mine'] ? __('yours') : $token->user->name }},
                                @endif
                                <span title="{{ $t['since']->toDayDateTimeString() }}">{{ __('open for :time', ['time' => $t['since']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}</span>
                            </div>
                            @if ($t['agent_version'])
                                <div class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('agent :version', ['version' => $t['agent_version']]) }}@if ($t['outdated'] && $t['mine']){!! __(', :command gets :newer', ['command' => '<code class="font-mono text-[0.95em] whitespace-nowrap text-zinc-700 dark:text-zinc-200">exposehub update</code>', 'newer' => e($t['offered'])]) !!}@endif
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center max-md:hidden" aria-hidden="true">
                            <span class="relative size-3.5 shrink-0 rounded-full bg-cable after:absolute after:inset-1 after:rounded-full after:bg-ink"></span>
                            @if ($t['closing'])
                                <span class="-ms-0.5 h-0 flex-1 border-t-[3px] border-dashed border-zinc-300 dark:border-zinc-600"></span>
                                <span class="ms-2 size-3.5 shrink-0 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></span>
                            @else
                                <span class="-mx-0.5 h-[3px] flex-1 bg-cable"></span>
                                <span class="relative size-3.5 shrink-0 rounded-full bg-cable after:absolute after:inset-1 after:rounded-full after:bg-ink"></span>
                            @endif
                        </div>

                        <div class="min-w-0 space-y-1 max-md:order-1 max-md:col-span-2">
                            @foreach ($t['hosts'] as $host)
                                <div class="flex flex-wrap items-baseline gap-x-3">
                                    @if ($t['closing'])
                                        <span class="font-mono text-[1.0625rem] [overflow-wrap:anywhere] text-zinc-500 dark:text-zinc-400">{{ $host }}</span>
                                    @else
                                        <a href="https://{{ $host }}" target="_blank" rel="noopener" class="font-mono text-[1.0625rem] font-medium [overflow-wrap:anywhere] underline decoration-transparent decoration-2 underline-offset-4 hover:decoration-cable focus-visible:decoration-cable">{{ $host }}</a>
                                    @endif
                                    @if (in_array($host, $t['protected'], true))
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('password') }}</span>
                                    @endif
                                    @if (in_array($host, $t['restricted'], true))
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('limited to networks') }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="text-right max-md:order-3 max-md:self-end">
                            @if ($t['closing'])
                                <span class="pe-3 text-sm text-zinc-500 dark:text-zinc-400" data-test="tunnel-closing">{{ __('Disconnecting') }}</span>
                            @elseif ($t['id'])
                                <flux:modal.trigger :name="'disconnect-'.$t['id']">
                                    <flux:button variant="ghost" size="sm" x-data="" x-on:click.prevent="$dispatch('open-modal', 'disconnect-{{ $t['id'] }}')" data-test="tunnel-disconnect-button">
                                        {{ __('Disconnect') }}
                                    </flux:button>
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </div>

                    @if ($t['id'] && ! $t['closing'])
                        <flux:modal :name="'disconnect-'.$t['id']" focusable class="max-w-lg">
                            <form wire:submit="disconnect('{{ $t['id'] }}')" class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Disconnect :host', ['host' => $t['hosts'][0]]) }}</flux:heading>
                                    <flux:subheading>
                                        @if (count($t['hosts']) > 1)
                                            {{ trans_choice('{1} The agent holds :count more address, which closes with it.|[2,*] The agent holds :count more addresses, which close with it.', count($t['hosts']) - 1, ['count' => count($t['hosts']) - 1]) }}
                                        @endif
                                        {{ __('The agent stops and does not reconnect. To open the tunnel again, start it on the machine it ran on. The token ":name" keeps working.', ['name' => $token->name]) }}
                                        @unless ($t['mine'])
                                            {{ __(':person gets a mail.', ['person' => $token->user->name]) }}
                                        @endunless
                                    </flux:subheading>
                                </div>

                                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                    <flux:modal.close>
                                        <flux:button variant="filled">{{ __('Keep tunnel') }}</flux:button>
                                    </flux:modal.close>

                                    <flux:button variant="danger" type="submit" data-test="tunnel-disconnect-confirm">
                                        {{ __('Disconnect tunnel') }}
                                    </flux:button>
                                </div>
                            </form>
                        </flux:modal>
                    @endif
                @empty
                    <div class="border-b border-zinc-200 py-12 dark:border-zinc-700" data-test="tunnels-empty">
                        <p class="font-display text-xl font-semibold">{{ __('No tunnel is open') }}</p>
                        <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('Start the agent next to a local service and it shows up here within seconds.') }}</p>
                        <x-command class="mt-4 max-w-md" command="exposehub http 8000" />
                    </div>
                @endforelse
            </div>

            @if ($this->elsewhere->isNotEmpty())
                <p class="mt-6 text-zinc-600 dark:text-zinc-300" data-test="tunnels-elsewhere">
                    {{ __('You also have tunnels open in') }}
                    @foreach ($this->elsewhere as $entry)
                        <a href="{{ route('tunnels.index', ['current_team' => $entry['team']->slug]) }}" wire:navigate class="font-medium text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white">{{ $entry['team']->name }}</a> ({{ $entry['count'] }}){{ $loop->last ? '.' : ',' }}
                    @endforeach
                </p>
            @endif
        @endif
    </div>
</section>
