#!/bin/sh
set -eu

REPOSITORY=brsxdlols/mkauth-ipv6-addon
BRANCH=${BRANCH:-main}
TMP_DIR=$(mktemp -d)

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT INT TERM

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute como root." >&2
    exit 1
fi

ARCHIVE_URL="https://github.com/$REPOSITORY/archive/refs/heads/$BRANCH.tar.gz"
if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$ARCHIVE_URL" -o "$TMP_DIR/addon.tar.gz"
elif command -v wget >/dev/null 2>&1; then
    wget -qO "$TMP_DIR/addon.tar.gz" "$ARCHIVE_URL"
else
    echo "Instale curl ou wget para continuar." >&2
    exit 1
fi

tar -xzf "$TMP_DIR/addon.tar.gz" -C "$TMP_DIR"
SOURCE_DIR=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -name 'mkauth-ipv6-addon-*' | head -n 1)
if [ -z "$SOURCE_DIR" ] || [ ! -f "$SOURCE_DIR/installers/install.sh" ]; then
    echo "Pacote baixado nao contem o instalador esperado." >&2
    exit 1
fi

# O arquivo dentro do tar.gz nao passa pelo `tr -d '\r'` usado no comando
# de instalacao. Normalize-o aqui para funcionar mesmo quando o checkout que
# publicou o pacote usou terminacoes CRLF.
CLEAN_INSTALLER="$TMP_DIR/install.sh"
tr -d '\r' < "$SOURCE_DIR/installers/install.sh" > "$CLEAN_INSTALLER"
chmod 700 "$CLEAN_INSTALLER"
SOURCE_ROOT="$SOURCE_DIR" sh "$CLEAN_INSTALLER"

