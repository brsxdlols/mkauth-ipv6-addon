#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TARGET_DIR=/opt/mk-auth/admin/addons/ipv6
ADDON_MENU=/opt/mk-auth/admin/addons/addon.js
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

if [ -f "$ADDON_MENU" ]; then
    cp -a "$ADDON_MENU" "$BACKUP_DIR/addon.js"

    # Remove entradas antigas ou duplicadas deste addon e registra uma unica vez.
    sed -i '\|addons/ipv6/ipv6\.php|d' "$ADDON_MENU"
    {
        printf '\n// MKAUTH IPV6 ADDON MENU INICIO\n'
        printf '%s\n' 'add_menu.conexoes('\''{"plink": "/admin/addons/ipv6/ipv6.php", "ptext": "Painel IPv4/IPv6"}'\'');'
        printf '%s\n' '// MKAUTH IPV6 ADDON MENU FIM'
    } >> "$ADDON_MENU"
else
    echo "Aviso: addon.js nao encontrado; atalho do menu nao foi registrado." >&2
fi

echo "Addon IPv6 instalado/atualizado."
echo "Destino: $TARGET_DIR"
echo "Backup anterior: $BACKUP_DIR"
echo "Atalho: Conexoes > Painel IPv4/IPv6"
