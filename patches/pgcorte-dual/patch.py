#!/usr/bin/env python3
"""MK-Auth/FreeRADIUS 3: preserve registered IPv4 for blocked List users.

No database writes, subscriber disconnects, MikroTik access or stored credentials.
Python 3.7+. Install outside the web root.
"""
import argparse
import contextlib
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import uuid

VERSION = '1.1.1'
BEGIN = '# BEGIN MKAUTH_PGCORTE_IPV4_PATCH v1'
END = '# END MKAUTH_PGCORTE_IPV4_PATCH'
LEGACY = '34421b9bd9770a914ff7147f6dc3a9dc8db70ded5007cdd7d23951f9e5a10354'
ASSIGN = re.compile(r'(?m)^[ \t]*authorize_reply_query\s*=\s*"((?:[^"\\]|\\[\s\S])*)"')
NATIVE = re.compile(r"SELECT id, username, attribute, value, op FROM \$\{authreply_table\} WHERE username = (BINARY )?'%\{SQL-User-Name\}' ORDER BY id", re.I)


class PatchError(Exception):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def normalized(body):
    return ' '.join(re.sub(r'\\\r?\n', ' ', body).split())


def reply_query_v1(binary=False):
    exact = 'BINARY ' if binary else ''
    return (
        "SELECT id, username, attribute, value, op FROM ${authreply_table} "
        "WHERE username = " + exact + "'%{SQL-User-Name}' UNION ALL "
        "SELECT -1 AS id, c.login AS username, 'Framed-IP-Address' AS attribute, "
        "TRIM(c.ip) AS value, ':=' AS op FROM sis_cliente c "
        "WHERE c.login = " + exact + "'%{SQL-User-Name}' "
        "AND c.bloqueado = 'sim' AND c.cli_ativado = 's' "
        "AND INET_ATON(TRIM(c.ip)) BETWEEN 1 AND 4294967294 "
        "AND BINARY INET_NTOA(INET_ATON(TRIM(c.ip))) = BINARY TRIM(c.ip) "
        "AND EXISTS (SELECT 1 FROM ${authreply_table} al "
        "WHERE al.username = " + exact + "c.login "
        "AND al.attribute = 'Mikrotik-Address-List' AND al.value = 'pgcorte') "
        "AND NOT EXISTS (SELECT 1 FROM ${authreply_table} ip "
        "WHERE ip.username = " + exact + "c.login AND ip.attribute = 'Framed-IP-Address') "
        "ORDER BY id"
    )


def reply_query(binary=False):
    exact = 'BINARY ' if binary else ''
    original = reply_query_v1(binary)
    return original[:-len('ORDER BY id')] + (
        "UNION ALL SELECT -2 AS id, a.username AS username, 'Framed-IP-Address' AS attribute, "
        "TRIM(a.ip) AS value, ':=' AS op FROM sis_adicional a "
        "JOIN sis_cliente c ON BINARY c.login = BINARY a.login "
        "WHERE a.username = " + exact + "'%{SQL-User-Name}' "
        "AND (a.bloqueado = 'sim' OR c.bloqueado = 'sim') AND c.cli_ativado = 's' "
        "AND INET_ATON(TRIM(a.ip)) BETWEEN 1 AND 4294967294 "
        "AND BINARY INET_NTOA(INET_ATON(TRIM(a.ip))) = BINARY TRIM(a.ip) "
        "AND NOT EXISTS (SELECT 1 FROM sis_cliente principal WHERE principal.login = " + exact + "a.username) "
        "AND NOT EXISTS (SELECT 1 FROM sis_adicional other WHERE other.username = " + exact + "a.username AND other.id <> a.id) "
        "AND EXISTS (SELECT 1 FROM ${authreply_table} al WHERE al.username = " + exact + "a.username "
        "AND al.attribute = 'Mikrotik-Address-List' AND al.value = 'pgcorte') "
        "AND NOT EXISTS (SELECT 1 FROM ${authreply_table} ip WHERE ip.username = " + exact + "a.username "
        "AND ip.attribute = 'Framed-IP-Address') ORDER BY id"
    )


