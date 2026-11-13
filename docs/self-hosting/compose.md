# Self-hosting with Docker Compose

One Linux server runs everything: Caddy for TLS, the tunnel server, the dashboard with its scheduled jobs, and Postgres. An install script asks a few questions, writes the configuration and checks your DNS. Upgrading means checking out a newer release and running the script again.

For Kubernetes, see [kubernetes.md](kubernetes.md).

## What you need

- A Linux server with a public IPv4 address, amd64 or arm64, and ports 80 and 443 reachable from the internet. Nothing else may listen on them. 1 vCPU and 1 GB of memory are enough for a team: the whole stack uses about 220 MiB at rest (measured on Ubuntu 26.04).
- Docker Engine 24 or newer with the Compose plugin 2.24 or newer ([install guide](https://docs.docker.com/engine/install/)).
- Two names in DNS, described next.
- For the recommended certificate mode, an API token for the DNS provider of the tunnel domain.
- An SMTP account for the mails that confirm addresses. Without one, mails go to the log and you copy links from there.

## DNS

Two records, both pointing at the server:

| Record | Type | Value | For |
|---|---|---|---|
| `exposehub.example.com` | A | server's IPv4 | the dashboard |
| `*.example-tunnels.net` | A | server's IPv4 | every tunnel, and `connect.example-tunnels.net` where agents connect |

No AAAA records: the install accepts IPv4 only (see [IPv6](#ipv6)). Clients with IPv6 fall back to IPv4 when a name has none.

If the DNS provider proxies traffic (Cloudflare's orange cloud), turn that off for both: tunnels need long-lived connections and uploads of any size, and Let's Encrypt has to reach the server itself.

Put the tunnels on a registrable domain of their own if you can, not under the dashboard's domain. A tunnel serves whatever runs on someone's laptop. On a subdomain of the dashboard's domain, such a page counts as the same site as the dashboard: it can set cookies the dashboard receives, and browsers treat requests from it as same-site. A separate domain keeps the two apart. A subdomain (`*.tunnels.example.com` next to `exposehub.example.com`) still works if you accept that.

The same holds between tunnels: every team's tunnels share the tunnel domain, so browsers treat them all as one site. The tunnel server strips cookies that a tunnel's responses set for the whole tunnel domain, but a page's scripts can still set them. If teams that do not trust each other share the install, add the tunnel domain to the private section of the [Public Suffix List](https://publicsuffix.org/), or have them use domains of their own, connected in the dashboard.

Tunnel names can also change hands: a name nobody saved is free for any team a minute after its agent disconnects (`EXPOSEHUB_GRACE_PERIOD`), and a removed domain can be connected by another team. Browsers keep what the earlier holder left on that name, such as a service worker or long-cached scripts, and the server does not clear it. Tell teams to save the addresses they rely on (the dashboard's Access page) or to use their own domain.

## Install

```sh
git clone --branch v0.3.1 https://github.com/jkbennemann/exposehub.git
cd exposehub/deploy/compose
./install.sh
docker compose up -d --wait
```

Use the newest tag from the [releases page](https://github.com/jkbennemann/exposehub/releases). A checkout of a release tag pins the images to that release; the `main` branch may refer to images that do not exist yet.

`install.sh`:

1. Checks Docker, Compose and the architecture.
2. Asks for the dashboard name, the tunnel domain, your email address, who may register (email domains; it suggests yours), the certificate mode and, optionally, an SMTP server.
3. Writes `.env` from `.env.example` and generates the secrets (`APP_KEY`, the secret the tunnel server and the dashboard share, the database password). DNS credentials go to a separate `caddy.env`, which only Caddy reads. Both files are readable by their owner only.
4. Checks that ports 80 and 443 are free and that the DNS records point at this server. It reads the server's address from the network configuration and asks no outside service. Behind NAT it can only check that the names exist; set `EXPOSEHUB_PUBLIC_IP` to compare against the public address.
5. Pulls the images.

It does not start anything. `docker compose up -d --wait` does, and runs the database migrations first.

Then open the dashboard, register with the address you gave as platform owner, confirm it, and you are the admin. Create an agent token under **Tokens** and, on your machine:

```sh
curl -fsSL https://connect.example-tunnels.net/install.sh | sh
exposehub login
exposehub http 8000
```

For an automated install (cloud-init, Ansible), pass `--non-interactive` and set the answers as environment variables; the header of `install.sh` lists them:

```sh
EXPOSEHUB_DASHBOARD_HOST=exposehub.example.com \
EXPOSEHUB_TUNNEL_DOMAIN=example-tunnels.net \
EXPOSEHUB_ADMIN_EMAILS=you@example.com \
EXPOSEHUB_ALLOWED_EMAIL_DOMAINS=example.com \
EXPOSEHUB_TLS_MODE=dns EXPOSEHUB_DNS_PROVIDER=hetzner HETZNER_API_TOKEN=... \
MAIL_HOST=smtp.example.com MAIL_USERNAME=... MAIL_PASSWORD=... \
./install.sh --non-interactive
```

## Certificates

Caddy obtains and renews every certificate from Let's Encrypt. `EXPOSEHUB_TLS_MODE` picks how the tunnel domain gets them.

**`dns` (recommended).** One wildcard certificate for `*.example-tunnels.net`, over the ACME DNS challenge. Caddy writes a TXT record through your DNS provider's API, so it needs a token. Supported: Cloudflare, Hetzner, Route 53, DigitalOcean, OVH, Gandi, Porkbun, deSEC, IONOS and netcup. What each token needs is in the comment of its file in [`deploy/compose/caddy/dns/`](../../deploy/compose/caddy/dns/). New tunnel names work immediately and do not show up anywhere.

**`on-demand`.** No DNS access needed. Caddy gets a certificate for each tunnel name the first time someone visits it, after the tunnel server confirms the name has a live tunnel. The costs:

- The first request to a new name waits a few seconds for the certificate.
- Let's Encrypt issues at most 50 new certificates per registered domain per week. Fixed names are fine; many random names a week are not.
- So that this allowance is not used up, the tunnel server approves at most 40 new names under the tunnel domain per week in total, and at most 10 per team (or static-token account), so that no single team can take them all. Names approved before within that week, `connect.` and teams' verified domains do not count. A name past either budget gets no certificate, and visitors to it see a certificate error until the week has room again; the server log says which budget ran out. `EXPOSEHUB_TLS_ASK_TOTAL_LIMIT`, `EXPOSEHUB_TLS_ASK_NAME_LIMIT` and `EXPOSEHUB_TLS_ASK_NAME_WINDOW` (default `168h`) change them; the per-team limit must stay below the total. `EXPOSEHUB_TLS_ASK_TOTAL_LIMIT=0` removes the total, which only makes sense if Let's Encrypt has granted you a higher limit.
- The total is shared. A handful of teams, or one person who owns several teams, can use up the 40 for the week, and then nobody gets a certificate for a new name until the week has passed; names that already have one keep working. That is a limit of this mode, not something a setting removes. If people you do not know can register, use the `dns` mode: one wildcard certificate covers every name and no budget applies.
- Every name is published in the Certificate Transparency logs, where anyone can read it within minutes.

**`internal`.** Caddy signs everything with its own local authority. For trying the stack on a laptop; see [Trying it locally](#trying-it-locally).

The dashboard gets its own certificate over HTTP in every mode. In the `dns` and `on-demand` modes it also sends `Strict-Transport-Security: max-age=31536000`, so browsers that have visited it once refuse plain HTTP for a year; not in the `internal` mode, whose certificates browsers do not trust. Domains that teams connect in the dashboard get theirs on demand on the first visit, in every mode, and only once the tunnel server confirms the domain is verified. Without that confirmation, anyone could make your Caddy request certificates for names of their choosing.

## Configuration

Everything lives in `.env`, with a comment for each setting. After a change:

```sh
docker compose up -d --wait
```

Run `./install.sh --reconfigure` to answer the questions again with the current values as defaults. Settings that can also be changed in the dashboard's admin area (allowed email domains, log retention, contact address) are overridden by the value saved there.

- **Legal pages:** put `imprint.md` and `privacy.md` into `legal/`; see [`legal/README.md`](../../deploy/compose/legal/README.md).
- **Who may register:** `EXPOSEHUB_ALLOWED_EMAIL_DOMAINS`. Empty means anyone on the internet can register and open tunnels on your server.
- **Limits:** one person owns at most 10 teams and saves at most 25 addresses; one team has at most 25 networks and 25 own domains. `EXPOSEHUB_MAX_TEAMS_PER_USER`, `EXPOSEHUB_MAX_SAVED_ADDRESSES_PER_USER`, `EXPOSEHUB_MAX_NETWORKS_PER_TEAM` and `EXPOSEHUB_MAX_DOMAINS_PER_TEAM` change them; `0` allows none.
- **Sign-up and reset mails:** one IP address can sign up 5 times an hour (`EXPOSEHUB_MAX_REGISTRATIONS_PER_IP_PER_HOUR`), and password-reset mails are limited to 10 an hour per IP address (`EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_IP_PER_HOUR`) and 3 per email address (`EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_ADDRESS_PER_HOUR`). Everyone behind one shared office address counts as one IP address, so if many colleagues sign up at once, raise the sign-up limit in `.env`, for example to 50, and run `docker compose up -d --wait`.
- **Password and two-factor guessing:** per account, a signed-in session may enter 5 wrong passwords a minute (password confirmation, account deletion, password change; `EXPOSEHUB_MAX_PASSWORD_CHECKS_PER_MINUTE`), and a sign-in may try 5 two-factor codes a minute (`EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_MINUTE`) and 20 a day (`EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_DAY`). After 5 wrong codes in a row (`EXPOSEHUB_MAX_TWO_FACTOR_FAILURES_IN_A_ROW`) the sign-in starts over with the password and the account holder gets a mail. Keep each at 1 or more; 0 would lock everyone out.
- **Single sign-on:** [admin.md](../admin.md#single-sign-on) has the setup, for the whole install (the `EXPOSEHUB_SSO_*` settings in `.env`) or for one team (entered in the dashboard). Either way the `web` container calls the provider itself at every sign-in, over HTTPS on port 443: the discovery document, the token exchange and the signing keys. The Compose setup lets it out; a firewall on the host or in front of it that limits outbound traffic has to allow the provider's hosts, those of each team's provider included. See [Troubleshooting](#troubleshooting) for how to check.
- **When the dashboard is down:** the tunnel server keeps a copy of the dashboard's token list, so agents keep working through a restart of the dashboard. If it cannot refresh that copy for longer than `EXPOSEHUB_CONTROL_PLANE_MAX_STALE` (default `5m`), it closes the tunnels opened with dashboard tokens and refuses those tokens until the dashboard answers again; otherwise a token revoked in the meantime would keep working. The agents reconnect by themselves once it is back. Static tokens keep working throughout. The admin overview shows when this happens. `0` keeps the last list forever.
- **Caddy:** the configuration is in `caddy/`. Changes there are overwritten by the next upgrade, so open an issue if you need something it cannot do.

## Upgrading

```sh
cd exposehub/deploy/compose
scripts/backup.sh
git fetch --tags
git checkout v0.2.0
./install.sh
docker compose up -d --wait
```

`install.sh` keeps every value and secret, adds settings the new release introduced (marked in `.env`), checks DNS again and pulls the new images. `up` runs the migrations before the new dashboard starts. Agents keep working during the restart: they reconnect within seconds and keep their addresses. They update themselves with `exposehub update` from the new tunnel server.

Read the release notes before skipping versions. Downgrading is not supported, because migrations only run forward; restore a backup instead.

## Backup and restore

```sh
scripts/backup.sh                       # writes backups/exposehub-<time>.tar.gz
scripts/backup.sh /mnt/backup           # or into another directory
```

The archive holds the database, `.env`, `caddy.env` and `legal/`. It contains every secret of the install, so copy it somewhere other than this server and keep it private. Certificates are not included; Caddy obtains new ones. Run it from cron for regular backups, for example daily at 03:00:

```cron
0 3 * * * cd /opt/exposehub/deploy/compose && scripts/backup.sh >/dev/null
```

To restore, on the same server or a new one with the same release checked out:

```sh
scripts/restore.sh backups/exposehub-20261112T030000Z.tar.gz --config
./install.sh
docker compose up -d --wait
```

`--config` also restores `.env`, `caddy.env` and `legal/`. Without it, only the database is restored, and only if the backup's `APP_KEY` matches the current one: two-factor secrets and other encrypted values cannot be read with another key.

## IPv6

Caddy publishes its ports on IPv4 only, and the Compose network is IPv4 only. This is on purpose. Docker would otherwise also accept connections on the server's IPv6 addresses and relay them into the IPv4 network through its userland proxy (`docker-proxy`), which replaces each visitor's address with the network's gateway (`172.30.53.1` by default). Every IPv6 visitor would then appear as that one private address: the request log would show it, all of them would share one login limit, and a network rule in the dashboard or an agent's `--allow` that contains it (for example `172.16.0.0/12` entered for a VPN) would let any IPv6 visitor on the internet into that tunnel.

As a second line, Caddy refuses with 403 every request whose sender is inside the Compose network (`EXPOSEHUB_SUBNET`), because such a request was relayed and its real sender is unknown. The same happens if Docker's iptables rules are turned off (`"iptables": false`) or with rootless Docker's default port driver, where every connection arrives that way: those setups cannot run this install. The `internal` mode does not refuse anything, because Docker on a laptop relays every connection.

To serve IPv6 visitors, Docker must hand them to Caddy with their own address: IPv6 enabled on the `edge` network with `ip6tables` (Docker 27 or newer), Caddy's IPv6 address added to `EXPOSEHUB_TRUSTED_PROXIES` of the server and the dashboard, and the ports also published on `[::]`, in a `docker-compose.override.yml`. Check the request log afterwards: it must show visitors' IPv6 addresses, not one address of the Docker network.

## Tunnels only

Answer `tunnels` to the first question (or set `EXPOSEHUB_STACK=tunnels`) to run only Caddy and the tunnel server. No dashboard, no Postgres, no accounts, no request logs and no teams' own domains. Agents sign in with the static tokens in `EXPOSEHUB_STATIC_TOKENS` (`account:token` pairs, comma separated); `install.sh` generates one for `admin` and prints it. Only the tunnel domain's record is needed in DNS.

## Trying it locally

The `internal` mode runs the whole stack on a laptop with names under `localhost`, which resolve to your own machine without any DNS:

```sh
EXPOSEHUB_STACK=full EXPOSEHUB_TLS_MODE=internal \
EXPOSEHUB_DASHBOARD_HOST=dash.localhost EXPOSEHUB_TUNNEL_DOMAIN=tunnels.localhost \
EXPOSEHUB_ADMIN_EMAILS=you@example.com EXPOSEHUB_ALLOWED_EMAIL_DOMAINS=example.com \
EXPOSEHUB_HTTP_PORT=8080 EXPOSEHUB_HTTPS_PORT=8443 \
./install.sh --non-interactive
docker compose up -d --wait
curl -k https://dash.localhost:8443/up
```

Browsers and the agent do not trust Caddy's local authority. Copy its root certificate out to trust it:

```sh
docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt
```

On Linux, `SSL_CERT_FILE=./caddy-root.crt exposehub ...` makes the agent trust it. On macOS, add it to the keychain (`sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain caddy-root.crt`) and remove it again when you are done.

## Troubleshooting

**Containers and logs.** `docker compose ps` shows each container's state and health; `docker compose logs -f caddy server web` shows what they do.

**No certificate.** `docker compose logs caddy | grep -i -E 'error|challenge'`. The usual causes are a DNS record that points elsewhere or is proxied by a CDN, port 80 or 443 blocked by a firewall, or (in the `dns` mode) a token without the right permissions. Let's Encrypt locks a name out for an hour after five failed attempts. Fix the cause, then wait.

**Agent cannot connect.** `curl -I https://connect.example-tunnels.net/healthz` should answer 200. If it doesn't, check the wildcard record and Caddy's log.

**Visitors get "This server cannot see the address you connect from".** Docker relayed their connection, so Caddy saw the Compose network's gateway instead of them and refused the request; see [IPv6](#ipv6). Usually an IPv6 override that does not keep addresses, or Docker running with `"iptables": false` or rootless.

**The subnet collides with a network of yours.** Set `EXPOSEHUB_SUBNET` and `EXPOSEHUB_CADDY_ADDRESS` (an address inside it) in `.env`, then `docker compose down && docker compose up -d --wait`.

**Mails do not arrive.** `docker compose logs web | grep -i mail`. With `MAIL_MAILER=log`, mails are written there instead of sent.

**Single sign-on ends with "Signing in did not work".** `docker compose logs web | grep 'Single sign-on failed'` says why. `discovery unreachable` means the `web` container cannot reach the provider: check outbound HTTPS from the host, and that the issuer is written exactly as the provider publishes it. With your provider's issuer:

```sh
docker compose exec web php -r \
  'echo @file_get_contents("https://example.okta.com/.well-known/openid-configuration") === false ? "unreachable" : "reachable", "\n";'
```

**Start over.** `docker compose down -v` deletes all containers and volumes, including the database and the certificates; `.env` stays.
