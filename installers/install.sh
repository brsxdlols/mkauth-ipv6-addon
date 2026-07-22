#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TARGET_DIR=/opt/mk-auth/admin/addons/ipv6
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR=/root/backups/mkauth-ipv6-addon-$STAMP

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute como root." >&2
    exit 1
fi

if [ ! -d /opt/mk-auth/admin/addons ]; then
    echo "MK-Auth nao encontrado em /opt/mk-auth/admin." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR" "$TARGET_DIR"
if [ -n "$(ls -A "$TARGET_DIR" 2>/dev/null || true)" ]; then
    cp -a "$TARGET_DIR/." "$BACKUP_DIR/"
fi

cp -a "$SOURCE_DIR/addons/ipv6/." "$TARGET_DIR/"
chown -R www-data:www-data "$TARGET_DIR" 2>/dev/null || true
find "$TARGET_DIR" -type d -exec chmod 755 {} \;
find "$TARGET_DIR" -type f -exec chmod 644 {} \;

php "$TARGET_DIR/install.php"

echo "Addon IPv6 instalado/atualizado."
echo "Destino: $TARGET_DIR"
echo "Backup anterior: $BACKUP_DIR"

