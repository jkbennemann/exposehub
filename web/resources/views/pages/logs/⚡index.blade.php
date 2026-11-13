<?php

use App\Concerns\RequiresTeamMembership;
use App\Models\LogGrant;
use App\Models\RequestLog;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Logs\LogStats;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Request logs')] #[RequiresTeamMembership] class extends Component {
    /** How far back each range reaches, in seconds, and into how many slots its timeline is cut. */
    private const RANGES = ['1h' => [3600, 60], '24h' => [86400, 48], '7d' => [604800, 56]];

    #[Locked]
    public int $teamId;

    #[Url]
    public string $range = '24h';

    #[Url]
    public string $q = '';

    /** "me", "all", or the id of a colleague who shared their logs. */
    #[Url]
    public string $owner = 'me';

    /** @var array<int, string> */
    #[Url]
    public array $statuses = ['2', '3', '4', '5'];

    /** The most rows the list shows; "Show older" stops there. */
    private const int MAX_ROWS = 500;

    public bool $live = true;

    /** Grows only through showOlder(), so a request cannot ask for every row at once. */
    #[Locked]
    public int $limit = 50;

    public ?int $open = null;

    public string $shareWith = '';

    public function mount(): void
    {
        $this->teamId = Auth::user()->currentTeam->id;
        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '24h';
        }
        // A visitor of the demo has no requests yet; the sample ones are shared with them.
        if (Auth::user()->is_demo && ! request()->has('owner')) {
            $this->owner = 'all';
        }
    }

    #[Computed]
    public function team(): Team
    {
        return Team::findOrFail($this->teamId);
    }

    /**
     * Colleagues who shared their logs with me, and are still in the team.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function sharers(): Collection
    {
        return User::query()
            ->whereIn('id', LogGrant::query()->select('owner_id')->where('team_id', $this->teamId)->where('viewer_id', Auth::id()))
            ->whereHas('teamMemberships', fn ($m) => $m->where('team_id', $this->teamId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Builder<RequestLog>
     */
    private function filtered(): Builder
    {
        $query = RequestLog::query()
            ->visibleTo(Auth::user(), $this->team)
            ->where('started_at', '>=', $this->from());

        $query->when($this->owner === 'me', fn ($q) => $q->where('request_logs.user_id', Auth::id()))
            ->when(ctype_digit($this->owner), fn ($q) => $q->where('request_logs.user_id', (int) $this->owner));

        $classes = array_values(array_intersect(['2', '3', '4', '5'], $this->statuses));
        if (count($classes) < 4) {
            $query->where(function ($q) use ($classes) {
                $q->whereRaw('1 = 0');
                foreach ($classes as $c) {
                    $q->orWhereBetween('status', [(int) $c * 100, (int) $c * 100 + 99]);
                }
            });
        }

        if (($term = trim($this->q)) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('path', 'like', $like)
                ->orWhere('host', 'like', $like)
                ->orWhere('method', strtoupper($term))
                ->orWhere('status', ctype_digit($term) ? (int) $term : -1));
        }

        return $query;
    }

    private function from(): CarbonInterface
    {
        return now()->subSeconds(self::RANGES[$this->range][0]);
    }

    /**
     * @return Collection<int, RequestLog>
     */
    #[Computed]
    public function rows(): Collection
    {
        // Without the headers and bodies (details): they can be large, and only
        // the opened row shows them, see openDetails().
        return $this->filtered()
            ->select(['request_logs.id', 'request_logs.team_id', 'request_logs.user_id', 'request_logs.agent_token_id', 'host', 'method', 'path', 'status',
                'duration_ms', 'request_bytes', 'response_bytes', 'remote', 'started_at'])
            ->with(['user:id,name', 'agentToken:id,name'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(min($this->limit, self::MAX_ROWS) + 1)
            ->get();
    }

    /**
     * Headers and bodies of the opened row, if its tunnel kept them.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function openDetails(): ?array
    {
        if ($this->open === null) {
            return null;
        }

        return $this->filtered()->whereKey($this->open)->first(['request_logs.id', 'details'])?->details;
    }

    /**
     * @return array{count: int, errors: int, p50: float|null, p95: float|null}
     */
    #[Computed]
    public function stats(): array
    {
        return (new LogStats($this->filtered()))->summary();
    }

    /**
     * @return array<int, array{path: string, count: int}>
     */
    #[Computed]
    public function busiest(): array
    {
        return (new LogStats($this->filtered()))->busiestPaths();
    }

    /**
     * @return array<int, array{start: CarbonInterface, total: int, errors: int}>
     */
    #[Computed]
    public function timeline(): array
    {
        return (new LogStats($this->filtered()))->timeline($this->from(), now(), self::RANGES[$this->range][1]);
    }

    #[Computed]
    public function hasAnyLogs(): bool
    {
        return RequestLog::query()->visibleTo(Auth::user(), $this->team)->exists();
    }

    /**
     * @return Collection<int, LogGrant>
     */
    #[Computed]
    public function myGrants(): Collection
    {
        return LogGrant::query()->where('team_id', $this->teamId)->where('owner_id', Auth::id())->with('viewer:id,name,email')->get();
    }

    /**
     * Team members I could still share my logs with.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function colleagues(): Collection
    {
        // The other members of the demo team are strangers to each other.
        if (Auth::user()->is_demo) {
            return new Collection;
        }

        return $this->team->members()
            ->where('users.id', '!=', Auth::id())
            ->whereNotIn('users.id', $this->myGrants->pluck('viewer_id'))
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
    }

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
            $this->limit = 50;
        }
    }

    public function toggleStatus(string $class): void
    {
        $this->statuses = in_array($class, $this->statuses, true)
            ? array_values(array_diff($this->statuses, [$class]))
            : [...$this->statuses, $class];
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function showOlder(): void
    {
        $this->limit = min($this->limit + 50, self::MAX_ROWS);
    }

    public function share(): void
    {
        abort_if(Auth::user()->is_demo, 403);

        $this->validate(['shareWith' => ['required', 'integer']], ['shareWith.required' => __('Choose a colleague.')]);

        $viewer = $this->team->members()->where('users.id', (int) $this->shareWith)->where('users.id', '!=', Auth::id())->first();
        abort_if($viewer === null, 403);

        LogGrant::query()->firstOrCreate(['team_id' => $this->teamId, 'owner_id' => Auth::id(), 'viewer_id' => $viewer->id]);
        AccountMail::send($viewer, AccountNotice::logsShared(Auth::user()->name, $this->team));

        $this->reset('shareWith');
        unset($this->myGrants, $this->colleagues);
        Flux::toast(variant: 'success', text: __(':name can now see your request logs.', ['name' => $viewer->name]));
    }

    public function stopSharing(int $grantId): void
    {
        $grant = LogGrant::query()->where('team_id', $this->teamId)->where('owner_id', Auth::id())->findOrFail($grantId);
        $name = $grant->viewer->name;
        $grant->delete();

        unset($this->myGrants, $this->colleagues);
        Flux::toast(variant: 'success', text: __(':name can no longer see your request logs.', ['name' => $name]));
    }

    public static function took(float $ms): string
    {
        return match (true) {
            $ms < 1 => '<1 ms',
            $ms < 1000 => round($ms).' ms',
            default => number_format($ms / 1000, $ms < 10000 ? 2 : 1).' s',
        };
    }

    public static function bytes(int $n): string
    {
        return match (true) {
            $n < 1024 => $n.' B',
            $n < 1048576 => number_format($n / 1024, 1).' KiB',
            default => number_format($n / 1048576, 1).' MiB',
        };
    }

    /**
     * A logged body, readable: pretty JSON, plain text, or a note for binary data.
     */
    public static function body(?string $base64, string $contentType): string
    {
        $raw = base64_decode((string) $base64, true);
        if ($raw === false || $raw === '') {
            return '';
        }
        if (! mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', substr($raw, 0, 2048))) {
            return __('Binary data, :size.', ['size' => self::bytes(strlen($raw))]);
        }
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($json) || str_contains($contentType, 'json'))) {
            return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $raw;
    }
}; ?>

