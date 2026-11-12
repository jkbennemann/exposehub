#!/bin/bash
# Runs inside the recording container, out of sight, before the demo starts:
# a tunnel server that hands out addresses under example.eu, the local
# service on port 3000, and visitors that arrive once the tunnel is open.
export PATH="/demo/bin:$PATH"
EXPOSEHUB_LISTEN=127.0.0.1:8080 EXPOSEHUB_AGENT_HOST=localhost \
EXPOSEHUB_TUNNEL_DOMAINS=example.eu EXPOSEHUB_STATIC_TOKENS=demo:demo-token \
EXPOSEHUB_PUBLIC_SCHEME=https EXPOSEHUB_PUBLIC_PORT=443 \
  exposehub-server > /tmp/server.log 2>&1 &
helper shop > /tmp/shop.log 2>&1 &
helper visit http://127.0.0.1:8080 shop.example.eu > /tmp/visit.log 2>&1 &
ln -sf /demo/bin/exposehub /usr/local/bin/exposehub