def prepare(text):
    matches = list(ASSIGN.finditer(text))
    if len(matches) != 1:
        raise PatchError('Esperada uma unica authorize_reply_query ativa; arquivo preservado.')
    item = matches[0]
    body = normalized(item.group(1))
    if BEGIN in text or END in text:
        if text.count(BEGIN) != 1 or text.count(END) != 1:
            raise PatchError('Marcadores duplicados ou incompletos; arquivo preservado.')
        start, stop = text.index(BEGIN), text.index(END)
        if not (start < item.start() < item.end() <= stop):
            raise PatchError('Consulta fora dos marcadores; arquivo preservado.')
        for previous_binary in (False, True):
            if body == reply_query_v1(previous_binary):
                block = BEGIN + '\n' + 'authorize_reply_query = "' + reply_query(previous_binary) + '"\n' + END
                return text[:start] + block + text[stop+len(END):], previous_binary, 'upgrade-v1'
        binary = body == reply_query(True)
        if body != reply_query(binary):
            raise PatchError('Patch modificado externamente; revisar antes de reaplicar.')
        return text, binary, 'installed'
    native = NATIVE.fullmatch(body)
    if native:
        binary = bool(native.group(1))
        origin = 'native'
    elif digest(body.encode()) == LEGACY:
        binary, origin = False, 'legacy-rnet'
    else:
        raise PatchError('Consulta personalizada nao reconhecida; nenhuma substituicao automatica.')
    block = BEGIN + '\n' + 'authorize_reply_query = "' + reply_query(binary) + '"\n' + END
    return text[:item.start()] + block + text[item.end():], binary, origin


def radius_settings(path):
    """Read only literal native settings; never evaluate shell or Radius expansions."""
    text = path.read_text(encoding='utf-8')
    result = {}
    for key in ['driver', 'dialect', 'server', 'port', 'login', 'password', 'radius_db', 'authreply_table']:
        pattern = re.compile(r'(?m)^\s*' + key + r'\s*=\s*(?:"([^"\n]*)"|([^\s#]+))\s*(?:#.*)?$')
        values = [a if a != '' else b for a, b in pattern.findall(text)]
        if len(values) != 1 or '${' in values[0] or '%{' in values[0] or '$ENV{' in values[0]:
            raise PatchError('Configuracao SQL complexa ou ausente: ' + key + '. Nao foram expostas credenciais.')
        result[key] = values[0]
    if result['driver'] != 'rlm_sql_mysql' or result['dialect'] != 'mysql':
        raise PatchError('Este pacote valida somente o backend MySQL nativo do MK-Auth.')
    if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', result['authreply_table']):
        raise PatchError('Nome de tabela SQL nao suportado.')
    return result


def verify_database(settings, binary):
    helper = Path(__file__).resolve().with_name('verify_database.php')
    php = shutil.which('php')
    if not php or not helper.is_file():
        raise PatchError('PHP CLI e verify_database.php sao obrigatorios para validar o banco.')
    query = reply_query(binary).replace('${authreply_table}', settings['authreply_table'])
    query = query.replace('%{SQL-User-Name}', '__mkauth_patch_schema_probe__')
    payload = dict(settings, query=query)
    run = subprocess.run([php, str(helper)], input=json.dumps(payload), text=True,
                         stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    try:
        result = json.loads(run.stdout)
    except ValueError:
        raise PatchError('A validacao SQL nao retornou resultado valido; credenciais omitidas.')
    if run.returncode or not result.get('ok'):
        raise PatchError('Falha na validacao SQL: ' + str(result.get('error', 'unknown')))


def config_check(binary, config_dir):
    run = subprocess.run([str(binary), '-XC', '-d', str(config_dir)],
                         stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45)
    if run.returncode:
        raise PatchError('FreeRADIUS -XC recusou a configuracao. Nenhum segredo foi enviado ao log do instalador.')


def atomic_write(path, data, metadata=None):
    metadata = metadata or path.stat()
    handle, temporary = tempfile.mkstemp(prefix='.mkauth-pgcorte-', dir=str(path.parent))
    try:
        with os.fdopen(handle, 'wb') as out:
            os.fchmod(out.fileno(), metadata.st_mode & 0o777)
            os.fchown(out.fileno(), metadata.st_uid, metadata.st_gid)
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def process_ids(binary):
    found = {}
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        try:
            if (entry / 'exe').resolve() != binary.resolve():
                continue
            command = (entry / 'cmdline').read_bytes().split(b'\0')
            # Never touch a temporary config-test/debug instance.
            if any(b'X' in a or b'C' in a for a in command[1:] if a.startswith(b'-')):
                continue
            stat = (entry / 'stat').read_text().split(') ', 1)[1].split()
            found[int(entry.name)] = stat[19]  # Linux starttime, robust to PID reuse.
        except (OSError, ValueError, IndexError):
            continue
    return found


def verify_service_config(config_dir, pids):
    for pid in pids:
        command = [os.fsdecode(a) for a in (Path('/proc') / str(pid) / 'cmdline').read_bytes().split(b'\0') if a]
        selected = Path('/etc/freeradius/3.0')
        for index, argument in enumerate(command[1:], 1):
            if argument == '-d':
                if index + 1 >= len(command):
                    raise PatchError('Diretorio de configuracao do processo nao identificado.')
                selected = Path(command[index + 1])
            elif argument.startswith('-d'):
                selected = Path(argument[2:])
            elif argument == '-n' or argument.startswith('-n'):
                raise PatchError('Instancia RADIUS nomeada requer integracao especifica; ativacao recusada.')
        if selected.resolve() != config_dir:
            raise PatchError('O processo em execucao usa outra configuracao; nenhuma alteracao aplicada.')


def run_service(command):
    r = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=35)
    if r.returncode:
        raise PatchError('O gerenciador de servicos nao concluiu a ativacao do FreeRADIUS.')


