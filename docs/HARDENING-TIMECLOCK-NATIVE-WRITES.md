# Release B: Time-clock serialization and native PDO writes

## Time-clock behavior

All direct, trusted-terminal employee-PIN and confirmed agent transitions now lock
in the same order: user, organization membership, time entry, then break. A
current locking read checks the entry/break state after acquiring that employee
lock. The transaction commits the transition, attendance events and audit record
together. A nested operation uses a savepoint so a failed action does not discard
unrelated work already performed by its caller.

Concurrent clock-ins, break starts/ends and clock-outs have one winning state
transition. A break start racing clock-out either happens before clock-out and is
closed by it, or is rejected after clock-out. It cannot leave an active break on a
closed entry. Confirmed agent proposals still revalidate their expected clock and
break state and require the existing voice verification where applicable.

Manager corrections also use the employee lock. They preserve recorded breaks,
reject times that exclude those breaks, close a remaining open break at the
explicit corrected clock-out time, and retain the correction reason and audit.

Existing duplicate open punches or breaks are **not deleted or silently merged**.
Normal transitions stop with a manager-correction message. An authorized manager
can correct the affected time entry explicitly. No new uniqueness migration is
applied to historical attendance because an existing duplicate would otherwise
block installation or require an unapproved choice about attendance history.
The common locked services enforce the invariant for all production write paths.

## Native PDO statements

Equipment creation, equipment service-contact creation and Knowledge Center
creation now use distinct `created_by` and `updated_by` parameter markers. PDO
native prepares remain enabled. The jobs statement is repaired by the hiring
portion of Release B.

## Acceptance commands

Run after a fresh installation and all migrations in an isolated MariaDB database:

```sh
php tests/hardening-timeclock-concurrency.php
node tests/hardening-native-pdo-http.cjs
php tests/timeclock-voice-contract.php
php tests/timeclock-agent-lifecycle.php
php tests/food-monster-g9b-clock.php --db
```

The concurrency regression starts independent PHP processes and needs
`proc_open`. Its rollback test uses a temporary, tenant-scoped database trigger to
raise an audit error, so the disposable test database account needs `TRIGGER`.
The trigger is removed in a `finally` block.

Employee PIN actions and PIN provisioning recheck current active membership and
assigned employee roles after acquiring the shared employee lock. A test-only
PDO statement barrier pauses a real PIN request at that lock while another
process commits an owner-role assignment; the request must then reject without
changing attendance or audit history.

The HTTP suite starts a local PHP server, signs in through the actual login form,
and creates/edits equipment, contacts and knowledge documents. A second browser
session verifies durability. It also checks CSRF, permissions, invalid input,
cross-tenant edits, actor fields and audit records. Its CLI fixture and the
concurrency test reject direct HTTP execution with status 404.

## Connected account-type controls

Role permission arrays now use complete database rows in account authorization,
the role API, and workspace bootstrap. They do not depend on `GROUP_CONCAT` size
limits. Account-type changes participate in the same organization/user locking
order as account lifecycle changes and refresh the actor's permissions under that
lock. Nonowners cannot grant permissions they lack, edit broader roles, or add
permissions to a role assigned to themselves. The canonical legacy system Super
Admin remains protected even if an old migration cleared its owner flag.

A committed permission change bumps affected membership versions, revoking those
old restaurant sessions. The HTTP suite verifies bounded delegation, protected
roles, cross-tenant denial, and reauthentication using the saved permission set.
