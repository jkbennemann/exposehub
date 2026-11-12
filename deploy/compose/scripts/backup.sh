#!/usr/bin/env bash
# Writes a backup of a Docker Compose install to backups/ (or the directory
# given as the first argument): the dashboard's database, .env with the
# secrets, caddy.env with the DNS credentials, and the legal texts.
#
#   scripts/backup.sh [DIRECTORY]
#
# Certificates are not included; Caddy obtains new ones after a restore.
# The archive holds every secret of the install, so it is readable by its
# owner only. Keep it somewhere else than this server.

set -euo pipefail
cd "$(dirname "$0")/.."

dir=${1:-backups}
umask 077
mkdir -p "$dir"

[ -f .env ] || { echo "error: no .env here; is this an installed exposehub/deploy/compose directory?" >&2; exit 1; }

stamp=$(date -u +%Y%m%dT%H%M%SZ)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

cp .env "$work/env"
[ -f caddy.env ] && cp caddy.env "$work/caddy.env"
mkdir -p "$work/legal"
cp legal/*.md "$work/legal/" 2>/dev/null || true

profiles=$(grep -E '^COMPOSE_PROFILES=' .env | tail -n1 | cut -d= -f2- | tr -d "'\"")
case ",$profiles," in
    *,dashboard,*)
        if ! docker compose ps --status running --services | grep -qx postgres; then
            echo "error: the postgres container is not running; start it with: docker compose up -d postgres" >&2
            exit 1
        fi
        # Custom format: compressed, and pg_restore can restore it selectively.
        docker compose exec -T postgres pg_dump -U exposehub -d exposehub --format=custom > "$work/database.dump"
        ;;
    *) echo "tunnels-only install: no database to back up" ;;
esac

archive="$dir/exposehub-$stamp.tar.gz"
tar -czf "$archive" -C "$work" .
echo "wrote $archive ($(du -h "$archive" | cut -f1))"