def activate(binary, before):
    if not before:
        return {'activation': 'on_next_service_start', 'pids': {}}
    if len(before) != 1:
        raise PatchError('Mais de um processo FreeRADIUS; ativacao automatica recusada.')
    if Path('/run/systemd/system').is_dir():
        run_service(['systemctl', 'restart', 'freeradius.service'])
    else:
        init = Path('/etc/init.d/freeradius')
        if not init.is_file():
            raise PatchError('Gerenciador do servico nao encontrado.')
        run_service([str(init), 'restart'])
        # Some MK-Auth SysV scripts report success but leave the old process alive.
        if process_ids(binary) == before:
            pid = next(iter(before))
            if process_ids(binary) != before:
                raise PatchError('Processo mudou durante a ativacao; nenhuma sinalizacao enviada.')
            os.kill(pid, signal.SIGTERM)
            for unused in range(100):
                if pid not in process_ids(binary):
                    break
                time.sleep(.1)
            else:
                raise PatchError('Processo nao terminou normalmente; SIGKILL nao foi usado.')
            run_service([str(init), 'start'])
    time.sleep(.3)
    after = process_ids(binary)
    if len(after) != 1 or after == before:
        raise PatchError('Nao foi confirmado um novo processo carregando a configuracao.')
    return {'activation': 'restarted', 'pids': after}


