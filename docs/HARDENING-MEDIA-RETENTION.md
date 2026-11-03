# Training media retention

Release D addresses audit finding AI-04. The saved retention deadline is an
access and training-eligibility boundary, even when filesystem cleanup has not
run. It does not change saved consent or extend an existing deadline.

## Access and selection

`glasses-vision-media-retention.php` provides a shared active-status/UTC-deadline
predicate for PHP and SQL. The deadline is exclusive, including microseconds.
A missing or malformed PHP deadline is unavailable; a database `NULL` retains
the existing explicitly unbounded policy. Server-local timezone settings do
not reinterpret UTC capture deadlines.

An authorized manager receives HTTP 410 for an expired image that still has an
active database row. Media remains authenticated, tenant scoped and uncached.
Training ZIPs require the same management permission as their private images;
view-only access cannot bypass that boundary through an archive.
Export manifests, visual quality, duplicate discovery, curated camera photos,
retraining candidates, V11 assembly/mining and release creation exclude expired
media. Metadata needed for historical review remains in the database; the
catalog marks logically expired media as expired before physical cleanup.

Training ZIP downloads verify every manifest image against its current tenant,
media identity, SHA-256, active status and retention deadline. An older ZIP
cannot extend the availability of the images it contains. Building an archive
checks deadlines while copying and again before recording the artifact.
Qualification counts and media/annotation checks use the exact surviving
release members, so excluded historical samples cannot inflate the score.

Qualification and cleanup serialize on the release row. Qualification holds
current source locks and repeats its deadline check after ZIP work and before
the final update. New experiments and new/retried training starts also check
source availability. Existing model and historical training records are not
rewritten. Copies previously downloaded outside Gelato cannot be recalled by
the application.

## Bounded host cleanup

The existing manager `expire` action cleans a bounded tenant-specific page.
The new host command processes a bounded global page and a bounded page of
derived training ZIPs:

```sh
php /absolute/path/to/gelato/scripts/expire-vision-training-media.php --limit=500 --release-limit=100
```

Configure that command in the hosting scheduler, for example every five
minutes, using the application's normal PHP account and database configuration.
No host scheduler or production deployment is installed by this code release.
HTTP access and training eligibility do not depend on scheduling the command.

The command keeps independent media/release scan cursors in configured private
storage. An exclusive file lock prevents overlapping workers. A completed scan
wraps to the beginning, so newly expired records and failures are retried without
blocking later IDs. Exit status 1 and structured failure counts identify an
incomplete cleanup. The process is CLI-only and returns 404 over HTTP.

Deletion checks the tenant-relative path, directory accessibility and symlinks.
An inaccessible file is never reported as absent or deleted. Filesystem failure
keeps the row retryable and records an audit event; other selected rows continue.
Missing bytes are recorded explicitly. Successful retries do not duplicate
deletion events. Derived ZIP cleanup retains manifests/hashes and marks the
release expired after removing its private files. Physical stock, production
build outcomes and KDS truth are unaffected.

## Verification

The Release D workflow runs native MariaDB tests, actual HTTP sessions, an
unprivileged filesystem fixture and a deterministic qualification/cleanup lock
wait. It checks expiry before cleanup, UTC/microsecond boundaries, tenant and
role denial, live-image success, manifest/quality exclusion, derived ZIP denial,
accurate qualification counts, idempotence, missing bytes, deletion failure and
retry. The same new availability regression must fail on Release C's original
production source before it is run against Release D.
