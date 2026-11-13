@php
    $team = auth()->user()->currentTeam;
    $tokens = $team->agentTokens()->usable()->where('user_id', auth()->id())->count();
    $install = 'curl -fsSL '.config('exposehub.agent_server').'/install.sh | sh';
    $login = 'exposehub login';
    // One sentence for a coding agent: the guide at /agents.md does the rest.
    $agentPrompt = __('Read :url, install the exposehub agent if it is missing, then give my local site a public address.', ['url' => route('agents')]);
    $mcp = 'claude mcp add exposehub -- exposehub mcp';
@endphp
<x-layouts::app :title="__('Overview')">
    <livewire:pages::teams.pending-invitations-modal />

    <div>
        <h1 class="page-title">{{ __('Put a local service online') }}</h1>
        <p class="mt-3 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
            {{ __('The agent on your machine holds a connection to the tunnel server. Anything sent to the public address travels down that connection to your local port.') }}
        </p>

        <x-wire
            class="mt-10 border-y border-zinc-200 py-8 dark:border-zinc-700"
            from="localhost:8000"
            :from-note="__('a port on your machine')"
            :to="config('exposehub.example_address')"
            :to-note="__('an address anyone can open')"
        />

        <ol class="mt-10 max-w-3xl space-y-8">
            <li class="grid gap-x-6 gap-y-2 sm:grid-cols-[2.5rem_1fr]">
                <span class="font-display text-3xl leading-none font-semibold text-zinc-400 dark:text-zinc-500" aria-hidden="true">1</span>
                <div>
                    <h2 class="font-display text-xl font-semibold">{{ __('Create an agent token') }}</h2>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">
                        @if ($tokens > 0)
                            {{ trans_choice('{1} You have :count token on :team.|[2,*] You have :count tokens on :team.', $tokens, ['count' => $tokens, 'team' => $team->name]) }}
                        @else
                            {{ __('The token tells the tunnel server that your agent may open tunnels for :team.', ['team' => $team->name]) }}
                        @endif
                    </p>
                    <flux:button class="mt-3" :variant="$tokens > 0 ? 'filled' : 'primary'" :href="route('tokens.index')" wire:navigate data-test="overview-tokens-link">
                        {{ $tokens > 0 ? __('Manage tokens') : __('Create token') }}
                    </flux:button>
                </div>
            </li>

            <li class="grid gap-x-6 gap-y-2 sm:grid-cols-[2.5rem_1fr]">
                <span class="font-display text-3xl leading-none font-semibold text-zinc-400 dark:text-zinc-500" aria-hidden="true">2</span>
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-semibold">{{ __('Install the agent and log in') }}</h2>
                    <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('The installer puts the agent in ~/.local/bin, needs no administrator rights, and remembers this server, so no command needs to name it. Run exposehub update to get a newer version.') }}</p>
                    <x-command class="mt-3" :command="$install" />
                    <p class="mt-4 max-w-[62ch] text-zinc-600 dark:text-zinc-300">{{ __('Then log in. The agent asks for the token and saves it, so it never shows up in your shell history.') }}</p>
                    <x-command class="mt-3" :command="$login" />
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('On Windows, download') }}
                        <a class="underline underline-offset-4 hover:text-zinc-900 dark:hover:text-white" href="{{ config('exposehub.agent_server') }}/agent/exposehub-windows-amd64.exe">exposehub-windows-amd64.exe</a>
                        {{ __('or') }}
                        <a class="underline underline-offset-4 hover:text-zinc-900 dark:hover:text-white" href="{{ config('exposehub.agent_server') }}/agent/exposehub-windows-arm64.exe">exposehub-windows-arm64.exe</a>.
                        {!! __('If it asks which server to use, run :command once.', ['command' => '<code class="font-mono whitespace-nowrap">exposehub server '.e(config('exposehub.agent_server')).'</code>']) !!}
                    </p>
                </div>
            </li>

            <li class="grid gap-x-6 gap-y-2 sm:grid-cols-[2.5rem_1fr]">
                <span class="font-display text-3xl leading-none font-semibold text-zinc-400 dark:text-zinc-500" aria-hidden="true">3</span>
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-semibold">{{ __('Start the agent next to your service') }}</h2>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('Replace 8000 with the port your service listens on.') }}</p>
                    <x-command class="mt-3" command="exposehub http 8000" />
                </div>
            </li>

            <li class="grid gap-x-6 gap-y-2 sm:grid-cols-[2.5rem_1fr]">
                <span class="font-display text-3xl leading-none font-semibold text-zinc-400 dark:text-zinc-500" aria-hidden="true">4</span>
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-semibold">{{ __('Share the address it prints') }}</h2>
                    <p class="mt-1 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
                        {!! __('The agent prints the public address and keeps it for as long as it runs. Add :subdomain to pick the name yourself, and :auth to let people in only with a password.', ['subdomain' => '<code class="font-mono text-[0.95em] whitespace-nowrap">--subdomain</code>', 'auth' => '<code class="font-mono text-[0.95em] whitespace-nowrap">--auth name:password</code>']) !!}
                    </p>
                    <p class="mt-3 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
                        {{ __('Every request that comes in shows up on your own machine, with headers, body and timing.') }}
                        <a href="{{ route('inspector') }}" wire:navigate class="font-medium text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white" data-test="overview-inspector-link">{{ __('How the inspector works') }}</a>
                    </p>
                    <p class="mt-3 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
                        {{ __('A summary of each request is also kept here for :days days, visible only to you.', ['days' => config('exposehub.log_retention_days')]) }}
                        <a href="{{ route('logs.index') }}" wire:navigate class="font-medium text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white" data-test="overview-logs-link">{{ __('Open your request logs') }}</a>
                    </p>
                </div>
            </li>
        </ol>

        {{-- The same steps, left to a coding agent. --}}
        <section class="mt-14 max-w-3xl border-t border-zinc-200 pt-10 dark:border-zinc-700" aria-labelledby="overview-agents" data-test="overview-agents">
            <h2 id="overview-agents" class="font-display text-2xl font-semibold">{{ __('Or let your coding agent do it') }}</h2>
            <p class="mt-2 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
                {{ __('Paste this into Claude Code, Cursor, Codex or another agent. It reads the instructions this server publishes for agents, installs the agent and tells you the address. Log in yourself first, as in step 2: the agent never needs your token.') }}
            </p>
            <x-command class="mt-4" :command="$agentPrompt" />
            <p class="mt-6 max-w-[62ch] text-zinc-600 dark:text-zinc-300">
                {{ __('Agents that speak MCP can open, list and close tunnels as tools, and use your team\'s own domains. In Claude Code:') }}
            </p>
            <x-command class="mt-3" :command="$mcp" />
            <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                <a href="{{ route('docs') }}#agents" class="underline underline-offset-4 hover:text-zinc-900 dark:hover:text-white">{{ __('Other clients and what the tools do') }}</a>
            </p>
        </section>
    </div>
</x-layouts::app>
