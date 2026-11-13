# Running exposehub on Kubernetes

The Helm chart runs the tunnel server, the dashboard with its scheduler, and a Postgres. It is the same chart the author's own installation runs on. For a single server, [Docker Compose](compose.md) is less work.

## What you need

- A cluster with Kubernetes 1.25 or newer, amd64 or arm64 nodes, and a default storage class (or set `postgres.storageClass`).
- An ingress controller with a public address. The chart sets ingress-nginx annotations for long-lived connections and unlimited uploads; with another controller, set its equivalents in `ingress.annotations`. Traefik's ingress-nginx provider reads the nginx ones.
- [cert-manager](https://cert-manager.io/docs/installation/). The tunnels need a wildcard certificate, and a wildcard certificate can only be issued over DNS-01, so cert-manager needs API access to the DNS of your tunnel domain. Check [the DNS-01 providers](https://cert-manager.io/docs/configuration/acme/dns01/) before you start. Alternatively bring your own wildcard certificate as a secret.
- Two names:
  - the dashboard, for example `dash.example.com`
  - a tunnel domain, for example `tunnels.example.net`. Tunnels become `<name>.tunnels.example.net` and agents connect to `connect.tunnels.example.net`.

  Put them on different registrable domains if you can. Tunnels serve whatever a developer's machine returns; under a shared parent domain those pages count as the same site as the dashboard and can set cookies for it.

  Tunnel names can also change hands: a name nobody saved is free for any team a minute after its agent disconnects (`EXPOSEHUB_GRACE_PERIOD`), and a removed domain can be connected by another team. Browsers keep what the earlier holder left on that name, such as a service worker or long-cached scripts, and the server does not clear it. Tell teams to save the addresses they rely on (the dashboard's Access page) or to use their own domain.
- An SMTP account for registration and password-reset mail. Without one, mail goes to the pod log, which is fine for a first try.

## DNS

Point both at the ingress controller's public address:

```
dash.example.com        A   203.0.113.10
*.tunnels.example.net   A   203.0.113.10
```

The wildcard covers `connect.tunnels.example.net` too. If you use a CDN or proxy in front, keep the tunnel records unproxied ("DNS only" at Cloudflare): proxies cap upload sizes and cut long-lived connections, and agents hold one WebSocket open for as long as they run.

## Secrets

Generate them once and keep them safe. Changing `APP_KEY` later signs everyone out and invalidates two-factor secrets; changing the database password after the first start locks the dashboard out of Postgres.

```sh
kubectl create namespace exposehub
kubectl -n exposehub create secret generic exposehub-secrets \
  --from-literal=EXPOSEHUB_NODE_SECRET="$(openssl rand -hex 32)" \
  --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
  --from-literal=DB_PASSWORD="$(openssl rand -hex 24)" \
  --from-literal=MAIL_PASSWORD='your SMTP password'
```

Then set `secrets.existingSecret: exposehub-secrets`. You can instead put the values under `secrets:` in your values file; the chart then creates the secrets itself.

For the DNS-01 solver, create the secret your provider needs in the same namespace, for example for Cloudflare a token with Zone:DNS:Edit on the tunnel domain:

```sh
kubectl -n exposehub create secret generic cloudflare-api-token \
  --from-literal=api-token='...'
```

## Values

A complete `values.yaml` for the example names above:

```yaml
server:
  tunnelDomain: tunnels.example.net
  # The ingress controller's pods. The pod CIDR (10.42.0.0/16 on k3s,
  # 10.244.0.0/16 on kubeadm and kind) only together with the network
  # policy below; see "Trusted proxies".
  trustedProxies: 10.42.0.0/16

web:
  host: dash.example.com
  adminEmails: you@example.com
  # allowedEmailDomains: example.com    # empty lets anyone register
  ingress:
    tls:
      clusterIssuer: letsencrypt-prod   # any issuer that can do HTTP-01
  mail:
    mailer: smtp
    host: smtp.example.com
    port: "587"
    username: noreply@example.com

ingress:
  className: nginx
  certificate:
    mode: issuer
    issuer:
      email: you@example.com
      solvers:
        - dns01:
            cloudflare:
              apiTokenSecretRef:
                name: cloudflare-api-token
                key: api-token

networkPolicy:
  type: kubernetes                      # the default
  ingressNamespace: ingress-nginx       # where your ingress controller runs

secrets:
  existingSecret: exposehub-secrets
```

### Trusted proxies

`server.trustedProxies` matters: the server and the dashboard believe `X-Forwarded-For` only from these addresses. Set it too narrow and every visitor seems to come from the ingress controller, so access rules by network and the addresses in request logs stop meaning anything. Set it too wide and a pod inside the range that reaches the server or the dashboard directly, not through the ingress, can name any address as the visitor's: it passes network rules meant for a VPN and gets around login limits.

Trust only the ingress controller. Ingress pods change their addresses, so the practical choices are:

- The pod CIDR, together with `networkPolicy.type: kubernetes` (the default) or `cilium`. The policies let only pods in `networkPolicy.ingressNamespace` reach the server and the dashboard, so the other pods inside the range cannot send them anything.
- With `networkPolicy.type: none`: the ingress controller's own addresses only, for example the node addresses when it runs on the host network, or a fixed address your CNI gives its pods. Never the pod CIDR: then every pod in the cluster can claim any visitor address.

`networkPolicy.ingressNamespace` is required unless you set `networkPolicy.type: none` on purpose.

Other values worth knowing, all documented in the chart's `values.yaml`:

| Value | Default | Meaning |
|---|---|---|
| `ingress.certificate.mode` | `issuer` | `issuer` (the chart creates a namespaced Issuer with your solvers), `clusterIssuer` (an existing one that can do DNS-01), `secret` (you provide the TLS secret) |
| `web.customDomains.enabled` | `false` | Teams can connect their own domains; see below |
| `postgres.enabled` | `true` | `false` uses `externalDatabase.*` instead |
| `networkPolicy.type` | `kubernetes` | `cilium`, or `none`; see below |
| `web.allowedEmailDomains` | empty | Limit registration to these email domains |
| `web.logRetentionDays` | `7` | How long request logs are kept |
| `web.sso.issuer`, `web.sso.clientId`, `web.sso.domains`, `secrets.ssoClientSecret` | empty | Single sign-on over OpenID Connect: on once all four are set. Accounts of those email domains then sign in through the provider only. `web.sso.name`, `tokenDays` and `requireVerifiedEmail` are optional; [admin.md](../admin.md) explains them |
| `web.sso.providerHosts` | empty | Only with `networkPolicy.type: cilium`: the hosts of identity providers the dashboard may call, the teams' providers included. See [Single sign-on](#single-sign-on) |
| `web.demo.enabled` | `false` | Offers "Try the demo" on the landing page: visitors without an account, wiped every `web.demo.resetMinutes`. Read [admin.md](../admin.md) first; `server.demoMaxTunnels` and `server.demoRequestsPerMinute` limit its tunnels |
| `server.additionalTunnelDomains` | `[]` | More domains tunnels can use; each needs its own wildcard record |
| `web.sourceUrl` | upstream | Where the dashboard's source link points. Point it at your fork if you run a changed version, so people can see what the install runs. |
| `legal.imprint`, `legal.privacy` | empty | Markdown for `/imprint` and `/privacy`; templates are in `web/resources/legal` |

## Install

```sh
helm install exposehub oci://ghcr.io/jkbennemann/charts/exposehub \
  --version 0.3.1 --namespace exposehub -f values.yaml
kubectl -n exposehub get certificate,pods
```

Certificates take a minute or two once DNS resolves. Then:

1. Register at `https://dash.example.com/register` with the address in `web.adminEmails`. With the `log` mailer, the verification link is in `kubectl -n exposehub logs deploy/exposehub-web -c web`.
2. Create an agent token in the dashboard.
3. On a developer machine:

   ```sh
   curl -fsSL https://connect.tunnels.example.net/install.sh | sh
   exposehub login
   exposehub http 8000
   ```

## Teams' own domains

With `web.customDomains.enabled: true`, a team can connect a domain like `dev.example.org`. Once its DNS record is verified, the dashboard creates an Ingress for it in the release's namespace, routed to the tunnel server, and cert-manager issues its certificate through `web.customDomains.clusterIssuer` (default: `web.ingress.tls.clusterIssuer`). That issuer must solve HTTP-01, since the team's DNS is not yours. The chart adds a ServiceAccount whose Role may only get, create, patch and delete Ingress objects in that namespace. The Ingress is named `<fullname>-domain-<id>` (with the release `exposehub`: `exposehub-domain-<id>`) and labelled `exposehub/instance: <fullname>`, and the dashboard changes or deletes only Ingresses that carry its own label, so two releases in one namespace leave each other's alone. `ingress.className` must be set, because the dashboard names it on every Ingress it creates.

## Single sign-on

[admin.md](../admin.md#single-sign-on) describes what single sign-on does and how to set up a provider, for the whole install (`web.sso` and `secrets.ssoClientSecret`) or for one team (entered in the dashboard, no values needed). This section is about the one thing the cluster has to allow.

**The dashboard calls the provider itself.** At every sign-in the dashboard pod fetches the provider's discovery document, exchanges the code for a token and reads the signing keys, all over HTTPS on port 443. The person's browser reaching the provider is not enough.

What that needs depends on `networkPolicy.type`:

- `kubernetes` (the default) and `none`: nothing. The dashboard's outbound traffic is open.
- `cilium`: the dashboard reaches named hosts only. The host of `web.sso.issuer` is opened by itself. Every other host goes into `web.sso.providerHosts`:

  ```yaml
  web:
    sso:
      providerHosts:
        - example.okta.com            # Okta: one host
        - login.microsoftonline.com   # Entra ID: one host
        - accounts.google.com         # Google: the issuer ...
        - "*.googleapis.com"          # ... and its token and key hosts; "*" matches within a name
  ```

  List the issuer's host of each team's provider you approve, and any further host a provider's discovery document names for its token endpoint or keys (`https://<issuer>/.well-known/openid-configuration` shows them; Okta uses one host, Google several). Then `helm upgrade`. A team's owner cannot do this step, so it belongs to approving a provider.

Egress rules of your own (another network plugin's policies, an egress gateway, a firewall in front of the nodes) need the same hosts opened.

**When a host is missing**, signing in ends on the login page with "Signing in did not work", and the dashboard's log has a line like this:

```
Single sign-on failed: discovery unreachable: cURL error 28: Connection timed out … for https://example.okta.com/.well-known/openid-configuration
```

To check from inside the pod, with your provider's issuer:

```sh
kubectl -n exposehub exec deploy/exposehub-web -c web -- php -r \
  'echo @file_get_contents("https://example.okta.com/.well-known/openid-configuration") === false ? "unreachable" : "reachable", "\n";'
```

## Network policies

`networkPolicy.type` is `kubernetes` by default, because the server and the dashboard believe forwarded addresses from `server.trustedProxies` and should be reachable only through the ingress controller (see [Trusted proxies](#trusted-proxies)). It needs `networkPolicy.ingressNamespace`. No single policy is right for every cluster: an ingress controller on the host network, for example, needs `networkPolicy.extraIngressFrom`, and a wrong policy shows up as a dashboard that cannot be reached. The policies only take effect if the cluster's network plugin enforces them (k3s, Calico and Cilium do; plain Flannel does not).

- `kubernetes`: standard NetworkPolicy. The server is reachable only from `networkPolicy.ingressNamespace` and may only reach the dashboard and DNS. The dashboard is reachable from the ingress controller and the server. Postgres is reachable only from the dashboard. The dashboard's outbound traffic stays open, because mail, the breached-password check and the Kubernetes API cannot be named by a standard policy. Add `networkPolicy.extraIngressFrom` when the ingress controller uses the host network.
- `cilium`: the same rules as CiliumNetworkPolicy, plus outbound traffic from the dashboard limited to Postgres, DNS, the breached-password service and the identity providers by name, the API server (custom domains) and the SMTP port. For single sign-on the dashboard calls the provider itself (discovery, the token exchange, the signing keys). The host of `web.sso.issuer` is opened by itself; every other host goes into `web.sso.providerHosts`: further hosts the provider's discovery document names, and the hosts of each team's provider an admin approves. See [Single sign-on](#single-sign-on).
- `none`: no policies. Every pod in the cluster can reach the server and the dashboard, so `server.trustedProxies` must then name only the ingress controller's own addresses.

## HSTS

The chart does not set `Strict-Transport-Security` itself, because the header belongs to whoever terminates TLS. Make sure the ingress controller sends it for the dashboard host, for example `max-age=31536000`. ingress-nginx does so by default (its ConfigMap keys `hsts`, `hsts-max-age`, `hsts-include-subdomains`). With Traefik, create a `headers` Middleware with `stsSeconds: 31536000` and reference it in `web.ingress.annotations` (`traefik.ingress.kubernetes.io/router.middlewares: <namespace>-<middleware>@kubernetescrd`). Without `includeSubDomains` is the safer choice: names under the dashboard's host are not necessarily served by this install.

## Upgrading

The chart, the images and the agent share one version. Read the release notes, then:

```sh
helm upgrade exposehub oci://ghcr.io/jkbennemann/charts/exposehub \
  --version 0.3.1 --namespace exposehub -f values.yaml
```

Database migrations run in an init container before the new dashboard serves a request. The tunnel server restarts with the upgrade; agents reconnect by themselves within a few seconds and keep their addresses. Installed agents pick up the new version with `exposehub update`.

Upgrading from a chart that defaulted to `networkPolicy.type: none`: set `networkPolicy.ingressNamespace` (or `networkPolicy.type: none` explicitly) first, or the upgrade stops with a message saying so.

Never change the release name or `fullnameOverride` of an existing install: the database volume is named `data-<release>-postgres-0`, and a new name means a new, empty volume.

## Backup

Everything worth keeping is in Postgres, plus your values file and secrets. A dump:

```sh
kubectl -n exposehub exec exposehub-postgres-0 -- \
  pg_dump -U exposehub -d exposehub --format=custom > exposehub-$(date +%F).dump
```

Restore into a fresh install (dashboard scaled to zero first, so nothing writes meanwhile):

```sh
kubectl -n exposehub scale deploy/exposehub-web --replicas=0
kubectl -n exposehub exec -i exposehub-postgres-0 -- \
  pg_restore -U exposehub -d exposehub --clean --if-exists < exposehub-2026-01-31.dump
kubectl -n exposehub scale deploy/exposehub-web --replicas=1
```

Keep the secret with `APP_KEY` alongside the dump: without the same key, two-factor secrets in the dump cannot be read.

## Limits

- The tunnel server runs as one pod. Its tunnel registry lives in memory, so a second replica would send visitors to a pod that does not hold their tunnel.
- Tokens are mirrored from the dashboard into the server pod, so agents keep working while the dashboard restarts. A server pod that starts while the dashboard is down knows no tokens until the dashboard is back.
- A dashboard outage longer than `server.controlPlaneMaxStale` (default `5m`) closes the tunnels opened with dashboard tokens, and the server refuses those tokens until the dashboard answers again; otherwise a token revoked in the meantime would keep working. The agents reconnect by themselves once it is back. Static tokens keep working. The admin overview shows when this happens. `"0"` keeps the last list forever.
- One person owns at most 10 teams and saves at most 25 addresses; one team has at most 25 networks and 25 own domains. One IP address can sign up 5 times an hour, and password-reset mails are limited to 10 an hour per IP address and 3 per email address. `web.limits` changes them. Everyone behind one shared office address counts as one IP address for sign-ups: raise `web.limits.registrationsPerIpPerHour` if many people there sign up at once.
- Per account, a signed-in session may enter 5 wrong passwords a minute (password confirmation, account deletion, password change), and a sign-in may try 5 two-factor codes a minute and 20 a day. After 5 wrong codes in a row the sign-in starts over with the password and the account holder gets a mail. `web.limits.passwordChecksPerMinute`, `twoFactorAttemptsPerMinute`, `twoFactorAttemptsPerDay` and `twoFactorFailuresInARow` change them; each must be at least 1.
- The tunnel server's certificate-name budget (`EXPOSEHUB_TLS_ASK_NAME_LIMIT`, `EXPOSEHUB_TLS_ASK_TOTAL_LIMIT`) belongs to Caddy's on-demand certificates in the Compose install. The chart uses one wildcard certificate, so it does not apply here.
