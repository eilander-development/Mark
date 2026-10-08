# Exercise deployment guards, data preservation, and rollback on a fake host.
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

SCRIPTS = Path(__file__).resolve().parents[1] / 'scripts'
REVISION = 'a' * 40
RELEASE = REVISION + '-123-1'


class ReleaseDeployTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.base = self.home / 'domains/site.test/public_html'
        self.public = self.base / 'public'
        self.public.mkdir(parents=True)
        (self.public / 'index.php').write_text('old version')
        (self.public / '.htaccess').write_text('hosting rules')
        (self.public / '.well-known').mkdir()
        (self.public / '.well-known/token').write_text('keep')
        (self.base / 'artisan').write_text('existing app')
        (self.base / '.env').write_text('APP_KEY=existing-key\n')
        (self.base / 'storage/app/public').mkdir(parents=True)
        (self.base / 'storage/app/public/upload.jpg').write_text('upload')
        self.bin = self.home / 'bin'
        self.bin.mkdir()
        self.env = dict(os.environ, HOME=str(self.home), PATH=str(self.bin)+':'+os.environ['PATH'], QA_LOG=str(self.home/'commands'))
        self.stub('php', '''#!/usr/bin/env bash
printf '%s\\n' "$*" >> "$QA_LOG"
if [[ "$1" == *release-backup.php ]] && [ "${FAIL_BACKUP:-}" = 1 ]; then exit 1; fi
if [[ "$1 $2" == 'artisan migrate' ]] && [ "${FAIL_MIGRATE:-}" = 1 ]; then exit 1; fi
exit 0
''')
        self.stub('curl', '''#!/usr/bin/env bash
[ "${FAIL_CURL:-}" != 1 ] || exit 22
while [ "$#" -gt 0 ]; do
 if [ "$1" = -o ]; then printf '{"revision":"%s"}' 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' > "$2"; exit 0; fi
 shift
done
''')

    def stub(self, name, content):
        path = self.bin / name
        path.write_text(content)
        path.chmod(0o755)

    def payload(self, catalog=False):
        stage = self.base / 'testapp-releases' / RELEASE
        stage.mkdir(parents=True)
        source = self.home / 'payload'
        (source / 'backend/bootstrap/cache').mkdir(parents=True)
        (source / 'backend/bootstrap/cache/config.php').write_text('stale')
        (source / 'public').mkdir()
        (source / 'public/index.php').write_text('new version')
        (source / 'public/.htaccess').write_text('app rules')
        (source / 'public/release.json').write_text(json.dumps({'revision': REVISION}))
        (source / 'scripts').mkdir()
        (source / 'scripts/release-backup.php').write_text('<?php')
        (source / 'scripts/deploy-config.sh').write_text('''DEPLOY_APP=testapp
DEPLOY_DB_CONNECTIONS=mysql
DEPLOY_TABLE_PREFIX=''
DEPLOY_EXCLUDE_PREFIX=''
DEPLOY_EXPECTED_DATABASE=''
DEPLOY_SESSION_CHECK=false
DEPLOY_CATALOG_MIGRATIONS='''+('true' if catalog else 'false')+'\n')
        with tarfile.open(stage / 'payload.tgz', 'w:gz') as archive:
            for name in ['backend', 'public', 'scripts']:
                archive.add(source / name, arcname=name)
        return stage

    def activate(self):
        return subprocess.run(['bash', str(SCRIPTS/'release-activate.sh'), str(self.public), RELEASE, 'testapp', 'https://site.test'], env=self.env, capture_output=True, text=True)

    def test_activation_preserves_key_uploads_and_hosting_files(self):
        stage = self.payload()
        result = self.activate()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.public/'index.php').read_text(), 'new version')
        self.assertEqual((self.public/'.htaccess').read_text(), 'hosting rules')
        self.assertEqual((self.public/'.well-known/token').read_text(), 'keep')
        self.assertEqual((self.public/'storage/upload.jpg').read_text(), 'upload')
        self.assertEqual((stage/'backend/.env').read_text(), 'APP_KEY=existing-key\n')
        self.assertFalse((stage/'backend/bootstrap/cache/config.php').exists())
        self.assertEqual((self.base/'testapp-backend').resolve(), stage/'backend')

    def test_failed_live_check_restores_previous_backend_and_public_files(self):
        previous = self.base/'previous-backend'
        previous.mkdir()
        (self.base/'testapp-backend').symlink_to(previous)
        self.payload()
        self.env['FAIL_CURL'] = '1'
        result = self.activate()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.public/'index.php').read_text(), 'old version')
        self.assertEqual((self.base/'testapp-backend').resolve(), previous)
        self.assertEqual((self.public/'.well-known/token').read_text(), 'keep')

    def test_failed_backup_prevents_migrations_and_activation(self):
        self.payload()
        self.env['FAIL_BACKUP'] = '1'
        result = self.activate()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.public/'index.php').read_text(), 'old version')
        self.assertNotIn('artisan migrate', (self.home/'commands').read_text())
        self.assertFalse((self.base/'testapp-backend').exists())

    def test_failed_migration_restores_files_before_switch(self):
        self.payload()
        self.env['FAIL_MIGRATE'] = '1'
        result = self.activate()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.public/'index.php').read_text(), 'old version')
        self.assertFalse((self.base/'testapp-backend').exists())

    def test_catalog_migrations_are_run_on_the_catalog_connection(self):
        self.payload(catalog=True)
        result = self.activate()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('migrate --database=catalog --path=database/migrations/catalog', (self.home/'commands').read_text())

    def test_resolver_rejects_another_domain(self):
        result = subprocess.run(['bash', str(SCRIPTS/'release-resolve.sh'), str(self.public), 'https://other.test', 'testapp'], env=self.env, capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def test_target_rejects_shell_injection_and_accepts_legacy_ftp_settings(self):
        project = self.home/'repo'
        (project/'scripts').mkdir(parents=True)
        shutil.copy(SCRIPTS/'release-target.sh', project/'scripts')
        (project/'scripts/deploy-config.sh').write_text('DEPLOY_DEFAULT_URL=https://site.test\n')
        env = dict(self.env, FTP_SERVER='ftps://server.test/path', FTP_USERNAME='hosting', SSH_PATH='domains/site.test/public_html', GITHUB_ENV=str(self.home/'github-env'))
        result = subprocess.run(['bash', 'scripts/release-target.sh'], cwd=project, env=env, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('SSH_TARGET_HOST=server.test', (self.home/'github-env').read_text())
        env['SSH_PATH'] = "domains/site.test/public_html'; touch injected"
        result = subprocess.run(['bash', 'scripts/release-target.sh'], cwd=project, env=env, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((project/'injected').exists())

    def test_bundle_omits_environment_files_sqlite_data_and_stale_caches(self):
        project = self.home/'package-repo'
        (project/'scripts').mkdir(parents=True)
        for name in ['release-package.sh', 'release-index.php', 'release-backup.php']:
            shutil.copy(SCRIPTS/name, project/'scripts'/name)
        (project/'scripts/deploy-config.sh').write_text('DEPLOY_APP=testapp\n')
        for directory in ['app', 'bootstrap/cache', 'config', 'database', 'resources/views', 'routes', 'vendor/tool', 'public/build', 'lang']:
            (project/directory).mkdir(parents=True, exist_ok=True)
        for name in ['artisan', 'composer.json', 'composer.lock', 'vendor/autoload.php', 'public/index.php', 'public/build/manifest.json', 'lang/nl.json']:
            (project/name).write_text('fixture')
        for name in ['vendor/tool/.envrc', 'vendor/tool/.env', 'vendor/tool/.env.example', 'database/database.sqlite', 'database/database.sqlite-wal', 'bootstrap/cache/config.php']:
            (project/name).write_text('must not ship')
        self.stub('git', '#!/usr/bin/env bash\nprintf "%s\\n" '+REVISION+'\n')
        output = self.home/'release.tgz'
        result = subprocess.run(['bash', 'scripts/release-package.sh', str(output)], cwd=project, env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        with tarfile.open(output) as archive:
            names = archive.getnames()
            self.assertIn('backend/lang/nl.json', names)
            self.assertIn('public/release.json', names)
            self.assertNotIn('backend/bootstrap/cache/config.php', names)
            self.assertFalse(any('.env' in name or '.sqlite' in name for name in names))
