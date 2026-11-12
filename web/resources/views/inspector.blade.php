@php
    $public = preg_replace('#^(https?://)your-name\.#', '$1shop.', config('exposehub.example_address'));
    $publicHost = preg_replace('#^https?://#', '', $public);

    // What the preview shows: a morning of webhook work, newest first.
    $rows = [
        ['09:41:07', 'POST', '/webhooks/payment', 202, '34 ms', true],
        ['09:41:02', 'POST', '/webhooks/payment', 400, '8 ms', false],
        ['09:40:51', 'GET', '/checkout', 500, '241 ms', false],
        ['09:40:49', 'GET', '/api/orders?page=2', 200, '12 ms', false],
        ['09:40:48', 'GET', '/api/orders', 200, '15 ms', false],
        ['09:40:46', 'GET', '/', 200, '22 ms', false],
    ];

    $uses = [
        [__('Debug webhooks'), __('See the exact payload and signature headers a provider sent. Fix your code, then press Replay: the same request reaches your service again, and the provider never has to resend it.')],
        [__('Find what failed'), __('Switch off 2xx to see only what went wrong. The numbers at the top count the 4xx and 5xx answers among the requests shown.')],
        [__('Spot slow endpoints'), __('The median and 95th-percentile times and the busiest paths follow the filter. Filter for one path to measure just that.')],
        [__('Hand a request on'), __('Copy as curl gives a command that sends the same request, with its headers and body, from any terminal.')],
    ];
@endphp
<x-layouts::app :title="__('Inspector')">
    <div>
        <h1 class="page-title">{{ __('See every request as it arrives') }}</h1>
        <p class="mt-3 max-w-[60ch] text-zinc-600 dark:text-zinc-300">
            {{ __('While the agent runs, it shows each request through your tunnels on a page on your own machine: what was sent, what your service answered, and how long it took. What it shows stays on your machine.') }}
        </p>
        <p class="mt-3 max-w-[60ch] text-zinc-600 dark:text-zinc-300">
            {{ __("For requests from earlier, or from a colleague's tunnel they shared with you, use the") }}
            <a href="{{ route('logs.index') }}" wire:navigate class="font-medium text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white">{{ __('request logs') }}</a>{{ __(': they keep a summary of each request for :days days.', ['days' => config('exposehub.log_retention_days')]) }}
        </p>

        {{-- Opening it is a sequence: start the agent, then open the page. --}}
        <ol class="mt-10 grid max-w-5xl gap-x-12 gap-y-8 lg:grid-cols-2">
            <li class="grid gap-x-6 gap-y-2 sm:grid-cols-[2.5rem_1fr]">
                <span class="font-display text-3xl leading-none font-semibold text-zinc-400 dark:text-zinc-500" aria-hidden="true">1</span>
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-semibold">{{ __('Start a tunnel') }}</h2>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('The inspector starts with it. The agent prints its address next to the word Inspector.') }}</p>
                    <x-command class="mt-3" command="exposehub http 8000" />
                </div>
            </li>
            <li class="grid gap-x-6 gap-y-2 sm:grid-cols-[2.5rem_1fr]">
                <span class="font-display text-3xl leading-none font-semibold text-zinc-400 dark:text-zinc-500" aria-hidden="true">2</span>
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-semibold">{{ __('Open it in your browser') }}</h2>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('If another program already uses port 4040, the agent takes the next free one, up to 4049, and prints that instead.') }}</p>
                    <flux:button class="mt-3" variant="primary" href="http://127.0.0.1:4040" target="_blank" rel="noopener" data-test="open-inspector">
                        {{ __('Open 127.0.0.1:4040') }}
                    </flux:button>
                </div>
            </li>
        </ol>

        {{-- A faithful preview of the page, with example data. The list below says in words what it shows. --}}
        <figure class="mt-14">
            <div class="mock" aria-hidden="true">
                <div class="mock-bar">
                    <span class="mock-brand"><i></i>exposehub <em>inspector</em></span>
                    <span class="mock-tunnel"><span>{{ $public }}</span><i></i><span class="mock-muted">localhost:8000</span></span>
                    <span class="mock-live">live</span>
                </div>
                <div class="mock-stats">
                    <span><b>6</b>requests</span>
                    <span class="mock-bad"><b>33%</b>answered 4xx or 5xx</span>
                    <span><b>19 ms</b>median time</span>
                    <span><b>241 ms</b>95th percentile</span>
                </div>
                <div class="mock-split">
                    <ol class="mock-rows">
                        @foreach ($rows as $i => [$time, $method, $path, $status, $took, $selected])
                            <li @class(['mock-row', 'is-selected' => $selected]) style="--i: {{ count($rows) - 1 - $i }}">
                                <span class="mock-faint">{{ $time }}</span>
                                <span class="mock-muted">{{ $method }}</span>
                                <span class="mock-path">{{ $path }}</span>
                                <span @class(['mock-s2' => $status < 300, 'mock-s4' => $status >= 400 && $status < 500, 'mock-s5' => $status >= 500])>{{ $status }}</span>
                                <span class="mock-muted">{{ $took }}</span>
                            </li>
                        @endforeach
                    </ol>
                    <div class="mock-detail">
                        <div class="mock-headline"><span class="mock-muted">POST</span> /webhooks/payment <span class="mock-s2">202</span></div>
                        <div class="mock-actions"><span class="mock-primary">Replay</span><span>Copy as curl</span></div>
                        <dl class="mock-headers">
                            <dt>Content-Type</dt><dd>application/json</dd>
                            <dt>Stripe-Signature</dt><dd>t=1794467562,v1=5257a869e7ec…</dd>
                            <dt>X-Forwarded-Host</dt><dd>{{ $publicHost }}</dd>
                        </dl>
