#!/bin/sh
# Records demo.gif from demo.tape with VHS (https://github.com/charmbracelet/vhs).
# Needs Go and Docker. Everything runs inside the VHS container, so it does
# not matter what listens on port 3000 of this machine.
set -eu
cd "$(dirname "$0")"
root=$(cd ../.. && pwd)
out=$(mktemp -d)
trap 'rm -rf "$out"' EXIT

mkdir -p "$out/bin"
(cd "$root/tunnel" && GOOS=linux CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.version=0.1.0" -o "$out/bin/" ./cmd/...)
GOOS=linux CGO_ENABLED=0 go build -o "$out/bin/helper" helper.go
cp demo.tape start.sh "$out/"

docker run --rm -v "$out:/demo" -w /demo ghcr.io/charmbracelet/vhs:v0.12.1 demo.tape
cp "$out/demo.gif" demo.gif
ls -l demo.gif
