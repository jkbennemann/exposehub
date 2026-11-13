# Admin area and limits

`/admin` is for the people who run an installation. Platform owners are the verified accounts whose addresses are in `EXPOSEHUB_ADMIN_EMAILS` (dashboard, comma separated); they cannot be demoted, suspended or deleted from the UI, so nobody locks the operator out. Owners can make other people admins. The area asks for the password again and answers 404 to everyone else.

- **Overview:** the dashboard, the tunnel server and the connected agents as one line, coloured by when each last reported; totals; things that need a look; the configuration in use.
- **People:** search and filter accounts; per person their teams, tokens and how many requests reached their tunnels (counts only, never paths or contents). Suspend (signs them out, their tokens stop working and their tunnels close within seconds; reversible), reactivate, delete, confirm an email address, resend the verification mail, make or remove admins (owners only). Nobody acts on themselves or on an owner, and only owners act on other admins.
- **Teams:** members, active tokens and domains of every team; revoke a token, remove a domain.
- **Live tunnels:** who is connected right now, as the tunnel server reports every 15 seconds: addresses, person and token, agent version, connected since. Revoking the token cuts the tunnel.
- **Settings:** allowed and hidden email domains, request log retention and the contact address can be changed here; a saved value overrides the deployment's, and *Reset* goes back to it.
- **Audit log:** every admin action with who, whom and when, kept `EXPOSEHUB_ADMIN_AUDIT_RETENTION_DAYS` (365) days. The affected person also gets a mail for each action.

## Limits

One person owns at most 10 teams (`EXPOSEHUB_MAX_TEAMS_PER_USER`) and saves at most 25 addresses across their teams (`EXPOSEHUB_MAX_SAVED_ADDRESSES_PER_USER`). One team has at most 25 networks (`EXPOSEHUB_MAX_NETWORKS_PER_TEAM`) and 25 own domains (`EXPOSEHUB_MAX_DOMAINS_PER_TEAM`). Every team's networks and domains go to the tunnel server in one shared list, so no single account may grow it without bound. Sign-ups are limited to 5 per IP address and hour (`EXPOSEHUB_MAX_REGISTRATIONS_PER_IP_PER_HOUR`), password-reset mails to 10 per IP address (`EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_IP_PER_HOUR`) and 3 per email address (`EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_ADDRESS_PER_HOUR`) and hour, because each one sends mail to an address someone typed in. Everyone behind one shared office address counts as one IP address, so raise the sign-up limit if many people sign up from there at once. Per account, a signed-in session may enter 5 wrong passwords a minute (`EXPOSEHUB_MAX_PASSWORD_CHECKS_PER_MINUTE`), and a sign-in may try 5 two-factor codes a minute (`EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_MINUTE`) and 20 a day (`EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_DAY`); after 5 wrong codes in a row (`EXPOSEHUB_MAX_TWO_FACTOR_FAILURES_IN_A_ROW`) the sign-in starts over with the password and the account holder gets a mail. An operator changes all of these in the dashboard's environment. `0` allows none, so the four guessing limits must stay at 1 or more.

## Demo

With `EXPOSEHUB_DEMO=true` the landing page offers "Try the demo". One click signs a visitor in without an account, into a demo team that holds sample data: request logs, two saved addresses, a domain and a network. The visitor can create tokens and open real tunnels with them.

It is off by default. Switch it on only for an install whose tunnel domain you are prepared to share with strangers: a demo tunnel is a public address that anyone can open without telling you who they are.

What keeps that within bounds:

- **Visitors are kept apart.** Each visitor is an account of their own, a plain member of the demo team. They see their own tokens, tunnels and request logs, not those of other visitors, and cannot change what the team shares.
- **A visitor cannot leave the demo.** Account settings, passkeys, two-factor, teams, invitations and the admin area are closed to them. Their address ends in `@demo.invalid` and never gets mail.
- **Nothing is kept.** Every `EXPOSEHUB_DEMO_RESET_MINUTES` (30 by default) the demo team, its visitors, their tokens and their request logs are deleted and the sample data is laid out again. The tunnel server closes the tunnels of the deleted tokens at its next sync, within seconds. Visitors are signed out by this.
- **The tunnel server limits demo tunnels.** They get random addresses only, show browsers a notice before the tunnelled page, and are capped in number and in requests a minute. [server.md](server.md) has the settings.
- **Nobody serves a page on what the demo shows.** Its two sample addresses are reserved for the demo team, whose own agents cannot pick a name, so no agent of anyone opens them. Its sample domain and rules are left out of what the tunnel server is told, so no certificate is asked for.
- **Switching it off takes effect at once.** Visitors are signed out on their next request and their tokens stop working at the tunnel server's next sync; the leftovers are deleted within the hour.

| Setting | Default | What it does |
|---|---|---|
| `EXPOSEHUB_DEMO` | `false` | Switches the demo on. |
| `EXPOSEHUB_DEMO_RESET_MINUTES` | `30` | How long after it was laid out the demo is wiped. |
| `EXPOSEHUB_DEMO_MAX_VISITORS` | `200` | How many visitors the demo holds until its next reset. |
| `EXPOSEHUB_DEMO_VISITORS_PER_IP_PER_HOUR` | `6` | How often one address may start the demo in an hour. |
| `EXPOSEHUB_DEMO_TOKENS_PER_VISITOR` | `2` | How many tokens, and so tunnels, one visitor can have. |

The limit per address counts an IPv6 /64 as one address, and it depends on the dashboard seeing visitors' real addresses: behind a proxy, `EXPOSEHUB_TRUSTED_PROXIES` has to name that proxy, or every visitor counts as one. The cap on visitors can still be filled by someone with many addresses, who then keeps others out until the next reset; that is the worst they can do with it.

The reset is one of the dashboard's scheduled jobs, so the scheduler has to run (it does in the Compose setup and the Helm chart). `php artisan demo:reset` wipes the demo at once.

In the admin area, visitors are listed under People → Demo visitors, apart from people with an account, and their tunnels show on the Tunnels page like any other, where you can close one.
