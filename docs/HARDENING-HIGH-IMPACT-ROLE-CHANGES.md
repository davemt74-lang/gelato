# High-impact role changes use canonical account mutation controls

## Additional finding C-01

This finding was discovered while reviewing Release C's confirmation migration. It is **additional to the original 31 audit findings**; Release C still closes original findings AI-01, AI-02 and AI-03.

The Food Monster Super Admin approval path changed `role_permissions` directly after locking only the target role. It trusted the request's cached owner authority after any database wait and did not advance affected restaurant membership versions. The Roles API already used restaurant and user ordering locks and membership version invalidation. Consequently, the approval path could continue with authority removed while it waited and left existing sessions current after changing their permissions.

## Shared controls and preserved policy

`fm9h_execute_role` now acquires the canonical restaurant ordering row, discovers the affected restaurant users, locks those users and the actor in sorted order, and reads the actor's active account/membership with a current locking query. It obtains effective permissions through `accounts_actor` and canonical owner semantics through `accounts_roles`. A cached owner flag, the mere presence of role-management permissions, or an old session grant cannot replace current Super Admin authority.

The locking actor query matters under MariaDB repeatable read: discovering affected users can establish a snapshot before acquiring their user locks. If a global account suspension commits while one of those locks is awaited, a normal consistent read could retain the earlier active account state. Both the Roles API and the approved role executor now request the shared helper's current locking read after acquiring their ordering locks. Role and membership mutations within the restaurant remain serialized by the restaurant ordering row.

Two small shared helpers contain the existing canonical affected-user query and membership version update. The Roles API and Food Monster executor both use them. This preserves the established role policy and avoids a separate implementation of session revocation.

The existing approval and execution gates remain:

- A real owner-authorized Super Admin session must have both `roles.edit` and `roles.assign_permissions`.
- The owner proposes the exact role, permission and grant/revoke operation, reviews a canonical message, explicitly approves it, and separately confirms the approved action in the same conversation.
- Only an unprotected custom role in that restaurant can change. Protected owner roles, system roles, unknown permissions and foreign restaurant roles remain denied.
- The role's name, description and sorted permission set must match the reviewed version; stale approvals cannot silently target a changed role.
- Approval identity, conversation, expiry and one-use state remain required. The public approval route also requires the exact retained proposal ID, hash and canonical message ID introduced by AI-02.

## Atomic invalidation, audit and replay

A committed permission change advances `organization_memberships.updated_at` for all users with an active grant of that role in the affected restaurant. The monotonic `GREATEST(NOW(6), previous + one microsecond)` expression is shared with the Roles API. Existing sessions for those memberships fail the Release B credential-version check. Other restaurant memberships belonging to the same global account, and unrelated roles in this restaurant, keep their versions.

The permission mutation, membership version changes, canonical `role.updated` audit entry, high-impact execution receipt and high-impact audit entry commit together. An audit or receipt failure rolls back all database changes and leaves the session grant unconsumed. The session grant is marked consumed only after commit.

The executor additionally checks the durable execution receipt for the approval ID under the restaurant ordering lock. This prevents a copied pre-execution session or lost post-commit session save from reusing the approval, even if a subsequent legitimate role edit restores the original permission-state hash. Competing confirmations yield one committed transition and one receipt.

No schema migration, permission redesign, model call, network operation or production deployment is part of this change.

## Acceptance evidence

Run against the disposable fully upgraded MariaDB acceptance database:

```sh
php tests/hardening-high-impact-role-contract.php
php tests/food-monster-g9h-roles.php
node tests/hardening-glasses-training-http.cjs
```

The new focused contract creates actual canonical owner and employee roles and sessions. It stages and approves through the real canonical confirmation envelope, verifies approval alone does not mutate permissions, tests exact-review rejection and protected/foreign role policy, and proves both grant and revoke invalidate affected sessions while preserving other restaurant and unrelated sessions. Trigger-injected audit failure must roll back permission, session-version, canonical audit and execution receipt writes. Replay tests include a copied session after a legitimate reversal restores the original role hash.

Concurrency cases launch independent PHP processes and require observation of each worker in `information_schema.INNODB_TRX` as `LOCK WAIT` before releasing the blocker. Cases remove the owner's real role while leaving bounded role-management permissions, suspend local membership, suspend the global account, and suspend the account after the worker has already established a repeatable-read snapshot while waiting on its user lock. Every revoked-authority request must fail without changing the target role or its users' session versions. Two simultaneous confirmations of one approved action must produce exactly one mutation and canonical audit. PDO errors and deadlocks are test failures, not accepted authorization denials.

The PHP test rejects non-CLI access before loading bootstrap. The local HTTP suite verifies GET, POST and HEAD receive a plain 404 for this entrypoint as well as the glasses test entrypoints.

Requirements match the Release C glasses contracts: PHP 8.2 with native PDO MySQL and mbstring, MariaDB 10.11 with all application migrations, `proc_open`, trigger creation/removal permissions, and visibility of `INNODB_TRX`. The HTTP guard test also needs Node.js 22. Only the local application and database are used.

PHP and MariaDB are unavailable in the editing workspace. PHP lint, behavioral, concurrency and live HTTP results must come from release CI; static review and Node syntax checks do not substitute for those gates.
