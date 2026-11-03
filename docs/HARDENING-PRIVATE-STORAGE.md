# Private storage and deployment safeguards

Release A fixes audit findings DEPLOY-01, DEPLOY-02, and AUTH-05.

## Installation

Extract the full deployment ZIP into the existing application directory. Preserve
`config.php`, `.env`, uploads, and all runtime storage data. In particular, preserve
`storage/keys/application.key`: existing encrypted credentials require that exact key.

The package contains `storage/.htaccess` and the application-root protection rules.
It contains no runtime keys, private documents, proofs, or uploads. Apache must allow
the included `.htaccess` rules. An equivalent denial must be configured when hosting
on a server that does not implement Apache rules.

Open `deployment-check.php` while signed in as an owner. It reports whether the private
upload directory is outside the application and known website document roots.

For a site installed directly at its web document root, the default upload directory
is `gelato-private-storage/<installation-hash>` beside the application directory.
The installation hash prevents different installations from sharing organization-ID
namespaces. Web requests and CLI workers use the same default path.

For an application installed under `public_html/restaurant`, the sibling default
would still be public. Set an explicit external directory in the existing config:

```php
'storage' => [
    'private_root' => '/home/ACCOUNT/gelato-private/RESTAURANT',
    'public_root' => '/home/ACCOUNT/public_html',
],
```

Replace the example account and directory names with the host's actual paths. PHP
must be able to create the private directory under its parent. `public_root` is a
validation boundary, particularly useful for CLI workers and aliased URL mounts.
It does not change where the website is served. A configured private root inside
the website, or a private root containing the website, is rejected.

## Existing documents and task proofs

Older uploads were written under `includes/gelato-private-storage` because the old
application-root value contained an unresolved `..` segment. The new root rules deny
HTTP access to this legacy tree immediately. After normal database authorization,
retrieving an old document copies it atomically to the external private root,
preserves its database-relative path, and removes the original file. Concurrent
readers accept only an identical completed copy. The application does not fall back
to serving a public URL.

If external storage is not configured safely or cannot be created, uploads fail and
reads report that storage requires administrator configuration. They do not silently
write back into the website. Existing legacy bytes remain untouched on a failed move.

The authenticated purchasing and task-proof endpoints retain their organization,
permission, and assignment checks. Their storage lookup additionally binds the file
to its server-selected upload namespace and organization. Traversal, absolute paths,
and linked files/directories cannot redirect an upload or read.

## Encryption keys

The existing key location is retained for compatibility. Independent PHP processes
now acquire the same exclusive initialization lock and recheck existence before
generating a key. A new key is published only after complete writing, with private
permissions. An invalid or unreadable existing key produces an error and is never
replaced automatically. Recover the original key from backup if it is lost or corrupt.

## Verification

Run the behavioral regressions with PHP 8.2, Sodium, and process execution enabled:

```sh
php tests/private-storage-behavior.php
php tests/secret-key-concurrency.php
```

The first test uses real temporary files, checks web/CLI consistency, rejects unsafe
paths and links, enforces organization prefixes, and migrates an old upload. The
second starts 12 independent processes against an initially absent key and verifies
that all receive the same persisted key. It also proves existing and invalid keys
are not overwritten.

Run the Apache test as an unprivileged user with Apache installed:

```sh
python3 tests/private-storage-package.test.py --http
python3 tests/private-storage-package.test.py --archive gelato-full-deploy.zip --http
```

The test extracts a fresh package into a subdirectory, creates harmless private-file
probes, verifies a public control is reachable, and requires HTTP 403 for every
private probe. It also removes the per-directory storage guard and verifies the
application-root denial still protects the file. The Full Deploy Package workflow
runs this against the actual ZIP before publishing it. The production runtime
workflow verifies the same archive exclusions and guard inclusion.
