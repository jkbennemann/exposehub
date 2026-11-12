# The agent

The agent is the `exposehub` command that runs next to your local service. The dashboard of an install has the same guide at `/docs`, with that install's own addresses filled in.

## Install

Install or update it on macOS and Linux with the script the tunnel server serves. It needs no administrator rights, installs to `~/.local/bin` (or `EXPOSEHUB_INSTALL_DIR`) and checks each download's SHA-256:

```sh
curl -fsSL https://connect.example.eu/install.sh | sh
```

On Windows, download `https://connect.example.eu/agent/exposehub-windows-amd64.exe` (or `-arm64.exe`). The server's `/agent/latest.json` lists every build with its checksum. The same binaries are attached to each [GitHub release](https://github.com/jkbennemann/exposehub/releases), with checksums and build provenance attestations (`gh attestation verify`).

## Commands

```
exposehub login
exposehub http TARGET... [--subdomain NAME] [--hostname HOST] [--auth USER:PASSWORD] [--allow ADDR|NETWORK] [--log-bodies | --no-log]
exposehub start [NAME...]
exposehub logout
exposehub update
exposehub server [URL]
exposehub version
```

The installer saves the server it was downloaded from, so no command needs `--server`. Otherwise the agent uses, in this order: `--server`, `EXPOSEHUB_SERVER`, the saved server, and a default built in with `-ldflags "-X main.defaultServer=..."` (empty in generic builds, so an agent never sends its token to a server it guessed). `exposehub server` shows which one is in use and where that comes from; `exposehub server URL` switches to another install and drops a token saved for the previous one.

`login` names the server, asks for the agent token and saves it in the user's config directory, readable by the owner only, so the token stays out of the shell history. `logout` forgets the token and keeps the server. `--token` or `EXPOSEHUB_TOKEN` override the saved token. The agent reconnects by itself and keeps its URL when the connection drops.

`--auth name:password` lets visitors in only with that name and password (HTTP Basic). The tunnel server checks it, so a request without the right credentials never reaches your machine. Repeat the flag for several people. Use it on a domain of your own in particular: such a domain is listed publicly as soon as its certificate is issued, and scanners find it within seconds.

## Several tunnels

One agent opens up to ten tunnels in one connection, with one inspector: `exposehub http shop=8000 api=3000 admin=8080` (each `NAME=` becomes the subdomain; shared flags apply to all). For tunnels you start often, an `exposehub.yml` in the project, found from any subdirectory, and `exposehub start [NAME...]`:

```yaml
tunnels:
  shop:
    target: 8000
    subdomain: shop
    auth: ["ada:${SHOP_PASSWORD}"]   # ${NAME} reads the environment; unset is an error
    allow: ["Office VPN", 203.0.113.0/24]
  hooks:
    target: 8000
    subdomain: shop-hooks
    log: bodies                      # requests (default), bodies or off
  docs:
    target: docs.test
    hostname: dev.example.com
    host_header: preserve
```

Unknown keys are errors, so a typo does not silently open a tunnel without its password. A file found in a parent directory is used only if no other account could have written it (on Linux and macOS: it and its directory belong to you or root, and neither is writable by everyone, except a sticky directory such as `/tmp`); otherwise `start` stops and names it, and `--file` loads it if you trust it.

## Limiting tunnels to networks

