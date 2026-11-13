# exposehub Helm chart

Runs the exposehub tunnel server, the dashboard and (optionally) a Postgres for it. The full guide, with DNS, certificates and upgrades, is [docs/self-hosting/kubernetes.md](../../../docs/self-hosting/kubernetes.md).

```sh
helm install exposehub oci://ghcr.io/jkbennemann/charts/exposehub \
  --version 0.4.0 --namespace exposehub --create-namespace \
  -f my-values.yaml
```

The chart version, the image tags and the agent all carry the same version number.

## Required values

| Value | Meaning |
|---|---|
| `server.tunnelDomain` | Tunnels get subdomains of it, e.g. `tunnels.example.net` |
| `server.trustedProxies` | Addresses of the ingress controller's pods; the pod CIDR only with a network policy |
| `networkPolicy.ingressNamespace` | The ingress controller's namespace, for the default `networkPolicy.type: kubernetes`; or set the type to `none` on purpose |
| `web.host` | The dashboard's hostname, e.g. `dash.example.com` |
| `secrets.nodeSecret`, `secrets.appKey`, `secrets.dbPassword` | Or `secrets.existingSecret` |
| `ingress.certificate.issuer.email` and `.solvers` | In the default `issuer` mode; see below |

## Certificates

`ingress.certificate.mode` decides where the wildcard certificate for `*.<tunnelDomain>` comes from:

- `issuer` (default): the chart creates a namespaced cert-manager Issuer. `solvers` is passed to it verbatim, so any DNS-01 provider cert-manager supports works.
- `clusterIssuer`: an existing ClusterIssuer that can solve DNS-01 for the tunnel domain.
- `secret`: you put a TLS secret named `ingress.tlsSecretName` into the namespace yourself.

The dashboard's certificate comes from `web.ingress.tls.clusterIssuer` or `web.ingress.tls.issuer` (HTTP-01 is enough), or from a secret you provide.

## Single sign-on

The dashboard calls the identity provider itself (discovery, token exchange, signing keys) on port 443. With `networkPolicy.type: cilium` it reaches named hosts only: the host of `web.sso.issuer` is opened by itself, and `web.sso.providerHosts` lists the rest, the hosts of each team's provider an admin approves included. The other policy types leave the dashboard's outbound traffic open. [docs/self-hosting/kubernetes.md](../../../docs/self-hosting/kubernetes.md#single-sign-on) has the details and a check.

## Limits and outages

- `web.limits` caps how many teams one person owns (10), how many addresses one person saves (25), and how many networks (25) and own domains (25) one team has. It also caps the mail anyone can make the dashboard send: sign-ups per IP address and hour (5; each mails a confirmation link), and password-reset mails per hour per IP address (10) and per email address (3). Everyone behind one shared office address counts as one IP address, so raise `registrationsPerIpPerHour` if many people sign up from there at once. `0` allows none. Per account, it limits wrong passwords inside a signed-in session (`passwordChecksPerMinute`, 5) and two-factor codes per sign-in (`twoFactorAttemptsPerMinute`, 5; `twoFactorAttemptsPerDay`, 20); after `twoFactorFailuresInARow` (5) wrong codes the sign-in starts over with the password and the account holder gets a mail. These four must be at least 1.
- `server.controlPlaneMaxStale` (default `5m`): when the server cannot refresh its token list from the dashboard for longer than this, it refuses dashboard tokens and closes their tunnels until the dashboard is back, so a revoked token cannot keep working. Static tokens keep working. The dashboard's admin overview shows it. `"0"` keeps the last list forever.
- The certificate-name budget (`EXPOSEHUB_TLS_ASK_NAME_LIMIT`, `EXPOSEHUB_TLS_ASK_TOTAL_LIMIT`, `EXPOSEHUB_TLS_ASK_NAME_WINDOW`) does not apply here: the chart uses one wildcard certificate and does not run the server's on-demand certificate check.

## Names

Resources are named after the release: with release `exposehub` they are `exposehub-server`, `exposehub-web` and `exposehub-postgres`. The database volume is `data-<release>-postgres-0`, so installing under another release name or changing `fullnameOverride` later starts with an empty database. Run one release per namespace.

## Files

- `values.yaml` documents every value.
- `ci/` holds the values CI renders the chart with. They are examples, not defaults.
