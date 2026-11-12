<?php

use App\Concerns\RequiresAdmin;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\RequestLog;
use App\Models\Team;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use App\Support\ServiceStatus;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin')] #[RequiresAdmin] class extends Component {
    /**
     * How a link in the diagram is drawn: up (solid), stale (dashed grey) or down (dashed red).
     */
    private function linkState(?Carbon $last, int $fresh = 60, int $stale = 600): string
    {
        if ($last === null) {
            return 'down';
        }
        $age = $last->diffInSeconds(now());

        return $age <= $fresh ? 'up' : ($age <= $stale ? 'stale' : 'down');
    }

    #[Computed]
    public function status(): array
    {
        $report = ServiceStatus::report();
        $reportAt = $report ? Carbon::createFromTimestamp((int) $report['received_at']) : null;
        $tunnels = collect($report['tunnels'] ?? []);
        $tokensStale = ServiceStatus::tokensStale();

        return [
            'snapshot' => ServiceStatus::lastSnapshot(),
            'logs' => ServiceStatus::lastLogs(),
            'report' => $report,
            'reportAt' => $reportAt,
            'tokensStale' => $tokensStale,
            'tokensRefreshedAt' => ServiceStatus::tokensRefreshedAt(),
            // The server's own word that it has no current token list counts
            // more than this dashboard's guess from when it last asked.
            'serverLink' => $tokensStale ? 'down' : $this->linkState(ServiceStatus::lastSnapshot()),
            'agentLink' => $this->linkState($reportAt),
            'agents' => $tunnels->count(),
            'outdated' => $report && ($report['agent_release'] ?? null)
                ? $tunnels->filter(fn ($t) => ($t['agent_version'] ?? '') !== $report['agent_release'])->count()
                : 0,
        ];
    }

    #[Computed]
    public function totals(): array
    {
        return [
            [User::count(), __('people')],
            [Team::where('is_personal', false)->count(), __('shared teams')],
            [AgentToken::usable()->count(), __('active agent tokens')],
            [CustomDomain::verified()->count(), __('connected domains')],
            [RequestLog::where('started_at', '>=', now()->subDay())->count(), __('requests in 24 hours')],
        ];
    }

    /**
     * Things an admin may want to look at, each with a link to where it is handled.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    #[Computed]
    public function attention(): array
    {
        $items = [];
        $status = $this->status;

        if ($status['tokensStale']) {
            $items[] = [__('The tunnel server cannot get its token list from this dashboard. Until it can, it refuses dashboard tokens, and tunnels opened with them are closed; static tokens keep working.').' '
                .($status['tokensRefreshedAt']
                    ? __('Its last successful refresh was :ago (:at UTC).', ['ago' => $status['tokensRefreshedAt']->diffForHumans(), 'at' => $status['tokensRefreshedAt']->copy()->utc()->format('d.m.Y H:i')])
                    : __('It has not refreshed it since it started.')), route('admin.overview')];
        } elseif ($status['serverLink'] !== 'up') {
            $items[] = [$status['snapshot']
                ? __('The tunnel server last fetched its tokens :ago. New and revoked tokens reach it only when it does.', ['ago' => $status['snapshot']->diffForHumans()])
                : __('The tunnel server has not fetched its tokens since this dashboard started. Check that it runs and has the node secret.'), route('admin.overview')];
        }
        if ($n = User::whereNotNull('suspended_at')->count()) {
            $items[] = [trans_choice('{1} One account is suspended.|[2,*] :count accounts are suspended.', $n, ['count' => $n]), route('admin.people', ['filter' => 'suspended'])];
        }
        if ($n = User::whereNull('email_verified_at')->where('created_at', '<', now()->subDay())->count()) {
            $items[] = [trans_choice('{1} One person registered more than a day ago and has not confirmed their address.|[2,*] :count people registered more than a day ago and have not confirmed their address.', $n, ['count' => $n]), route('admin.people', ['filter' => 'unverified'])];
        }
        if ($n = CustomDomain::whereNull('verified_at')->where('created_at', '<', now()->subDay())->count()) {
            $items[] = [trans_choice('{1} One domain has been waiting for its DNS records for more than a day.|[2,*] :count domains have been waiting for their DNS records for more than a day.', $n, ['count' => $n]), route('admin.teams')];
        }
        if ($status['outdated'] > 0) {
            $items[] = [trans_choice('{1} One connected agent runs an older version than the server offers.|[2,*] :count connected agents run an older version than the server offers.', $status['outdated'], ['count' => $status['outdated']]), route('admin.tunnels')];
        }

        return $items;
    }

    #[Computed]
    public function config(): array
    {
        $mail = config('mail.default');

        return [
            __('Agents connect to') => config('exposehub.agent_server'),
            __('Tunnel addresses') => '*.'.config('exposehub.tunnel_domain'),
            __('Who may register') => AllowedEmailDomain::domains() === []
                ? __('anyone')
                : implode(', ', AllowedEmailDomain::shownDomains()).(count(AllowedEmailDomain::domains()) > count(AllowedEmailDomain::shownDomains()) ? ' '.__('and :n hidden', ['n' => count(AllowedEmailDomain::domains()) - count(AllowedEmailDomain::shownDomains())]) : ''),
            __('Request logs') => ($this->status['report'] && ! ($this->status['report']['request_logs'] ?? true))
                ? __('switched off at the tunnel server')
                : trans_choice('{1} kept :count day|[2,*] kept :count days', (int) config('exposehub.log_retention_days'), ['count' => (int) config('exposehub.log_retention_days')]),
            __('Mail') => $mail === 'smtp'
                ? __(':from via :host', ['from' => config('mail.from.address'), 'host' => config('mail.mailers.smtp.host')])
                : __(':from via :mailer', ['from' => config('mail.from.address'), 'mailer' => $mail]),
            __('Certificates for team domains') => config('exposehub.domain_provisioner.driver') === 'kubernetes' ? __('cert-manager, through one Ingress per domain') : __('handled in front of the tunnel server'),
            __('Platform owners') => implode(', ', config('exposehub.admin_emails')) ?: __('none configured'),
        ];
    }
}; ?>

<x-admin.frame :title="__('Service')" :lead="__('How the parts of this installation are doing, and what may need you.')">
    @php $s = $this->status; @endphp

    {{-- The service as it runs: this dashboard, the tunnel server, the agents. --}}
    <section aria-label="{{ __('Service status') }}" data-test="admin-diagram">
        <div class="grid items-center gap-x-4 gap-y-3 sm:grid-cols-[minmax(0,13rem)_minmax(3rem,1fr)_minmax(0,13rem)_minmax(3rem,1fr)_minmax(0,13rem)]">
            <div class="rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="font-display text-xl font-semibold">{{ __('Dashboard') }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('this application') }}</p>
            </div>

            <div class="flex items-center max-sm:pl-6">
                <span @class(['admin-link sm:w-full', 'is-stale' => $s['serverLink'] === 'stale', 'is-down' => $s['serverLink'] === 'down'])></span>
            </div>

            <div class="rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="font-display text-xl font-semibold">{{ __('Tunnel server') }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    @if ($s['report'])
                        {{ __('version :v', ['v' => $s['report']['server_version']]) }}
                    @else
                        {{ __('no report yet') }}
                    @endif
                </p>
                @if ($s['tokensStale'])
                    <p class="mt-2 text-sm font-medium text-red-700 dark:text-red-400" data-test="admin-tokens-stale">
                        {{ __('Token list out of date: dashboard tokens are refused.') }}
                        @if ($s['tokensRefreshedAt'])
                            {{ __('Last refreshed') }}
                            <time datetime="{{ $s['tokensRefreshedAt']->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') }}" data-local="dmy-hm">{{ $s['tokensRefreshedAt']->copy()->utc()->format('d.m.Y H:i') }}</time>.
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex items-center max-sm:pl-6">
                <span @class(['admin-link sm:w-full', 'is-stale' => $s['agentLink'] === 'stale', 'is-down' => $s['agentLink'] === 'down'])></span>
            </div>

            <a href="{{ route('admin.tunnels') }}" wire:navigate class="rounded-md border border-zinc-200 bg-white p-4 hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800/50 dark:hover:border-zinc-500">
                <p class="font-display text-xl font-semibold">{{ trans_choice('{0} No agents|{1} One agent|[2,*] :count agents', $s['agents'], ['count' => $s['agents']]) }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('connected right now') }}</p>
            </a>
        </div>

        <dl class="mt-5 grid gap-x-10 gap-y-2 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Tokens fetched by the server') }}</dt>
                <dd>{{ $s['snapshot']?->diffForHumans() ?? __('not since the dashboard started') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last status report') }}</dt>
                <dd>{{ $s['reportAt']?->diffForHumans() ?? __('none received') }}@if ($s['report'] && ($s['report']['agent_release'] ?? null)){{ ', '.__('offers agent :v', ['v' => $s['report']['agent_release']]) }}@endif</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Request logs received') }}</dt>
                <dd>{{ $s['logs']?->diffForHumans() ?? __('none yet') }}</dd>
            </div>
        </dl>
    </section>

    {{-- Totals as one ruled line, like a ledger. --}}
    <section class="mt-12 grid grid-cols-2 border-y border-zinc-200 sm:grid-cols-5 dark:border-zinc-700" aria-label="{{ __('Totals') }}">
        @foreach ($this->totals as [$value, $label])
            <div class="border-zinc-200 px-1 py-5 sm:border-l sm:px-5 sm:first:border-l-0 sm:first:pl-0 dark:border-zinc-700">
                <p class="font-display text-3xl font-semibold tabular-nums">{{ number_format($value) }}</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
            </div>
        @endforeach
    </section>

    <div class="mt-14 grid gap-x-16 gap-y-12 lg:grid-cols-2">
        <section>
            <h2 class="font-display text-2xl font-semibold">{{ __('Needs a look') }}</h2>
            <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="admin-attention">
                @forelse ($this->attention as [$text, $href])
                    <li class="border-b border-zinc-200 dark:border-zinc-700">
                        <a href="{{ $href }}" wire:navigate class="flex items-start gap-3 py-3 hover:text-zinc-900 dark:hover:text-white">
                            <span class="mt-2 size-2 shrink-0 rounded-full bg-cable"></span>
                            <span>{{ $text }}</span>
                        </a>
                    </li>
                @empty
                    <li class="border-b border-zinc-200 py-3 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('Nothing right now.') }}</li>
                @endforelse
            </ul>
        </section>

        <section>
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="font-display text-2xl font-semibold">{{ __('Configuration') }}</h2>
                <a href="{{ route('admin.settings') }}" wire:navigate class="text-sm font-medium underline decoration-cable decoration-2 underline-offset-4">{{ __('Change settings') }}</a>
            </div>
            <dl class="mt-4 border-t border-zinc-200 dark:border-zinc-700">
                @foreach ($this->config as $label => $value)
                    <div class="grid gap-x-6 border-b border-zinc-200 py-3 sm:grid-cols-[13rem_minmax(0,1fr)] dark:border-zinc-700">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="[overflow-wrap:anywhere]">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>
</x-admin.frame>
