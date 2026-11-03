# Agent confirmation binding (AI-02)

## Defect and intended behavior

The old shared confirmation store kept one pending row per organization, user,
and specialist. A second tab could replace that row. A subsequent `Confirm`
looked up whichever row occupied the slot, without checking the proposal the
person had reviewed. The natural-language continuation tool forwarded a generic
`Confirm` and the current chat turn, so it had the same substitution risk.

Confirmation now requires the exact reviewed proposal. A new proposal cannot
replace an unresolved proposal in the same specialist queue. Missing, stale,
expired, replayed, cross-owner, cross-organization, wrong-specialist, wrong-thread,
changed-message, changed-payload, and forged review evidence produces a
`GacConfirmationConflict`, mapped to HTTP **409** by each affected API. These
rejections do not execute a business action or consume a different pending row.

## Binding contract

Every new pending row has version `2` and includes:

- Authenticated organization and user IDs.
- The owning specialist node, action type, exact payload, and summary.
- The owner-scoped canonical conversation and originating user message IDs.
- A random proposal ID, creation time, and expiration time.
- The canonical agent proposal message ID and a hash of its exact displayed text.
- A SHA-256 hash over the complete immutable envelope, including the exact payload,
  both message references, summary, and expiry.

Associative payload keys are sorted recursively before hashing; list order and
numeric types remain significant. The hash detects changed evidence. It is not
an authorization credential: the session owner, CSRF, canonical database messages,
and existing domain permission and voice checks remain required.

The shared public proposal object is:

```json
{
  "id": "gap-<16 hex>",
  "hash": "<64 hex>",
  "node": "operations",
  "threadId": "agent-<20 hex>",
  "sourceMessageId": "msg-<20 hex>",
  "messageId": "msg-<20 hex>",
  "type": "inventory_adjust",
  "summary": "The exact server-generated proposal summary",
  "expiresAt": "An ISO-8601 timestamp"
}
```

Purchasing and Scheduling retain their `pap-` and `sap-` IDs and existing session
buckets. They use the same shared storage, binding, validation, and claim code.
They have no separate legacy confirmation exception.

A confirmation or cancellation request must send:

```json
{
  "message": "Confirm",
  "threadId": "agent-<20 hex>",
  "sourceMessageId": "msg-<current user turn>",
  "proposalId": "gap-<reviewed proposal>",
  "proposalHash": "<reviewed hash>",
  "proposalMessageId": "msg-<reviewed agent proposal message>"
}
```

`sourceMessageId` on this request is the current user turn. It is distinct from
the original source message in the proposal and from `proposalMessageId`.

## Canonical recording and one-use execution

1. The request sets a request-local context using
   `gac_request_context($input, $pdo, $actor)`. This is never a session-global
   "current thread" and never an argument chosen by the model.
2. `gac_pending_store()` verifies that the source message is a saved user message
   belonging to the authenticated owner and conversation. Threadless legacy
   creation fails safely. Storage refuses to replace an unresolved or claimed
   row, including an old unbound legacy row.
3. The initial public proposal has an empty hash and agent message ID. It cannot
   be confirmed before it has been recorded canonically.
4. The workspace loads the exact pending ID using `gac_pending_for_binding()`,
   replaces client-supplied display text with `gac_proposal_answer()`, and records
   the canonical agent message. `gac_pending_bind_message()` verifies its owner,
   thread, role, exact text, and structured proposal identity. The workspace
   stores the returned complete metadata in that same canonical message. Binding
   an existing proposal to a different message is forbidden.
5. `gac_pending_reviewed()` verifies all supplied fields, recomputes the immutable
   hash, and checks the saved canonical message text and public metadata. Routing
   can perform a non-consuming session-envelope check; the executing destination
   always performs the full database-backed verification.
6. `gac_pending_claim()` holds the PHP session lock while matching the evidence,
   marks the row claimed, persists that state, and reacquires the session lock
   before returning the exact payload to the domain executor. A second worker
   cannot claim the same row. The claimed row blocks replacement while the action
   is in flight. A crash or lost response cannot turn the same review into a
   second execution.
