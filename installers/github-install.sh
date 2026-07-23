#!/bin/sh
set -eu

REPOSITORY=brsxdlols/mkauth-ipv6-addon
BRANCH=main
TMP_DIR=$(mktemp -d)

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT INT TERM

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute como root." >&2
    exit 1
fi

if command -v curl >/dev/null 2>&1; then
    curl -fsSL "https://github.com/$REPOSITORY/archive/refs/heads/$BRANCH.tar.gz" \
        -o "$TMP_DIR/addon.tar.gz"
elif command -v wget >/dev/null 2>&1; then
    wget -qO "$TMP_DIR/addon.tar.gz" \
        "https://github.com/$REPOSITORY/archive/refs/heads/$BRANCH.tar.gz"
else
    echo "Instale curl ou wget para continuar." >&2
    exit 1
fi

tar -xzf "$TMP_DIR/addon.tar.gz" -C "$TMP_DIR"
sh "$TMP_DIR/mkauth-ipv6-addon-$BRANCH/installers/install.sh"
