# Hardening Release A — Identity and private data

## Purpose

This release addresses the first security and privacy group from the codebase audit
at `5d2f332e630dd3efda07fd36224a05fe2420726b`. Its acceptance is scoped to the findings
below. It does not certify that the remaining audit findings are fixed.

| Finding | Change |
| --- | --- |
| AUTH-01 | Email-first customer enrollment, recipient-chosen credentials, atomic verification, and verified identity at customer access boundaries. |
| AUTH-02 | Shared sign-in session contract with credential binding, idle timeout, and absolute lifetime across password, customer, first-owner, wholesale invitation, and trusted-terminal PIN sign-in. |
| AUTH-03 | Shared atomic failure counter and lockout in customer password verification. |
| AUTH-04 | Password recovery locks the account and token, rechecks expiry inside the transaction, and consumes all outstanding reset tokens for that account. |
| AUTH-05 | Exclusive, atomic first-use key initialization that preserves existing key material. |
| FE-01 | Workspace records are filtered by server permissions and organization before rendering. |
| FE-02 | Server bootstrap remains in memory; private browser drafts are scoped by organization, user, and login and cleared on sign-out/account change. |
| FE-03 | Public brand images use DOM properties instead of interpolated HTML. |
| DEPLOY-01 | Private document paths use canonical external storage, with authorized migration of legacy uploads. |
| DEPLOY-02 | Deployment ZIPs retain storage protection while excluding live storage/configuration; actual archive contents receive Apache HTTP-denial tests. |
| DATA-08 | Reading workspace/jobs no longer inserts or publishes demonstration jobs. |

## Installation

1. Back up the database, application configuration, uploads, and runtime storage.
2. Upload and extract the **inner full deployment ZIP** directly into the application
   directory, preserving the existing `config.php`, `uploads/`, and storage data.
   The package includes `storage/.htaccess` but no live key or document contents.
3. Sign in again, then run the normal `upgrade.php` process once. The migration
   `20261228_customer_email_verification.sql` creates the customer-verification table.
4. Open `deployment-check.php` and confirm that private storage resolves outside the
   website document root. Subdirectory hosting may require explicit
   `storage.private_root` and `storage.public_root` settings; see
   [private storage installation](HARDENING-PRIVATE-STORAGE.md).
5. Verify that outbound account email works using the site's configured PHP mail
   transport. New customer enrollment and previously unverified customer linkage
   require delivery of the confirmation link.
6. Hard-refresh the workspace and confirm the packaged revision in `DEPLOY_SHA.txt`.

The application revokes all sessions created before this session contract. Users
must sign in again once after installation. Password recovery subsequently revokes
every password/PIN session for the affected account on its next authenticated request.

Old shared browser-only chat, training history, roster, and audit caches cannot be
attributed reliably to a user. The first hardened workspace load removes those
caches. They are not migrated. Database records are preserved. New local drafts
survive reloads within the same login/tab session and clear on sign-out. Anonymous
ordering carts and nonprivate device preferences are preserved.

## Session policy

`security.session_idle_timeout_seconds` and
`security.session_absolute_lifetime_seconds` default to the existing
`session_lifetime_seconds` value (eight hours in the example configuration). The
minimum supported setting is fifteen minutes. These deadlines are enforced by the
application on protected requests, independently of PHP garbage collection.

Successful sign-in replaces prior session state, rotates the session ID, and creates
a fresh browser-state identity. Password sign-in carries the exact verified password
hash through session establishment, preventing a concurrent password reset from
granting a new session under stale credentials. Credential material is never included
in the current-user/workspace response.

## Acceptance tests

The `Hardening Release A - Identity and Private Data` workflow installs MariaDB,
applies every migration, verifies repeat-upgrade idempotence, and runs:

- `tests/hardening-auth-contract.php`: session binding/expiry, independent-session
  revocation, token expiry/replay, lockout, and a real two-process reset race.
- `tests/hardening-auth-http.cjs`: actual login/reset/protected-page requests with
  independent cookie jars, permission-filtered workspace output, logout, and guarded
  test entrypoints.
- `tests/customer-account-contract.php`: verified customer creation/linkage,
  tenant boundaries, consent, lockout, token handling, and legacy identity protection.
- `tests/customer-email-http.cjs`: actual public signup/verification controllers
  with a captured local sendmail transport. No email is sent externally by this test.
- `tests/hardening-workspace-contract.php`: independent permission gates, tenant
  isolation, effective role permissions, and absence of read-side job writes.
- `tests/hardening-browser-state.test.js` and
  `tests/hardening-public-brand.test.js`: account/logout isolation and inert brand
  rendering, including preservation of ordering carts.
- `tests/private-storage-behavior.php`: real filesystem containment, permissions,
  namespace checks, immutable writes, and legacy migration.
- `tests/secret-key-concurrency.php`: twelve independent key-initialization processes
  receive the same persisted key; invalid existing keys are preserved and rejected.
- `tests/private-storage-package.test.py --http`: fresh-package public control and
  denied private files, including encoded paths and an absent per-directory guard.

Existing customer, menu, ordering, CRM, POS, voice, and deployment checks remain in
the release gates. The full and runtime packaging workflows also validate their
actual ZIP contents and HTTP protection.

## Next audit phase

Release B covers durable administration/hiring, native-PDO write defects, inventory
ledger consistency, receiving/cancellation state transitions, and timeclock
concurrency. Remaining AI confirmation, large tool-response, device ownership,
financial-volume, scheduling, recipe, retention, and UI findings remain tracked by
the original audit and must not be described as resolved by Release A.
