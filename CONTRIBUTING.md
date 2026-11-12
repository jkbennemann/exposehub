# Contributing

Thanks for wanting to help. Bug reports, fixes, documentation and features are all welcome. For anything bigger than a small fix, open an issue first so we can agree on the approach before you spend time on it.

Security problems go through [SECURITY.md](SECURITY.md), not public issues.

## Contributor licence agreement

Before your first pull request can be merged, you sign the [Contributor License Agreement](CLA.md) once. exposehub is source-available under the Functional Source License (FSL-1.1-ALv2), and the maintainer may offer exposehub as a hosted service or under other licences; the CLA gives the maintainer the rights to do that with contributed code. You keep the copyright to your work.

Signing takes one comment. When you open a pull request, the CLA bot asks you to sign; reply on the pull request with exactly:

```
I have read the CLA Document and I hereby sign the CLA
```

The signature covers all your later contributions. If your employer may own what you write, get their permission first (section 4 of the CLA). If the check stays red after signing, comment `recheck`.

## Layout

- `tunnel/` — Go module with the tunnel server (`cmd/exposehub-server`) and the agent (`cmd/exposehub`)
- `web/` — the Laravel dashboard
- `deploy/compose/` — the Docker Compose install with `install.sh`
- `deploy/helm/exposehub/` — the Helm chart
- `deploy/caddy/` — the Caddy image used by the Compose install

## Tunnel server and agent

You need Go 1.26 or newer.

```sh
cd tunnel
go build -o bin/ ./cmd/...
go test -race ./...
go test -run '^$' -bench . ./internal/e2e   # benchmarks
```

The README's *Try it locally* section runs a server, an agent and a local service together on `*.localhost`, with no DNS setup.

Code is formatted with `gofmt` and must pass `go vet`; CI checks both.

## Dashboard

You need PHP 8.5, Composer 2, Node 22 and pnpm (`corepack enable`).

```sh
cd web
composer setup   # installs dependencies, creates .env and an SQLite database, builds assets
composer dev     # starts the development servers
composer test    # Pint, PHPStan and the Pest tests, as CI runs them
composer lint    # fixes code style
```

To work against a local tunnel server, set `EXPOSEHUB_NODE_SECRET` in `web/.env` and start the server with `EXPOSEHUB_CONTROL_PLANE_URL` pointing at the dashboard and the same `EXPOSEHUB_NODE_SECRET`.

## Pull requests

- Keep one change per pull request, with tests for behaviour you add or fix.
- Update the README or the docs when behaviour or configuration changes, and add a line under *Unreleased* in [CHANGELOG.md](CHANGELOG.md).
- Nothing about one particular install (domains, addresses, names) belongs in the code; everything an operator decides is configuration.
- Write commit messages that say what the change does and why.

## Releases

All parts share one version, kept in the root `VERSION` file. A maintainer releases by:

1. moving the *Unreleased* entries in `CHANGELOG.md` under a new version heading,
2. setting `VERSION` and the Compose default image tag to it,
3. tagging `vX.Y.Z` and pushing the tag.

The release workflow then checks that the tag matches `VERSION`, runs the tests, publishes the server, dashboard and Caddy images for amd64 and arm64 to `ghcr.io/jkbennemann/exposehub-*`, attaches the agent binaries to the GitHub release and pushes the Helm chart to `oci://ghcr.io/jkbennemann/charts/exposehub`.