7. The domain retains its existing fresh permission, voice identity, record
   version, and business checks. Completion clears only the expected proposal
   ID. The ordinary expiry is 600 seconds, with existing per-domain overrides;
   an abandoned claimed row blocks replacement for at most 1,800 seconds.

Expired or legacy unbound evidence is not implicitly upgraded. The user must
make and review a new proposal. Failed evidence checks leave current pending
state intact. Once an exact proposal has been claimed, a retry needs a newly
reviewed action; existing domain transactions and stale-record guards continue
to determine whether a domain failure made a change.

## Routing and model paths

`gaw_pending_action_node($organizationId, $userId, $evidence)` performs an exact
lookup. Passing an empty evidence array cannot fall back to the latest pending
node. Its two-argument form remains an existence check for proposal-staging
collision guards only.

The B8D12 natural-language continuation tool still allows the model to choose
only `confirm` or `cancel`. It is exposed only when the current context contains
valid reviewed evidence for the current owner and thread. It forwards the exact
proposal ID, hash, and canonical agent message ID unchanged, along with the
current user message reference. The destination then claims that same proposal.
The model cannot select a proposal ID, route, payload, organization, or user.

The older B8D4 Scheduling fast path uses the same evidence. It intercepts only
its exact Scheduling proposal; a separate pending Scheduling row cannot hijack
an Operations confirmation. All B8D18 proposal adapters publish the shared
metadata. The public Purchasing and Scheduling wrappers preserve their previous
bucket names while enforcing the same binding.

## Owner approvals

High-impact proposal messages use **Approve / Reject**, with explicit wording
that approval alone does not execute an action. Those decisions require the same
canonical reviewed evidence and retain the typed Super Admin gate. A later role
or task-archive `Confirm` verifies the original bound approval retained in its
grant. A purchase approval retains the originally reviewed Purchasing proposal.

Approval completion exposes prior metadata as `data.reviewedProposal`, not as a
new `data.proposal`. The frontend can restore that evidence only against the
original saved message in the same thread. It must never append or rebind the
old proposal as a new proposal message. Missing purchase-owner approval is a
preflight denial that leaves the exact current purchase proposal available for
the approval workflow.

## Covered destinations

The shared contract covers Operations, Time Clock, Prep, Customer CRM and its
Operations follow-up handoff, Marketing, Catering, Wholesale, Front of House,
Equipment, Recipes, Online Orders, Purchasing, and Scheduling. It also covers
B8D4 direct Scheduling confirmation, all B8D18 proposal adapters, B8D12 model
continuation, and the high-impact approval envelope and subsequent grant review.

## Verification

New focused suites:

```sh
php tests/hardening-agent-confirmation-contract.php
node tests/hardening-agent-confirmation-http.cjs
```

The unit contract verifies missing and changed identifiers, hashes, payloads,
owner/tenant/node isolation, wrong threads, changed canonical text, binding
immutability, unresolved legacy rows, explicit cancellation, expiry, replay,
claimed-row replacement, exact completion IDs, separate Purchasing/Scheduling
queues, natural-language forwarding, and specialist HTTP conflict mappings.

The HTTP suite uses the real login and CSRF flows, canonical Workspace messages,
actual Operations endpoints, a MariaDB database, and four PHP server workers. It
asserts that two same-user tabs cannot replace or accidentally confirm another
action, compares domain snapshots after every rejection category, verifies one
stock-ledger write under simultaneous confirmation, and executes both legacy and
model-staged proposals through the real specialist. The model fixture calls the
actual proposal adapter and continuation tool without calling an external model.

`hardening-agent-confirmation-server.cjs` exports a reusable `start()` fixture for
real-browser tests. Its CLI fixture supports `seed`, `snapshot`, `pending`,
`expire-pending`, `seed-model`, and `continue-model`. New PHP test entrypoints reject
non-CLI requests before bootstrap. The helper
`hardening-agent-confirmation-test-support.php` supplies actual canonical source
and proposal messages for existing SQLite and MariaDB lifecycle suites; their
domain assertions remain in place.

PHP and MariaDB execution must run in the configured project CI environment.
No external model, email, or production writes are needed by these suites.
