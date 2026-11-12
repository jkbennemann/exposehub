# syntax=docker/dockerfile:1
# Image of the tunnel server, which also hands out the agent. Build from the
# tunnel/ directory, naming the version both get:
#   docker buildx build --platform linux/arm64,linux/amd64 \
#     --build-arg VERSION=<version> \
#     --build-arg DEFAULT_SERVER=https://connect.example.com \
#     -t <registry>/exposehub-server:<version> .
#
# DEFAULT_SERVER is the server the agent uses when none is given or saved.
# Set it only for an image made for one install. Agents installed with this
# server's install.sh save the server anyway, so it mainly helps people who
# download the Windows build by hand.

FROM --platform=$BUILDPLATFORM golang:1.27-alpine AS build
ARG TARGETOS
ARG TARGETARCH
ARG VERSION=dev
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=$TARGETOS GOARCH=$TARGETARCH \
    go build -trimpath -ldflags "-s -w -X main.version=$VERSION" -o /out/exposehub-server ./cmd/exposehub-server

# The agent for every platform it supports, plus latest.json naming each
# build and its SHA-256. The server serves them at /install.sh and /agent/.
# Nothing here depends on the image's own platform, so a multi-platform build
# compiles the agents once.
FROM --platform=$BUILDPLATFORM golang:1.27-alpine AS agents
ARG VERSION=dev
ARG DEFAULT_SERVER=""
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN set -eu; mkdir -p /agent; files=""; \
    for target in darwin/arm64 darwin/amd64 linux/amd64 linux/arm64 windows/amd64 windows/arm64; do \
        os="${target%/*}"; arch="${target#*/}"; ext=""; \
        if [ "$os" = windows ]; then ext=".exe"; fi; \
        name="exposehub-$os-$arch$ext"; \
        CGO_ENABLED=0 GOOS="$os" GOARCH="$arch" \
            go build -trimpath -ldflags "-s -w -X main.version=$VERSION -X main.defaultServer=$DEFAULT_SERVER" -o "/agent/$name" ./cmd/exposehub; \
        sum="$(sha256sum "/agent/$name" | cut -d ' ' -f 1)"; \
        files="$files${files:+,}\"$os-$arch\":{\"name\":\"$name\",\"sha256\":\"$sum\"}"; \
    done; \
    printf '{"version":"%s","files":{%s}}\n' "$VERSION" "$files" > /agent/latest.json

# Static binary, no shell, runs as an unprivileged user.
FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/exposehub-server /exposehub-server
COPY --from=agents /agent /agent
ENV EXPOSEHUB_AGENT_DOWNLOADS=/agent
EXPOSE 8080
# The image has no shell or curl, so the server checks itself: /healthz on
# EXPOSEHUB_INTERNAL_LISTEN when set, otherwise any answer on the main listener.
HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD ["/exposehub-server", "healthcheck"]
ENTRYPOINT ["/exposehub-server"]
