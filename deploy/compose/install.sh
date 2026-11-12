#!/usr/bin/env bash
# Installs or upgrades exposehub with Docker Compose.
#
#   ./install.sh                      asks for what it needs, writes .env
#   ./install.sh --non-interactive    takes the values from the environment
#   docker compose up -d --wait       then starts everything
#
# Rerunning it is the upgrade path: it keeps every value and secret already
# in .env, adds settings a newer release introduced, checks DNS again and
# pulls the images of the checked-out release. It never starts or stops
# containers itself.
#
# Settings it asks for, also accepted as environment variables:
#   EXPOSEHUB_STACK                 full (default) or tunnels
#   EXPOSEHUB_DASHBOARD_HOST        e.g. exposehub.example.com (full stack)
#   EXPOSEHUB_TUNNEL_DOMAIN         e.g. example-tunnels.net
#   EXPOSEHUB_ADMIN_EMAILS          platform owners (full stack)
#   EXPOSEHUB_ALLOWED_EMAIL_DOMAINS who may register; empty lets anyone
#   EXPOSEHUB_TLS_MODE              dns, on-demand or internal
#   EXPOSEHUB_DNS_PROVIDER          with dns; plus the provider's credentials
#   EXPOSEHUB_ACME_EMAIL            defaults to the first admin address
#   MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS
#   EXPOSEHUB_PUBLIC_IP             this server's public IPv4, if it cannot
#                                   be read from the network configuration

set -euo pipefail

MIN_DOCKER=24.0.0
MIN_COMPOSE=2.24.0
PROVIDERS="cloudflare hetzner route53 digitalocean ovh gandi porkbun desec ionos netcup"

cd "$(dirname "$0")"

interactive=1
reconfigure=0
skip_dns=0
skip_pull=0

usage() {
    cat <<'EOF'
Usage: ./install.sh [options]

  --non-interactive   ask nothing; take settings from the environment and .env
  --reconfigure       ask every question again, with the current values as defaults
  --skip-dns-check    do not check that the DNS records point at this server
  --skip-pull         do not pull the images
  -h, --help          show this help

See docs/self-hosting/compose.md for the settings and what they mean.
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --non-interactive) interactive=0 ;;
        --reconfigure) reconfigure=1 ;;
        --skip-dns-check) skip_dns=1 ;;
        --skip-pull) skip_pull=1 ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
    shift
done

# ----------------------------------------------------------------- output

if [ -t 1 ]; then
    bold=$(printf '\033[1m'); dim=$(printf '\033[2m'); red=$(printf '\033[31m')
    yellow=$(printf '\033[33m'); green=$(printf '\033[32m'); reset=$(printf '\033[0m')
else
    bold=""; dim=""; red=""; yellow=""; green=""; reset=""
fi

step() { printf '\n%s▸ %s%s\n' "$bold" "$*" "$reset"; }
ok() { printf '  %s✓%s %s\n' "$green" "$reset" "$*"; }
note() { printf '  %s%s%s\n' "$dim" "$*" "$reset"; }
warn() { printf '  %s! %s%s\n' "$yellow" "$*" "$reset" >&2; warnings=$((warnings + 1)); }
die() { printf '\n%serror:%s %s\n' "$red" "$reset" "$*" >&2; exit 1; }
warnings=0

if [ "$interactive" = 1 ] && [ ! -t 0 ]; then
    die "standard input is not a terminal. Run ./install.sh in a terminal, or pass --non-interactive with the settings in the environment."
fi

# ------------------------------------------------------------------ helpers

# version_ge A B: whether version A is B or newer.
version_ge() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -t. -k1,1n -k2,2n -k3,3n | head -n1)" = "$2" ]
}

rand_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }

