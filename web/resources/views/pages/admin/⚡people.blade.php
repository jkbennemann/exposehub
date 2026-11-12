<?php

use App\Concerns\RequiresAdmin;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('People')] #[RequiresAdmin] class extends Component {
    use WithPagination;

    #[Url]
    public string $q = '';

    /** all, admins, suspended or unverified */
    #[Url]
    public string $filter = 'all';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'admins', 'suspended', 'unverified'], true) ? $filter : 'all';
        $this->resetPage();
    }

    #[Computed]
    public function people(): LengthAwarePaginator
    {
        $owners = config('exposehub.admin_emails');
        $sessions = config('session.driver') === 'database' ? config('session.table', 'sessions') : null;

        return User::query()
            ->when(trim($this->q) !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->q))).'%';
                $query->where(fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]));
            })
            ->when($this->filter === 'admins', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('is_admin', true)->orWhereIn(DB::raw('lower(email)'), $owners ?: [''])))
            ->when($this->filter === 'suspended', fn (Builder $q) => $q->whereNotNull('suspended_at'))
            ->when($this->filter === 'unverified', fn (Builder $q) => $q->whereNull('email_verified_at'))
            ->withCount([
                'teams',
                'agentTokens as active_tokens' => fn (Builder $q) => $q->usable(),
                'requestLogs as requests_week' => fn (Builder $q) => $q->where('started_at', '>=', now()->subDays(7)),
            ])
            ->when($sessions, fn (Builder $q) => $q->addSelect(['last_seen' => DB::table($sessions)
                ->selectRaw('max(last_activity)')->whereColumn("{$sessions}.user_id", 'users.id')]))
            ->orderBy('name')
            ->paginate(40);
    }

    #[Computed]
    public function counts(): array
    {
        $owners = config('exposehub.admin_emails');

        return [
            'all' => User::count(),
            'admins' => User::where(fn (Builder $q) => $q->where('is_admin', true)->orWhereIn(DB::raw('lower(email)'), $owners ?: ['']))->count(),
            'suspended' => User::whereNotNull('suspended_at')->count(),
            'unverified' => User::whereNull('email_verified_at')->count(),
        ];
    }
}; ?>

<x-admin.frame :title="__('People')" :lead="__('Everyone with an account. Open a person to suspend, delete or change them. You see how many requests reach their tunnels, never the requests themselves.')">
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex rounded-md border border-zinc-200 dark:border-zinc-700" role="group" aria-label="{{ __('Show') }}">
            @foreach (['all' => __('Everyone'), 'admins' => __('Admins'), 'suspended' => __('Suspended'), 'unverified' => __('Not confirmed')] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    @class(['px-3 py-1.5 text-sm first:rounded-l-md last:rounded-r-md', 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $filter === $key, 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $filter !== $key])>
                    {{ $label }} <span class="tabular-nums opacity-60">{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <label class="min-w-[16rem] flex-1">
            <span class="sr-only">{{ __('Search by name or email') }}</span>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Search by name or email') }}"
                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="people-search">
        </label>
    </div>

    <div class="mt-6 border-t border-zinc-200 dark:border-zinc-700" data-test="people-list">
        <div class="hidden grid-cols-[minmax(0,2.4fr)_5rem_5rem_7rem_8rem] gap-4 border-b border-zinc-200 py-2 text-sm text-zinc-500 md:grid dark:border-zinc-700 dark:text-zinc-400">
            <span>{{ __('Person') }}</span><span class="text-right">{{ __('Teams') }}</span><span class="text-right">{{ __('Tokens') }}</span><span class="text-right">{{ __('Requests, 7 days') }}</span><span class="text-right">{{ __('Last seen') }}</span>
        </div>
        @forelse ($this->people as $person)
            <a href="{{ route('admin.person', $person) }}" wire:navigate wire:key="p-{{ $person->id }}" data-test="person-row"
               class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-zinc-200 py-3 hover:bg-zinc-100 md:grid-cols-[minmax(0,2.4fr)_5rem_5rem_7rem_8rem] dark:border-zinc-700 dark:hover:bg-zinc-800/60">
                <span class="flex min-w-0 items-center gap-3">
                    <flux:avatar :initials="$person->initials()" size="sm" />
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium">{{ $person->name }}</span>
                            @if ($person->isOwner())
                                <span class="rounded bg-cable px-1.5 py-0.5 text-xs font-semibold text-ink">{{ __('Owner') }}</span>
                            @elseif ($person->is_admin)
                                <span class="rounded border border-cable px-1.5 py-0.5 text-xs font-semibold">{{ __('Admin') }}</span>
                            @endif
                            @if ($person->isSuspended())
                                <span class="rounded bg-fault/15 px-1.5 py-0.5 text-xs font-semibold text-fault">{{ __('Suspended') }}</span>
                            @endif
                            @if (! $person->email_verified_at)
                                <span class="rounded bg-zinc-200 px-1.5 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ __('Not confirmed') }}</span>
                            @endif
                        </span>
                        <span class="block truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $person->email }}</span>
                    </span>
                </span>
                <span class="text-right tabular-nums max-md:hidden">{{ $person->teams_count }}</span>
                <span class="text-right tabular-nums max-md:hidden">{{ $person->active_tokens }}</span>
                <span class="text-right tabular-nums max-md:hidden">{{ number_format($person->requests_week) }}</span>
                <span class="text-right text-sm text-zinc-500 dark:text-zinc-400">{{ $person->last_seen ? \Illuminate\Support\Carbon::createFromTimestamp($person->last_seen)->diffForHumans(short: true) : '–' }}</span>
            </a>
        @empty
            <p class="border-b border-zinc-200 py-10 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('Nobody matches.') }}</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $this->people->links() }}</div>
</x-admin.frame>
