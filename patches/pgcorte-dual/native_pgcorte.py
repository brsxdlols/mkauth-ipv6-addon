#!/usr/bin/env python3
"""Integrate dual identification into the authorized native PGCORTE response."""
import argparse
import datetime
import hashlib
import json
import os
import re
from pathlib import Path
import stat
import subprocess
import sys
import uuid
from patch import atomic_write, locked, PatchError, save_json

ROOT = Path('/usr/local/share/mkauth-pgcorte-dual/v1.1.1')
BACKUPS = Path('/var/backups/mkauth-pgcorte-native')
TARGET = Path('/opt/mk-auth/mkt/.user.ini')
NATIVE = Path('/opt/mk-auth/mkt/pgcorte.hhvm')
BEGIN = '; BEGIN MKAUTH_PGCORTE_DUAL_NATIVE v1'
END = '; END MKAUTH_PGCORTE_DUAL_NATIVE'


def ini_block():
    return (BEGIN+'\nauto_prepend_file = "'+str(ROOT/'native_hook.php')+'"\n'+END+'\n').encode()


def previous_ini_block():
    return (BEGIN+'\nauto_prepend_file = "/usr/local/share/mkauth-pgcorte-dual/v1.1.0/native_hook.php"\n'+END+'\n').encode()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def lint(path):
    r = subprocess.run(['php', '-l', str(path)], capture_output=True, timeout=30)
    if r.returncode:
        raise PatchError('PHP recusou o arquivo; nenhuma fonte protegida foi exibida.')


def safe_file(path):
    if path.is_symlink() or not path.is_file():
        raise PatchError('Arquivo ausente ou link inesperado: '+str(path))
    if path.stat().st_uid != 0 or path.stat().st_mode & 0o022:
        raise PatchError('Arquivo deve pertencer ao root e nao permitir escrita de grupo/outros.')


def execute(action, backup=None):
    if os.geteuid() != 0:
        raise PatchError('Execute como root.')
    safe_file(NATIVE)
    if NATIVE.read_bytes().startswith(b'<?php /* MKAUTH_PGCORTE'):
        raise PatchError('Restaure a tentativa antiga na fonte protegida antes de instalar.')
    if TARGET.is_symlink():
        raise PatchError("Link inesperado na configuracao PHP.")
    exists = TARGET.exists()
    if exists: safe_file(TARGET)
    data = TARGET.read_bytes() if exists else b''
    block = ini_block()
    installed = block in data
    upgrading = previous_ini_block() in data
    if b'MKAUTH_PGCORTE_DUAL_NATIVE' in data and not installed and not upgrading:
        raise PatchError('Configuracao propria divergente; revise antes de atualizar.')
    active_prepend = re.findall(rb'(?m)^\s*auto_prepend_file\s*=', data)
    if active_prepend and ((not installed and not upgrading) or len(active_prepend) != 1):
        raise PatchError('auto_prepend_file personalizado encontrado; configuracao preservada.')
    package = Path(__file__).resolve().parent
    files = {name: (package/name).read_bytes() for name in ('native_hook.php', 'pgcorte_dual.rsc')}
    lint(NATIVE)
    lint(package/'native_hook.php')
    if action == 'preflight':
        return {'status': 'compatible', 'installed': installed}, 0
    if action == 'check':
        complete = installed and all((ROOT/n).is_file() and sha((ROOT/n).read_bytes()) == sha(b) for n,b in files.items())
        return {'status': 'installed' if complete else 'absent_or_changed', 'native_authorization_preserved': True, 'php_user_ini_cache_seconds': 300}, 0 if complete else 3
    if action == 'rollback':
        if not backup:
            raise PatchError('Informe --backup com o diretorio retornado na instalacao.')
        folder = Path(backup).resolve()
        if folder.parent != BACKUPS.resolve():
            raise PatchError('Backup fora do diretorio esperado.')
        safe_file(folder/'manifest.json')
        safe_file(folder/'original.ini')
        record = json.loads((folder/'manifest.json').read_text())
        original = (folder/'original.ini').read_bytes()
        if record['after_sha256'] != sha(data) or record['before_sha256'] != sha(original):
            raise PatchError('A fonte ou o backup mudou; rollback recusado.')
        if record['before_existed']:
            atomic_write(TARGET, original)
        else:
            TARGET.unlink()
        return {'status': 'rolled_back', 'backup': str(folder)}, 0
    # Validate the helper before touching the native endpoint.
    ROOT.mkdir(parents=True, exist_ok=True, mode=0o755)
    for parent in (ROOT, ROOT.parent):
        if parent.is_symlink() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022:
            raise PatchError('Diretorio do helper inseguro.')
    for name, content in files.items():
        target = ROOT/name
        if target.exists():
            safe_file(target)
            if target.read_bytes() != content:
                raise PatchError('Helper existente diverge do pacote; instalacao recusada.')
        else:
            fd = os.open(str(target), os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o644)
            with os.fdopen(fd, 'wb') as out:
                out.write(content)
                out.flush()
                os.fsync(out.fileno())
    if installed:
        return {'status': 'already_installed', 'target': str(TARGET)}, 0
    BACKUPS.mkdir(parents=True, exist_ok=True, mode=0o700)
    BACKUPS.chmod(0o700)
    folder = BACKUPS/(datetime.datetime.now().strftime('%Y%m%d-%H%M%S')+'-'+uuid.uuid4().hex[:8])
    folder.mkdir(mode=0o700)
    original = folder/'original.ini'
    original.write_bytes(data)
    original.chmod(0o600)
    patched = data.replace(previous_ini_block(), block) if upgrading else data+(b'\n' if data and not data.endswith(b'\n') else b'')+block
    save_json(folder/'manifest.json', {'before_sha256': sha(data), 'after_sha256': sha(patched), 'target': str(TARGET), 'before_existed': exists})
    atomic_write(TARGET, patched, TARGET.stat() if exists else NATIVE.stat())
    return {'status': 'installed', 'target': str(TARGET), 'backup': str(folder), 'native_authorization_preserved': True, 'php_user_ini_cache_seconds': 300}, 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['preflight','check','apply','rollback'])
    parser.add_argument('--backup')
    args = parser.parse_args()
    try:
        with locked(Path('/run/lock/mkauth-pgcorte-native.lock')):
            result, code = execute(args.action, args.backup)
        print(json.dumps(result))
        return code
    except (PatchError, OSError, ValueError, subprocess.SubprocessError, KeyError) as error:
        print(json.dumps({'status': 'error', 'message': str(error)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