# env_get KEY [FILE]: the value of KEY in FILE (default .env), unquoted.
env_get() {
    local file=${2:-.env} line
    [ -f "$file" ] || return 0
    line=$(grep -E "^$1=" "$file" | tail -n1 || true)
    line=${line#*=}
    case "$line" in
        \'*\' | \"*\") line=${line:1:${#line}-2} ;;
    esac
    printf '%s' "$line"
}

# env_has KEY [FILE]: whether FILE sets KEY (possibly to nothing).
env_has() { [ -f "${2:-.env}" ] && grep -qE "^$1=" "${2:-.env}"; }

# env_set KEY VALUE [FILE]: sets KEY in FILE, replacing an existing line.
# Values with characters Compose would interpret are single-quoted, which
# Compose reads literally.
env_set() {
    local key=$1 value=$2 file=${3:-.env} tmp
    case "$value" in
        *"'"* | *$'\n'*) die "$key cannot contain a single quote or a line break." ;;
    esac
    if [ -n "$value" ] && ! printf '%s' "$value" | grep -qE '^[A-Za-z0-9_./:@,+=-]+$'; then
        value="'$value'"
    fi
    tmp=$(mktemp "$file.XXXXXX")
    K="$key" V="$value" awk '
        index($0, ENVIRON["K"] "=") == 1 { if (!done) print ENVIRON["K"] "=" ENVIRON["V"]; done = 1; next }
        { print }
        END { if (!done) print ENVIRON["K"] "=" ENVIRON["V"] }
    ' "$file" > "$tmp"
    chmod 600 "$tmp"
    mv "$tmp" "$file"
}

# current KEY [FILE]: the environment's value if set, else the file's.
current() {
    if [ -n "${!1+x}" ]; then printf '%s' "${!1}"; else env_get "$1" "${2:-.env}"; fi
}

# ask KEY QUESTION [DEFAULT] [secret]: sets KEY in .env. Interactive runs ask
# on a first install, with --reconfigure, or when the value is missing;
# otherwise the current value stays.
ask() {
    local key=$1 question=$2 default=${3:-} secret=${4:-} value
    value=$(current "$key")
    if [ "$interactive" = 1 ] && { [ "$fresh" = 1 ] || [ "$reconfigure" = 1 ] || [ -z "$value" ]; }; then
        [ -n "$value" ] && default=$value
        if [ "$secret" = secret ]; then
            local shown=""
            [ -n "$default" ] && shown=" [keep current]"
            read -r -s -p "  $question$shown: " value; echo
        else
            local shown=""
            [ -n "$default" ] && shown=" [$default]"
            read -r -p "  $question$shown: " value
        fi
        value=${value:-$default}
    else
        value=${value:-$default}
    fi
    env_set "$key" "$value"
}

# require KEY DESCRIPTION [check]: fails unless KEY is set and passes check.
require() {
    local value
    value=$(env_get "$1")
    [ -n "$value" ] || die "$1 is not set: $2. Set it in the environment or in .env, or run ./install.sh without --non-interactive."
    if [ -n "${3:-}" ] && ! "$3" "$value"; then
        die "$1 is not valid: '$value' ($2)."
    fi
}

is_hostname() {
    printf '%s' "$1" | grep -qE '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$'
}
is_emails() {
    local e
    for e in ${1//,/ }; do
        printf '%s' "$e" | grep -qE '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$' || return 1
    done
}
# Let's Encrypt refuses an account whose contact address has no public domain,
# and Caddy would then retry for minutes with every certificate missing.
is_acme_email() {
    is_emails "$1" || return 1
    case "${1##*@}" in
        *.example | *.test | *.invalid | *.localhost | *.local | *.internal | \
            example.com | example.net | example.org | localhost) return 1 ;;
    esac
    [[ $1 != *,* ]]
}
is_tls_mode() { case "$1" in dns | on-demand | internal) return 0 ;; esac; return 1; }
is_stack() { case "$1" in full | tunnels) return 0 ;; esac; return 1; }
is_provider() { case " $PROVIDERS " in *" $1 "*) return 0 ;; esac; return 1; }

# The variables each DNS provider's snippet in caddy/dns/ reads.
provider_vars() {
    case "$1" in
        cloudflare) echo CLOUDFLARE_API_TOKEN ;;
        hetzner) echo HETZNER_API_TOKEN ;;
        route53) echo AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_REGION ;;
        digitalocean) echo DIGITALOCEAN_API_TOKEN ;;
        ovh) echo OVH_ENDPOINT OVH_APPLICATION_KEY OVH_APPLICATION_SECRET OVH_CONSUMER_KEY ;;
        gandi) echo GANDI_BEARER_TOKEN ;;
        porkbun) echo PORKBUN_API_KEY PORKBUN_API_SECRET_KEY ;;
        desec) echo DESEC_TOKEN ;;
        ionos) echo IONOS_API_TOKEN ;;
        netcup) echo NETCUP_CUSTOMER_NUMBER NETCUP_API_KEY NETCUP_API_PASSWORD ;;
    esac
}

# ---------------------------------------------------------------- preflight

step "Checking this host"

command -v docker >/dev/null 2>&1 || die "Docker is not installed. See https://docs.docker.com/engine/install/"
if ! docker info >/dev/null 2>&1; then
    die "cannot reach the Docker daemon. Is it running, and may this user use it (sudo, or membership in the docker group)?"
