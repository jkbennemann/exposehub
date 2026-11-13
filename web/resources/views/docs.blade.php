@php
    $agent = config('exposehub.agent_server');
    $agentHost = parse_url($agent, PHP_URL_HOST) ?: $agent;
    $domain = config('exposehub.tunnel_domain');
    $scheme = parse_url($agent, PHP_URL_SCHEME) ?: 'https';
    $shop = $scheme.'://shop.'.$domain;
    $days = (int) config('exposehub.log_retention_days');
    // Dashboard pages need an account; guests are sent to sign in first.
    $dash = fn (string $route) => auth()->check() ? route($route) : route('login');

    $sections = [
        'start' => __('Get started'),
        'share' => __('Share with colleagues'),
        'local' => __('Ports and local sites'),
        'several' => __('Several tunnels at once'),
        'webhooks' => __('Webhooks'),
        'inspector' => __('Inspector'),
        'logs' => __('Request logs'),
        'password' => __('Password protection'),
        'networks' => __('Limit to networks'),
        'domains' => __('Your own domain'),
        'agents' => __('Coding agents'),
        'teams' => __('Teams and tokens'),
        'reference' => __('Commands and flags'),
        'trouble' => __('When something is off'),
        'privacy' => __('What is stored'),
    ];

    $flags = [
        ['--subdomain NAME', __('Ask for a name under :domain, for example shop. Without it the server picks a random one.', ['domain' => $domain])],
        ['--hostname HOST', __('Use a domain your team connected in the dashboard instead of a subdomain.')],
        ['--auth NAME:PASSWORD', __('Let visitors in only with this name and password. Repeat it for several people.')],
        ['--allow VALUE', __('Let only visitors from this address, range (203.0.113.0/24) or named network of your team in. Repeat it for more. Adds to the rules in the dashboard.')],
        ['--log-bodies', __('Keep headers and bodies in the request log too, not only the summary.')],
        ['--no-log', __('Keep nothing of this tunnel\'s requests in the dashboard.')],
        ['--inspect ADDR', __('Where the inspector listens, 127.0.0.1:4040 by default, or off.')],
        ['--host-header MODE', __('preserve or rewrite the Host header your service sees. The default depends on the target.')],
        ['--domain DOMAIN', __('Which tunnel domain to use, when the server offers more than one.')],
        ['--server URL', __('Another tunnel server than the saved one. EXPOSEHUB_SERVER works too.')],
        ['--token TOKEN', __('Another token than the saved one. EXPOSEHUB_TOKEN works too.')],
        ['--verbose', __('Print connection details while it runs.')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', [
            'title' => __('Docs'),
            'description' => __('How to use exposehub: install the agent, open a tunnel, share it, receive webhooks, inspect requests, protect a tunnel and use your own domain.'),
        ])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5 lg:px-8">
            <x-app-logo href="{{ route('home') }}" />
            <nav class="flex items-center gap-2" aria-label="{{ __('Account') }}">
                <x-source-link class="max-sm:hidden" />
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

        <main class="mx-auto max-w-7xl px-6 pt-8 pb-24 lg:px-8 lg:pt-14">
            <div class="max-w-[46rem]">
                <h1 class="font-display text-[clamp(2.5rem,1.6rem+3.6vw,4.25rem)] leading-[1.02] font-semibold tracking-tight">{{ __('How to use exposehub') }}</h1>
                <p class="mt-5 text-xl text-zinc-600 dark:text-zinc-300">
                    {{ __('From the first command to a domain of your own. Everything here is written for this installation: agents connect to :agent and tunnels get addresses under :domain.', ['agent' => $agentHost, 'domain' => $domain]) }}
                </p>
            </div>

            <div class="mt-12 lg:grid lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-16">
                {{-- Contents: one cable down the page, a jack per section; the one you are reading is plugged in. --}}
                <nav class="docs-toc lg:sticky lg:top-8 lg:self-start" aria-label="{{ __('On this page') }}">
                    <details class="lg:hidden rounded-md border border-zinc-200 dark:border-zinc-700" data-toc-mobile>
                        <summary class="cursor-pointer px-4 py-3 font-medium">{{ __('On this page') }}</summary>
                        <ol class="space-y-1 px-4 pb-4">
                            @foreach ($sections as $id => $label)
                                <li><a href="#{{ $id }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">{{ $label }}</a></li>
                            @endforeach
                        </ol>
                    </details>
                    <div class="max-lg:hidden"><ol class="docs-cable">
                        @foreach ($sections as $id => $label)
                            <li><a href="#{{ $id }}" data-toc="{{ $id }}"><span class="docs-jack" aria-hidden="true"></span><span>{{ $label }}</span></a></li>
                        @endforeach
                    </ol></div>
                </nav>

                <article class="docs mt-10 max-w-[44rem] lg:mt-0" data-docs>
                    <section id="start">
                        <h2>{{ __('Get started') }}</h2>
                        <p>{{ __('Three commands, once per machine. After that, the last one is all you need.') }}</p>

                        <ol class="docs-steps">
                            <li>
                                <h3>{{ __('Install the agent') }}</h3>
                                <p>{{ __('On macOS and Linux, without administrator rights. It goes to ~/.local/bin and checks the download\'s checksum.') }}</p>
                                <x-command :command="'curl -fsSL '.$agent.'/install.sh | sh'" />
                                <p>{!! __('On Windows, download :amd or, on ARM, :arm, and use it from a terminal.', [
                                    'amd' => '<a href="'.e($agent).'/agent/exposehub-windows-amd64.exe">exposehub-windows-amd64.exe</a>',
                                    'arm' => '<a href="'.e($agent).'/agent/exposehub-windows-arm64.exe">exposehub-windows-arm64.exe</a>',
                                ]) !!}</p>
                            </li>
                            <li>
                                <h3>{{ __('Log in') }}</h3>
                                <p>{!! __('Create a token on the :page page of the dashboard, one per machine, then paste it when the agent asks. It is saved for your user only and stays out of your shell history.', ['page' => '<a href="'.e($dash('tokens.index')).'">'.e(__('Agent tokens')).'</a>']) !!}</p>
                                <x-command command="exposehub login" />
                            </li>
                            <li>
                                <h3>{{ __('Open a tunnel') }}</h3>
                                <p>{{ __('Give it the port your service listens on. The agent prints the public address and keeps it for as long as it runs.') }}</p>
                                <x-command command="exposehub http 8000" />
                                <pre class="docs-out"><span>Forwarding  </span><b>{{ $scheme }}://k3v9q2mw7x.{{ $domain }}</b><span>  -&gt;  http://localhost:8000</span>
