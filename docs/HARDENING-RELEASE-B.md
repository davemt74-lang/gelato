# Hardening Release B — Durable operations

## Purpose and scope

This release follows Hardening Release A at
`6040375acff4197cf0d5126959c724d98e7f6b45` and addresses seven findings from the
codebase audit at `5d2f332e630dd3efda07fd36224a05fe2420726b`.

| Finding | Change |
| --- | --- |
| FE-05 (P1) | Server-backed account invitations, profile and role edits, tenant suspension, audit records, and session invalidation replace browser-only account controls. |
| FE-04 (P1) | Public applications and actual attachment bytes reach the database/private storage before a receipt is displayed; authorized reviewers share one durable queue. |
| DATA-01 (P1) | Jobs, equipment, service contacts and knowledge documents use distinct PDO markers with native prepares enabled. |
| DATA-02 (P1) | Stock movements use exact four-decimal arithmetic and commit the stock balance, ledger and audit together; an excessive reduction is rejected. |
| DATA-03 (P1) | Direct, PIN and agent attendance transitions serialize against the employee and recheck the current clock/break state under locks. |
| DATA-04 (P1) | Purchase-order submit, receive and cancel reread canonical state under locks; concurrent requests cannot overwrite a completed transition. |
| DATA-05 (P2) | An established inventory base unit cannot be changed in place, preventing reinterpretation of stock, costs and order history. |

The account review also closes connected privilege-delegation and suspended-device
gaps. These are supporting controls for FE-05, not additional claims against the
original finding count. Food Monster's capability map accounts for all 141 API
files; the new entries remain discovery-only and grant no execution permissions.

## Account administration

Managers invite staff through `api/users.php`. The server validates the actor's
current membership and permissions inside the transaction, validates the selected
tenant role, saves a hashed, expiring invitation token, and records an audit event.
The manager never chooses or receives a staff password. The recipient follows the
emailed link and chooses their own password. An existing global account must prove
its current password before accepting an invitation to another restaurant.

New restaurant membership and role access begin only when the recipient accepts.
Before acceptance, the inviting restaurant sees only the contact details supplied
with its invitation. It cannot inspect an existing account's private profile in
another restaurant. A failed email delivery is displayed explicitly; a manager can
retry or cancel the pending invitation. Acceptance checks expiry, replay, email
binding, the inviter's current authority and the recipient's current status again
under locks. Competing acceptance requests can grant access only once.

Profile, local job-title, role and status changes persist in the database. Shared
global personal details are editable by the account holder; a restaurant manager
cannot overwrite another restaurant's shared identity profile. Role grants are
bounded by the manager's own effective permissions. Owner roles, self-suspension
and self-escalation receive explicit protection. Role permission edits apply the
same delegation rules and invalidate affected sessions.

Each session is bound to the current membership version as well as its password
version. Suspending a restaurant membership invalidates its sessions on the next
authenticated request. Reactivation does not revive those old sessions. Sessions
for another still-active restaurant remain valid. This versioned contract requires
one fresh sign-in after installation for all existing browser sessions.

Paired glasses devices also require an active accountable user in the device's
own restaurant. Expo handoff rechecks that account under locks before kitchen
writes. Legacy devices with missing or inactive accountability need an active
manager to use the existing revoke-and-re-pair flow. Device token scopes and wearer
ownership rules remain governed by the existing device contracts; broader device
ownership work is still an open audit item.

## Hiring and job publishing

The public application form posts actual data and files to the server. It accepts
only the server-selected restaurant and a currently published, unexpired job.
Successful retries with the same submission key return the original receipt;
changed content under the same key is rejected. The database audit and application
metadata commit together. Failed writes remove newly created private file bytes.
Private attachments require an authorized reviewer in the same restaurant.

The shared hiring queue supports durable status history and private reviewer
notes. Employee conversion uses the saved applicant details and creates an account
invitation; repeating the conversion returns the existing linked result. Job saves
wait for the server response and cannot overwrite a different job through a slug
collision. Existing browser-only prototype applications are not imported: they
were never reliably delivered or attributable. Existing database applications are
preserved and receive review identifiers.

