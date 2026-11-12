# Security

exposehub puts machines on the public internet, so security reports get priority.

## Reporting a vulnerability

Report it privately through GitHub: **[Report a vulnerability](https://github.com/jkbennemann/exposehub/security/advisories/new)** (the *Security* tab, *Report a vulnerability*). Please do not open a public issue, pull request or discussion for it.

Include what is affected, how to reproduce it, and what an attacker gains. A proof of concept helps. You will get an answer within a week, usually sooner. Once a fix is released, the advisory is published with credit to you, unless you prefer not to be named.

## Supported versions

exposehub is in 0.x. Only the latest release gets fixes; upgrade to it before reporting, if you can.

| Version | Supported |
|---|---|
| latest 0.x release | yes |
| anything older | no |

## In scope

- The tunnel server (`tunnel/cmd/exposehub-server`): routing, token checks, access rules, password protection, the internal API, agent downloads
- The agent (`tunnel/cmd/exposehub`): the connection to the server, the local inspector, self-update, the install script
- The dashboard (`web/`): accounts, teams, tokens, domains, request logs, the admin area
- The container images, the Compose setup in `deploy/compose` and the Helm chart in `deploy/helm`, where their defaults are unsafe

## Out of scope

- What a local service exposed through a tunnel does; that is its owner's code
- Missing hardening that has no practical attack, such as headers on pages without sensitive content
- Denial of service by sheer traffic volume
- An install whose operator changed the defaults to something unsafe
- Reports produced by automated scanners without a demonstrated impact