<span>Inspector   http://127.0.0.1:4040</span></pre>
                            </li>
                        </ol>
                        <p>{{ __('Stop it with Ctrl+C. If the connection drops, the agent reconnects by itself and keeps the address.') }} {{ __('exposehub update installs the newest agent the server offers.') }}</p>
                    </section>

                    <section id="share">
                        <h2>{{ __('Share with colleagues') }}</h2>
                        <p>{{ __('Send the address to anyone. They see what runs on your machine right now: your branch, your data, your latest change, without a deploy. Reload, and they see the next change.') }}</p>
                        <p>{{ __('A random address changes every time you start the agent. Pick a name to keep the same link:') }}</p>
                        <x-command command="exposehub http 8000 --subdomain shop" />
                        <p>{!! __('That opens :url. The name belongs to your team while your agent is connected and for a short time after it disconnects (a minute, unless the server is set up otherwise), so a reconnect gets it back. A teammate cannot take it while you use it; once it is free, anyone can.', ['url' => '<code>'.e($shop).'</code>']) !!}</p>
                        <p class="docs-tip">{{ __('Testing on your phone works the same way: open the address on the phone, on any network.') }}</p>
                    </section>

                    <section id="local">
                        <h2>{{ __('Ports and local sites') }}</h2>
                        <p>{{ __('The target can be a port, a host and port, or a full URL:') }}</p>
                        <div class="space-y-3">
                            <x-command command="exposehub http 8000" />
                            <x-command command="exposehub http shop.test" />
                            <x-command command="exposehub http https://localhost:8443" />
                        </div>
                        <p>{!! __('A local site with a name of its own, such as :name, keeps seeing that name in the Host header, so virtual hosts in your local web server keep working. The public address arrives in :header. A plain port sees the public address as Host instead. Use :flag to change either.', [
                            'name' => '<code>shop.test</code>', 'header' => '<code>X-Forwarded-Host</code>', 'flag' => '<code>--host-header preserve|rewrite</code>',
                        ]) !!}</p>
                        <p>{!! __('Your service also gets the visitor\'s address in :xff and the scheme in :xfp, as behind any proxy.', ['xff' => '<code>X-Forwarded-For</code>', 'xfp' => '<code>X-Forwarded-Proto</code>']) !!}</p>
                        <h3>{{ __('Dev servers') }}</h3>
                        <p>{!! __('Vite and webpack dev servers turn away requests that name a host they do not know. The agent notices that answer, sends the dev server its own name from then on and says so in the terminal; pages and hot reload then work without any setting. With :flag the agent leaves the public name alone, and the dev server needs it in its own list of allowed hosts.', ['flag' => '<code>--host-header preserve</code>']) !!}</p>
                        <p>{!! __('Next.js serves its pages under any name, but lets hot reload through only from addresses it allows. The agent passes the hot-reload connection of a page opened through the tunnel on as coming from the dev server\'s own address, and says so in the terminal; pages of other sites stay shut out. With :flag it leaves that alone and prints the :setting line to add to :file instead.', ['flag' => '<code>--host-header preserve</code>', 'setting' => '<code>allowedDevOrigins</code>', 'file' => '<code>next.config.js</code>']) !!}</p>
                    </section>

                    <section id="several">
                        <h2>{{ __('Several tunnels at once') }}</h2>
                        <p>{{ __('One agent can open up to ten tunnels, for example a shop, its API and an admin, each on its own address. Name them on the command line:') }}</p>
                        <x-command command="exposehub http shop=8000 api=3000 admin=8080" />
                        <p>{{ __('Each name becomes a subdomain. Flags such as --auth or --allow apply to all of them, the inspector shows them together, and every request line says which tunnel it came through.') }}</p>
                        <p>{!! __('For tunnels you start every day, put them in an :file in your project and run :cmd. Each tunnel can have its own settings:', ['file' => '<code>exposehub.yml</code>', 'cmd' => '<code>exposehub start</code>']) !!}</p>
                        <pre class="docs-out"><b>tunnels:</b>
  <b>shop:</b>
    target: 8000
    subdomain: shop
    auth: ["ada:${SHOP_PASSWORD}"]
    allow: ["Office VPN"]
  <b>hooks:</b>
    target: 8000
    subdomain: shop-hooks
    log: bodies
  <b>docs:</b>
    target: docs.test
    hostname: dev.example.com</pre>
                        <p>{!! __(':cmd opens only those. The file is looked for in the current directory and its parents, so it works from anywhere in the project. :var reads an environment variable, so passwords stay out of the file; a variable that is not set stops the agent instead of opening a tunnel without a password.', ['cmd' => '<code>exposehub start shop docs</code>', 'var' => '<code>${NAME}</code>']) !!}</p>
                    </section>

                    <section id="webhooks">
                        <h2>{{ __('Webhooks') }}</h2>
                        <p>{{ __('Payment providers, git hosts and other services can call code running on your machine. Start a tunnel with a fixed name and enter its address in the provider\'s webhook settings:') }}</p>
                        <x-command command="exposehub http 8000 --subdomain shop-hooks" />
                        <pre class="docs-out"><span>{{ $scheme }}://shop-hooks.{{ $domain }}</span><b>/webhooks/payment</b></pre>
                        <p>{{ __('Set a breakpoint and debug the real request. When you need it again, replay it from the inspector instead of triggering a new payment.') }}</p>
                    </section>

                    <section id="inspector">
                        <h2>{{ __('Inspector') }}</h2>
                        <p>{!! __('While the agent runs, :url shows every request through your tunnels as it happens: method, path, status and timing, and for each one the full request and answer with headers and bodies.', ['url' => '<a href="http://127.0.0.1:4040">http://127.0.0.1:4040</a>']) !!}</p>
                        <ul>
                            <li>{{ __('Replay sends a request to your service again, as often as you like.') }}</li>
                            <li>{{ __('Copy as curl gives you the request as a command to tweak and run.') }}</li>
                            <li>{{ __('Filter by path, method or status, and see the error share, the median and the 95th-percentile time at a glance.') }}</li>
                        </ul>
                        <p>{{ __('Nothing of it leaves your machine. The agent keeps the last 1000 requests, up to 512 KiB of each body. If port 4040 is taken, it uses the next free port up to 4049 and prints which. --inspect off turns it off.') }}</p>
                    </section>

                    <section id="logs">
                        <h2>{{ __('Request logs') }}</h2>
                        <p>{!! __('The :page page in the dashboard keeps a summary of every request to your tunnels for :days days: time, address, method, path, status, duration, size and the visitor\'s network. It works after the agent has stopped, which the inspector does not.', ['page' => '<a href="'.e($dash('logs.index')).'">'.e(__('Request logs')).'</a>', 'days' => $days]) !!}</p>
                        <ul>
                            <li>{{ __('Only you see your logs. Share them with a colleague in your team on that page, and stop sharing whenever you like.') }}</li>
                            <li>{{ __('Values in the address that look like secrets (token, key, signature, password and the like) are stored as [redacted]. The visitor\'s IP address is shortened to its network.') }}</li>
                            <li>{!! __('Headers and bodies are kept only when you start the tunnel with :flag, up to 64 KiB each, with authorization headers and cookies blanked out.', ['flag' => '<code>--log-bodies</code>']) !!}</li>
                            <li>{!! __(':flag keeps nothing of a tunnel\'s requests.', ['flag' => '<code>--no-log</code>']) !!}</li>
                        </ul>
                    </section>

                    <section id="password">
                        <h2>{{ __('Password protection') }}</h2>
                        <p>{{ __('Let visitors in only with a name and password:') }}</p>
                        <x-command command="exposehub http 8000 --auth ada:s3cret --auth ben:0th3r" />
                        <p>{{ __('The tunnel server checks it, so a request without the right password never reaches your machine. The browser asks for it once. Your service does not see the Authorization header the password came in, so its own login keeps working.') }}</p>
                        <p class="docs-tip">{{ __('Use it whenever a tunnel is open for longer, and always on a domain of your own: such a domain is listed publicly as soon as its certificate is issued, and scanners ask it for files like .env within seconds.') }}</p>
                    </section>

                    <section id="networks">
                        <h2>{{ __('Limit to networks') }}</h2>
                        <p>{!! __('A tunnel can answer only requests from certain networks, such as your company VPN. Everyone else gets a page saying the tunnel is limited to certain networks, with their own address, and the request never reaches your machine. Set it up on the :page page of your team:', ['page' => '<a href="'.e($dash('access.index')).'">'.e(__('Access')).'</a>']) !!}</p>
                        <ul>
                            <li>{{ __('Networks are named lists of addresses and ranges, IPv4 or IPv6, for example the exit addresses of the VPN. On the VPN, "Add my address" fills in the address you connect from.') }}</li>
                            <li>{{ __('The team default applies to every address without a rule of its own, random ones included: anyone, or only listed networks.') }}</li>
                            <li>{{ __('Saved addresses are names your team keeps: nobody else can open a tunnel on them, and each has a rule. Keep the team on the VPN and open just the address you give a payment provider for webhooks.') }}</li>
                            <li>{{ __('Your own domains get a rule there too.') }}</li>
                        </ul>
                        <p>{{ __('Team owners and admins manage networks and the team default. Members save addresses and limit them further, but cannot open what the team keeps closed. Changes reach running tunnels within seconds.') }}</p>
                        <p>{{ __('On the command line, --allow limits a single run further. It never opens what the dashboard keeps closed: a visitor has to pass both.') }}</p>
                        <x-command command="exposehub http 8000 --allow 'Office VPN' --allow 2001:db8:42::/48" />
                    </section>

                    <section id="domains">
                        <h2>{{ __('Your own domain') }}</h2>
                        <p>{{ __('A team can open tunnels on a domain it controls, such as dev.example.com, with a certificate issued for it.') }}</p>
                        <ol class="docs-steps">
                            <li>
                                <h3>{{ __('Add it') }}</h3>
                                <p>{!! __('On the :page page of your team. Team owners and admins can add domains.', ['page' => '<a href="'.e($dash('domains.index')).'">'.e(__('Domains')).'</a>']) !!}</p>
                            </li>
                            <li>
                                <h3>{{ __('Create two DNS records') }}</h3>
                                <p>{{ __('The page shows them with your values: a TXT record that proves the domain is yours, and a record that points it at the tunnel server. Then press Check DNS.') }}</p>
                                <pre class="docs-out"><b>TXT  </b> <span>_exposehub.dev.example.com</span>  <span>exposehub-verify=…</span>