<pre class="mock-body"><span class="p">{</span>
  <span class="k">"type"</span><span class="p">:</span> <span class="s">"payment_intent.succeeded"</span><span class="p">,</span>
  <span class="k">"amount"</span><span class="p">:</span> <span class="n">12990</span><span class="p">,</span>
  <span class="k">"currency"</span><span class="p">:</span> <span class="s">"eur"</span><span class="p">,</span>
  <span class="k">"livemode"</span><span class="p">:</span> <span class="l">false</span>
<span class="p">}</span></pre>
                    </div>
                </div>
            </div>
            <figcaption class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('The inspector with example requests. The selected one is a payment webhook that failed once, was fixed in the code, and was replayed.') }}
            </figcaption>
        </figure>

        <section class="mt-16">
            <h2 class="font-display text-3xl leading-tight font-semibold">{{ __('What it is good for') }}</h2>
            <div class="mt-6 grid gap-x-10 gap-y-8 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($uses as [$title, $text])
                    <div class="border-t-[3px] border-zinc-200 pt-4 dark:border-zinc-700">
                        <h3 class="font-display text-xl font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-zinc-600 dark:text-zinc-300">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-16 grid gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div>
                <h2 class="font-display text-3xl leading-tight font-semibold">{{ __('What stays on your machine') }}</h2>
                <div class="mt-4 max-w-[62ch] space-y-3 text-zinc-600 dark:text-zinc-300">
                    <p>{{ __('The agent keeps the last 1000 requests in its memory, with up to 512 KiB of each body. They are gone when the agent stops. Authorization headers and cookies are shown as they arrived, because the page is only for you.') }}</p>
                    <p>{{ __('The page answers only on your own machine. Other websites open in your browser can neither read it nor send requests from it.') }}</p>
                    <p>{{ __('A request whose body was larger than what is kept cannot be replayed exactly, and neither can a WebSocket connection; both are still shown.') }}</p>
                </div>
            </div>
            <div class="space-y-8">
                <div>
                    <h3 class="font-display text-xl font-semibold">{{ __('Keys') }}</h3>
                    <dl class="mt-3 grid grid-cols-[6rem_minmax(0,1fr)] gap-x-4 gap-y-2 text-zinc-600 dark:text-zinc-300">
                        <dt><kbd class="kbd">↑</kbd> <kbd class="kbd">↓</kbd></dt><dd>{{ __('Move through the list (j and k work too)') }}</dd>
                        <dt><kbd class="kbd">/</kbd></dt><dd>{{ __('Filter by path, method or status') }}</dd>
                        <dt><kbd class="kbd">Esc</kbd></dt><dd>{{ __('Clear the filter') }}</dd>
                    </dl>
                </div>
                <div>
                    <h3 class="font-display text-xl font-semibold">{{ __('Options') }}</h3>
                    <dl class="mt-3 space-y-3 text-zinc-600 dark:text-zinc-300">
                        <div><dt class="font-mono text-[0.9375rem] text-zinc-900 dark:text-white">--inspect 127.0.0.1:5000</dt><dd>{{ __('Use another address on your machine.') }}</dd></div>
                        <div><dt class="font-mono text-[0.9375rem] text-zinc-900 dark:text-white">--inspect off</dt><dd>{{ __('Run the tunnel without the inspector.') }}</dd></div>
                    </dl>
                </div>
            </div>
        </section>
    </div>
</x-layouts::app>
