#!/bin/sh
set -eu
PATCH_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
if [ ! -f "$PATCH_DIR/SHA256SUMS" ]; then
    echo 'Pacote incompleto: SHA256SUMS ausente.' >&2
    exit 1
fi
(cd "$PATCH_DIR" && sha256sum -c SHA256SUMS >/dev/null)
python3 "$PATCH_DIR/native_pgcorte.py" preflight
python3 "$PATCH_DIR/native_pgcorte.py" preflight --admin
python3 "$PATCH_DIR/patch.py" apply --activate "$@"
python3 "$PATCH_DIR/native_pgcorte.py" apply
exec python3 "$PATCH_DIR/native_pgcorte.py" apply --admin