fi
docker_version=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo 0)
version_ge "${docker_version%%[-+]*}" "$MIN_DOCKER" || die "Docker $docker_version is too old; $MIN_DOCKER or newer is needed."
ok "Docker $docker_version"

compose_version=$(docker compose version --short 2>/dev/null || true)
compose_version=${compose_version#v}
[ -n "$compose_version" ] || die "the Docker Compose plugin is missing (docker compose). See https://docs.docker.com/compose/install/linux/"
version_ge "${compose_version%%[-+]*}" "$MIN_COMPOSE" || die "Docker Compose $compose_version is too old; $MIN_COMPOSE or newer is needed."
ok "Docker Compose $compose_version"

case "$(uname -m)" in
    x86_64 | amd64 | aarch64 | arm64) ok "architecture $(uname -m)" ;;
    *) die "the images exist for amd64 and arm64 only, not $(uname -m)." ;;
esac

for tool in awk grep od; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is needed but not installed."
done

# ----------------------------------------------------------------- settings

umask 077
fresh=0
if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    fresh=1
    step "Writing .env"
else
    step "Updating .env (current values are kept)"
    # Settings a newer release added.
    added=""
    while IFS= read -r line; do
        key=${line%%=*}
        env_has "$key" || added="$added$line"$'\n'
    done < <(grep -E '^[A-Z_][A-Z0-9_]*=' .env.example)
    if [ -n "$added" ]; then
        printf '\n# Added by install.sh on %s; see .env.example.\n%s' "$(date +%F)" "$added" >> .env
        note "added new settings: $(printf '%s' "$added" | cut -d= -f1 | tr '\n' ' ')"
    fi
    if grep -qE '^EXPOSEHUB_VERSION=' .env; then
        warn "EXPOSEHUB_VERSION is set in .env ($(env_get EXPOSEHUB_VERSION)), so the images stay at that version whichever release is checked out. Remove the line to follow the checkout."
    fi
fi
[ -f caddy.env ] || { : > caddy.env; chmod 600 caddy.env; }

# Any setting of .env.example given in the environment wins over .env.
while IFS= read -r key; do
    if [ -n "${!key+x}" ]; then env_set "$key" "${!key}"; fi
done < <(grep -oE '^[A-Z_][A-Z0-9_]*=' .env.example | tr -d =)

if [ "$interactive" = 1 ] && { [ "$fresh" = 1 ] || [ "$reconfigure" = 1 ]; }; then
    note "Press Enter to take the value in brackets."
fi

ask EXPOSEHUB_STACK "Stack: full (dashboard, accounts) or tunnels (tunnel server only)" full
require EXPOSEHUB_STACK "full or tunnels" is_stack
stack=$(env_get EXPOSEHUB_STACK)

ask EXPOSEHUB_TUNNEL_DOMAIN "Tunnel domain (tunnels get <name>.<domain>)"
env_set EXPOSEHUB_TUNNEL_DOMAIN "$(env_get EXPOSEHUB_TUNNEL_DOMAIN | tr '[:upper:]' '[:lower:]')"
require EXPOSEHUB_TUNNEL_DOMAIN "the domain tunnels get subdomains of, such as example-tunnels.net" is_hostname
tunnel_domain=$(env_get EXPOSEHUB_TUNNEL_DOMAIN)

