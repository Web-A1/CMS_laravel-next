#!/usr/bin/env python3
"""Run only the isolated showcase, with no inherited application credentials."""
import argparse
import base64
import os
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
BACKEND = ROOT / 'backend'
FRONTEND = ROOT / 'frontend'


def environment():
    # Retain only runtime/cache locations, never inherited DB, provider or proxy variables.
    result = {key: os.environ[key] for key in ('PATH', 'HOME', 'TMPDIR', 'USER', 'LANG', 'TERM') if key in os.environ}
    for path in (BACKEND / '.env.example', FRONTEND / '.env.example'):
        for line in path.read_text().splitlines():
            if line and not line.startswith('#') and '=' in line:
                key, value = line.split('=', 1)
                result[key] = value.strip('"')
    database = BACKEND / 'database/demo.sqlite'
    if database.is_symlink() or database.parent.resolve() != BACKEND / 'database':
        raise SystemExit('Unsafe demo database path')
    result['DB_DATABASE'] = str(database)
    result['DB_URL'] = ''
    result['NEXT_TELEMETRY_DISABLED'] = '1'
    result['APP_CONFIG_CACHE'] = str(BACKEND / 'bootstrap/cache/config.php')
    keyfile = ROOT / '.demo/key'
    if keyfile.exists():
        if keyfile.is_symlink():
            raise SystemExit('Unsafe key file')
        result['APP_KEY'] = keyfile.read_text().strip()
    return result


def run(command, cwd, env):
    subprocess.run(command, cwd=cwd, env=env, check=True)


def prepare():
    for relative in ('bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions',
                     'storage/framework/views', 'storage/logs', 'storage/app/private', 'storage/app/public'):
        path = BACKEND / relative
        if not path.resolve().is_relative_to(BACKEND):
            raise SystemExit('Unsafe storage path')
        path.mkdir(parents=True, exist_ok=True)
    (ROOT / '.demo').mkdir(exist_ok=True)
    keyfile = ROOT / '.demo/key'
    if not keyfile.exists():
        keyfile.write_text('base64:' + base64.b64encode(os.urandom(32)).decode())
        keyfile.chmod(0o600)
    database = BACKEND / 'database/demo.sqlite'
    if database.is_symlink():
        raise SystemExit('Unsafe demo database path')
    database.touch(exist_ok=True)
    for directory in (BACKEND, FRONTEND):
        if not (directory / '.env').exists():
            shutil.copyfile(directory / '.env.example', directory / '.env')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['install', 'setup', 'backend', 'frontend', 'build', 'lint', 'standards', 'test-frontend', 'test-backend', 'typecheck'])
    action = parser.parse_args().action
    prepare()
    env = environment()
    if action == 'install':
        run(['composer', 'install', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-interaction'], BACKEND, env)
        run(['pnpm', 'install', '--frozen-lockfile', '--ignore-scripts'], FRONTEND, env)
    elif action == 'setup':
        link = BACKEND / 'public/storage'
        if link.exists() or link.is_symlink():
            if not link.is_symlink() or link.resolve() != BACKEND / 'storage/app/public':
                raise SystemExit('Unsafe demo public storage link')
        run(['php', 'artisan', 'package:discover', '--ansi'], BACKEND, env)
        run(['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction'], BACKEND, env)
        run(['php', 'artisan', 'db:seed', '--class=DatabaseSeeder', '--force', '--no-interaction'], BACKEND, env)
        if not link.is_symlink():
            run(['php', 'artisan', 'storage:link', '--no-interaction'], BACKEND, env)
    elif action in ('backend', 'frontend'):
        command = ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port=8100'] if action == 'backend' else ['pnpm', 'exec', 'next', 'dev', '--webpack', '--hostname', '127.0.0.1', '--port', '3100']
        os.chdir(BACKEND if action == 'backend' else FRONTEND)
        os.execvpe(command[0], command, env)
    elif action == 'test-backend':
        # Deliberately select the portable reviewable suite, never the private-data suite.
        testenv = dict(env, APP_ENV='testing', DB_DATABASE=':memory:', CACHE_STORE='array', SESSION_DRIVER='array')
        run(['php', '-d', 'memory_limit=512M', 'vendor/bin/phpunit', '--filter=DemoPublicApiTest|SellableTariffTest|LessonBodyContractTest|RbacLegacyRoleMapTest'], BACKEND, testenv)
    else:
        commands = {'build': ['pnpm', 'build'], 'lint': ['pnpm', 'lint'],
                    'standards': ['pnpm', 'run', 'check:standards'], 'test-frontend': ['pnpm', 'test'],
                    'typecheck': ['pnpm', 'exec', 'tsc', '--noEmit']}
        run(commands[action], FRONTEND, env)


if __name__ == '__main__':
    main()
