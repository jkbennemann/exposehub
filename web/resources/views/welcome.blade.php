@php
    $demo = preg_replace('#^(https?://)your-name\.#', '$1demo.', config('exposehub.example_address'));
    $demoHost = preg_replace('#^https?://#', '', $demo);

    // The film: five things a tunnel is for, each one scene on the same cable.
    $scenes = [
        ['title' => __('Share a local site'), 'text' => __('Send a client the address and they see what is running on your machine, as you change it.')],
        ['title' => __('Receive webhooks'), 'text' => __('Payment providers, git hosts and other services can call your local code while you develop it.')],
        ['title' => __('Test on your phone'), 'text' => __('Open the same address on any device, on any network, without building or deploying anything.')],
        ['title' => __('Keep strangers out'), 'text' => __('With a password on the tunnel, requests without it are turned away at the server and never reach your machine.')],
        ['title' => __('Use your own domain'), 'text' => __('Connect a domain of your own with two DNS records. Its certificate is issued for you.')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5 lg:px-8">
            <x-app-logo href="{{ route('home') }}" />

            <nav class="flex items-center gap-2" aria-label="{{ __('Account') }}">
                <flux:button variant="ghost" :href="route('docs')" class="max-sm:hidden">{{ __('Docs') }}</flux:button>
                @auth
                    <flux:button variant="primary" :href="route('dashboard')">{{ __('Open dashboard') }}</flux:button>
                @else
                    <flux:button variant="ghost" :href="route('login')">{{ __('Log in') }}</flux:button>
                    @if (Route::has('register'))
                        <flux:button variant="primary" :href="route('register')">{{ __('Create account') }}</flux:button>
                    @endif
                @endauth
            </nav>
        </header>

        <main class="mx-auto max-w-7xl px-6 pt-10 pb-24 lg:px-8 lg:pt-16">
            <h1 class="max-w-[16ch] font-display text-[clamp(2.75rem,1.6rem+5vw,5.5rem)] leading-[0.98] font-semibold tracking-tight">
                {{ __('A public address for a port on your machine') }}
            </h1>
            <p class="mt-6 max-w-[56ch] text-xl text-zinc-600 dark:text-zinc-300">
                {{ __('One command connects a service on your computer to an address anyone can open. Your team runs the server itself, on its own domain.') }}
            </p>

            {{--
                The film. The stage is an illustration and hidden from assistive technology; the
                list of scenes below it says the same in words and is the control.
            --}}
            <section class="film mt-14" data-film data-scene="0" aria-label="{{ __('What a tunnel is for') }}">
                <div class="film-stage" aria-hidden="true">
                    <div class="film-local">
                        <div class="film-local-head">
                            <span>{{ __('your machine') }}</span>
                            <span class="font-mono">localhost:8000</span>
                        </div>
                        <ul class="film-log scene s0">
                            <li style="--i:0"><b>GET</b> / <i>200</i></li>
                            <li style="--i:1"><b>GET</b> /app.css <i>200</i></li>
                            <li style="--i:2"><b>GET</b> /logo.svg <i>200</i></li>
                        </ul>
                        <ul class="film-log scene s1">
                            <li style="--i:0"><b>POST</b> /webhooks/payment <i>200</i></li>
                            <li style="--i:1"><b>POST</b> /webhooks/push <i>200</i></li>
                            <li style="--i:2"><b>POST</b> /webhooks/payment <i>200</i></li>
                        </ul>
                        <ul class="film-log scene s2">
                            <li style="--i:0"><b>GET</b> / <i>200</i></li>
                            <li style="--i:1"><b>GET</b> /app.css <i>200</i></li>
                            <li style="--i:2"><b>POST</b> /login <i>302</i></li>
                        </ul>
                        <ul class="film-log scene s3">
                            <li style="--i:1"><b>GET</b> / <i>200</i></li>
                            <li class="film-log-note" style="--i:1.6">{{ __('3 requests stopped before they got here') }}</li>
                        </ul>
                        <ul class="film-log scene s4">
                            <li style="--i:1.1"><b>GET</b> / <i>200</i></li>
                            <li style="--i:1.7"><b>GET</b> /pricing <i>200</i></li>
                        </ul>
                    </div>

                    <div class="film-cable">
                        <span class="film-jack"></span>
                        <div class="film-line">
                            @foreach ([0, 1, 2, 4] as $scene)
                                @foreach ([0, 1, 2] as $n)
                                    <i class="film-packet film-in scene s{{ $scene }}" style="--i:{{ $n }}"></i>
                                    <i class="film-packet film-out scene s{{ $scene }}" style="--i:{{ $n }}"></i>
                                @endforeach
                            @endforeach

                            {{-- Scene 3: three strangers bounce off the gate, one visitor with the password passes. --}}
                            <span class="film-gate scene s3">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="1.5" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                            </span>
                            @foreach ([0, 1, 2] as $n)
                                <i class="film-packet film-stopped scene s3" style="--i:{{ $n }}"></i>
                            @endforeach
                            <i class="film-packet film-in film-key scene s3" style="--i:2"></i>
                            <i class="film-packet film-out scene s3" style="--i:2"></i>
                        </div>
                        <span class="film-jack"></span>
                    </div>

                    <div class="film-remote">
                        <div class="film-window scene s0">
                            <div class="film-address font-mono">{{ $demo }}</div>
                            <div class="film-page"><span style="--w:55%;--i:0"></span><span style="--w:90%;--i:1"></span><span style="--w:75%;--i:2"></span><span style="--w:40%;--i:3"></span></div>
                        </div>

                        <div class="film-senders scene s1">
                            <div style="--i:0"><span>{{ __('Payment provider') }}</span><code>POST /webhooks/payment</code></div>
                            <div style="--i:1"><span>{{ __('Git host') }}</span><code>POST /webhooks/push</code></div>
                        </div>

                        <div class="film-phone scene s2">
                            <div class="film-address font-mono">{{ $demoHost }}</div>
                            <div class="film-page"><span style="--w:70%;--i:0"></span><span style="--w:95%;--i:1"></span><span style="--w:60%;--i:2"></span><span style="--w:85%;--i:3"></span></div>
                        </div>

                        <ul class="film-strangers scene s3">
                            <li style="--i:0"><code>GET /.env</code><b>401</b></li>
                            <li style="--i:1"><code>GET /.git/HEAD</code><b>401</b></li>
                            <li style="--i:2"><code>GET /wp-config.php</code><b>401</b></li>
                            <li class="film-welcome" style="--i:3"><code>{{ __('ada, with the password') }}</code><b>200</b></li>
                        </ul>

                        <div class="film-window film-domain scene s4">
                            <div class="film-address font-mono">
                                <span class="film-domain-old">{{ $demo }}</span>
                                <span class="film-domain-new">https://dev.example.com</span>
                            </div>
                            <div class="film-cert">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" pathLength="1" /></svg>
                                <span>{{ __('Certificate issued') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <ol class="film-scenes">
                    @foreach ($scenes as $n => $scene)
                        <li>
                            <button type="button" data-film-scene="{{ $n }}" @if ($n === 0) aria-current="true" @endif>
                                <span class="film-scene-title">{{ $scene['title'] }}</span>
                                <span class="film-scene-text">{{ $scene['text'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{--
                One request, seen twice: in full on the developer's machine, and as the trimmed
                summary the dashboard keeps. The two panels are an illustration; the text under
                each says the same in words.
            --}}
            <section class="mt-20" aria-labelledby="seen-heading" data-test="landing-requests">
                <h2 id="seen-heading" class="max-w-[34ch] font-display text-3xl leading-tight font-semibold">{{ __('See what came through the tunnel') }}</h2>

                <div class="mt-8 grid gap-x-10 gap-y-12 lg:grid-cols-2 lg:grid-rows-[auto_auto] lg:gap-y-0">
                    <div class="lg:row-span-2 lg:grid lg:grid-rows-subgrid">
                        <div class="rounded-md bg-zinc-900 p-4 font-mono text-sm text-zinc-100 sm:p-5 dark:bg-zinc-950 dark:ring-1 dark:ring-zinc-700" aria-hidden="true">
                            <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 border-b border-zinc-700 pb-3 font-sans text-zinc-400">
                                <span>{{ __('your machine') }}</span>
                                <span class="font-mono text-cable">127.0.0.1:4040</span>
                            </div>
                            <p class="mt-3 [overflow-wrap:anywhere]"><span class="text-zinc-400">POST</span> /webhooks/payment?sig=9f3ac41e <span class="float-right text-live">200</span></p>
                            <dl class="mt-3 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-0.5 text-xs text-zinc-300">
                                <dt class="text-zinc-500">content-type</dt><dd>application/json</dd>
                                <dt class="text-zinc-500">user-agent</dt><dd>PaymentProvider/2.1</dd>
                            </dl>
                            <pre class="mt-3 overflow-x-auto rounded bg-white/5 px-3 py-2 text-xs leading-relaxed text-zinc-200">{
  "id": "evt_1Q8",
  "type": "payment.succeeded",
  "amount": 4900
}</pre>
                            <div class="mt-4 flex gap-2 font-sans text-xs font-medium">
                                <span class="rounded bg-cable px-2.5 py-1 text-ink">{{ __('Replay') }}</span>
                                <span class="rounded px-2.5 py-1 text-zinc-300 ring-1 ring-zinc-700">{{ __('Copy as curl') }}</span>
                            </div>
                        </div>
                        <div>
                            <h3 class="mt-6 font-display text-xl font-semibold">{{ __('Live, on your machine') }}</h3>
                            <p class="mt-2 max-w-[52ch] text-zinc-600 dark:text-zinc-300">
                                {!! __('While the agent runs, the inspector at :address shows each request with its headers and body. Send one to your service again with Replay, or copy it as a curl command. None of it leaves your computer.', ['address' => '<code class="font-mono text-[0.95em] whitespace-nowrap">127.0.0.1:4040</code>']) !!}
                            </p>
                        </div>
                    </div>

                    <div class="lg:row-span-2 lg:grid lg:grid-rows-subgrid">
                        <div class="self-start rounded-md border border-zinc-200 bg-white p-4 text-sm sm:p-5 dark:border-zinc-700 dark:bg-zinc-800/60" aria-hidden="true">
                            <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 border-b border-zinc-200 pb-3 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <span>{{ __('Request logs') }}</span>
                                <span>{{ __('kept :days days', ['days' => config('exposehub.log_retention_days')]) }}</span>
                            </div>
                            <p class="mt-3 font-mono [overflow-wrap:anywhere]"><span class="text-zinc-500 dark:text-zinc-400">09:15:48 POST</span> /webhooks/payment?sig=<mark class="rounded-sm bg-cable/25 px-0.5 text-inherit dark:bg-cable/20 dark:text-cable">[redacted]</mark> <span class="float-right text-teal-700 dark:text-live">200</span></p>
                            <dl class="mt-3 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-0.5 text-xs">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('From network') }}</dt><dd class="font-mono"><mark class="rounded-sm bg-cable/25 px-0.5 text-inherit dark:bg-cable/20 dark:text-cable">203.0.113.0</mark></dd>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Took') }}</dt><dd class="font-mono">38 ms</dd>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Body') }}</dt><dd>{{ __('not kept') }}</dd>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Seen by') }}</dt><dd>{{ __('you, and Ada since you shared it') }}</dd>
                            </dl>
                        </div>
                        <div>
                            <h3 class="mt-6 font-display text-xl font-semibold">{{ __('Afterwards, in the dashboard') }}</h3>
                            <p class="mt-2 max-w-[52ch] text-zinc-600 dark:text-zinc-300">
                                {!! __('A summary of each request stays for :days days: when it came, the address, the status and how long it took. Only you see it, unless you share it with a colleague. The visitor\'s IP address is shortened and secrets in the address are blanked out. Headers and bodies are kept only for tunnels you start with :flag.', ['days' => (int) config('exposehub.log_retention_days'), 'flag' => '<code class="font-mono text-[0.95em] whitespace-nowrap">--log-bodies</code>']) !!}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-20 grid gap-x-16 gap-y-10 lg:grid-cols-2">
                <div>
                    <h2 class="font-display text-3xl leading-tight font-semibold">{{ __('Three commands') }}</h2>
                    <p class="mt-3 max-w-[52ch] text-zinc-600 dark:text-zinc-300">
                        {{ __('Install the agent on macOS or Linux, without administrator rights. Log in once with a token from the dashboard. After that, one command opens a tunnel to whatever port your service listens on.') }}
                    </p>
                    <p class="mt-3 max-w-[52ch] text-zinc-600 dark:text-zinc-300">
                        {{ __('On Windows, download') }}
                        <a href="{{ config('exposehub.agent_server') }}/agent/exposehub-windows-amd64.exe" class="font-medium text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white">exposehub.exe</a>
                        {{ __('in place of the first command.') }}
                    </p>
                </div>
                <div class="space-y-3">
                    <x-command :command="'curl -fsSL '.config('exposehub.agent_server').'/install.sh | sh'" />
                    <x-command command="exposehub login" />
                    <x-command command="exposehub http 8000" />
                </div>
            </section>

            <section class="mt-20 rounded-md bg-zinc-900 px-6 py-10 text-zinc-100 sm:px-10 dark:bg-zinc-950 dark:ring-1 dark:ring-zinc-700">
                <h2 class="max-w-[22ch] font-display text-3xl leading-tight font-semibold">{{ __('Run by your team, on your own server') }}</h2>
                <p class="mt-3 max-w-[60ch] text-zinc-300">
                    {{ __('The tunnel server and this dashboard are software you host yourself. Traffic goes from your visitors to your server to your machine, and through nobody else. Teams share addresses and domains, and each person has tokens of their own.') }}
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    @auth
                        <flux:button variant="primary" :href="route('dashboard')">{{ __('Open dashboard') }}</flux:button>
                    @else
                        @if (Route::has('register'))
                            <flux:button variant="primary" :href="route('register')">{{ __('Create account') }}</flux:button>
                        @endif
                        <a href="{{ route('login') }}" class="inline-flex h-10 items-center rounded-md px-4 text-sm font-medium text-zinc-100 underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-cable">{{ __('Log in') }}</a>
                    @endauth
                </div>
            </section>
        </main>

        <x-legal-links class="mx-auto max-w-7xl px-6 pb-10 lg:px-8" />

        {{-- Advances the film. Without it the first scene simply stays, and the list still reads on its own. --}}
        <script>
            (() => {
                const film = document.querySelector('[data-film]');
                if (! film) return;

                const buttons = [...film.querySelectorAll('[data-film-scene]')];
                const still = window.matchMedia('(prefers-reduced-motion: reduce)');
                const duration = 6000;
                let scene = 0;
                let timer = null;

                const show = (next) => {
                    scene = (next + buttons.length) % buttons.length;
                    film.dataset.scene = scene;
                    buttons.forEach((button, n) => n === scene ? button.setAttribute('aria-current', 'true') : button.removeAttribute('aria-current'));
                };
                const stop = () => { clearInterval(timer); timer = null; film.classList.add('film-paused'); };
                const play = () => {
                    if (still.matches || timer) return;
                    film.classList.remove('film-paused');
                    timer = setInterval(() => show(scene + 1), duration);
                };

                buttons.forEach((button, n) => button.addEventListener('click', () => { stop(); show(n); play(); }));
                // Hold the scene while someone is reading or tabbing through it.
                film.addEventListener('pointerenter', stop);
                film.addEventListener('pointerleave', play);
                film.addEventListener('focusin', stop);
                film.addEventListener('focusout', play);
                document.addEventListener('visibilitychange', () => document.hidden ? stop() : play());
                still.addEventListener('change', () => still.matches ? stop() : play());

                play();
            })();
        </script>

        @fluxScripts
    </body>
</html>