@contextlib.contextmanager
def locked(path):
    import fcntl
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor = os.open(str(path), os.O_RDWR | os.O_CREAT | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    metadata = os.fstat(descriptor)
    if metadata.st_uid != 0 or not stat.S_ISREG(metadata.st_mode):
        os.close(descriptor)
        raise PatchError('Arquivo de lock inseguro; operacao recusada.')
    with os.fdopen(descriptor, 'a') as handle:
        os.fchmod(handle.fileno(), 0o600)
        fcntl.flock(handle, fcntl.LOCK_EX)
        yield


def save_json(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    path.chmod(0o600)


def execute(args):
    config_dir = Path(args.config_dir).resolve()
    query = Path(args.queries or str(config_dir / 'mods-config/sql/main/mysql/queries.conf')).resolve()
    sql_config = Path(args.sql_config or str(config_dir / 'mods-enabled/sql')).resolve()
    binary = Path(args.radius_binary).resolve()
    if not query.is_file() or not binary.is_file():
        raise PatchError('Instalacao FreeRADIUS 3 nao encontrada nos caminhos informados.')
    original = query.read_bytes()
    updated, case_sensitive, origin = prepare(original.decode('utf-8'))
    result = {'version': VERSION, 'queries': str(query), 'status': origin,
              'configuration_sha256': digest(original), 'database_writes': False,
              'mikrotik_commands': False, 'subscriber_disconnects': False}
    if args.command == 'check':
        verify_database(radius_settings(sql_config), case_sensitive)
        result['schema_validated'] = True
        result['protected_on_disk'] = origin == 'installed'
        result['service_running'] = bool(process_ids(binary))
        state_file = Path(args.backup_root).resolve() / 'state.json'
        prior = json.loads(state_file.read_text()) if state_file.is_file() else {}
        recorded = (prior.get('queries') == str(query) and prior.get('configuration_sha256') == digest(original)
                    and prior.get('activation') in ['restarted', 'on_next_service_start'])
        result['activation'] = prior.get('activation', 'not_recorded') if recorded else 'not_recorded'
        if origin != 'installed':
            return result, 3
        return result, 0 if recorded else 4
    if os.geteuid() != 0:
        raise PatchError('Aplicacao e rollback exigem root.')
    backup_root = Path(args.backup_root).resolve()
    backup_root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if backup_root.stat().st_uid != 0:
        raise PatchError('O diretorio de backups deve pertencer a root.')
    backup_root.chmod(0o700)
    state = backup_root / 'state.json'
    before_service = process_ids(binary)
    if len(before_service) > 1:
        raise PatchError('Mais de um processo FreeRADIUS; alteracao recusada.')
    verify_service_config(config_dir, before_service)
    if args.command == 'rollback':
        if not args.backup:
            raise PatchError('Informe --backup com o diretorio mostrado durante a aplicacao.')
        backup = Path(args.backup).resolve()
        manifest = json.loads((backup / 'manifest.json').read_text())
        old = (backup / 'queries.conf.before').read_bytes()
        if manifest['queries'] != str(query) or digest(old) != manifest['before_sha256']:
            raise PatchError('Backup nao corresponde ao arquivo alvo.')
        if digest(original) != manifest['after_sha256']:
            raise PatchError('Arquivo mudou depois do patch; rollback automatico recusado.')
        config_check(binary, config_dir)
        atomic_write(query, old)
        try:
            config_check(binary, config_dir)
            activation = activate(binary, before_service) if args.activate else {'activation': 'restart_required'}
        except Exception:
            atomic_write(query, original)
            raise
        result.update(activation, status='rolled_back', backup=str(backup), configuration_sha256=digest(old))
        save_json(state, result)
        return result, 0 if args.activate or not before_service else 4
    verify_database(radius_settings(sql_config), case_sensitive)
    config_check(binary, config_dir)
    new = updated.encode('utf-8')
    if new == original and state.exists():
        prior = json.loads(state.read_text())
        if prior.get('queries') == str(query) and prior.get('configuration_sha256') == digest(new) and prior.get('activation') in ['restarted', 'on_next_service_start']:
            result.update(status='already_installed', activation=prior['activation'])
            return result, 0
    backup = backup_root / (datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-' + uuid.uuid4().hex[:8])
    backup.mkdir(mode=0o700)
    (backup / 'queries.conf.before').write_bytes(original)
    (backup / 'queries.conf.before').chmod(0o600)
    manifest = {'version': VERSION, 'queries': str(query), 'before_sha256': digest(original),
                'after_sha256': digest(new), 'created_at': datetime.datetime.now().astimezone().isoformat()}
    save_json(backup / 'manifest.json', manifest)
    atomic_write(query, new)
    try:
        config_check(binary, config_dir)
        activation = activate(binary, before_service) if args.activate else {'activation': 'restart_required'}
    except Exception as problem:
        atomic_write(query, original)
        restored = False
        try:
            config_check(binary, config_dir)
            current = process_ids(binary)
            if before_service and current != before_service:
                if current:
                    activate(binary, current)
                else:
                    run_service(['/etc/init.d/freeradius', 'start'])
            restored = bool(process_ids(binary)) if before_service else True
        except Exception:
            pass
        save_json(backup / 'failure.json', {'configuration_restored': True, 'service_recovered': restored})
        raise PatchError('Ativacao falhou. Configuracao anterior restaurada; servico recuperado=' + str(restored) + '. Backup: ' + str(backup)) from problem
    result.update(activation, status='installed', backup=str(backup), configuration_sha256=digest(new), schema_validated=True)
    save_json(state, result)
    return result, 0 if args.activate or not before_service else 4


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['check', 'apply', 'rollback'])
    parser.add_argument('--activate', action='store_true', help='Reinicia somente FreeRADIUS para carregar a consulta.')
    parser.add_argument('--config-dir', default='/etc/freeradius/3.0')
    parser.add_argument('--queries')
    parser.add_argument('--sql-config')
    parser.add_argument('--radius-binary', default='/usr/sbin/freeradius')
    parser.add_argument('--backup-root', default='/var/backups/mkauth-radius-pgcorte')
    parser.add_argument('--backup')
    args = parser.parse_args()
    try:
        if args.command == 'check':
            value, code = execute(args)
        else:
            if os.geteuid() != 0:
                raise PatchError('Execute como root.')
            with locked(Path('/run/lock/mkauth-radius-pgcorte.lock')):
                value, code = execute(args)
        print(json.dumps(value, ensure_ascii=False))
        return code
    except (PatchError, OSError, ValueError, subprocess.SubprocessError) as error:
        # Deliberately do not print subprocess output or configuration values.
        message = str(error) if isinstance(error, PatchError) else 'Falha de leitura ou execucao; nenhuma credencial foi exibida.'
        print(json.dumps({'ok': False, 'error': message}, ensure_ascii=False))
        return 1


if __name__ == '__main__':
    sys.exit(main())
