<?php

use App\Concerns\RequiresAdmin;
use App\Models\AdminAction;
use App\Support\Settings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] #[RequiresAdmin] class extends Component {
    public string $allowed = '';

    public string $hidden = '';

    public string $retention = '';

    public string $contact = '';

    public function mount(): void
    {
        $this->fill([
            'allowed' => implode("\n", config('exposehub.allowed_email_domains')),
            'hidden' => implode("\n", config('exposehub.hidden_email_domains')),
            'retention' => (string) config('exposehub.log_retention_days'),
            'contact' => (string) config('exposehub.contact_email'),
        ]);
    }

    /**
     * Who last changed each setting here, or null when it comes from config.
     *
     * @return array<string, array{by: ?string, at: ?string}>
     */
    #[Computed]
    public function origin(): array
    {
        return DB::table('settings')->leftJoin('users', 'users.id', '=', 'settings.updated_by')
            ->get(['settings.key', 'users.name', 'settings.updated_at'])
            ->mapWithKeys(fn ($row) => [$row->key => ['by' => $row->name, 'at' => $row->updated_at]])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function domains(string $text): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($d) => mb_strtolower(ltrim(trim($d), '@')),
            preg_split('/[\s,;]+/', $text) ?: [],
        ))));
    }

    public function save(): void
    {
        $this->validate([
            'retention' => ['required', 'integer', 'min:1', 'max:90'],
            'contact' => ['nullable', 'email', 'max:254'],
        ]);
        $domainRule = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
        foreach (['allowed', 'hidden'] as $field) {
            foreach ($this->domains($this->{$field}) as $domain) {
                if (! preg_match($domainRule, $domain)) {
                    $this->addError($field, __(':domain is not a domain name.', ['domain' => $domain]));

                    return;
                }
            }
        }
        $hidden = $this->domains($this->hidden);
        if (array_diff($hidden, $this->domains($this->allowed)) !== []) {
            $this->addError('hidden', __('Hidden domains must also be allowed.'));

            return;
        }

        $new = [
            'allowed_email_domains' => $this->domains($this->allowed),
            'hidden_email_domains' => $hidden,
            'log_retention_days' => (int) $this->retention,
            'contact_email' => trim($this->contact) ?: null,
        ];

        $changed = [];
        foreach ($new as $key => $value) {
            $current = config(Settings::EDITABLE[$key]['config']);
            if ($value == $current) {
                continue;
            }
            $changed[$key] = ['from' => $current, 'to' => $value];
            $value == Settings::default($key) ? Settings::reset($key) : Settings::put($key, $value, Auth::id());
        }

        if ($changed === []) {
            Flux::toast(text: __('Nothing changed.'));

            return;
        }

        AdminAction::record(Auth::user(), 'settings.updated', null, implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($changed))), $changed);
        unset($this->origin);
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function resetSetting(string $key): void
    {
        abort_unless(isset(Settings::EDITABLE[$key]), 404);
        $from = config(Settings::EDITABLE[$key]['config']);
        Settings::reset($key);
        AdminAction::record(Auth::user(), 'settings.updated', null, str_replace('_', ' ', $key).' '.__('(back to the configured value)'), [$key => ['from' => $from, 'to' => Settings::default($key)]]);
        $this->mount();
        unset($this->origin);
        Flux::toast(variant: 'success', text: __('Back to the configured value.'));
    }
}; ?>