@php
    $rows = $this->rows;
    $more = $rows->count() > $limit;
    $rows = $rows->take($limit);
    $stats = $this->stats;
    $timeline = $this->timeline;
    $peak = max(1, ...array_column($timeline, 'total'));
    $rangeLabels = ['1h' => __('Last hour'), '24h' => __('24 hours'), '7d' => __(':days days', ['days' => 7])];
    $statusTone = fn (int $s) => match (true) {
        $s >= 500 => 'text-fault',
        $s >= 400 => 'text-amber-700 dark:text-cable',
        $s >= 300 => 'text-zinc-500 dark:text-zinc-400',
        default => 'text-teal-700 dark:text-live',
    };
@endphp

<section @if ($live) wire:poll.5s.visible @endif>
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
        <div>
            <h1 class="page-title">{{ __('Request logs') }}</h1>
            <p class="mt-3 max-w-[64ch] text-zinc-600 dark:text-zinc-300">
                {{ __('Every request your tunnels in :team received, kept for :days days. Only you see your logs, and the colleagues you share them with below.', ['team' => $this->team->name, 'days' => config('exposehub.log_retention_days')]) }}
            </p>
        </div>
        <button type="button" wire:click="$toggle('live')" class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-200/60 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-pressed="{{ $live ? 'true' : 'false' }}" data-test="logs-live">
            <span @class(['size-2 rounded-full', 'bg-live shadow-[0_0_0_4px_rgb(20_184_176/0.25)]' => $live, 'bg-zinc-400' => ! $live])></span>
            {{ $live ? __('Live, updates every 5 seconds') : __('Paused') }}
        </button>
    </div>

    {{-- Filters --}}
    <div class="mt-8 flex flex-wrap items-center gap-3">
        <div class="flex overflow-hidden rounded-md border border-zinc-200 dark:border-zinc-700" role="group" aria-label="{{ __('Time range') }}">
            @foreach ($rangeLabels as $key => $label)
                <button type="button" wire:click="setRange('{{ $key }}')" aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                    @class(['px-3 py-1.5 text-sm', 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $range === $key, 'bg-white text-zinc-600 hover:text-zinc-900 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:text-white' => $range !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex overflow-hidden rounded-md border border-zinc-200 font-mono text-sm dark:border-zinc-700" role="group" aria-label="{{ __('Statuses') }}">
            @foreach (['2', '3', '4', '5'] as $class)
                @php $on = in_array($class, $statuses, true); @endphp
                <button type="button" wire:click="toggleStatus('{{ $class }}')" aria-pressed="{{ $on ? 'true' : 'false' }}"
                    @class(['px-2.5 py-1.5', 'bg-white text-zinc-900 dark:bg-zinc-900 dark:text-white' => $on, 'bg-transparent text-zinc-400 line-through dark:text-zinc-500' => ! $on])>{{ $class }}xx</button>
            @endforeach
        </div>
        <label class="min-w-56 flex-1">
            <span class="sr-only">{{ __('Search path, host, method or status') }}</span>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Search path, host, method or status') }}"
                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 font-mono text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="logs-search">
        </label>
        @if ($this->sharers->isNotEmpty())
            <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('Whose') }}
                <select wire:model.live="owner" class="rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900" data-test="logs-owner">
                    <option value="me">{{ __('My tunnels') }}</option>
                    @foreach ($this->sharers as $sharer)
                        <option value="{{ $sharer->id }}">{{ $sharer->name }}</option>
                    @endforeach
                    <option value="all">{{ __('Everything I can see') }}</option>
                </select>
            </label>
        @endif
    </div>

    {{-- Numbers and timeline --}}
    <div class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-zinc-200 bg-zinc-200 lg:grid-cols-[repeat(4,minmax(0,8.5rem))_minmax(0,1fr)] dark:border-zinc-700 dark:bg-zinc-700">
        @php
            $tiles = [
                [number_format($stats['count']), $stats['count'] === 1 ? __('request') : __('requests'), false],
                [$stats['count'] ? round($stats['errors'] / $stats['count'] * 100).'%' : '–', __('answered 4xx or 5xx'), $stats['errors'] > 0],
                [$stats['p50'] === null ? '–' : $this->took($stats['p50']), __('median time'), false],
                [$stats['p95'] === null ? '–' : $this->took($stats['p95']), __('95th percentile'), false],
            ];
        @endphp
        @foreach ($tiles as [$value, $label, $bad])
            <div class="bg-white px-4 py-3 dark:bg-zinc-900">
                <div @class(['font-display text-2xl leading-tight font-semibold tabular-nums', 'text-fault' => $bad])>{{ $value }}</div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
            </div>
        @endforeach
        <figure class="col-span-2 bg-white px-4 py-3 lg:col-span-1 dark:bg-zinc-900">
            <div class="flex h-14 items-end gap-px" role="img" aria-label="{{ __(':count requests over :range, :errors of them answered 4xx or 5xx.', ['count' => $stats['count'], 'range' => $rangeLabels[$range], 'errors' => $stats['errors']]) }}">
                @foreach ($timeline as $slot)
                    <div class="flex h-full flex-1 flex-col justify-end" title="{{ $slot['start']->format($range === '7d' ? 'D H:i' : 'H:i') }}: {{ $slot['total'] }}" data-at="{{ $slot['start']->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') }}" data-local="{{ $range === '7d' ? 'day-hm' : 'hm' }}" data-local-attr="title" data-local-suffix=": {{ $slot['total'] }}">
                        @if ($slot['total'] > 0)
                            <div class="w-full rounded-t-[2px] bg-zinc-800 dark:bg-zinc-300" style="height: {{ max(6, $slot['total'] / $peak * 100) }}%">
                                @if ($slot['errors'] > 0)
                                    <div class="w-full rounded-t-[2px] bg-fault" style="height: {{ max(12, $slot['errors'] / $slot['total'] * 100) }}%"></div>
                                @endif
                            </div>
                        @else
                            <div class="h-px w-full bg-zinc-200 dark:bg-zinc-700"></div>
                        @endif
                    </div>
                @endforeach
            </div>
            <figcaption class="mt-1.5 flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ $rangeLabels[$range] }} {{ __('ago') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-fault"></span>{{ __('4xx or 5xx') }}</span>
                <span>{{ __('now') }}</span>
            </figcaption>
        </figure>
    </div>

    @if ($this->busiest)
        <div class="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
            <span>{{ __('Busiest') }}</span>
            @foreach ($this->busiest as $path)
                <button type="button" wire:click="$set('q', @js($path['path']))" class="font-mono text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white">{{ $path['path'] }}</button>
                <span class="-ml-4 tabular-nums">{{ $path['count'] }}</span>
            @endforeach
        </div>
    @endif

    {{-- The log --}}
    <div class="mt-6 border-t border-zinc-200 dark:border-zinc-700" data-test="logs-table">
        @forelse ($rows as $log)
            @php $isOpen = $open === $log->id; @endphp
            <div wire:key="log-{{ $log->id }}" class="border-b border-zinc-200 dark:border-zinc-700">
                <button type="button" wire:click="toggle({{ $log->id }})" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                    @class(['grid w-full grid-cols-[3.5rem_minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-0.5 px-2 py-2 text-left font-mono text-sm hover:bg-zinc-100 sm:grid-cols-[9rem_3.5rem_minmax(0,1fr)_3rem_4.5rem_4.5rem] dark:hover:bg-zinc-800', 'bg-zinc-100 shadow-[inset_3px_0_0_var(--color-cable)] dark:bg-zinc-800' => $isOpen]) data-test="log-row">
                    <span class="order-4 col-span-2 text-xs text-zinc-500 sm:order-none sm:col-span-1 sm:text-sm dark:text-zinc-400">
                        <time class="hidden sm:inline" datetime="{{ $log->started_at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') }}" data-local="dm">{{ $log->started_at->format('d.m.') }}</time>
                        <time datetime="{{ $log->started_at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') }}" data-local="hms">{{ $log->started_at->format('H:i:s') }}</time>
                    </span>
                    <span class="text-zinc-500 dark:text-zinc-400">{{ $log->method }}</span>
                    <span class="truncate" title="{{ $log->host }}{{ $log->path }}">{{ $log->path }}</span>
                    <span class="{{ $statusTone($log->status) }} text-right tabular-nums">{{ $log->status === 101 ? 'ws' : $log->status }}</span>
                    <span class="order-5 text-right text-xs text-zinc-500 tabular-nums sm:order-none sm:text-sm dark:text-zinc-400">{{ $this->took($log->duration_ms) }}</span>
                    <span class="hidden text-right text-zinc-500 tabular-nums sm:inline dark:text-zinc-400">{{ $this->bytes($log->response_bytes) }}</span>
                </button>

                @if ($isOpen)
                    <div class="px-2 pt-2 pb-6 sm:pl-[9.75rem]" data-test="log-detail">
                        <dl class="grid grid-cols-[8rem_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                            <dd class="font-mono [overflow-wrap:anywhere]">{{ $log->host }}{{ $log->path }}</dd>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</dt>
                            <dd><time datetime="{{ $log->started_at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') }}" data-local="full">{{ $log->started_at->format('d.m.Y H:i:s.v') }}</time>, {{ __('took :time', ['time' => $this->took($log->duration_ms)]) }}</dd>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Sizes') }}</dt>
                            <dd>{{ __(':in sent, :out answered', ['in' => $this->bytes($log->request_bytes), 'out' => $this->bytes($log->response_bytes)]) }}</dd>
                            @if ($log->remote)
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('From network') }}</dt>
                                <dd class="font-mono">{{ $log->remote }}</dd>
                            @endif
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Tunnel of') }}</dt>
                            <dd>{{ $log->user->name }}@if ($log->agentToken), {{ __('token :name', ['name' => $log->agentToken->name]) }}@endif</dd>
                        </dl>

                        @php $details = $this->openDetails; @endphp
                        @if ($details)
                            @foreach (['request' => __('Request'), 'response' => __('Response')] as $side => $heading)
                                @php
                                    $headers = $details[$side.'_header'] ?? [];
                                    $type = strtolower(collect($headers)->first(fn ($v, $k) => strtolower($k) === 'content-type')[0] ?? '');
                                    $text = $this->body($details[$side.'_body'] ?? null, $type);
                                @endphp
                                <h3 class="mt-5 font-display text-lg font-semibold">{{ $heading }}</h3>
                                <dl class="mt-2 grid grid-cols-[minmax(8rem,max-content)_minmax(0,1fr)] gap-x-5 gap-y-0.5 font-mono text-xs">
                                    @foreach (collect($headers)->sortKeys() as $name => $values)
                                        @foreach ((array) $values as $value)
                                            <dt class="text-zinc-500 dark:text-zinc-400">{{ $name }}</dt>
                                            <dd class="[overflow-wrap:anywhere]">{{ $value }}</dd>
                                        @endforeach
                                    @endforeach
                                </dl>
                                @if ($text !== '')
                                    <pre class="mt-3 max-h-96 overflow-auto rounded-md border border-zinc-200 bg-white p-3 font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere] dark:border-zinc-700 dark:bg-zinc-950">{{ $text }}</pre>
                                    @if ($details[$side.'_truncated'] ?? false)
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Cut short: only the first 64 KiB of each body are kept.') }}</p>
                                    @endif
                                @endif
                            @endforeach
                        @else
                            <p class="mt-4 max-w-[62ch] text-sm text-zinc-500 dark:text-zinc-400">
                                {!! __('Headers and bodies are not kept for this tunnel. Start it with :flag to keep them, or look at them live in the :inspector.', ['flag' => '<code class="font-mono whitespace-nowrap text-zinc-900 dark:text-white">--log-bodies</code>', 'inspector' => '<a class="underline underline-offset-4" href="'.e(route('inspector')).'" wire:navigate>'.e(__('inspector')).'</a>']) !!}
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="py-12" data-test="logs-empty">
                @if ($this->hasAnyLogs)
                    <p class="font-display text-xl font-semibold">{{ __('Nothing matches') }}</p>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('No request in this time range matches the search and the statuses selected.') }}</p>
                @else
                    <p class="font-display text-xl font-semibold">{{ __('No requests logged yet') }}</p>
                    <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('Start a tunnel and send it a request; it appears here within a few seconds.') }}</p>
                    <x-command class="mt-4 max-w-xl" command="exposehub http 8000" />
                @endif
            </div>
        @endforelse
    </div>

    @if ($more)
        <div class="mt-4">
            <flux:button variant="filled" wire:click="showOlder" data-test="logs-older">{{ __('Show older requests') }}</flux:button>
        </div>
    @endif

    {{-- How logging works, and sharing --}}
    <div class="mt-16 grid gap-x-16 gap-y-10 lg:grid-cols-2">
        <div>
            <h2 class="font-display text-2xl font-semibold">{{ __('What is logged') }}</h2>
            <div class="mt-3 max-w-[62ch] space-y-3 text-zinc-600 dark:text-zinc-300">
                <p>{{ __('For every request: the address, method, status, time taken, sizes, and the visitor\'s network, never their full address. Values in the query string that look like secrets (tokens, keys, signatures) are blanked out.') }}</p>
                <p>{!! __('Headers and bodies only for tunnels started with :bodies, up to 64 KiB each, with Authorization headers and cookies blanked out. :off keeps nothing of a tunnel\'s requests.', ['bodies' => '<code class="font-mono whitespace-nowrap text-zinc-900 dark:text-white">--log-bodies</code>', 'off' => '<code class="font-mono whitespace-nowrap text-zinc-900 dark:text-white">--no-log</code>']) !!}</p>
                <p>{{ __('Logs are deleted after :days days, and at once when you leave the team or delete your account.', ['days' => config('exposehub.log_retention_days')]) }}</p>
            </div>
        </div>

        <div>
            <h2 class="font-display text-2xl font-semibold">{{ __('Who can see your logs') }}</h2>
            <p class="mt-3 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('Only you, unless you share them with a colleague in :team. They see all your tunnels\' logs in this team until you stop sharing or one of you leaves.', ['team' => $this->team->name]) }}</p>

            <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="log-grants">
                @foreach ($this->myGrants as $grant)
                    <li class="flex items-center justify-between gap-4 border-b border-zinc-200 py-2.5 dark:border-zinc-700" wire:key="grant-{{ $grant->id }}">
                        <span>{{ $grant->viewer->name }}</span>
                        <flux:button size="sm" variant="ghost" wire:click="stopSharing({{ $grant->id }})" data-test="log-grant-revoke">{{ __('Stop sharing') }}</flux:button>
                    </li>
                @endforeach
            </ul>

            @if ($this->colleagues->isNotEmpty())
                <form wire:submit="share" class="mt-4 flex flex-wrap items-start gap-2">
                    <label class="min-w-48 flex-1">
                        <span class="sr-only">{{ __('Colleague') }}</span>
                        <select wire:model="shareWith" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="log-share-with">
                            <option value="">{{ __('Choose a colleague') }}</option>
                            @foreach ($this->colleagues as $colleague)
                                <option value="{{ $colleague->id }}">{{ $colleague->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <flux:button type="submit" variant="primary" data-test="log-share">{{ __('Share my logs') }}</flux:button>
                    @error('shareWith') <p class="w-full text-sm text-fault">{{ $message }}</p> @enderror
                </form>
            @elseif ($this->myGrants->isEmpty() && ! auth()->user()->is_demo)
                <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">{{ __('There is nobody else in this team yet.') }}</p>
            @endif

            @if ($this->sharers->isNotEmpty())
                <p class="mt-6 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Shared with you by :names. Choose whose logs to see with the filter at the top.', ['names' => $this->sharers->pluck('name')->join(', ', ' '.__('and').' ')]) }}
                </p>
            @endif
        </div>
    </div>
</section>
