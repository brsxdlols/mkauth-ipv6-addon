import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('native_pgcorte',ROOT/'native_pgcorte.py')
native=importlib.util.module_from_spec(spec)
spec.loader.exec_module(native)


class NativePatchTests(unittest.TestCase):
    def test_apply_repeat_rollback_preserves_native_bytes(self):
        with tempfile.TemporaryDirectory() as temp:
            base=Path(temp)
            target=base/'.user.ini'
            protected=base/'pgcorte.hhvm'
            protected.write_bytes(b'<?php echo \"native fixture\"; exit;')
            original=b'; existing setting\n'
            target.write_bytes(original)
            target.chmod(0o644)
            with patch.object(native,'ROOT',base/'helper'/'v1.1.0'), patch.object(native,'TARGET',target), patch.object(native,'NATIVE',protected), patch.object(native,'BACKUPS',base/'backups'):
                self.assertEqual(native.execute('check')[1],3)
                result,code=native.execute('apply')
                self.assertEqual(code,0)
                self.assertEqual(target.read_bytes(),original+native.ini_block())
                self.assertEqual(native.execute('apply')[0]['status'],'already_installed')
                self.assertEqual(native.execute('check')[1],0)
                native.execute('rollback',result['backup'])
                self.assertEqual(target.read_bytes(),original)

    def test_refuses_rollback_after_foreign_change(self):
        with tempfile.TemporaryDirectory() as temp:
            base=Path(temp);target=base/'.user.ini'
            protected=base/'pgcorte.hhvm'
            protected.write_bytes(b'<?php echo \"native fixture\"; exit;')
            target.write_bytes(b'; existing setting\n')
            with patch.object(native,'ROOT',base/'helper'/'v1.1.0'), patch.object(native,'TARGET',target), patch.object(native,'NATIVE',protected), patch.object(native,'BACKUPS',base/'backups'):
                result,_=native.execute('apply')
                target.write_bytes(target.read_bytes()+b'\n// external change\n')
                with self.assertRaises(native.PatchError):native.execute('rollback',result['backup'])

    def test_rollback_removes_only_ini_created_by_patch(self):
        with tempfile.TemporaryDirectory() as temp:
            base=Path(temp);target=base/'.user.ini';protected=base/'pgcorte.hhvm'
            original=b'<?php echo "fixture";'
            protected.write_bytes(original)
            with patch.object(native,'ROOT',base/'helper'/'v1.1.0'), patch.object(native,'TARGET',target), patch.object(native,'NATIVE',protected), patch.object(native,'BACKUPS',base/'backups'):
                result,_=native.execute('apply')
                native.execute('rollback',result['backup'])
                self.assertFalse(target.exists())
                self.assertEqual(protected.read_bytes(),original)

    def test_custom_prepend_is_preserved(self):
        with tempfile.TemporaryDirectory() as temp:
            base=Path(temp);target=base/'.user.ini';protected=base/'pgcorte.hhvm'
            protected.write_bytes(b'<?php echo "fixture";')
            original=b'auto_prepend_file = "/custom/provider.php"\n'
            target.write_bytes(original)
            with patch.object(native,'TARGET',target), patch.object(native,'NATIVE',protected):
                with self.assertRaises(native.PatchError):native.execute('apply')
                self.assertEqual(target.read_bytes(),original)


if __name__=='__main__':unittest.main()
