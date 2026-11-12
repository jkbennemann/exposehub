# syntax=docker/dockerfile:1
# Image of the dashboard. Build from the web/ directory, naming the version it
# shows in its footer (release builds use the same version for every image):
#   docker buildx build --platform linux/arm64,linux/amd64 \
#     --build-arg VERSION=<version> -t <registry>/exposehub-web:<version> .
#
# It serves plain HTTP on port 8080 and expects a reverse proxy in front for
# TLS. The filesystem can be mounted read-only except for /app/storage,
# /app/bootstrap/cache, /data, /config and /tmp.

# PHP dependencies. The output is the same on every architecture, so this
# stage runs on the build machine's own.
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# Front-end assets. The stylesheet imports Flux from vendor/, so it needs the PHP dependencies.
FROM --platform=$BUILDPLATFORM node:25-alpine AS assets
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml .npmrc ./
RUN pnpm install --frozen-lockfile
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN pnpm run build

FROM dunglas/frankenphp:1-php8.5
ARG VERSION=dev
RUN install-php-extensions pdo_pgsql intl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    # The base image lets the binary bind ports below 1024 through a file
    # capability. This image listens on 8080, and a binary with file
    # capabilities cannot be started at all once a container drops every
    # capability and forbids privilege escalation, as hardened clusters do.
    && setcap -r /usr/local/bin/frankenphp
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh
ENV APP_ENV=production \
    APP_DEBUG=false \
    EXPOSEHUB_VERSION=$VERSION \
    LOG_CHANNEL=stderr \
    XDG_DATA_HOME=/data \
    XDG_CONFIG_HOME=/config
USER www-data
EXPOSE 8080
# The base image's own check asks Caddy's admin API, which the Caddyfile turns
# off. /up is Laravel's health route and needs neither a session nor a login.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/app/docker/Caddyfile", "--adapter", "caddyfile"]