The dashboard's **Access** page limits who can reach a team's tunnels by IP address, for example to a company VPN. Networks are named lists of IPv4/IPv6 addresses and ranges; the team default applies to every address without a rule of its own; saved addresses are subdomains only that team can use, each with a rule (anyone, the team default, or listed networks); connected domains get a rule as well. Owners and admins manage networks and the default; members can only restrict further. `--allow` (an address, a range or a network's name) limits one run on top: a visitor has to pass both. The tunnel server checks the rules on every request, before the password, and answers 403 with the visitor's address; changes reach running tunnels with the next token sync. Behind a reverse proxy it takes the right-most `X-Forwarded-For` entry that is not a trusted proxy, so a visitor cannot choose their address by sending the header themselves.

## Inspector

While `exposehub http` runs, `http://127.0.0.1:4040` shows every request through its tunnels as it happens: method, path, status and timing, and for each one the full request and response with headers and bodies (JSON pretty-printed, forms as fields). A request can be sent to the local service again with **Replay**, or copied as a `curl` command. A strip at the top counts requests, the share answered with 4xx or 5xx, the median and 95th-percentile time, and the busiest paths, for whatever the filter shows.

Nothing leaves the machine. The agent keeps the last 1000 requests, at most 64 MiB, in memory and up to 512 KiB of each body; a request whose body was cut short cannot be replayed. If port 4040 is taken, for example by another agent, the next free port up to 4049 is used and printed. `--inspect 127.0.0.1:5000` picks another address and `--inspect off` turns it off. The page only answers on loopback names and refuses changes from other sites, so a web page open in the same browser cannot read or replay what it shows.

The inspector's data is also there as JSON, for scripts and for coding agents that want to see what a webhook sent:

| Request | Answer |
|---|---|
| `GET /api/tunnels` | the open tunnels: name, public URL, local target |
| `GET /api/requests` | a summary of each kept request: id, method, URL, status, duration, sizes |
| `GET /api/requests/{id}` | one request in full: headers and bodies of request and response (bodies base64-encoded) |
| `GET /api/events` | a Server-Sent Events stream with one `exchange` event per request as it happens |
| `POST /api/requests/{id}/replay` | sends the request to the local service again; needs the header `X-Exposehub-Inspector: 1` |
| `DELETE /api/requests` | forgets all kept requests; needs the same header |

```sh
curl -s http://127.0.0.1:4040/api/requests | jq '.[] | select(.status >= 500)'
```

## Request log

With a dashboard behind the server, each request through a tunnel also lands in the owner's **Request logs** page, kept for 7 days (`EXPOSEHUB_LOG_RETENTION_DAYS` in the dashboard). A row holds the time, address, method, path, status, duration, sizes and the visitor's network (an IPv4 address cut to its /24, IPv6 to its /48). Query values named like secrets (`token`, `key`, `sig`, `code`, …) are stored as `[redacted]`.

Headers and bodies are stored only for tunnels started with `--log-bodies`, up to 64 KiB each, with `Authorization`, cookies and headers named like tokens, secrets or passwords blanked out. `--no-log` keeps nothing of a tunnel's requests. Only the owner sees the log, plus colleagues in the same team they share it with on that page. Tunnels opened with a static token are not logged.

## Own domains

`--hostname` opens the tunnel on a domain your team has connected in the dashboard, in place of a subdomain of the tunnel domain.

## What the local service sees as Host

A loopback target such as `8000` sees the public hostname in the `Host` header. A named target such as `mysite.test` sees its own name, so local virtual hosts keep working; the public hostname is in `X-Forwarded-Host`. Override with `--host-header preserve` or `--host-header rewrite`.

## Dev servers

Vite and webpack dev servers turn away requests that name a host they do not know (`Blocked request. This host is not allowed`, `Invalid Host header`). The agent recognises that answer on a loopback target, sends the dev server its own name from then on, and says so once in the terminal. The first visitor already gets the page, and the hot-reload socket opens. With `--host-header preserve` the agent leaves the public name alone, and the dev server needs it in its own allowed hosts.

Next.js serves its pages under any name, but lets its dev endpoints (the hot-reload socket, the error overlay) be used only from origins it allows, which by default is its own address. A page opened through the tunnel is the dev server's own page, so once Next.js has refused it, the agent passes such requests on with the dev server's own address as their `Origin`, says so once in the terminal, and repeats the refused one: hot reload works from the first attempt, with nothing added to `next.config.js`. This is narrow on purpose. Only an `Origin` equal to the tunnel's own public address is replaced, and only on Next's dev endpoints: pages of other sites stay refused, which is what the check is for, and the application's own routes see the real origin, which Server Actions rely on. With `--host-header preserve` the agent changes nothing and prints the `allowedDevOrigins` line to add to `next.config.js` instead.

If nothing listens on the local port when the tunnel opens, the agent says so (`Waiting  nothing answers at http://localhost:3000 yet`) and keeps the tunnel open; it serves as soon as the service starts.

## Closing a tunnel from the dashboard

The dashboard's **Tunnels** page lists your agents that are connected right now, with their addresses, the token they use and since when. **Disconnect** stops one you left running on another machine: that agent exits with `the server closed the tunnel: it was disconnected in the dashboard` and does not reconnect, and its token keeps working. Team owners and admins see and disconnect the whole team's tunnels; the person concerned gets a mail. Starting a second agent with the same token on the same address also takes the address over from the first.
