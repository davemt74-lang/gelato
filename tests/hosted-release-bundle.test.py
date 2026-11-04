"""Exercise the hosted signing command against the actual deploy artifact."""

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from zipfile import ZipFile


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('archive', type=Path)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    signer = Path(__file__).resolve().parents[1] / 'scripts' / 'sign-hosted-release.php'
    with ZipFile(args.archive) as archive:
        version = archive.read('GELATO_VERSION.txt').decode().strip()
        commit = archive.read('DEPLOY_SHA.txt').decode().strip()
    if not re.fullmatch(r'(?:0|[1-9][0-9]{0,8})(?:\.(?:0|[1-9][0-9]{0,8})){2}', version):
        print('Hosted signing checks apply to stable release versions.')
        return

    key, public = subprocess.check_output([args.php, '-r', '''
        $pair = sodium_crypto_sign_keypair();
        echo base64_encode(sodium_crypto_sign_secretkey($pair)), " ",
            base64_encode(sodium_crypto_sign_publickey($pair));
    '''], text=True).split()
    env = os.environ | {'GELATO_HOSTED_SIGNING_KEY': key}

    def sign(*arguments, environment=env):
        return subprocess.run([args.php, signer, args.archive, *arguments], env=environment,
                              capture_output=True, text=True, timeout=60)

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        notes = root / 'notes.txt'
        notes.write_bytes('Owner workflow fixes\r\n  Café $(not a command)\r\n'.encode())
        bundle = root / 'signed'
        result = sign('--bundle', bundle, version, notes, commit)
        assert result.returncode == 0, result.stderr
        assert {file.name for file in bundle.iterdir()} == {
            'gelato-hosted.zip', 'gelato-hosted.sha256', 'gelato-hosted.sig', 'release-notes.txt', 'release.json',
        }
        package = bundle / 'gelato-hosted.zip'
        checksum = hashlib.sha256(args.archive.read_bytes()).hexdigest()
        assert hashlib.sha256(package.read_bytes()).hexdigest() == checksum
        assert (bundle / 'gelato-hosted.sha256').read_text() == f'{checksum}  gelato-hosted.zip\n'
        assert (bundle / 'release-notes.txt').read_text() == 'Owner workflow fixes\n  Café $(not a command)\n'
        manifest = json.loads((bundle / 'release.json').read_text())
        assert manifest == {
            'version': version, 'channel': 'stable', 'source_commit': commit,
            'package': package.name, 'sha256': checksum,
            'public_key_sha256': hashlib.sha256(base64.b64decode(public)).hexdigest(),
        }
        signature = (bundle / 'gelato-hosted.sig').read_text().strip()
        verifier = '''
            $payload = "Gelato Hosted Release v1\n{$argv[1]}\n{$argv[2]}\n";
            exit(sodium_crypto_sign_verify_detached(base64_decode($argv[3], true),
                $payload, base64_decode($argv[4], true)) ? 0 : 1);
        '''
        subprocess.run([args.php, '-r', verifier, version, checksum, signature, public], check=True)
        assert subprocess.run([args.php, '-r', verifier, version, 'f' * 64, signature, public]).returncode == 1
        legacy = sign()
        assert legacy.returncode == 0 and legacy.stdout.strip() == signature

        error_dir = root / 'rejected'
        wrong_version = '0.0.0' if version != '0.0.0' else '1.0.0'
        wrong_commit = 'f' * 40 if commit != 'f' * 40 else 'e' * 40
        cases = [
            (wrong_version, notes, commit, env),
            (version, notes, wrong_commit, env),
            (version, root / 'missing.txt', commit, env),
            (version, notes, commit, env | {'GELATO_HOSTED_SIGNING_KEY': ''}),
            (version, notes, commit, env | {'GELATO_HOSTED_SIGNING_KEY': base64.b64encode(b'x' * 64).decode()}),
        ]
        for requested, notes_path, revision, environment in cases:
            result = sign('--bundle', error_dir, requested, notes_path, revision, environment=environment)
            assert result.returncode != 0 and not error_dir.exists(), result.stderr
        assert sign('--bundle', bundle, version, notes, commit).returncode != 0
        assert (bundle / 'gelato-hosted.sig').read_text().strip() == signature

    print('Hosted release bundle verified, including rejection cases and legacy signatures.')


if __name__ == '__main__':
    main()
