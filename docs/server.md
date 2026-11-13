# Tunnel server configuration

The Compose install and the Helm chart set these for you; [compose.md](self-hosting/compose.md) and [kubernetes.md](self-hosting/kubernetes.md) say where. This page is the full list, for running `exposehub-server` yourself or changing a default.

Nothing about an install is compiled in. The server reads these environment variables:

| Variable | Meaning | Default |
|---|---|---|
| `EXPOSEHUB_LISTEN` | Address for visitors and agents | `:8080` |
| `EXPOSEHUB_AGENT_HOST` | Hostname agents connect to, e.g. `connect.example.eu` | required |
| `EXPOSEHUB_TUNNEL_DOMAINS` | Comma-separated domains that tunnels get subdomains of; the first is the default | required |
| `EXPOSEHUB_STATIC_TOKENS` | `account:token` pairs, comma-separated | required without a dashboard |
| `EXPOSEHUB_TLS_CERT_FILE`, `EXPOSEHUB_TLS_KEY_FILE` | Serve TLS with your own (wildcard) certificate | plain HTTP |
| `EXPOSEHUB_PUBLIC_SCHEME`, `EXPOSEHUB_PUBLIC_PORT` | How visitors reach the server, used in tunnel URLs; set these behind another proxy | from listen address and TLS |
| `EXPOSEHUB_TRUSTED_PROXIES` | Comma-separated addresses or CIDRs of reverse proxies in front of the server; `X-Forwarded-For` and `X-Forwarded-Proto` are believed only from these | none |
| `EXPOSEHUB_AGENT_DOWNLOADS` | Directory with agent builds and their `latest.json`, served at `/install.sh` and `/agent/` on the agent host; the Docker image sets it | none |
| `EXPOSEHUB_CUSTOM_DOMAIN_HEADERS` | `Name=Value` pairs, comma-separated, added to every response on a domain a team has connected | none |
| `EXPOSEHUB_GRACE_PERIOD` | How long a subdomain stays reserved after its agent disconnects | `1m` |
| `EXPOSEHUB_DEMO_MAX_TUNNELS` | How many tunnels of the dashboard's demo account may be open at once; `0` opens none. See [Demo tunnels](#demo-tunnels) | `20` |
| `EXPOSEHUB_DEMO_REQUESTS_PER_MINUTE` | How many requests each demo tunnel answers a minute; more get `429` | `60` |
| `EXPOSEHUB_DEMO_MEGABYTES_PER_MINUTE` | How much data each demo tunnel carries a minute, both directions together; more waits | `5` |
| `EXPOSEHUB_LOG_LEVEL` | `debug`, `info`, `warn` or `error` | `info` |
| `EXPOSEHUB_LOG_FORMAT` | `text` writes `key=value` lines, `json` one JSON object per line, for log pipelines | `text` |
| `EXPOSEHUB_CONTROL_PLANE_URL`, `EXPOSEHUB_NODE_SECRET` | The dashboard to take agent tokens from, and the secret shared with it; works alongside `EXPOSEHUB_STATIC_TOKENS` | none |
| `EXPOSEHUB_CONTROL_PLANE_MAX_STALE` | How long the server keeps using the dashboard's token list when it cannot refresh it. After that it refuses dashboard tokens and closes their tunnels until the dashboard answers again, so a revoked token cannot keep working; the agents retry by themselves. Static tokens are not affected. It logs an error and the status report says so. `0` keeps the last list forever | `5m` |
| `EXPOSEHUB_REQUEST_LOGS` | `off` stops sending request summaries to the dashboard's request log | on with a dashboard |
| `EXPOSEHUB_INTERNAL_LISTEN` | Second plain-HTTP listener for the private network only, e.g. `:9090`. Serves `/healthz` and `/tls/ask?domain=`, the `ask` endpoint for Caddy's on-demand TLS: yes for the agent host and domains a team has verified, no for anything else | off |
| `EXPOSEHUB_TLS_ASK_TUNNEL_NAMES` | `true` lets `/tls/ask` also approve names a live tunnel holds, for a proxy that gets one certificate per tunnel instead of a wildcard | `false` |
| `EXPOSEHUB_TLS_ASK_NAME_LIMIT`, `EXPOSEHUB_TLS_ASK_NAME_WINDOW` | How many different tunnel names of one team (or static-token account) `/tls/ask` approves within the window. Each one costs a certificate from the install's Let's Encrypt allowance (50 new certificates per registered domain per week), so one token cannot use it all up. It must stay below `EXPOSEHUB_TLS_ASK_TOTAL_LIMIT`, so that no single team can take the whole install's budget. Names approved before within the window, the agent host and verified domains do not count | `10`, `168h` |
| `EXPOSEHUB_TLS_ASK_TOTAL_LIMIT` | How many different tunnel names `/tls/ask` approves within the same window, across all teams and all tunnel domains (several tunnel domains can share one registered domain, which is what Let's Encrypt counts), since many teams together could otherwise use up Let's Encrypt's allowance. The default leaves room below the 50 for the agent host and teams' domains. Once it is used up, new names get no certificate until the window has room again, and the server logs a warning once per window; names approved before keep theirs. `0` means no install-wide limit, only for a CA or a Let's Encrypt rate-limit exemption that allows more | `40` |

`exposehub-server healthcheck [URL]` exits 0 when the server answers and 1 otherwise; the image has no shell or curl, so container health checks run this.

## DNS

Point the agent host and a wildcard record for each tunnel domain (`*.example.eu`) at the server.

## Teams sharing one tunnel domain

All teams' tunnels share the tunnel domain. The server removes a `Domain` attribute that names the tunnel domain, or a parent of it, from cookies a tunnel sets, so one tunnel cannot set cookies for the others through its responses. Browsers still treat all tunnels under one domain as the same site, and a page's scripts can still set such cookies with `document.cookie`. If teams that do not trust each other share an install, add the tunnel domain to the private section of the [Public Suffix List](https://publicsuffix.org/) or give each team a domain of its own.

## Demo tunnels

The dashboard can offer a demo account that visitors use without signing up. Anyone can then open a tunnel under your tunnel domain, and nobody answers for what is behind it. The dashboard marks the demo account's tokens for the server, and the server holds their tunnels to these rules:

- The address is always a random one. `--subdomain` and `--hostname` are refused, so nobody can open `paypal.example.eu`. An agent that reconnects gets the random address it had.
- A token has one tunnel. A second agent with the same token takes over, and the first one stops.
- At most `EXPOSEHUB_DEMO_MAX_TUNNELS` demo tunnels are open at once; the next agent is told that the demo is full.
- Each demo tunnel answers `EXPOSEHUB_DEMO_REQUESTS_PER_MINUTE` requests a minute. It can use a minute's worth at once, so a page loads with its assets, and gets the rest back evenly. Requests beyond that get `429` with `Retry-After`, and show up in the request log like other refusals.
- Each demo tunnel carries `EXPOSEHUB_DEMO_MEGABYTES_PER_MINUTE` megabytes a minute, counting request bodies, responses and both directions of a WebSocket. It can use a minute's worth at once; after that, data passes only as fast as the allowance fills up again, about 85 KiB a second at the default. Without this, one request could carry any amount, and the request limit would not bound what a demo tunnel relays.
- Both allowances belong to the token, not to the agent's connection: an agent that reconnects, or a second one that takes the address over, finds them as they were left.
- `--log-bodies` is ignored: the request log keeps summaries, never headers or bodies.
- A browser that opens a page of a demo tunnel first gets a notice: this is a demo tunnel on your agent host, opened by someone without an account, and passwords or payment details do not belong there unless the visitor knows who runs it. Its button sets a cookie for that one address, good for 30 minutes, and leads on to the page. Webhook senders, `curl`, scripts, WebSockets and a page's own requests pass without the notice. A browser is recognised by `Sec-Fetch-Mode: navigate`; one that does not send that header, as all do over plain HTTP, by asking for HTML, with any method, so a form another site posts to the tunnel gets the notice too. Answers of a demo tunnel carry `Vary: Sec-Fetch-Mode`, so a copy a page fetched in the background is not what the browser shows when the address is opened.

The notice is for the people a demo tunnel is shown to, so the person who opened the tunnel must not be able to skip it for them. The button's address works only with a signature that came with the notice. That signature and the cookie are bound to the tunnel and to the visitor's network (the first three bytes of an IPv4 address, the first six of an IPv6 address), and the server keeps the cookie to itself: the service behind the tunnel neither receives it nor can set it. This is as good as the server's knowledge of visitor addresses. Behind a reverse proxy that is not listed in `EXPOSEHUB_TRUSTED_PROXIES`, every visitor has the proxy's address and one visitor's signature works for all of them. A visitor whose address changes network between two requests sees the notice again.

On a demo tunnel the path `/.exposehub/demo-continue` and the cookie `__exposehub_demo` belong to the server. The key that signs is made when the server starts, so browsers see the notice once more after a restart.

Two things the server does not do for you. Demo tunnels need a wildcard certificate: with one certificate per tunnel name (`EXPOSEHUB_TLS_ASK_TUNNEL_NAMES`), all demo tunnels share the demo team's allowance of `EXPOSEHUB_TLS_ASK_NAME_LIMIT` new names a week. And the limits bound how much an anonymous visitor can do with your domain, they do not make it safe: a demo tunnel can still serve any page to anyone who clicks through the notice.

Tokens from `EXPOSEHUB_STATIC_TOKENS` are never demo tokens.

## Names that change hands

A tunnel name can change hands. A name nobody saved is free for any team one grace period after its agent disconnects, and a domain one team removes can be connected by another. Browsers that visited the name before keep what its earlier holder left there: a service worker, long-cached scripts, stored data. Such a script would then run on the new holder's pages. The server does not clear this. For anything people come back to, save the address on the dashboard's **Access** page so the name stays your team's, or use a domain of your own, and do not reuse a name another team has used for something that matters.
