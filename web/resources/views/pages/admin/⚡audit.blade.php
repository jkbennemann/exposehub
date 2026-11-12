<?php

use App\Concerns\RequiresAdmin;
use App\Models\AdminAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit log')] #[RequiresAdmin] class extends Component {
    use WithPagination;

    #[Url]
    public string $q = '';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return AdminAction::query()
            ->when(trim($this->q) !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->q))).'%';
                $query->where(fn (Builder $q) => $q->whereRaw('lower(admin_name) like ?', [$term])
                    ->orWhereRaw('lower(admin_email) like ?', [$term])
                    ->orWhereRaw('lower(subject_label) like ?', [$term]));
            })
            ->latest('id')
            ->paginate(50);
    }

    /**
     * A detail worth showing next to an entry, in words.
     */
    public function note(AdminAction $entry): ?string
    {
        $d = $entry->details ?? [];

        return match (true) {
            isset($d['reason']) => __('Reason: :r', ['r' => $d['reason']]),
            $entry->action === 'token.revoked' && isset($d['team']) => __('in :team, owned by :owner', ['team' => $d['team'], 'owner' => $d['owner'] ?? '?']),
            $entry->action === 'domain.removed' && isset($d['team']) => __('from :team', ['team' => $d['team']]),
            $entry->action === 'settings.updated' => collect($d)->map(fn ($c, $k) => str_replace('_', ' ', $k).': '.json_encode($c['from'] ?? null).' → '.json_encode($c['to'] ?? null))->join('; '),
            default => null,
        };
    }
}; ?>

<x-admin.frame :title="__('Audit log')" :lead="__('Every action taken in the admin area, newest first. Entries are kept for :days days.', ['days' => (int) config('exposehub.admin_audit_retention_days')])">
    <label class="block max-w-md">
        <span class="sr-only">{{ __('Search by admin or subject') }}</span>
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Search by admin or person') }}"
            class="w-full rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
    </label>

    <ol class="mt-6 border-t border-zinc-200 dark:border-zinc-700" data-test="audit-list">
        @forelse ($this->entries as $entry)
            <li class="grid gap-x-6 gap-y-1 border-b border-zinc-200 py-3 sm:grid-cols-[9.5rem_minmax(0,1fr)] dark:border-zinc-700" wire:key="a-{{ $entry->id }}">
                <time class="text-sm text-zinc-500 tabular-nums dark:text-zinc-400" datetime="{{ $entry->created_at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') }}" data-local="dmy-hm">{{ $entry->created_at->format('d.m.Y H:i') }}</time>
                <div class="min-w-0">
                    <p><span class="font-medium">{{ $entry->admin_name }}</span> {{ $entry->describe() }}</p>
                    @if ($note = $this->note($entry))
                        <p class="mt-0.5 text-sm text-zinc-500 [overflow-wrap:anywhere] dark:text-zinc-400">{{ $note }}</p>
                    @endif
                </div>
            </li>
        @empty
            <li class="border-b border-zinc-200 py-10 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('Nothing recorded yet.') }}</li>
        @endforelse
    </ol>

    <div class="mt-6">{{ $this->entries->links() }}</div>
</x-admin.frame>
