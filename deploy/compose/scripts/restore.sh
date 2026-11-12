#!/usr/bin/env bash
# Restores a backup written by scripts/backup.sh.
#
#   scripts/restore.sh ARCHIVE            restore the database
#   scripts/restore.sh ARCHIVE --config   also restore .env, caddy.env and legal/
#
# On a new server: clone the same release, then run this with --config
# (no ./install.sh needed before; run it afterwards to check DNS and pull).
# The current .env and caddy.env are kept as *.before-restore when replaced.
#
# The database is replaced as a whole: everything since the backup is lost.

set -euo pipefail
cd "$(dirname "$0")/.."

archive=${1:-}
config=0
[ "${2:-}" = --config ] && config=1
if [ -z "$archive" ] || [ ! -f "$archive" ]; then
    echo "usage: scripts/restore.sh ARCHIVE [--config]" >&2
    exit 2
fi

umask 077
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
tar -xzf "$archive" -C "$work"
[ -f "$work/env" ] || { echo "error: $archive is not an exposehub backup (no env inside)" >&2; exit 1; }

value() { grep -E "^$1=" "$2" | tail -n1 | cut -d= -f2- | tr -d "'\""; }

if [ "$config" = 1 ] || [ ! -f .env ]; then
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    [ -f .env ] && cp .env ".env.before-restore-$stamp"
    [ -f caddy.env ] && cp caddy.env "caddy.env.before-restore-$stamp"
    cp "$work/env" .env
    [ -f "$work/caddy.env" ] && cp "$work/caddy.env" caddy.env
    cp "$work"/legal/*.md legal/ 2>/dev/null || true
    echo "restored .env, caddy.env and legal/"
elif [ "$(value APP_KEY .env)" != "$(value APP_KEY "$work/env")" ]; then
    echo "error: the backup was made with another APP_KEY than the one in .env." >&2
    echo "Two-factor secrets and other encrypted values in the database only work with" >&2
    echo "the backup's key. Run again with --config to restore its .env as well." >&2
    exit 1
fi

if [ ! -f "$work/database.dump" ]; then
    echo "the backup holds no database (tunnels-only install); done"
    exit 0
fi

echo "stopping the dashboard"
docker compose stop web scheduler >/dev/null 2>&1 || true
docker compose up -d --wait postgres

# The volume may have been created with another password than the restored
# .env holds; align it over the container's local socket.
password=$(value DB_PASSWORD .env)
docker compose exec -T postgres psql -q -U exposehub -d exposehub -v ON_ERROR_STOP=1 \
    -v pw="$password" <<'SQL'
ALTER USER exposehub PASSWORD :'pw';
SQL

echo "restoring the database"
docker compose exec -T postgres pg_restore -U exposehub -d exposehub --clean --if-exists --no-owner --exit-on-error \
    < "$work/database.dump"

echo "starting everything"
docker compose up -d --wait
echo "restored from $archive"
