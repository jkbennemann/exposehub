<?php

use App\Actions\Admin\AdminActions;
use App\Actions\Account\DeleteAccount;
use App\Concerns\RequiresAdmin;
use App\Models\AgentToken;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Title('Person')] #[RequiresAdmin] class extends Component {
    #[Locked]
    public int $userId;

    public string $reason = '';

    public string $confirmEmail = '';

    public function mount(User $user): void
    {
        $this->userId = $user->id;
    }

    #[Computed]
    public function person(): User
    {
        return User::with(['teams'])->findOrFail($this->userId);
    }

    /** Why the signed-in admin may not act on this person, or null. */
    #[Computed]
    public function refusal(): ?string
    {
        return app(AdminActions::class)->refusal(Auth::user(), $this->person);
    }

    /**
     * @return Collection<int, AgentToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        // Not revoked, team still there. A suspended person's tokens are listed
        // too: they are only paused and work again on reactivation.
        return $this->person->agentTokens()->whereNull('revoked_at')->whereHas('team')->with('team:id,name')->latest('id')->get();
    }

    #[Computed]
    public function activity(): array
    {
        $logs = $this->person->requestLogs();
        $week = (clone $logs)->where('started_at', '>=', now()->subDays(7));
        $total = (clone $week)->count();

        return [
            'day' => (clone $logs)->where('started_at', '>=', now()->subDay())->count(),
            'week' => $total,
            'errors' => $total ? round((clone $week)->where('status', '>=', 400)->count() / $total * 100) : null,
        ];
    }

    #[Computed]
    public function facts(): array
    {
        $p = $this->person;
        $lastSeen = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('user_id', $p->id)->max('last_activity')
            : null;

        return array_filter([
            __('Joined') => $p->created_at?->format('d.m.Y'),
            __('Email address') => $p->email_verified_at ? __('confirmed :date', ['date' => $p->email_verified_at->format('d.m.Y')]) : __('not confirmed'),
            __('Two-factor sign-in') => $p->two_factor_confirmed_at ? __('on') : __('off'),
            __('Passkeys') => (string) DB::table('passkeys')->where('user_id', $p->id)->count(),
            __('Last seen') => $lastSeen ? Carbon::createFromTimestamp($lastSeen)->diffForHumans() : null,
            __('Blocking deletion') => app(DeleteAccount::class)->blockingTeams($p)->pluck('name')->join(', ') ?: null,
        ], fn ($v) => $v !== null);
    }

    private function run(string $modal, callable $action, string $done): void
    {
        try {
            $action(app(AdminActions::class), Auth::user());
        } catch (AuthorizationException|HttpException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::modal($modal)->close();
        Flux::toast(variant: 'success', text: $done);
        unset($this->person, $this->refusal, $this->tokens, $this->facts);
    }

    public function suspend(): void
    {
        $this->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->run('suspend', fn (AdminActions $a, User $me) => $a->suspend($me, $this->person, $this->reason), __('Account suspended. Their tunnels close within seconds.'));
        $this->reason = '';
    }

    public function reactivate(): void
    {
        $this->run('reactivate', fn (AdminActions $a, User $me) => $a->reactivate($me, $this->person), __('Account active again.'));
    }

    public function grantAdmin(): void
    {
        $this->run('grant-admin', fn (AdminActions $a, User $me) => $a->grantAdmin($me, $this->person), __('Admin rights given.'));
    }

    public function revokeAdmin(): void
    {
        $this->run('revoke-admin', fn (AdminActions $a, User $me) => $a->revokeAdmin($me, $this->person), __('Admin rights removed.'));
    }

    public function verifyEmail(): void
    {
        $this->run('verify', fn (AdminActions $a, User $me) => $a->verifyEmail($me, $this->person), __('Email address confirmed.'));
    }

    public function resendVerification(): void
    {
        $this->run('verify', fn (AdminActions $a, User $me) => $a->resendVerification($me, $this->person), __('Verification mail sent.'));
    }

    public function revokeToken(int $tokenId): void
    {
        $token = $this->person->agentTokens()->findOrFail($tokenId);
        $this->run("revoke-{$tokenId}", fn (AdminActions $a, User $me) => $a->revokeToken($me, $token), __('Token revoked. Its agents disconnect within seconds.'));
    }

    public function delete(): void
    {
        if (mb_strtolower(trim($this->confirmEmail)) !== mb_strtolower($this->person->email)) {
            $this->addError('confirmEmail', __('Type the address exactly as shown.'));

            return;
        }
        try {
            app(AdminActions::class)->delete(Auth::user(), $this->person);
        } catch (AuthorizationException|HttpException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: __('Account deleted.'));
        $this->redirectRoute('admin.people', navigate: true);
    }
}; ?>

@php $p = $this->person; $me = auth()->user(); @endphp
<x-admin.frame :title="$p->name">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 -mt-4" data-test="person-head">
        <span class="text-zinc-600 dark:text-zinc-300">{{ $p->email }}</span>
        @if ($p->isOwner())
            <span class="rounded bg-cable px-1.5 py-0.5 text-xs font-semibold text-ink">{{ __('Platform owner') }}</span>
        @elseif ($p->is_admin)
            <span class="rounded border border-cable px-1.5 py-0.5 text-xs font-semibold">{{ __('Admin') }}</span>
        @endif
        @if ($p->isSuspended())
            <span class="rounded bg-fault/15 px-1.5 py-0.5 text-xs font-semibold text-fault">{{ __('Suspended :when', ['when' => $p->suspended_at->diffForHumans()]) }}</span>
        @endif
    </div>

    @if ($p->isSuspended() && $p->suspension_reason)
        <p class="mt-4 max-w-[64ch] border-l-4 border-fault pl-4 text-zinc-700 dark:text-zinc-200">{{ $p->suspension_reason }}</p>
    @endif

    <div class="mt-10 grid gap-x-16 gap-y-12 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-12">
            <section>
                <h2 class="font-display text-2xl font-semibold">{{ __('Account') }}</h2>
                <dl class="mt-4 border-t border-zinc-200 dark:border-zinc-700">
                    @foreach ($this->facts as $label => $value)
                        <div class="grid gap-x-6 border-b border-zinc-200 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] dark:border-zinc-700">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section>
                <h2 class="font-display text-2xl font-semibold">{{ __('Traffic to their tunnels') }}</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Counts only. What was requested stays in their own request log.') }}</p>
                <div class="mt-4 grid grid-cols-3 border-y border-zinc-200 dark:border-zinc-700" data-test="person-activity">
                    @foreach ([[number_format($this->activity['day']), __('requests, 24 hours')], [number_format($this->activity['week']), __('requests, 7 days')], [$this->activity['errors'] === null ? '–' : $this->activity['errors'].'%', __('answered 4xx or 5xx')]] as [$v, $l])
                        <div class="border-zinc-200 py-4 pr-4 not-first:border-l not-first:pl-5 dark:border-zinc-700">
                            <p class="font-display text-3xl font-semibold tabular-nums">{{ $v }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $l }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section>
                <h2 class="font-display text-2xl font-semibold">{{ __('Teams') }}</h2>
                <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700">
                    @forelse ($p->teams as $team)
                        <li class="flex items-baseline justify-between gap-4 border-b border-zinc-200 py-3 dark:border-zinc-700">
                            <a href="{{ route('admin.teams', ['q' => $team->slug]) }}" wire:navigate class="font-medium hover:underline">{{ $team->name }}</a>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $team->is_personal ? __('personal') : ucfirst($team->pivot->role instanceof \BackedEnum ? $team->pivot->role->value : (string) $team->pivot->role) }}</span>
                        </li>
                    @empty
                        <li class="border-b border-zinc-200 py-3 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('In no team.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section>
                <h2 class="font-display text-2xl font-semibold">{{ __('Agent tokens') }}</h2>
                @if ($p->isSuspended() && $this->tokens->isNotEmpty())
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paused while the account is suspended.') }}</p>
                @endif
                <ul class="mt-4 border-t border-zinc-200 dark:border-zinc-700" data-test="person-tokens">
                    @forelse ($this->tokens as $token)
                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 border-b border-zinc-200 py-3 dark:border-zinc-700" wire:key="t-{{ $token->id }}">
                            <span class="min-w-0">
                                <span class="font-medium">{{ $token->name }}</span>
                                <span class="ml-2 font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $token->prefix }}…</span>
                                <span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ __(':team, created :when', ['team' => $token->team?->name, 'when' => $token->created_at->diffForHumans()]) }}</span>
                            </span>
                            <flux:modal.trigger :name="'revoke-'.$token->id">
                                <flux:button size="sm" variant="ghost">{{ __('Revoke') }}</flux:button>
                            </flux:modal.trigger>
                        </li>
                        <flux:modal :name="'revoke-'.$token->id" class="max-w-lg">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Revoke :name', ['name' => $token->name]) }}</flux:heading>
                                    <flux:subheading>{{ __('Agents using it are disconnected within seconds and cannot reconnect. :name gets a mail about it.', ['name' => $p->name]) }}</flux:subheading>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <flux:modal.close><flux:button variant="filled">{{ __('Keep token') }}</flux:button></flux:modal.close>
                                    <flux:button variant="danger" wire:click="revokeToken({{ $token->id }})">{{ __('Revoke token') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @empty
                        <li class="border-b border-zinc-200 py-3 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('No tokens.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- Actions. What this admin may not do is shown, with the reason, instead of hidden. --}}
        <aside class="flex flex-col gap-3 self-start lg:sticky lg:top-6" data-test="person-actions">
            <h2 class="font-display text-2xl font-semibold">{{ __('Actions') }}</h2>
            @if ($this->refusal)
                <p class="rounded-md border border-zinc-200 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300" data-test="person-refusal">{{ $this->refusal }}</p>
            @endif

            @if (! $p->email_verified_at)
                <flux:modal.trigger name="verify">
                    <flux:button class="w-full" :disabled="(bool) $this->refusal || $p->hasOwnerAddress()">{{ __('Confirm email address') }}</flux:button>
                </flux:modal.trigger>
            @endif

            @if ($me->isOwner() && ! $p->isOwner() && $me->isNot($p))
                @if ($p->is_admin)
                    <flux:modal.trigger name="revoke-admin"><flux:button class="w-full">{{ __('Remove admin rights') }}</flux:button></flux:modal.trigger>
                @else
                    <flux:modal.trigger name="grant-admin"><flux:button class="w-full" :disabled="$p->isSuspended() || ! $p->email_verified_at">{{ __('Make admin') }}</flux:button></flux:modal.trigger>
                @endif
            @endif

            @if ($p->isSuspended())
                <flux:modal.trigger name="reactivate"><flux:button class="w-full" variant="primary" :disabled="$me->is($p) || $p->isOwner() || ($p->is_admin && ! $me->isOwner())" data-test="person-reactivate">{{ __('Reactivate account') }}</flux:button></flux:modal.trigger>
            @else
                <flux:modal.trigger name="suspend"><flux:button class="w-full" :disabled="(bool) $this->refusal" data-test="person-suspend">{{ __('Suspend account') }}</flux:button></flux:modal.trigger>
            @endif

            <flux:modal.trigger name="delete"><flux:button class="w-full" variant="danger" :disabled="(bool) $this->refusal && ! ($p->isSuspended() && $me->isOwner() && ! $p->isOwner() && $me->isNot($p))">{{ __('Delete account') }}</flux:button></flux:modal.trigger>
        </aside>
    </div>

    <flux:modal name="suspend" class="max-w-lg">
        <form wire:submit="suspend" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Suspend :name', ['name' => $p->name]) }}</flux:heading>
                <flux:subheading>{{ __('They are signed out and cannot sign in, and their tunnels close within seconds. Teams, tokens and data stay as they are, so reactivating restores everything. They get a mail with the reason you give.') }}</flux:subheading>
            </div>
            <flux:textarea wire:model="reason" :label="__('Reason (in the mail and the audit log)')" rows="3" data-test="suspend-reason" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" data-test="suspend-confirm">{{ __('Suspend account') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reactivate" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reactivate :name', ['name' => $p->name]) }}</flux:heading>
                <flux:subheading>{{ __('They can sign in again and their tokens work again. They get a mail.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="reactivate" data-test="reactivate-confirm">{{ __('Reactivate account') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="grant-admin" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Make :name an admin', ['name' => $p->name]) }}</flux:heading>
                <flux:subheading>{{ __('Admins see everyone\'s account data and request counts, and can suspend and delete accounts, revoke tokens, remove domains and change settings. They cannot act on platform owners or other admins.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="grantAdmin">{{ __('Make admin') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="revoke-admin" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Remove admin rights from :name', ['name' => $p->name]) }}</flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="revokeAdmin">{{ __('Remove admin rights') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="verify" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirm :email', ['email' => $p->email]) }}</flux:heading>
                <flux:subheading>{{ __('Confirm it for them only when you know the address is theirs. Otherwise send the verification mail again.') }}</flux:subheading>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button variant="filled" wire:click="resendVerification">{{ __('Send the mail again') }}</flux:button>
                <flux:button variant="primary" wire:click="verifyEmail">{{ __('Confirm address') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete" class="max-w-lg">
        <form wire:submit="delete" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete the account of :name', ['name' => $p->name]) }}</flux:heading>
                <flux:subheading>{{ __('The account, their personal team and the teams only they are in are deleted for good, with tokens, domains and request logs. They get a last mail. This cannot be undone.') }}</flux:subheading>
            </div>
            <flux:input wire:model="confirmEmail" :label="__('Type :email to confirm', ['email' => $p->email])" autocomplete="off" data-test="delete-confirm-email" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" data-test="delete-confirm">{{ __('Delete account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</x-admin.frame>
