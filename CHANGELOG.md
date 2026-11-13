# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). The tunnel server, the agent, the dashboard, the images and the Helm chart share one version.

## [Unreleased]

### Fixed

- A team's settings (members, invitations, single sign-on) were reachable only through an unlabelled pencil icon under Settings → Teams. The team switcher now has "Team settings" for the current team and "All my teams", and the button in the list says what it leads to.

## [0.3.0] - 2026-11-13

### Added

- Single sign-on over OpenID Connect: Okta, Microsoft Entra ID, Google Workspace, Keycloak and others. A provider can be set for the whole install in the deployment (`EXPOSEHUB_SSO_ISSUER`, a client and the email domains it answers for), or for one team: the team's owner enters it in the team's settings, a platform admin approves it under Admin → Single sign-on, and whoever it signs in becomes a member of that team. Accounts of a provider's domains are made at their first sign-in and sign in through it only; the platform owners keep their password. Their agent tokens work for `EXPOSEHUB_SSO_TOKEN_DAYS` (30) after the last sign-in through the provider, so that someone the provider no longer lets in loses their tunnels. Off by default; [docs/admin.md](docs/admin.md) has the setup.

### Changed

- The dashboard has two new migrations (`user_identities`, `sso_connections`). They run with the usual `migrate` step of an upgrade and change nothing for an install without single sign-on.
- The login page shows single sign-on only once a provider is in force; without one it looks as before.

## [0.2.0] - 2026-11-13

### Added

- A demo that needs no account. With `EXPOSEHUB_DEMO=true` the landing page offers "Try the demo": one click signs a visitor in, into a demo team with sample data, where they can create tokens and open real tunnels. Each visitor sees only their own tokens, tunnels and request logs. Every 30 minutes the demo team, its visitors and what they made are deleted and the sample data is laid out again. Off by default; [docs/admin.md](docs/admin.md) says what to consider before switching it on.
- The tunnel server holds tunnels of the demo to tighter rules than a team's: random addresses only, one tunnel per token, a cap on how many are open at once, a limit on requests and on data a minute, no bodies in the request log, and a notice that browsers see before the first page. New settings `EXPOSEHUB_DEMO_MAX_TUNNELS`, `EXPOSEHUB_DEMO_REQUESTS_PER_MINUTE` and `EXPOSEHUB_DEMO_MEGABYTES_PER_MINUTE`; see "Demo tunnels" in [docs/server.md](docs/server.md).

### Changed

