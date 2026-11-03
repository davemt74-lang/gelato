# Hardening Release C — Food Monster and device ownership

## Scope

This release follows Hardening Release B at
`7927f0b2264faf2a6a45bd80f545e2569c768672`. It addresses the final three P1
findings from the original codebase audit at
`5d2f332e630dd3efda07fd36224a05fe2420726b`, plus a related role-execution gap
found while reviewing the confirmation changes.

| Finding | Implemented behavior |
| --- | --- |
| AI-01 | Legitimate read results use explicit encoded-byte budgets and truthful summaries. Numbered selections contain only the records actually displayed. |
| AI-02 | Confirm, Cancel, Approve and Reject carry the exact proposal identity, hash, conversation and saved review message. Another tab cannot substitute its current proposal. |
| AI-03 | A paired device can end only its own training session. Device, session, assignments and audit updates are serialized and atomic. |
| Additional finding C-01 | High-impact role execution uses the account administration locking, fresh-authority and affected-session invalidation rules. |

Releases A, B and C together address 21 of the original 31 findings. Ten original
P2 findings remain; C-01 is additional and does not change that denominator.
This is a scoped remediation release, not a claim that every possible defect has
been eliminated or that production has been deployed.

## Bounded, useful Food Monster reads

The runtime previously applied one 3,072-byte limit to every serialized result.
The complete capability report and realistic supplier comparisons exceeded it
before the user could see a useful answer. Increasing the global limit would
also loosen unrelated tool contracts, including tools classified as reads that
actually stage a proposal or a handoff.

The new server-owned contracts name each eligible pure read and its byte budget.
Unknown tools and writes retain the original strict limit. The executor and
durable checkpoint replay use the same JSON encoding and bounds. Capability
responses provide a compact summary; complete discovery information remains in
the existing capability view. Private tool results remain local terminal output
and are not sent back to an external model.

Large collections retain complete records, state how much was retrieved and
displayed, and link to the existing detailed screen. Long or Unicode text is
measured after JSON encoding. The low-stock read counts matching tenant records
separately from its bounded retrieval, so a large restaurant does not receive a
partial count described as its entire inventory.

Daily Prep checklists, Need More receipts and supplier options save only their
displayed numbered prefix. A later numbered choice therefore refers to the same
record the employee saw. These selection-producing reads run separately so a
batch cannot overwrite another displayed selection or overflow the task journal.
Supplier comparisons retain saved terms, blockers and comparison identity.

## Exact proposal review and confirmation

A new proposal is tied to its authenticated restaurant, account, Agent node,
canonical conversation and originating saved user message. It starts unbound and
cannot be confirmed. The workspace then saves the Agent's review text from the
server's immutable proposal summary, attaches the canonical Agent message ID,
and returns the final content hash. Browser-supplied prose cannot describe a
different action from the one that will execute.

The client sends `threadId`, `proposalId`, `proposalHash` and
`proposalMessageId` when continuing a reviewed action. The server verifies those
fields, the saved review message, expiry, current actor and domain permissions.
A missing, stale, foreign or already-used review returns HTTP 409 without
consuming another proposal. An unresolved proposal cannot be overwritten by a
new request for the same account and Agent node.

The one-use claim is durably saved before the domain write. A repeated Confirm
cannot replay an action after a lost response or a competing request. Existing
inventory version checks, purchasing approval gates and domain-specific validation
remain part of execution. The canonical message and its final review metadata are written in the same
database transaction. A database rollback restores the original unbound session
proposal; interruption before session persistence leaves confirmation unavailable
rather than authorizing a different action.

Food Monster and the standalone Operations, Scheduling, Time Clock and owner
Agent bridge all use the canonical conversation contract. Review evidence is
kept per conversation and per browser tab. Standalone clients acknowledge a
proposal only after displaying its canonical answer. Reload restores only an
exact saved review message. High-impact approval handoffs retrieve and display
the original approved proposal before restoring its evidence for a later Confirm.
Standalone review pointers use organization, account and login-session scope,
store only identity metadata, and clear with private browser state. A response
arriving after sign-out cannot restore old evidence or continue the old request.

Each asynchronous Food Monster turn captures its original conversation. Changing
the selected conversation while a request is running cannot retarget the
specialist request or bind its response to a different thread. A separate action
receipt logging failure does not hide an already-saved canonical Agent response.

## Glasses training ownership and lifecycle

The device endpoint passes the full authenticated pairing into training-session
completion. The service locks the device first, then selects the session by
restaurant, device and public ID before returning metadata or changing state.
The accountable account and pairing must still be active. Same-restaurant and
cross-restaurant foreign-device attempts fail without changing assignments or
history.

Authorized manager administration uses an explicit separate function with fresh
management permissions. Employee runtime paths keep their existing wearer and
location controls. Session state, glasses training assignment, generic training
assignment, event history and audit record commit together. Nested callers use
savepoints, so an audit failure cannot leave a partial outer transaction.
Repeated terminal outcomes are read-only; competing complete and cancel requests
produce one coherent terminal state.

The related high-impact role executor now follows the same organization/account
locking and session invalidation mechanisms as ordinary role administration.
An actor revoked while waiting for a lock cannot proceed using stale authority.
The existing typed owner approval and protected-role restrictions still apply.

## Validation and installation

`.github/workflows/hardening-release-c.yml` installs PHP 8.2, native PDO MariaDB
and SQLite drivers, Node 22 and MariaDB 10.11. It performs a clean install, all
pending upgrades and an idempotent second upgrade, then runs the new observation,
confirmation, device ownership, concurrency, HTTP and actual-browser contracts.
Existing workflow gates continue to exercise the affected specialist lifecycle,
model routing, purchasing, training and permission behavior. Test entrypoints are
CLI-only. Browser tests stub only the external model boundary and use the actual
workspace, specialist APIs, session cookies and database for actions.

The release adds no schema migration. Install the complete deployment package
using the normal Gelato backup and upload/extract process. Existing unbound
pre-upgrade proposals are intentionally not confirmable; sign out and back in,
then request and review a fresh proposal. Updated asset URLs refresh the modified
Agent code and its loader chain.

The release artifact must be built from the final merged commit. Verification
checks the outer archive digest, inner checksum and archive integrity, flat
deployment root, embedded deployment SHA, required files and every packaged Git
blob against the exact merged source tree. The user-facing download is the
verified inner full-deployment ZIP. No production hosting action is part of this
release workflow.

## Open follow-up C-02 — task archive authority after a lock wait

The confirmation review also identified a separate timing gap in
`fm9i_execute` (`includes/food-monster-g9i-task-archive.php`) and
`operations_archive_task` (`includes/operations-archive-core.php`). They check
the cached owner's task-management authority before the task-row lock, without
refreshing that authority after a wait. A role or membership revocation can
commit while the archival request waits, after which the cached request can
still archive the task. This is a code-review observation; Release C does not
implement or claim a tested fix for it.

Existing typed Super Admin approval, exact proposal evidence, restaurant/task
scope, expected task status and version checks, source-linked task protection,
and atomic archival history/audit remain in place. Archival preserves the task
row. A future change should recheck current authority under the appropriate
locks and include a deterministic revocation-during-wait regression. C-02 is
explicitly outside this release's implementation scope. The original audit count
remains **21 of 31 addressed, with ten original P2 findings open**; C-01 is an
additional closed finding and C-02 is an additional open follow-up.
