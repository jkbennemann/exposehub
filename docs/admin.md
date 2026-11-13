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

## Single sign-on

exposehub can sign people in through your identity provider over OpenID Connect: Okta, Microsoft Entra ID, Google Workspace, Keycloak and others. It is off until you set the issuer, a client and at least one email domain.

### What it does

- **The login page gets a button.** "Sign in with Okta", or whatever you name the provider.
- **The provider answers for the email domains you name.** An account with such an address is made at the person's first sign-in through the provider. It needs no invitation and no password.
- **Those accounts sign in through the provider only.** A password, a passkey or a remembered sign-in no longer gets them in: a session that did not begin at the provider is ended. Nobody can register an address of these domains or move an account to one.
- **You keep a way in.** The addresses in `EXPOSEHUB_ADMIN_EMAILS` keep password, two-factor and passkeys, so the people who run the install get in while the provider is down or set up wrongly. Make sure at least one of them has a password that works before you switch this on. The provider never signs anyone in as one of them, makes an account for such an address or moves an account to it: who runs the install is not the provider's to say.
- **Agent tokens need a recent sign-in.** A token lives on a laptop and outlives the browser session, so someone you have disabled at the provider would keep their tunnels. Tokens of an account the provider answers for work for `EXPOSEHUB_SSO_TOKEN_DAYS` (30) after its last sign-in through the provider. The person gets a mail three days before, and signing in to the dashboard is all it takes. Accounts that have not signed in through the provider yet get the same time, counted from when you switched single sign-on on.
- **Where the dashboard asks for the password again** (the admin area, security settings), such an account is sent to the provider to sign in again. That needs a provider that says when the person last signed in (`auth_time`); Okta, Entra ID and Keycloak do.

An existing account is tied to the person at their first sign-in if it has the same address and confirmed that address itself; the person gets a mail about it. From then on the account is found by the provider's own id for the person, not by the address, and follows the address if it changes at the provider.

### Setting it up

1. At the provider, create an OpenID Connect application of the "web application" kind (authorization code flow, with a client secret). Its redirect address is `https://<your dashboard>/sso/callback`; the admin area shows the exact address under Settings. It needs the scopes `openid`, `email` and `profile`.
2. Set these for the dashboard and its scheduler:

| Setting | What it is |
|---|---|
| `EXPOSEHUB_SSO_ISSUER` | The issuer as the provider publishes it, for example `https://example.okta.com`. It must match character for character, a trailing slash included |
| `EXPOSEHUB_SSO_CLIENT_ID`, `EXPOSEHUB_SSO_CLIENT_SECRET` | The application's client id and secret |
| `EXPOSEHUB_SSO_DOMAINS` | The email domains the provider answers for, comma-separated |
| `EXPOSEHUB_SSO_NAME` | What the button calls the provider. Default: single sign-on |
| `EXPOSEHUB_SSO_TOKEN_DAYS` | How many days tokens work after the last sign-in through the provider; `0` for good. Default `30` |
| `EXPOSEHUB_SSO_REQUIRE_VERIFIED_EMAIL` | Whether the provider has to say that an address is confirmed (`email_verified`). Default `true`. Entra ID does not say it; set `false` there, and only for a provider where people cannot choose their own address |
| `EXPOSEHUB_SSO_SCOPES` | Default `openid email profile` |

With Okta: Applications → Create App Integration → OIDC, Web Application; grant type Authorization Code; sign-in redirect URI as above; assign the people or groups who may use exposehub. The issuer is your Okta domain, or the issuer of the authorization server you use.

### What it does not do yet

- **Disabling someone at the provider does not close their tunnels at once.** It closes them when their tokens' days run out. Suspend the account in the admin area to cut it off immediately; that takes effect within seconds.
- **Teams are not filled from the provider's groups.** People join teams by invitation, as before.
- **One provider per install,** and no SAML.
