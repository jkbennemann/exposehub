<?php

use App\Actions\Admin\AdminActions;
use App\Concerns\RequiresAdmin;
use App\Models\AgentToken;
use App\Support\ServiceStatus;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Live tunnels')] #[RequiresAdmin] class extends Component {
    #[Computed]
    public function report(): ?array
    {
        return ServiceStatus::report();
    }

    #[Computed]
    public function fresh(): bool
    {
        return ServiceStatus::reportIsFresh();
    }

    /**
     * The reported tunnels, joined with the dashboard's tokens.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function tunnels(): Collection
    {
        $rows = collect($this->report['tunnels'] ?? []);
        $tokens = AgentToken::with(['user:id,name,email', 'team:id,name,slug'])
            ->whereIn('id', $rows->pluck('token_id')->filter()->map(fn ($id) => (int) $id)->all())
            ->get()->keyBy('id');

        return $rows->map(fn (array $t) => $t + [
            'token' => $t['token_id'] ? $tokens->get((int) $t['token_id']) : null,
            'since' => Carbon::parse($t['connected_at']),
        ]);
    }

    public function revoke(int $tokenId): void
    {
        $token = AgentToken::findOrFail($tokenId);
        app(AdminActions::class)->revokeToken(Auth::user(), $token);
        Flux::modal("cut-{$tokenId}")->close();
        Flux::toast(variant: 'success', text: __('Token revoked. The tunnel closes within seconds.'));
        unset($this->tunnels);
    }
}; ?>

<x-admin.frame :title="__('Live tunnels')" :lead="__('Who is connected right now, as the tunnel server reports it every 15 seconds. To cut a tunnel, revoke the token it uses.')">
    <div wire:poll.15s>
        @if (! $this->report)
            <p class="rounded-md border border-zinc-200 p-5 text-zinc-700 dark:border-zinc-700 dark:text-zinc-200" data-test="tunnels-no-report">
                {{ __('The tunnel server has not reported yet. It reports every 15 seconds once it is connected to this dashboard.') }}
            </p>
        @else
            @unless ($this->fresh)
                <p class="mb-6 rounded-md border border-fault/40 bg-fault/10 p-4 text-zinc-800 dark:text-zinc-100" data-test="tunnels-stale">
                    {{ __('The last report is :ago. The list below may be out of date.', ['ago' => Carbon::createFromTimestamp($this->report['received_at'])->diffForHumans()]) }}
                </p>
            @endunless

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Server :v', ['v' => $this->report['server_version']]) }}@if ($this->report['agent_release'] ?? null), {{ __('offers agent :v', ['v' => $this->report['agent_release']]) }}@endif,
                {{ __('running since :when', ['when' => Carbon::parse($this->report['started_at'])->diffForHumans()]) }}.
            </p>

            <div class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="tunnels-list">
                @forelse ($this->tunnels as $i => $t)
                    @php $token = $t['token']; $old = ($this->report['agent_release'] ?? null) && $t['agent_version'] !== $this->report['agent_release']; @endphp
                    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 border-b border-zinc-200 py-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1.4fr)_7rem_7rem_auto] dark:border-zinc-700" wire:key="tun-{{ $i }}-{{ $t['token_id'] }}" data-test="tunnel-row">
                        <div class="min-w-0 space-y-0.5">
                            @foreach ($t['hosts'] as $host)
                                <div class="flex items-center gap-2 font-mono text-[0.95rem]">
                                    <span class="truncate">{{ $host }}</span>
                                    @if (in_array($host, $t['protected'], true))
                                        <span class="text-xs font-sans text-zinc-500" title="{{ __('Password protected') }}">{{ __('password') }}</span>
                                    @endif
                                    @if (in_array($host, $t['restricted'] ?? [], true))
                                        <span class="text-xs font-sans text-zinc-500" title="{{ __('Limited to networks') }}">{{ __('networks') }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="min-w-0 text-sm max-md:order-3 max-md:col-span-2">
                            @if ($token)
                                <a href="{{ route('admin.person', $token->user_id) }}" wire:navigate class="font-medium hover:underline">{{ $token->user?->name }}</a>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('in :team, token :name', ['team' => $token->team?->name, 'name' => $token->name]) }}</span>
                            @else
                                <span class="text-zinc-500">{{ __('static token :account', ['account' => $t['account']]) }}</span>
                            @endif
                        </div>
                        <div @class(['text-sm tabular-nums max-md:hidden', 'font-medium underline decoration-cable decoration-2 underline-offset-4' => $old]) @if ($old) title="{{ __('Older than the agent the server offers') }}" @endif>{{ $t['agent_version'] ?: '–' }}</div>
                        <div class="text-sm text-zinc-500 max-md:hidden dark:text-zinc-400" title="{{ $t['since']->toDateTimeString() }}">{{ $t['since']->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>
                        <div class="text-right">
                            @if ($token)
                                <flux:modal.trigger :name="'cut-'.$token->id"><flux:button size="sm" variant="ghost">{{ __('Revoke token') }}</flux:button></flux:modal.trigger>
                                <flux:modal :name="'cut-'.$token->id" class="max-w-lg">
                                    <div class="space-y-6 text-left">
                                        <div>
                                            <flux:heading size="lg">{{ __('Revoke :name', ['name' => $token->name]) }}</flux:heading>
                                            <flux:subheading>{{ __('Every agent using this token is disconnected within seconds and cannot reconnect. :name gets a mail.', ['name' => $token->user?->name]) }}</flux:subheading>
                                        </div>
                                        <div class="flex justify-end gap-2">
                                            <flux:modal.close><flux:button variant="filled">{{ __('Keep token') }}</flux:button></flux:modal.close>
                                            <flux:button variant="danger" wire:click="revoke({{ $token->id }})">{{ __('Revoke token') }}</flux:button>
                                        </div>
                                    </div>
                                </flux:modal>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="border-b border-zinc-200 py-10 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('No agent is connected right now.') }}</p>
                @endforelse
            </div>
        @endif
    </div>
</x-admin.frame>