<b>CNAME</b> <span>dev.example.com</span>             <span>{{ config('exposehub.custom_domain_target') }}</span></pre>
                                <p>{{ __('For the top of a domain (example.com itself), DNS does not allow a CNAME; the page then asks for the address instead.') }}</p>
                            </li>
                            <li>
                                <h3>{{ __('Open a tunnel on it') }}</h3>
                                <x-command command="exposehub http 8000 --hostname dev.example.com --auth ada:s3cret" />
                                <p>{{ __('The certificate is issued within about a minute of connecting the domain. Until then, browsers show a certificate warning.') }}</p>
                            </li>
                        </ol>
                    </section>

                    <section id="agents">
                        <h2>{{ __('Coding agents') }}</h2>
                        <p>{!! __('Claude Code, Cursor, Codex and other coding agents can open tunnels for you. Log in yourself first with :login; the agent then uses the saved token and never needs to see it. Hand the agent one sentence:', ['login' => '<code>exposehub login</code>']) !!}</p>
                        <x-command :command="__('Read :url, install the exposehub agent if it is missing, then give my local site a public address.', ['url' => route('agents')])" />
                        <p>{!! __(':guide holds this server\'s own instructions for agents, in Markdown: how to install the agent, how to read its output, and the rules it has to follow (only open what you asked for, a password for anything private, never ask for your token).', ['guide' => '<a href="'.e(route('agents')).'">'.e(route('agents')).'</a>']) !!}</p>
                        <h3>{{ __('As an MCP server') }}</h3>
                        <p>{!! __(':command runs the agent as an MCP server. Its tools open a tunnel to a port, address or folder (with a subdomain, one of your team\'s own domains, a password or network limits), list and close the open ones, list which addresses your team may use, and show the latest requests a tunnel received. Tunnels stay open while the agent\'s client runs it. In Claude Code:', ['command' => '<code>exposehub mcp</code>']) !!}</p>
                        <x-command command="claude mcp add exposehub -- exposehub mcp" />
                        <p>{!! __('Clients configured with JSON (Cursor, Claude Desktop and others) take :json. Give the full path that :which prints if the client does not find the command. The agent serves folders only inside the project it runs in; a client that starts it elsewhere needs :root.', ['root' => '<code>--root /path/to/project</code>', 'json' => '<code>{"command": "exposehub", "args": ["mcp"]}</code>', 'which' => '<code>command -v exposehub</code>']) !!}</p>
                        <h3>{{ __('From scripts') }}</h3>
                        <p>{!! __(':flag prints one JSON object per line instead of text. The first line whose event is ready holds the addresses; an error line is the last before the agent stops.', ['flag' => '<code>exposehub http 3000 --json</code>']) !!}</p>
                        <h3>{{ __('A folder instead of a port') }}</h3>
                        <p>{!! __('A target such as :dist is served by the agent itself, read only: no folder listings, and nothing whose name starts with a dot, such as .env or .git. The agent refuses hidden folders such as ~/.ssh, your home folder and every folder above it, and the system\'s own folders. It is online while the agent runs.', ['dist' => '<code>./dist</code>']) !!}</p>
                        <x-command command="exposehub http ./dist --subdomain preview" />
                    </section>

                    <section id="teams">
                        <h2>{{ __('Teams and tokens') }}</h2>
                        <p>{{ __('Everyone has a personal team. Create more teams in the dashboard and invite colleagues: a team shares its addresses and domains, and each person has tokens of their own.') }}</p>
                        <ul>
                            <li>{{ __('Create one token per machine and name it after it. When a laptop is lost, revoke that one token; agents using it are disconnected within seconds.') }}</li>
                            <li>{{ __('Members see and revoke their own tokens. Team owners and admins see and revoke everyone\'s, and manage the team\'s domains.') }}</li>
                            <li>{{ __('When someone leaves a team, their tokens for it stop working and their request logs in it are deleted.') }}</li>
                            <li>{{ __('The Tunnels page lists your agents that are connected right now. Disconnect one you left running on another machine: that agent stops, and its token keeps working. Owners and admins see and disconnect the whole team\'s.') }}</li>
                        </ul>
                    </section>

                    <section id="reference">
                        <h2>{{ __('Commands and flags') }}</h2>
                        <dl class="docs-ref">
                            @foreach ([
                                ['exposehub login', __('Save your agent token. It asks for it, so it never lands in your shell history.')],
                                ['exposehub http TARGET...', __('Open a tunnel to each target: a port, host[:port] or URL. NAME=TARGET asks for that subdomain.')],
                                ['exposehub start [NAME...]', __('Open the tunnels of exposehub.yml, all of them or the ones named.')],
                                ['exposehub update', __('Install the agent version the server offers.')],
                                ['exposehub logout', __('Forget the saved token. The token stays valid until you revoke it in the dashboard.')],
                                ['exposehub server [URL]', __('Show which tunnel server is used and why, or switch to another.')],
                                ['exposehub version', __('Print the agent\'s version.')],
                            ] as [$cmd, $text])
                                <dt><code>{{ $cmd }}</code></dt><dd>{{ $text }}</dd>
                            @endforeach
                        </dl>
                        <h3>{{ __('Flags for exposehub http') }}</h3>
                        <dl class="docs-ref">
                            @foreach ($flags as [$flag, $text])
                                <dt><code>{{ $flag }}</code></dt><dd>{{ $text }}</dd>
                            @endforeach
                        </dl>
                    </section>

                    <section id="trouble">
                        <h2>{{ __('When something is off') }}</h2>
                        <dl class="docs-faq">
                            <dt>{{ __('Visitors see "This tunnel is reconnecting"') }}</dt>
                            <dd>{{ __('Your agent lost its connection and is coming back; the address is kept for it. The page retries every 5 seconds by itself.') }}</dd>
                            <dt>{{ __('Visitors see "Nothing is connected here"') }}</dt>
                            <dd>{{ __('No agent is serving that address. Start it again; the page shows the command with the right name.') }}</dd>
                            <dt>{{ __('Visitors see "The site behind this tunnel is not answering"') }}</dt>
                            <dd>{{ __('The tunnel works, but your service does not: it is stopped, still starting, or listens on another port. The page names the address the agent tried and retries every 5 seconds.') }}</dd>
                            <dt>{{ __('Visitors see "This tunnel is limited to certain networks"') }}</dt>
                            <dd>{{ __('They are not on a network the tunnel allows. The page shows their address: connect the VPN, or add the address to a network on the Access page.') }}</dd>
                            <dt>{{ __('The agent prints "Waiting" and that nothing answers at your local address') }}</dt>
                            <dd>{{ __('Nothing listens on the local port yet. The tunnel is open all the same and starts serving as soon as your service does.') }}</dd>
                            <dt>{{ __('"the server closed the tunnel: it was disconnected in the dashboard"') }}</dt>
                            <dd>{{ __('You or a team owner closed it on the Tunnels page. The token is still valid: start the agent again.') }}</dd>
                            <dt>{{ __('"is saved by another team"') }}</dt>
                            <dd>{{ __('Another team keeps that name for itself. Pick another one.') }}</dd>
                            <dt>{{ __('"your team has no network called …"') }}</dt>
                            <dd>{{ __('The name after --allow does not match a network on the Access page. Names are not case sensitive, but spelling counts; put names with spaces in quotes.') }}</dd>
                            <dt>{{ __('"the server rejected the token"') }}</dt>
                            <dd>{!! __('The token was revoked, the team was deleted or you left it. Create a new token and run :cmd again.', ['cmd' => '<code>exposehub login</code>']) !!}</dd>
                            <dt>{{ __('"is in use by another agent of your team"') }}</dt>
                            <dd>{{ __('A colleague is serving that name right now. Pick another one, or ask them to stop.') }}</dd>
                            <dt>{{ __('"is in use by another account"') }}</dt>
                            <dd>{{ __('Another team uses that name at the moment. Pick another one.') }}</dd>
                            <dt>{{ __('The browser warns about the certificate on my own domain') }}</dt>
                            <dd>{{ __('The certificate is issued within about a minute of connecting the domain. If you clicked through the warning before that, close the tab or restart the browser: some browsers keep showing the site as not secure for the rest of the session.') }}</dd>
                        </dl>
                    </section>

                    <section id="privacy">
                        <h2>{{ __('What is stored') }}</h2>
                        <p>{{ __('Requests to your tunnels go to your machine and back; their content is not stored unless you start a tunnel with --log-bodies. The request log keeps summaries for :days days, visible only to you and those you share it with. The inspector keeps everything on your machine.', ['days' => $days]) }}</p>
                        @if (\App\Support\LegalPage::Privacy->exists())
                            <p>{!! __('The :notice explains in full what is processed and why.', ['notice' => '<a href="'.e(route('legal.privacy')).'">'.e(__('privacy notice')).'</a>']) !!}</p>
                        @endif
                    </section>
                </article>
            </div>
        </main>

        <x-legal-links class="mx-auto max-w-7xl px-6 pb-10 lg:px-8" />

        {{-- Plugs in the jack of the section being read. Without it the contents list still works. --}}
        <script>
            (() => {
                const links = new Map([...document.querySelectorAll('[data-toc]')].map((a) => [a.dataset.toc, a]));
                const sections = [...document.querySelectorAll('[data-docs] > section')];
                if (! links.size || ! sections.length || ! ('IntersectionObserver' in window)) return;
                const visible = new Set();
                const mark = () => {
                    // Above the first section, the first one counts as being read.
                    const current = sections.find((s) => visible.has(s.id)) ?? (scrollY < sections[0].offsetTop ? sections[0] : null);
                    if (! current) return;
                    links.forEach((a, id) => id === current.id ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current'));
                };
                mark();
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((e) => e.isIntersecting ? visible.add(e.target.id) : visible.delete(e.target.id));
                    mark();
                }, { rootMargin: '-15% 0px -70% 0px' });
                sections.forEach((s) => io.observe(s));
                document.querySelector('[data-toc-mobile]')?.addEventListener('click', (e) => {
                    if (e.target.closest('a')) e.currentTarget.removeAttribute('open');
                });
            })();
        </script>
        @fluxScripts
    </body>
</html>