if [ "$stack" = full ]; then
    ask EXPOSEHUB_DASHBOARD_HOST "Dashboard host name"
    env_set EXPOSEHUB_DASHBOARD_HOST "$(env_get EXPOSEHUB_DASHBOARD_HOST | tr '[:upper:]' '[:lower:]')"
    require EXPOSEHUB_DASHBOARD_HOST "the dashboard's host name, such as exposehub.example.com" is_hostname
    dashboard_host=$(env_get EXPOSEHUB_DASHBOARD_HOST)
    case "$dashboard_host" in
        *."$tunnel_domain" | "$tunnel_domain")
            die "the dashboard ($dashboard_host) cannot be the tunnel domain or under it: that name would belong to tunnels. Use another name, ideally on another domain." ;;
    esac

    ask EXPOSEHUB_ADMIN_EMAILS "Your email address (platform owner; comma separate several)"
    env_set EXPOSEHUB_ADMIN_EMAILS "$(env_get EXPOSEHUB_ADMIN_EMAILS | tr '[:upper:]' '[:lower:]' | tr -d ' ')"
    require EXPOSEHUB_ADMIN_EMAILS "email addresses, comma separated" is_emails
    admin_email=$(env_get EXPOSEHUB_ADMIN_EMAILS); admin_email=${admin_email%%,*}

    if [ "$interactive" = 1 ] && { [ "$fresh" = 1 ] || [ "$reconfigure" = 1 ]; }; then
        default_domains=$(current EXPOSEHUB_ALLOWED_EMAIL_DOMAINS)
        [ "$fresh" = 1 ] && [ -z "$default_domains" ] && default_domains=${admin_email#*@}
        read -r -p "  Who may register? Email domains, comma separated; '-' for anyone [${default_domains:--}]: " value
        value=${value:-$default_domains}
        [ "$value" = "-" ] && value=""
        env_set EXPOSEHUB_ALLOWED_EMAIL_DOMAINS "$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]' | tr -d ' ')"
    elif [ -n "${EXPOSEHUB_ALLOWED_EMAIL_DOMAINS+x}" ]; then
        env_set EXPOSEHUB_ALLOWED_EMAIL_DOMAINS "$EXPOSEHUB_ALLOWED_EMAIL_DOMAINS"
    fi
    if [ -z "$(env_get EXPOSEHUB_ALLOWED_EMAIL_DOMAINS)" ]; then
        warn "anyone can register and open tunnels on this server. Set EXPOSEHUB_ALLOWED_EMAIL_DOMAINS (or Settings in the admin area) to limit that."
    fi
    env_set COMPOSE_PROFILES dashboard
    env_set EXPOSEHUB_CONTROL_PLANE_URL "http://web:8080"
else
    env_set COMPOSE_PROFILES ""
    env_set EXPOSEHUB_CONTROL_PLANE_URL ""
    if [ -z "$(current EXPOSEHUB_STATIC_TOKENS)" ]; then
        env_set EXPOSEHUB_STATIC_TOKENS "admin:$(rand_hex 24)"
        note "generated an agent token for the account 'admin' (EXPOSEHUB_STATIC_TOKENS)"
    else
        env_set EXPOSEHUB_STATIC_TOKENS "$(current EXPOSEHUB_STATIC_TOKENS)"
    fi
fi

step "Certificates"
note "dns: one wildcard certificate, needs an API token for the tunnel domain's DNS (recommended)"
note "on-demand: a certificate per tunnel name on its first visit, no DNS access needed"
note "internal: Caddy's own authority, only for trying it out locally"
ask EXPOSEHUB_TLS_MODE "Mode" dns
require EXPOSEHUB_TLS_MODE "dns, on-demand or internal" is_tls_mode
tls_mode=$(env_get EXPOSEHUB_TLS_MODE)

if [ "$tls_mode" = dns ]; then
    note "providers: $PROVIDERS"
    ask EXPOSEHUB_DNS_PROVIDER "DNS provider of $tunnel_domain"
    require EXPOSEHUB_DNS_PROVIDER "one of: $PROVIDERS" is_provider
    provider=$(env_get EXPOSEHUB_DNS_PROVIDER)
    note "what the token needs: see caddy/dns/$provider.caddy"
    for var in $(provider_vars "$provider"); do
        value=$(current "$var" caddy.env)
        if [ "$interactive" = 1 ] && { [ -z "$value" ] || [ "$reconfigure" = 1 ]; }; then
            case "$var" in
                *TOKEN* | *SECRET* | *KEY* | *PASSWORD*) ask_secret=secret ;;
                *) ask_secret="" ;;
            esac
            default=$value
            [ -z "$default" ] && [ "$var" = AWS_REGION ] && default=us-east-1
            [ -z "$default" ] && [ "$var" = OVH_ENDPOINT ] && default=ovh-eu
            if [ "$ask_secret" = secret ]; then
                read -r -s -p "  $var${default:+ [keep current]}: " input; echo
            else
                read -r -p "  $var${default:+ [$default]}: " input
            fi
            value=${input:-$default}
        fi
        [ -n "$value" ] || die "$var is not set: the $provider DNS provider needs it (in caddy.env or the environment)."
        env_set "$var" "$value" caddy.env
    done
    ok "credentials for $provider are in caddy.env"
else
    env_set EXPOSEHUB_DNS_PROVIDER ""
fi

if [ "$tls_mode" = on-demand ]; then
    env_set EXPOSEHUB_TLS_ASK_TUNNEL_NAMES true
else
    env_set EXPOSEHUB_TLS_ASK_TUNNEL_NAMES false
fi

