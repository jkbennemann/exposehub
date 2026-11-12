# Privacy notice

[Date of this version]

This notice covers the dashboard at [dashboard address] and the tunnel
addresses under [tunnel domain].

## 1. Controller

The controller within the meaning of Art. 4 (7) GDPR is:

[Name]  
[Street and number]  
[Postal code and city]  
[Country]  
[Email address]

[Data protection officer: contact details, or a sentence saying why none is appointed.]

## 2. Short version

- The dashboard sets only the cookies it needs to keep you signed in.
- There are no analytics, tracking or advertising services.
- Fonts, scripts and styles are served from this server. Nothing is loaded from third parties.
- Requests that pass through a tunnel are forwarded to the tunnel owner's machine. A summary of each request is kept for [7] days and shown only to the tunnel owner and the colleagues they share it with. Headers and bodies are kept only if the owner turns that on.

## 3. Your account

| Data | Purpose | Legal basis | Retention |
|---|---|---|---|
| Name, email address | Identifying you, sign-in, messages about your account | Art. 6 (1)(b) GDPR | Until you delete the account |
| Password (stored only as a hash) | Sign-in | Art. 6 (1)(b) GDPR | Until changed or the account is deleted |
| Passkeys (public key, name, last use) | Sign-in without a password | Art. 6 (1)(b) GDPR | Until you remove the passkey |
| Two-factor secret and recovery codes (encrypted) | Protecting your account | Art. 6 (1)(b) GDPR | Until you switch two-factor sign-in off |
| Team memberships, roles, invitations (including the invited email address) | Working in teams | Art. 6 (1)(b) GDPR; for invited addresses Art. 6 (1)(f) GDPR | Until the team, membership or invitation is removed; open invitations expire |
| Agent tokens (stored only as a hash, with a name and creation time) | Letting your agent open tunnels | Art. 6 (1)(b) GDPR | Until deleted with the account or team; revoked tokens are kept for the record |
| Domains you connect (the hostname and a verification code) | Opening tunnels on a domain of your own | Art. 6 (1)(b) GDPR | Until you remove the domain or the team is deleted |
| Session record (IP address, browser identification, time of last activity) | Keeping you signed in | Art. 6 (1)(b) GDPR | Deleted when the session ends, at the latest after [session lifetime, 2 hours by default] without activity |

Accounts are limited to certain email domains. [Remove this sentence if registration is open.]

### Password check

When you set or change a password, it is checked against a public list of
passwords known from data breaches. For that, the first five characters of
the password's SHA-1 hash are sent to `api.pwnedpasswords.com`, a service of
Have I Been Pwned. The password itself and its full hash never leave this
server, and the request comes from the server, not from your browser. Legal
basis: Art. 6 (1)(f) GDPR, the legitimate interest in keeping accounts safe.

### Administration

The people who run this service, and those they make admins, have an admin
area. There they see the account data listed above (except passwords,
secrets and recovery codes), the time of your last activity, the names of
your agent tokens and domains, and how many requests reached your tunnels
and what share of them failed. They do not see which requests these were:
request logs stay visible only to you and the colleagues you share them with.

Admins can suspend, reactivate or delete an account, confirm an email
address, revoke an agent token and remove a domain. You get a mail for every
one of these actions that concerns you. Each action is recorded in an audit
log with the names and addresses of the admin and the person concerned, and
kept for one year. The tunnel server also reports every 15 seconds which
agents are connected (token, addresses, agent version, time of connection);
that report is kept for a few minutes only. Legal basis: Art. 6 (1)(f) GDPR,
the legitimate interest in operating the service securely and preventing
misuse.

## 4. Cookies

| Cookie | Purpose | Lifetime |
|---|---|---|
| Session cookie | Keeps you signed in | [Session lifetime, 2 hours by default] |
| `XSRF-TOKEN` | Protects forms against forged requests | Same as the session |
| Remember cookie | Only if you tick "Remember me": signs you in again | Until you sign out |

These cookies are strictly necessary for a service you asked for, so no
consent is required under § 25 (2) TDDDG. Your choice of light or dark
appearance is kept in your browser's local storage and is not sent to the
server.

## 5. Email

The service sends email to verify your address, reset your password and
deliver team invitations. It also tells you when something changes in your
account or teams: a new password or email address, two-factor sign-in or a
passkey added or removed, an agent token created or revoked, being removed
from a team, a team deleted, and the deletion of your account. A change you
did not make then does not go unnoticed. [Name the mail provider, its location and the
contract under Art. 28 GDPR, or state that mail is sent from your own server.]

## 6. Tunnels

When someone opens a tunnel address, the tunnel server passes the request to
the agent of the user who opened the tunnel, and passes the answer back.

- **Visitors of a tunnel address:** the request, including your IP address, is forwarded to the tunnel owner's machine so that their service can answer it. The tunnel owner decides what their service does with it and is responsible for that processing. If the tunnel is password protected, the name and password you enter are checked by the tunnel server and are not passed on. If the tunnel's team limits it to certain networks, the tunnel server compares your IP address with those networks first; a refused request gets a page showing your address and is recorded in the owner's request log like any other.
- **Tunnel owners:** the tunnel server records in its log when an agent connects and disconnects, with the team it belongs to and the tunnel addresses. [State where server logs are kept and for how long.] Legal basis: Art. 6 (1)(f) GDPR, the legitimate interest in operating the service and tracing misuse.

### Request log

[Remove this subsection if the tunnel server runs with EXPOSEHUB_REQUEST_LOGS=off.]

For every request to a tunnel address, the tunnel server sends a summary to
the request log of the tunnel's owner in the dashboard:

- time, tunnel address, method, path and query string, status code, duration and the sizes of request and answer;
- the visitor's network instead of the IP address: an IPv4 address is cut to its first three parts (`203.0.113.0`), an IPv6 address to its first three groups.

Values in the query string whose names suggest a secret, such as tokens,
keys, signatures and passwords, are replaced before anything is stored.

Headers and bodies of requests and answers are kept only if the owner starts
the tunnel with `--log-bodies`, each up to 64 KiB. Even then, authorization
headers, cookies and headers named like tokens, secrets or passwords are
replaced before storing. With `--no-log`, nothing about a tunnel's requests
is stored.

Only the tunnel owner sees their request log, and colleagues in the same team
whom the owner explicitly shares it with. Sharing ends when the owner stops
it or either of them leaves the team.

The log is deleted after [7] days (EXPOSEHUB_LOG_RETENTION_DAYS), and at once when the owner leaves the team
or deletes their account. Legal basis: Art. 6 (1)(f) GDPR, the legitimate
interest of tunnel owners in finding faults in the services they expose, and
of this service in tracing misuse. If the tunnel owner turns on headers and
bodies, they decide to and are responsible for that.

Apart from that, the content of requests and responses is not stored by this
service.

## 7. Logs of the dashboard

[State what the server in front of the dashboard logs. The application itself
writes no access log. When an error occurs it logs the error with the address
that was requested, which is kept as long as your server keeps its logs.]

## 8. Hosting and DNS

[Name the hosting provider, the data centre location and the contract under
Art. 28 GDPR. Name the DNS providers of your domains and say whether traffic
passes through them or only name resolution does.]

## 9. Your rights

You have the right to access (Art. 15 GDPR), rectification (Art. 16),
erasure (Art. 17), restriction of processing (Art. 18), data portability
(Art. 20) and to object to processing based on Art. 6 (1)(f) (Art. 21). To use
them, write to the address in section 1. You can delete your account yourself
under Settings.

You also have the right to lodge a complaint with a supervisory authority
(Art. 77 GDPR), for example [name and address of the competent authority].
