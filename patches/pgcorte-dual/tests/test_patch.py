import importlib.util
from pathlib import Path
import unittest
from unittest import mock
import tempfile
import sys
import argparse

PACKAGE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('pgcorte_patch', str(PACKAGE / 'patch.py'))
patch = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patch)

NATIVE = '''# native MK-Auth
# authorize_reply_query = "comment only"
authorize_check_query = "untouched check"
authorize_reply_query = "SELECT id, username, attribute, value, op \\
 FROM ${authreply_table} \\
 WHERE username = '%{SQL-User-Name}' \\
 ORDER BY id"
accounting_start_query = "untouched accounting"
'''


class QueryTests(unittest.TestCase):
    def test_upgrade_primary_only_patch_to_additional_support(self):
        old = patch.BEGIN + '\n' + 'authorize_reply_query = "' + patch.reply_query_v1() + '"\n' + patch.END
        updated, sensitive, source = patch.prepare(old)
        self.assertEqual(source, 'upgrade-v1')
        self.assertIn('sis_adicional', updated)
        self.assertEqual(updated.count(patch.BEGIN), 1)
        self.assertEqual(patch.prepare(updated)[2], 'installed')

    def test_native_preserves_accounting_and_comments(self):
        updated, sensitive, source = patch.prepare(NATIVE)
        self.assertEqual(source, 'native')
        self.assertFalse(sensitive)
        self.assertIn('authorize_check_query = "untouched check"', updated)
        self.assertIn('accounting_start_query = "untouched accounting"', updated)
        self.assertIn('# authorize_reply_query = "comment only"', updated)
        self.assertEqual(patch.prepare(updated)[0], updated)

    def test_case_sensitive_native(self):
        updated, sensitive, source = patch.prepare(NATIVE.replace("username = '", "username = BINARY '"))
        self.assertTrue(sensitive)
        self.assertIn("c.login = BINARY '%{SQL-User-Name}'", updated)

    def test_crlf_input_is_understood(self):
        updated, unused, source = patch.prepare(NATIVE.replace('\n', '\r\n'))
        self.assertEqual(source, 'native')
        self.assertEqual(len(list(patch.ASSIGN.finditer(updated))), 1)

    def test_custom_auth_logic_is_not_replaced(self):
        bad = NATIVE.replace('ORDER BY id"', 'AND vendor_rule=1 ORDER BY id"')
        with self.assertRaises(patch.PatchError):
            patch.prepare(bad)

    def test_multiple_active_queries_are_rejected(self):
        with self.assertRaises(patch.PatchError):
            patch.prepare(NATIVE + '\nauthorize_reply_query = "other"\n')

    def test_truncated_or_modified_patch_is_rejected(self):
        installed = patch.prepare(NATIVE)[0]
        for changed in [installed.replace(patch.END, ''), installed.replace("c.bloqueado = 'sim'", '1=1')]:
            with self.assertRaises(patch.PatchError):
                patch.prepare(changed)

    def test_secrets_and_expansions_are_not_evaluated(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / 'sql'
            config.write_text('driver = "rlm_sql_mysql"\ndialect = "mysql"\nserver = "localhost"\nport = 3306\nlogin = "root"\npassword = "${secret}"\nradius_db = "mkradius"\nauthreply_table = "radreply"\n')
            with self.assertRaises(patch.PatchError) as caught:
                patch.radius_settings(config)
            self.assertNotIn('${secret}', str(caught.exception))

    def test_no_restart_when_service_is_intentionally_stopped(self):
        with mock.patch.object(patch, 'run_service') as service:
            self.assertEqual(patch.activate(Path('/irrelevant'), {})['activation'], 'on_next_service_start')
            service.assert_not_called()

    def test_ambiguous_service_processes_are_not_killed(self):
        with mock.patch.object(patch.os, 'kill') as kill:
            with self.assertRaises(patch.PatchError):
                patch.activate(Path('/irrelevant'), {1: '100', 2: '200'})
            kill.assert_not_called()

    def test_failed_validation_restores_original_and_redacts_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'queries.conf'
            target.write_text(NATIVE, encoding='utf-8')
            args = argparse.Namespace(command='apply', activate=True, config_dir=str(root),
                queries=str(target), sql_config=str(root/'sql'), radius_binary=sys.executable,
                backup_root=str(root/'backups'), backup=None)
            def write(path, data, metadata=None):
                path.write_bytes(data)
            with mock.patch.object(patch.os, 'geteuid', return_value=0, create=True), \
                 mock.patch.object(patch, 'radius_settings', return_value={}), \
                 mock.patch.object(patch, 'verify_database'), \
                 mock.patch.object(patch, 'process_ids', return_value={}), \
                 mock.patch.object(patch, 'atomic_write', side_effect=write), \
                 mock.patch.object(patch, 'config_check', side_effect=[None,patch.PatchError('NEVER_PRINT_PASSWORD'),None]):
                with self.assertRaises(patch.PatchError) as caught:
                    patch.execute(args)
            self.assertEqual(target.read_text(encoding='utf-8'), NATIVE)
            self.assertNotIn('NEVER_PRINT_PASSWORD', str(caught.exception))
            self.assertTrue(list((root/'backups').glob('*/failure.json')))


if __name__ == '__main__':
    unittest.main()
