#!/usr/bin/env python3
"""Verify fresh archive exclusions and real Apache denials without PHP or production data."""
import argparse
import grp
import os
from pathlib import Path, PurePosixPath
import pwd
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def verify_archive(archive, destination):
    with zipfile.ZipFile(archive) as z:
        names = set()
        for item in z.infolist():
            path = PurePosixPath(item.filename)
            check(not path.is_absolute() and '..' not in path.parts, 'Unsafe archive path')
            check((item.external_attr >> 16) & 0o170000 != 0o120000, 'Symlinks are not deployable file entries')
            name = str(path).rstrip('/')
            names.add(name)
            check(name not in ('config.php', '.env'), 'Live configuration included')
            check(not (name == 'uploads' or name.startswith('uploads/')), 'Runtime uploads included')
            check(not name.startswith(('includes/gelato-private-storage/', 'gelato-private-storage/')), 'Private uploads included')
            if name == 'storage' or name.startswith('storage/'):
                check(name in ('storage', 'storage/.htaccess'), 'Runtime storage data included: ' + name)
        for required in ('.htaccess', 'storage/.htaccess', 'includes/private-storage.php', 'includes/secret-key.php'):
            check(required in names, 'Missing deploy protection: ' + required)
        z.extractall(destination)


def verify_http(public_root, app_root, work):
    apache = shutil.which('apache2') or shutil.which('httpd')
    check(apache is not None, 'Apache is required; install apache2 for --http')
    module_dir = Path('/usr/lib/apache2/modules')
    check(module_dir.is_dir(), 'Apache module directory is unavailable')
    probe = b'GELATO-PRIVATE-PROBE-MUST-NOT-BE-SERVED'
    paths = [
        'storage/keys/application.key',
        'storage/vision-media/private.jpg',
        'includes/gelato-private-storage/purchasing-documents/7/private.pdf',
        'includes/gelato-private-storage/task-proofs/7/private.jpg',
        'gelato-private-storage/private.txt',
    ]
    for relative in paths:
        target = app_root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(probe)
    (public_root / 'control.txt').write_bytes(b'public-control')
    (app_root / 'public-control.txt').write_bytes(b'application-public-control')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    user = pwd.getpwuid(os.getuid()).pw_name
    group = grp.getgrgid(os.getgid()).gr_name
    # CI executes as its unprivileged runner. Refuse accidental root server execution.
    check(os.getuid() != 0, 'Run the Apache test as an unprivileged user, not root')
    modules = ['mpm_event', 'authz_core', 'authz_host', 'dir', 'mime', 'headers', 'rewrite']
    config = work / 'apache.conf'
    lines = [f'ServerRoot "{work}"', f'PidFile "{work / "apache.pid"}"', f'ErrorLog "{work / "apache-error.log"}"',
             'ServerName 127.0.0.1', f'Listen 127.0.0.1:{port}', f'User {user}', f'Group {group}']
    lines += [f'LoadModule {module}_module "{module_dir / ("mod_" + module + ".so")}"' for module in modules]
    lines += [f'DocumentRoot "{public_root}"', 'TypesConfig /etc/mime.types',
              '<Directory />', 'Require all denied', '</Directory>',
              f'<Directory "{public_root}">', 'AllowOverride All', 'Options FollowSymLinks', 'Require all granted', '</Directory>']
    config.write_text('\n'.join(lines) + '\n')
    process = subprocess.Popen([apache, '-f', str(config), '-DFOREGROUND'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    base = f'http://127.0.0.1:{port}'
    try:
        ready = False
        for _ in range(100):
            if process.poll() is not None:
                break
            try:
                with urllib.request.urlopen(base + '/control.txt', timeout=1) as response:
                    ready = response.status == 200 and response.read() == b'public-control'
                if ready:
                    break
            except (urllib.error.URLError, TimeoutError):
                time.sleep(0.05)
        if not ready:
            error_log = work / 'apache-error.log'
            detail = error_log.read_text() if error_log.exists() else ''
            if process.poll() is not None:
                detail += process.communicate(timeout=2)[1].decode(errors='replace')
            raise AssertionError('Apache did not serve the public control: ' + detail[-4000:])
        prefix = '/' + str(app_root.relative_to(public_root)).strip('/')
        with urllib.request.urlopen(base + prefix + '/public-control.txt', timeout=5) as response:
            check(response.status == 200 and response.read() == b'application-public-control',
                  'Private-path rules must preserve normal public application files')
        for relative in paths + ['%73torage/keys/application.key']:
            try:
                with urllib.request.urlopen(base + prefix + '/' + relative, timeout=5) as response:
                    status, body = response.status, response.read()
            except urllib.error.HTTPError as error:
                status, body = error.code, error.read()
            check(status == 403 and probe not in body, f'Private path was not denied: {relative} (HTTP {status})')
        # Root-level rules must still protect storage after the per-directory file is absent.
        (app_root / 'storage/.htaccess').unlink()
        try:
            urllib.request.urlopen(base + prefix + '/storage/keys/application.key', timeout=5)
            raise AssertionError('Missing runtime guard bypassed application-root denial')
        except urllib.error.HTTPError as error:
            check(error.code == 403, 'Application-root denial must remain effective')
    finally:
        if process.poll() is None:
            process.terminate()
        try:
            process.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.communicate(timeout=5)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--http', action='store_true')
    args = parser.parse_args()
    repo = Path(__file__).resolve().parent.parent
    with tempfile.TemporaryDirectory(prefix='gelato-private-package-') as temp:
        work = Path(temp)
        archive = args.archive
        if archive is None:
            archive = work / 'fresh-deploy.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                for relative in ('.htaccess', 'storage/.htaccess', 'includes/private-storage.php', 'includes/secret-key.php'):
                    z.write(repo / relative, relative)
        app_root = work / 'public/gelato'
        app_root.mkdir(parents=True)
        verify_archive(archive, app_root)
        if args.http:
            verify_http(work / 'public', app_root, work)
        print('private storage package protections passed' + (' (Apache HTTP)' if args.http else ''))


if __name__ == '__main__':
    main()