- The dashboard has a new migration (two columns that mark the demo's team and accounts). It runs with the usual `migrate` step of an upgrade and changes nothing for an install without the demo.

### Security

- The Access page had a helper that a browser could call with the id of any saved address; its answer told whether an address with that id exists in another team, and nothing else. It can no longer be called from the browser.

## [0.1.2] - 2026-11-13

### Changed

- The landing page says that exposehub is open source: a line under the headline, a section with the repository's layout and licence, and an icon in the header of the landing and docs pages that leads to the source. The icon is GitHub's when `EXPOSEHUB_SOURCE_URL` points there and a neutral one for a fork kept elsewhere; with the setting empty, none of it is shown.

## [0.1.1] - 2026-11-13

### Licence

- exposehub is now open source under the [Apache License 2.0](LICENSE). Version 0.1.0 stays under the Functional Source License it was published with.
- Contributions no longer need a signed contributor licence agreement. They come in under Apache-2.0, as section 5 of the licence says.
- The Helm chart named AGPL-3.0 as its licence on Artifact Hub; it names Apache-2.0 now.

### Changed

- The dashboard's assets are built with vite-plus 1.5.0.

## [0.1.0] - 2026-11-12

First public release.

### Licence

- Source-available under the Functional Source License, [FSL-1.1-ALv2](https://github.com/jkbennemann/exposehub/blob/v0.1.0/LICENSE.md). Each release converts to Apache-2.0 two years after it is published.
- Contributions are accepted under a [Contributor License Agreement](https://github.com/jkbennemann/exposehub/blob/v0.1.0/CLA.md).

### Tunnel server and agent

- HTTP and WebSocket tunnels from a local service to a public subdomain, or to a domain a team has connected; the agent reconnects by itself and keeps its address.
- Up to ten tunnels over one connection (`exposehub http shop=8000 api=3000`), and an `exposehub.yml` per project for tunnels started often with `exposehub start`.
- Password protection per tunnel (`--auth`), checked by the server before a request reaches the local machine.
- Access rules by IP address and network (`--allow`, plus networks managed in the dashboard), checked on every request.
- A local request inspector at `http://127.0.0.1:4040` with full requests and responses, replay and copy as `curl`.
- The server hands out the agent: `curl -fsSL https://connect.<your domain>/install.sh | sh`, with SHA-256 checks; `exposehub update` installs the version the server offers.
- `exposehub login`, `logout` and `server`; the installer remembers the server it came from.
- Styled error pages for browsers when a tunnel cannot serve a request.
- Vite, webpack and Next.js dev servers work without configuration, hot reload included. When Vite or webpack refuses the public hostname, the agent sends it its own name from then on; when Next.js refuses its hot-reload socket or error overlay to a page opened through the tunnel, the agent passes those requests on as coming from the dev server's own address. Pages of other sites stay refused. The agent says what it did, and the first visitor already gets a working page. `--host-header preserve` turns both off; the agent then prints the `allowedDevOrigins` line Next.js needs.
- The agent says at start when nothing listens on the local port yet, and keeps the tunnel open.
- `EXPOSEHUB_LOG_FORMAT=json` makes the tunnel server log one JSON object per line (`server.logFormat` in the Helm chart).
- Configuration entirely through environment variables; nothing about an install is compiled in.

### Dashboard

- Accounts with passkeys, two-factor authentication and email verification; registration can be limited to email domains, some of which can stay unnamed.
- Teams, agent tokens, teams' own domains verified by a DNS TXT record, saved addresses and network access rules.
- Request logs per tunnel owner, kept for a configurable number of days, with secrets redacted; headers and bodies only for tunnels started with `--log-bodies`.
- A Tunnels page that lists the agents connected right now and disconnects one that was left running; its token keeps working. Members see their own, owners and admins the team's, and a person whose tunnel a colleague closes gets a mail.
- An admin area for operators: overview of the dashboard, the tunnel server and connected agents; people, teams, live tunnels, settings and an audit log.
- A mail to the account holder for every change to their account, and complete account deletion.
- Public documentation at `/docs`, and imprint and privacy pages from the operator's own Markdown files.

### Self-hosting

- Container images for the tunnel server, the dashboard and Caddy, for amd64 and arm64.
- A Docker Compose install with `install.sh`, including certificates for the tunnel domain and for teams' own domains.
- A Helm chart for Kubernetes.

### Security

- Resetting or changing a password ends the account's other sessions.
- Passkeys and two-factor authentication can only be set up once the email address is verified; a password reset removes those of an account that never verified.
- Limits per person and team: 10 owned teams and 25 saved addresses per person, 25 networks and 25 own domains per team, changeable with `EXPOSEHUB_MAX_*`.
- Team invitations are rate-limited: 10 per inviter per hour, 2 per team and address per day.
- Sign-ups (each sends a verification mail) and password-reset mails are rate-limited: 5 sign-ups per IP address and hour, 10 reset mails per IP address and 3 per email address and hour, changeable with `EXPOSEHUB_MAX_REGISTRATIONS_PER_IP_PER_HOUR` and `EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_*`.
- Wrong passwords inside a signed-in session (5 a minute per account) and two-factor codes (5 a minute, 20 a day) are limited; after 5 wrong codes in a row the sign-in starts over with the password and the account holder gets a mail. Changeable with `EXPOSEHUB_MAX_PASSWORD_CHECKS_PER_MINUTE` and `EXPOSEHUB_MAX_TWO_FACTOR_*`.
- When the tunnel server cannot refresh the dashboard's token list for longer than `EXPOSEHUB_CONTROL_PLANE_MAX_STALE` (5 minutes), it closes the tunnels opened with dashboard tokens until the dashboard is back, so a revoked token cannot keep working; static tokens keep working, and the admin overview says so.
- On-demand certificates for tunnel names are budgeted per team: 10 new names a week by default (`EXPOSEHUB_TLS_ASK_NAME_LIMIT`, `EXPOSEHUB_TLS_ASK_NAME_WINDOW`).
- The agent uses an `exposehub.yml` found in a parent directory only if it belongs to you (or root) and others cannot change it; `--file` still names any file.
- The Helm chart's NetworkPolicy is on by default (`networkPolicy.type: kubernetes`) and needs `networkPolicy.ingressNamespace`, or `none` chosen on purpose.
- The Compose install refuses requests that Docker relayed with the network's gateway as source (IPv6 or rootless Docker) outside the `internal` TLS mode, instead of treating all those visitors as one address.

[Unreleased]: https://github.com/jkbennemann/exposehub/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/jkbennemann/exposehub/releases/tag/v0.3.0
[0.2.0]: https://github.com/jkbennemann/exposehub/releases/tag/v0.2.0
[0.1.2]: https://github.com/jkbennemann/exposehub/releases/tag/v0.1.2
[0.1.1]: https://github.com/jkbennemann/exposehub/releases/tag/v0.1.1
[0.1.0]: https://github.com/jkbennemann/exposehub/releases/tag/v0.1.0