if [ "$tls_mode" != internal ]; then
    ask EXPOSEHUB_ACME_EMAIL "Email for Let's Encrypt (expiry warnings)" "${admin_email:-}"
    require EXPOSEHUB_ACME_EMAIL "one real email address (Let's Encrypt rejects reserved domains such as example.com or .test)" is_acme_email
fi

if [ "$stack" = full ]; then
    step "Mail"
    note "Accounts are confirmed by mail. Without an SMTP server, mails go to the log (docker compose logs web)."
    ask MAIL_HOST "SMTP server (empty: write mails to the log)"
    if [ -n "$(env_get MAIL_HOST)" ]; then
        ask MAIL_PORT "SMTP port" 587
        ask MAIL_USERNAME "SMTP user name"
        ask MAIL_PASSWORD "SMTP password" "" secret
        ask MAIL_FROM_ADDRESS "Sender address" "noreply@$dashboard_host"
        env_set MAIL_MAILER smtp
    else
        env_set MAIL_MAILER log
    fi
fi

# Ports and the derived link suffix.
https_port=$(current EXPOSEHUB_HTTPS_PORT); https_port=${https_port:-443}
http_port=$(current EXPOSEHUB_HTTP_PORT); http_port=${http_port:-80}
env_set EXPOSEHUB_HTTPS_PORT "$https_port"
env_set EXPOSEHUB_HTTP_PORT "$http_port"
if [ "$https_port" = 443 ]; then env_set EXPOSEHUB_PORT_SUFFIX ""; else env_set EXPOSEHUB_PORT_SUFFIX ":$https_port"; fi

# ------------------------------------------------------------------ secrets

step "Secrets"
if [ -z "$(env_get APP_KEY)" ]; then
    env_set APP_KEY "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    ok "generated APP_KEY"
else
    ok "APP_KEY kept"
fi
if [ -z "$(env_get EXPOSEHUB_NODE_SECRET)" ]; then
    env_set EXPOSEHUB_NODE_SECRET "$(rand_hex 32)"
    ok "generated EXPOSEHUB_NODE_SECRET"
else
    ok "EXPOSEHUB_NODE_SECRET kept"
fi
if [ -z "$(env_get DB_PASSWORD)" ]; then
    if docker volume inspect exposehub_postgres-data >/dev/null 2>&1; then
        die "DB_PASSWORD is empty, but the database volume exposehub_postgres-data already exists with another password. Put the old password back into .env (or restore it from a backup)."
    fi
    env_set DB_PASSWORD "$(rand_hex 24)"
    ok "generated DB_PASSWORD"
else
    ok "DB_PASSWORD kept"
fi
note "keep a copy of .env and caddy.env somewhere safe: scripts/backup.sh includes them"

# Compose reads .env itself; check that it accepts the result.
docker compose config --quiet || die "docker compose rejects the configuration above; see its message."

# -------------------------------------------------------------------- ports

step "Ports"
running_caddy=$(docker compose ps --status running --services 2>/dev/null | grep -x caddy || true)
if [ -n "$running_caddy" ]; then
    ok "Caddy of this install is running; ports $http_port and $https_port are its own"
else
    for port in "$http_port" "$https_port"; do
        if command -v ss >/dev/null 2>&1; then
            busy=$(ss -Hltn "( sport = :$port )" 2>/dev/null || true)
        elif command -v lsof >/dev/null 2>&1; then
            busy=$(lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null || true)
        else
            note "cannot check port $port (neither ss nor lsof is installed)"
            continue
        fi
        if [ -n "$busy" ]; then
            die "port $port is in use by another program. exposehub's Caddy needs it; stop that program (for example another web server) or, for a test, set EXPOSEHUB_HTTP_PORT / EXPOSEHUB_HTTPS_PORT in .env."
        fi
        ok "port $port is free"
    done
fi

# ---------------------------------------------------------------------- DNS

is_private_ipv4() {
    case "$1" in
        10.* | 127.* | 192.168.* | 169.254.*) return 0 ;;
        172.1[6-9].* | 172.2[0-9].* | 172.3[01].*) return 0 ;;
        100.6[4-9].* | 100.[7-9][0-9].* | 100.1[01][0-9].* | 100.12[0-7].*) return 0 ;;
    esac
    return 1
}

