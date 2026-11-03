# Task archive authority after database waits

## Additional audit finding C-02

The Operations archive service previously accepted the authenticated request's
cached `tasks.manage` permission, then waited for the task row. The Food Monster
caller likewise checked protected owner authority before entering that service.
An account or role change could commit during the wait without being checked
again when the archive continued.

`operations_archive_task` now acquires the same restaurant and user locks used by
canonical account and role administration. It then holds the actor's membership
row and locks the exact restaurant/task row. A current locking account read and
fresh role/permission lookup run after these waits, before checking or mutating
the task. A revocation which obtained the authority locks first is therefore
visible. If the archive obtained them first, canonical revocation waits until
that archive transaction finishes. Role assignments and permission sets remain
stable under the restaurant lock.

Ordinary task managers retain the existing native Operations archive permission.
The Agent caller supplies an additional server-owned authorization callback
inside the same transaction. That callback requires a currently protected owner,
the same approved conversation and approval ID, and a grant which has not expired
while waiting. Browser input cannot supply this callback or broaden the policy.

The executor also checks its durable execution receipt before acting. The receipt
is a system message, so its owner is the canonical conversation rather than the
message's nullable `user_id`. This prevents a copied session grant from executing
again even if an independent operation restores the task's old status/version.
The task row, task history, ordinary audit, Agent receipt and Agent audit commit
atomically. Session consumption follows a successful commit; a rollback leaves
the grant available to retry its still-approved operation.

## Preserved behavior

The change preserves exact canonical proposal review, typed owner approval and a
separate Confirm. It preserves restaurant isolation, expected task status and
version, source-linked task protection, and the existing archive semantics:
the task row is retained, marked archived and cancelled, and removed from active
task results. An archive still requires its own transaction and cannot commit
unrelated work belonging to an outer transaction. No schema migration is added.

## Regression coverage

Run against a fully installed and upgraded MariaDB database with native PDO
prepares, `pdo_mysql` and `mbstring`, using Gelato's normal test configuration:

```sh
php tests/hardening-task-archive-contract.php
php tests/food-monster-g9i-task-archive.php
```

The new contract creates independent restaurants, actual saved roles and
memberships, canonical conversations, reviewed proposals and owner approvals.
Independent PHP workers are observed in `information_schema.INNODB_TRX` as
`LOCK WAIT` before the parent commits its change. Owner-role revocation while
retaining ordinary task-management permission, membership suspension, permission
removal, global-account suspension and restaurant suspension must all prevent
archival without adding task history or audit. The worker observer recognizes
the former task-first implementation as well as the new earlier authority lock,
so the same authority test can demonstrate the original failure:

```sh
php tests/hardening-task-archive-contract.php authority-only
```

Additional cases cover native manager success, read-only and foreign-restaurant
denials, source-linked tasks, stale status/version, invalid canonical review,
row-preserving archival, receipt ownership, copied-grant replay after restored
task state, an injected audit failure, expiry or task changes during a task-row
wait, and two competing confirmations. Database errors and deadlocks are not
accepted as successful rejection. The existing G9I contract retains its original
assertions; its fixture now persists the protected owner role that execution
checks against the database.

All test entrypoints remain CLI-only. The code does not add a production cleanup,
deployment action or new automatic archival policy.