The public form uses the canonical hiring fields. The old visual form builder now
labels its saved layout as a local draft instead of claiming to publish it.
See [hiring details](HARDENING-HIRING.md) for attachments, limits and retry behavior.

## Inventory, receiving and attendance

Inventory balances and movement deltas use the same bounded, four-decimal quantity
representation. Negative balances, unsupported precision and an excessive stock
deduction fail before a ledger entry is written. Receipts lock inventory IDs in a
consistent order before the order and its lines. Cancellation closes only the
unreceived remainder of a partial order; existing receipts, stock and price history
remain intact. Need More coverage reflects the remaining open order quantity.

Established base units are immutable. This release does not convert historical
stock or repair past inconsistent ledger entries. A future explicit conversion
workflow must preserve quantities, costs, receipts and their original units.
See [inventory and purchasing details](HARDENING-INVENTORY-PURCHASING.md).

Clock-in, clock-out and break actions use a common locking and transaction policy
across normal, trusted-PIN and agent routes. Attendance changes and their audits
commit together. PIN eligibility is rechecked after locking, so a concurrent role
change cannot authorize a stale employee PIN operation. Existing duplicate open
entries or breaks fail with a manager-correction message; history is not silently
rewritten. See [attendance and native writes](HARDENING-TIMECLOCK-NATIVE-WRITES.md).

## Installation

1. Back up the database, `config.php`, uploads and runtime/private storage.
2. Upload and extract the full deployment ZIP into the application directory,
   preserving existing configuration and live data. Use the inner
   `gelato-full-deploy.zip` if downloading through GitHub's outer artifact wrapper.
3. Sign in again, then run the normal `upgrade.php` process. It applies
   `20261229_user_account_lifecycle.sql` and `20261230_job_applications.sql`, as well
   as any earlier pending migrations. Repeat upgrades must report no pending work.
4. Check `deployment-check.php`. Release A's private-storage root must remain
   outside the public document root, including the new hiring document namespace.
5. Confirm outbound PHP mail works before inviting staff. Delivery failures are
   reported by the account screen, and no membership is granted before acceptance.
6. Enable PHP's ZIP extension for DOCX validation. PHP upload/post limits must
   accommodate the desired application attachments; see the hiring guide.
7. Hard-refresh the workspace and verify the installed commit in `DEPLOY_SHA.txt`.

Production installation is a separate action from source merge and package
verification. The release process does not deploy the live hosting directory.

## Acceptance gates

The `Hardening Release B - Durable Operations` workflow installs a fresh MariaDB
database, applies the complete migration history, verifies repeat-upgrade
idempotence and runs native PHP syntax checks. Focused suites cover:

- Account DB transactions, permission boundaries, complete role permissions,
  independent tenant sessions, private pending identities, email delivery failure,
  token replay/expiry and a real two-process acceptance race.
- Actual account HTTP controllers and a Chromium desktop/mobile journey for
  invitation, acceptance, sign-in, durable editing, suspension and visible errors.
- Hiring HTTP submission, byte-identical private attachment downloads, independent
  reviewers, tenant isolation, retries, stale status rejection and rollback cleanup.
- Concurrent inventory deductions and receipts, both cancel/receive ordering cases,
  exact quantities, unit protection, partial cancellation and failed-audit rollback.
- Concurrent clock and break transitions, stale PIN eligibility and atomic audit
  behavior, plus actual native-PDO equipment/contact/knowledge HTTP saves.
- Paired-device active-account checks and existing glasses foundation/Expo behavior.
- Existing identity, customer, workspace, timeclock, Need More, purchasing and
  deployment-entrypoint contracts where relevant, alongside the repository's
  existing pull-request and post-merge workflows.

Test mail is captured locally in CI; these tests do not send email externally.
Concurrency and rollback tests use disposable database records and temporary
triggers. Passing syntax or source-contract checks alone is not runtime acceptance.

## Remaining audit work

Release A resolved 11 findings and Release B targets these seven. The remaining 13
original findings comprise three P1 AI/runtime issues and ten P2 findings. AI
confirmation binding, tool-response size and device ownership need the next focused
hardening pass. The original performance, financial-volume, scheduling, recipe,
retention and UI opportunities remain tracked separately. This release does not
certify the entire application as free of bugs.
