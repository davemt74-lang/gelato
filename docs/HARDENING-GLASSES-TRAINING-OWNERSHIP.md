# Glasses training session ownership and atomic endings

## Finding AI-03

The device `training.session_end` route previously forwarded only the authenticated device's restaurant, an arbitrary session public ID, an end status, a reason and the pairing user's numeric ID. The domain function selected a session by restaurant and public ID without checking its device or locking the row. A second authenticated device in the restaurant could therefore end another device's session. Concurrent completion and cancellation could also read the same active session and leave its glasses assignment and canonical employee training assignment in different final states.

## Authorization and attribution

The device route now passes the full authenticated device row to `glasses_training_session_end`. Within the transaction, the domain function:

1. Rechecks the pairing's active global account and active membership in the actual restaurant using the Release B accountability helper.
2. Locks the current device row by restaurant, internal device ID and public device ID. It verifies current active/revoked status, paired account and token hash. A cached row does not survive revocation or re-pairing.
3. Selects and locks the session with **restaurant ID, authenticated device ID and session public ID in the same query** before inspecting its state.
4. Uses the freshly verified `paired_by` identity for device events and audit attribution. The trainee, assigning manager and request body cannot replace that actor.

Another device, an unknown session and a foreign restaurant receive the same session-not-found response at the scoped session lookup. Completed sessions remain subject to the same ownership check; the replay path cannot disclose another device's history.

The explicit `glasses_training_session_end_managed` function preserves the Vision Lab management override. It requires the existing `glasses.view` and `glasses.manage` permissions from current database roles and grants, including canonical owner semantics. It uses `accounts_actor` and a current locking permission read so an older outer transaction snapshot cannot restore a revoked grant. Management does not inherit device authority. An authorized manager can still close a session after its physical device or paired account has been revoked, and that audit identifies the manager.

Runtime training retains its existing manager, restaurant, location, employee, device and `runtimePublicId` checks. Runtime lifecycle calls now recheck manager permissions within the transaction and check the current locked runtime binding. The runtime's manager identity is preserved for start and end attribution.

## Transaction and replay contract

Training lifecycle transitions use a savepoint-aware transaction wrapper. The restaurant ordering lock aligns these short transitions with canonical account and role mutations and serializes competing training starts and endings. After account checks, lifecycle code locks the device, then the target or active session, then its glasses assignment and canonical training assignment. The scoped session lock remains explicit even though the restaurant ordering lock also serializes these calls. No provider, filesystem or network operation occurs inside the transaction.

The ordering lock is intentionally limited to training lifecycle calls. It trades concurrency between training transitions in one restaurant for a simple, reviewable order that also prevents fresh manager checks from racing canonical account/role changes. Other restaurants have independent ordering rows.

Before changing an active session, the domain checks that its parent assignment belongs to the same restaurant, device and employee and still has active status. The canonical assignment must belong to the same restaurant and employee, reference the same glasses program, and remain incomplete. Inconsistent existing references fail with a manager-correction error and preserve their evidence.

| Committed ending | Session | Glasses assignment | Canonical assignment | Completion timestamps |
| --- | --- | --- | --- | --- |
| Completion | `completed` | `completed` | `completed` | Present in both assignments |
| Cancellation | `cancelled` | `assigned` | `assigned` | Empty in both assignments |
| Interruption | `interrupted` | `assigned` | `assigned` | Empty in both assignments |

Each new start or ending records a device event and an audit entry in the same transaction as the affected session and assignments. Failure to write the audit rolls back the lifecycle operation. When called inside a caller-owned transaction, the savepoint rolls back only the failed operation and preserves earlier caller work.

An authorized retry of a terminal session returns its original terminal status, timestamp and reason without writing another assignment, device event or audit entry. A conflicting retry does not change the first committed outcome. A retry of an older interrupted session cannot affect a newer active session for the same assignment. Returning the current locked session values also avoids stale state from an older repeatable-read snapshot.

Start now locks before reading the assignment state, rechecks current pairing/account authority, and atomically binds an unbound assignment to the winning device. A concurrent completion cannot be followed by a stale start that reactivates the completed assignment. Existing database unique constraints on active device and active employee slots remain in force.

## Acceptance tests

Run against a clean `database/install.sql` database after applying every migration through `UpgradeService`, with native PDO prepares enabled:

```sh
php tests/hardening-glasses-training-contract.php
php tests/hardening-glasses-training-concurrency.php
node tests/hardening-glasses-training-http.cjs
php tests/glasses-v11-training-identity-contract.php
php tests/glasses-v11-production-evidence-contract.php
php tests/glasses-employee-runtime-contract.php
php tests/hardening-glasses-account-authority.php
```

The focused database contract covers independent devices, all three unauthorized end statuses, foreign-tenant denial, current account/pairing checks with cached device rows, corrupted parent and canonical ledger references, correct completion/interruption state, idempotent replay, real device/manager actor attribution, fresh manager authority, revoked-device management cleanup, runtime integration, and top-level/nested audit rollback.

The concurrency contract launches independent PHP processes, announces their actual transaction-lock attempts, and verifies every worker appears in MariaDB's `INNODB_TRX` as `LOCK WAIT` before releasing the held row. It covers duplicate and conflicting endings, manager/device competition, start/end races, two devices competing for one unbound assignment, account/permission changes while a request waits, and revoked grants behind an older outer transaction snapshot. Unexpected PDO errors or deadlocks fail the test; they are not accepted as valid application denials.

The HTTP contract starts a local PHP server and uses actual paired-device bearer tokens for two devices in one restaurant and a third device in another restaurant. It verifies payload spoofing cannot replace device/restaurant/actor identity, suspended membership/global-account denial, authorized completion/replay, manager overrides through independent real login sessions, permission/tenant/CSRF denial, durable ledger state and audit attribution. Every new PHP fixture or test rejects non-CLI requests before loading bootstrap; the HTTP test verifies GET, POST and HEAD rejection.

### Runtime requirements

- PHP 8.2 with PDO MySQL and mbstring, plus the extensions required by the existing fully upgraded application.
- MariaDB 10.11 with InnoDB, native prepared statements, a local `config.php`, and a disposable acceptance database.
- The database test account needs permission to create/drop a test trigger and inspect `information_schema.INNODB_TRX` (the CI MariaDB root account provides these).
- `proc_open` must be enabled for the deterministic process races.
- Node.js 22 for the HTTP contract's built-in `fetch`, `getSetCookie` and timeout support.
- Local PHP/HTTP and database access only. These tests call no external services or model providers.

PHP and MariaDB are unavailable in the editing workspace. Database, concurrency, PHP lint and live HTTP execution must be confirmed by the release CI job. Local Node syntax checks and diff review do not substitute for those gates.
