# syntax=docker/dockerfile:1
# Caddy for the Docker Compose install: the edge proxy that terminates TLS
# for the dashboard, the tunnel domain and teams' own domains. It is the
# stock Caddy plus DNS-provider modules, so a wildcard certificate for the
# tunnel domain can be obtained over the ACME DNS challenge.
#
# Build from the repository root or from this directory:
#   docker buildx build --platform linux/arm64,linux/amd64 \
#     -t <registry>/exposehub-caddy:<version> deploy/caddy
#
# A provider is added by adding its module below and a snippet in
# deploy/compose/caddy/dns/. Only modules from github.com/caddy-dns that are
# maintained and build against this Caddy version belong here.
#
# Every module carries an exact version. Without one, xcaddy fetches
# whatever the module's publisher released last at the moment the image is
# built, and that code would ship in a signed release without anyone here
# having seen it. Nothing tracks these versions automatically: raise one by
# hand after reading what changed (`go list -m -versions <module>` lists the
# releases). digitalocean has no releases, so it is pinned to a commit.
# Their own dependencies (libdns and the providers' API clients) follow
# from these versions and Caddy's, so they are fixed as well.

ARG CADDY_VERSION=2.11.9

# xcaddy cross-compiles, so a multi-platform build compiles on the build
# machine's own architecture instead of under emulation. The builder image is
# pinned by digest because it brings xcaddy, which resolves the modules. Raise
# its tag together with CADDY_VERSION.
FROM --platform=$BUILDPLATFORM caddy:2.11.9-builder-alpine@sha256:646fee578e680989ca5c7fd1ce6f1de574d19fd71ef2221810cc0510a9f40c86 AS build
ARG CADDY_VERSION
ARG TARGETOS
ARG TARGETARCH
# XCADDY_SETCAP=0: the builder image would give the binary a file capability.
RUN GOOS=$TARGETOS GOARCH=$TARGETARCH CGO_ENABLED=0 XCADDY_SETCAP=0 xcaddy build "v${CADDY_VERSION}" \
        --output /out/caddy \
        --with github.com/caddy-dns/cloudflare@v0.2.4 \
        --with github.com/caddy-dns/hetzner/v2@v2.0.1 \
        --with github.com/caddy-dns/route53@v1.6.2 \
        --with github.com/caddy-dns/digitalocean@v0.0.0-20250606074528-04bde2867106 \
        --with github.com/caddy-dns/ovh@v1.1.0 \
        --with github.com/caddy-dns/gandi@v1.2.1 \
        --with github.com/caddy-dns/porkbun@v0.3.1 \
        --with github.com/caddy-dns/desec@v1.1.0 \
        --with github.com/caddy-dns/ionos@v1.3.0 \
        --with github.com/caddy-dns/netcup@v1.0.0

# The official image copies a binary that carries the cap_net_bind_service
# file capability. A binary with file capabilities cannot start at all in a
# container that drops every capability and forbids privilege escalation,
# which is how the Compose file runs it. Docker lets unprivileged processes
# bind ports below 1024 inside a container anyway
# (net.ipv4.ip_unprivileged_port_start=0), so this binary carries none and
# runs as an ordinary user.
FROM alpine:3.24
RUN apk add --no-cache ca-certificates mailcap tzdata \
    && addgroup -S -g 1000 caddy \
    && adduser -S -D -H -u 1000 -G caddy -s /sbin/nologin caddy \
    && mkdir -p /config/caddy /data/caddy /etc/caddy \
    && chown -R caddy:caddy /config /data
COPY --from=build /out/caddy /usr/bin/caddy
ENV XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data
USER caddy
EXPOSE 80 443 443/udp
WORKDIR /srv
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:2019/config/ || exit 1
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