resolve_a() {
    local name=$1
    if command -v getent >/dev/null 2>&1; then
        getent ahostsv4 "$name" 2>/dev/null | awk '{ print $1 }' | sort -u
    elif command -v dig >/dev/null 2>&1; then
        dig +short A "$name" 2>/dev/null | grep -E '^[0-9.]+$' | sort -u
    elif command -v host >/dev/null 2>&1; then
        host -t A "$name" 2>/dev/null | awk '/has address/ { print $NF }' | sort -u
    fi
}

dns_problems=0
placeholder_ip="<this server's IPv4>"
check_name() {
    local name=$1 record=$2 addresses
    addresses=$(resolve_a "$name" | tr '\n' ' ')
    addresses=${addresses% }
    if [ -z "$addresses" ]; then
        warn "$name does not resolve. Create: $record  A  ${public_ip:-$placeholder_ip}"
        dns_problems=$((dns_problems + 1))
    elif [ -n "$public_ip" ] && [ "$addresses" != "$public_ip" ]; then
        warn "$name resolves to $addresses, not to this server ($public_ip). Fix: $record  A  $public_ip (DNS only: not proxied by a CDN)"
        dns_problems=$((dns_problems + 1))
    else
        ok "$name → $addresses"
    fi
}

if [ "$tls_mode" = internal ]; then
    step "DNS"
    note "skipped in the internal mode"
elif [ "$skip_dns" = 1 ]; then
    step "DNS"
    note "skipped (--skip-dns-check)"
else
    step "DNS"
    if ! command -v getent >/dev/null 2>&1 && ! command -v dig >/dev/null 2>&1 && ! command -v host >/dev/null 2>&1; then
        warn "cannot look up names (no getent, dig or host); skipping the DNS check"
    else
        public_ip=${EXPOSEHUB_PUBLIC_IP:-}
        if [ -z "$public_ip" ] && command -v ip >/dev/null 2>&1; then
            # The address this host sends from; no packet leaves for this.
            public_ip=$(ip -4 route get 192.0.2.1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }')
        fi
        if [ -z "$public_ip" ] || is_private_ipv4 "$public_ip"; then
            note "this host's own address${public_ip:+ ($public_ip)} is not public, probably behind NAT; checking only that the names resolve."
            note "Set EXPOSEHUB_PUBLIC_IP=<public IPv4> to check that they point here."
            public_ip=""
        else
            note "this server: $public_ip"
        fi
        [ "$stack" = full ] && check_name "$dashboard_host" "$dashboard_host"
        check_name "connect.$tunnel_domain" "*.$tunnel_domain"
        check_name "check-$(rand_hex 4).$tunnel_domain" "*.$tunnel_domain"
        if [ "$dns_problems" -gt 0 ]; then
            note "Let's Encrypt refuses certificates while these records are wrong, and repeated failures lock a name out for an hour."
            if [ "$interactive" = 1 ]; then
                read -r -p "  Continue anyway? [y/N] " answer
                case "$answer" in y | Y | yes) ;; *) die "fix the records and run ./install.sh again." ;; esac
            else
                die "fix the records and run ./install.sh again, or pass --skip-dns-check."
            fi
        fi
    fi
fi

# ------------------------------------------------------------------- images

step "Images"
if [ "$skip_pull" = 1 ]; then
    note "skipped (--skip-pull)"
else
    docker compose pull --quiet || die "pulling the images failed; see the message above."
    ok "pulled $(docker compose config --images | tr '\n' ' ')"
fi

# --------------------------------------------------------------- next steps

suffix=$(env_get EXPOSEHUB_PORT_SUFFIX)
step "Ready"
echo "  Start or upgrade with:"
echo
echo "    docker compose up -d --wait"
echo
if [ "$stack" = full ]; then
    echo "  Then open https://$dashboard_host$suffix and register as $admin_email."
    if [ "$(env_get MAIL_MAILER)" = log ]; then
        echo "  Mail goes to the log, so the confirmation link is in: docker compose logs web"
    fi
    echo "  Create an agent token in the dashboard, then on your machine:"
else
    echo "  Agent token: $(env_get EXPOSEHUB_STATIC_TOKENS | cut -d, -f1 | cut -d: -f2-)"
    echo "  On your machine:"
fi
echo
echo "    curl -fsSL https://connect.$tunnel_domain$suffix/install.sh | sh"
echo "    exposehub login"
echo "    exposehub http 8000"
if [ "$tls_mode" = internal ]; then
    echo
    echo "  Internal mode: trust Caddy's root certificate first, see docs/self-hosting/compose.md."
fi
if [ "$warnings" -gt 0 ]; then
    echo
    echo "  ${yellow}$warnings warning(s) above.${reset}"
fi