<x-admin.frame :title="__('Settings')" :lead="__('Values here take precedence over the configuration the service was deployed with. Reset one to go back to the deployed value.')">
    @php
        $fields = [
            ['allowed_email_domains', 'allowed', __('Who may register'), __('Email domains, one per line. People can register and change their address only within these. Empty lets anyone register.'), 'textarea'],
            ['hidden_email_domains', 'hidden', __('Domains not shown'), __('Allowed domains that are never named on a page or in an error, for example a private one. One per line.'), 'textarea'],
            ['log_retention_days', 'retention', __('Keep request logs for'), __('Days, 1 to 90. The privacy notice names this number: change the notice when you change it.'), 'days'],
            ['contact_email', 'contact', __('Contact address'), __('Named in mails about suspended and deleted accounts. Empty leaves it out.'), 'email'],
        ];
    @endphp

    <form wire:submit="save" class="max-w-3xl border-t border-zinc-200 dark:border-zinc-700" data-test="settings-form">
        @foreach ($fields as [$key, $model, $label, $help, $kind])
            <div class="grid gap-x-10 gap-y-3 border-b border-zinc-200 py-6 md:grid-cols-[15rem_minmax(0,1fr)] dark:border-zinc-700">
                <div>
                    <p class="font-display text-lg font-semibold">{{ $label }}</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        @if (isset($this->origin[$key]))
                            {{ __('Changed here by :name, :when.', ['name' => $this->origin[$key]['by'] ?? __('a deleted account'), 'when' => \Illuminate\Support\Carbon::parse($this->origin[$key]['at'])->diffForHumans()]) }}
                            <button type="button" wire:click="resetSetting('{{ $key }}')" data-test="setting-reset" class="font-medium underline decoration-cable decoration-2 underline-offset-4">{{ __('Reset') }}</button>
                        @else
                            {{ __('From the deployment.') }}
                        @endif
                    </p>
                </div>
                <div>
                    @if ($kind === 'textarea')
                        <flux:textarea wire:model="{{ $model }}" rows="3" class="font-mono" :aria-label="$label" />
                    @elseif ($kind === 'days')
                        <flux:input wire:model="{{ $model }}" type="number" min="1" max="90" class="max-w-32" :aria-label="$label" />
                    @else
                        <flux:input wire:model="{{ $model }}" type="email" :aria-label="$label" />
                    @endif
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $help }}</p>
                    @error($model)<p class="mt-2 text-sm text-fault">{{ $message }}</p>@enderror
                </div>
            </div>
        @endforeach

        <div class="flex items-center gap-4 py-6">
            <flux:button type="submit" variant="primary" data-test="settings-save">{{ __('Save settings') }}</flux:button>
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Saved changes are recorded in the audit log.') }}</span>
        </div>
    </form>

    {{-- Single sign-on is set in the deployment; shown here so an admin can see what is in force and what to register with the provider. --}}
    <section class="mt-10 max-w-3xl" data-test="admin-sso">
        <h2 class="font-display text-2xl font-semibold">{{ __('Single sign-on') }}</h2>
        @if (\App\Support\Sso\Sso::enabled())
            <p class="mt-2 text-zinc-600 dark:text-zinc-300">{{ __('On. Accounts of the domains below sign in with :name only; the platform owners keep their password and passkeys.', ['name' => \App\Support\Sso\Sso::name()]) }}</p>
            <dl class="mt-4 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Issuer') }}</dt><dd class="font-mono [overflow-wrap:anywhere]">{{ \App\Support\Sso\Sso::issuer() }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Client id') }}</dt><dd class="font-mono [overflow-wrap:anywhere]">{{ config('exposehub.sso.client_id') }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Redirect address to register there') }}</dt><dd class="font-mono [overflow-wrap:anywhere]">{{ \App\Support\Sso\Sso::redirectUri() }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Domains') }}</dt><dd class="font-mono">{{ implode(', ', \App\Support\Sso\Sso::domains()) }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Tokens work') }}</dt><dd>{{ \App\Support\Sso\Sso::tokenDays() > 0 ? trans_choice('{1} one day after the last sign-in|[2,*] :n days after the last sign-in', \App\Support\Sso\Sso::tokenDays(), ['n' => \App\Support\Sso\Sso::tokenDays()]) : __('without a limit') }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Accounts signed in through it') }}</dt><dd>{{ \App\Models\UserIdentity::query()->where('issuer', \App\Support\Sso\Sso::issuer())->count() }}</dd>
            </dl>
        @else
            <p class="mt-2 text-zinc-600 dark:text-zinc-300">{{ __('Off. It is switched on in the deployment, with the issuer, the client and the email domains of your identity provider; the admin guide says how.') }}</p>
        @endif
    </section>

    <section class="mt-10 max-w-3xl">
        <h2 class="font-display text-2xl font-semibold">{{ __('Set in the deployment only') }}</h2>
        <p class="mt-2 text-zinc-600 dark:text-zinc-300">{{ __('Addresses, the tunnel domain, mail, the node secret, platform owners and whether the tunnel server logs requests at all live in the deployment (Helm chart values), because the tunnel server and the cluster need them too.') }}</p>
    </section>
</x-admin.frame>
