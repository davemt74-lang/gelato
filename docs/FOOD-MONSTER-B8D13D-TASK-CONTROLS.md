# Food Monster B8D13D — Agent Task Visibility and Safe Stop

## What changed

- Agent Chat shows recent task status, verified step counts and a user-controlled **Request stop** button for active tasks. Agent Brain presents the same metadata, and the floating Agent bar exposes its own activity drawer.
- The status API is scoped to the currently authenticated Gelato user, organization, and canonical conversation. No plan arguments, personal observations, session tokens, or LLM credentials appear in task responses.
- Stop requests are CSRF-protected and require a signed-in click. A running task checks the persisted stop flag before each next tool action and again immediately before executing a tool. No LLM-generated text can trigger this endpoint.
- Expired read-only tasks may be conclusively cancelled. Expired write tasks become **unverified** because a write may have completed before the connection failed. A stop requested during an in-flight write does **not** roll back that effect; its final receipt is authoritative.
- Fully completed, failed, or uncertain tasks are immutable to late stop requests. Cancellation intent is idempotent and stored in the local database.

## Installation

Deploy the exact certified Cloud ZIP and run the ordinary owner-only `upgrade.php` once. The database migration adds the nullable `cancel_requested_at` field to the Agent task journal. Keep `config.php`, environment settings, uploads and application storage.

## Safe acceptance checklist

1. As a signed-in user, open Agent Chat and trigger an authorized multi-step read; task progress appears without exposing private observations in the status panel.
2. On both desktop and mobile, confirm the task progress card follows the active conversation and the main chat remains scrollable.
3. Request a stop before a follow-up step; no further tool actions should begin, and the final task shows **Cancelled**.
4. Simulate a dropped connection for a pure read; it may be cancelled or safely recovered from server checkpoints without re-reading completed steps.
5. Simulate a lost write lease; the task must display **Outcome unverified**, never a fake cancellation or automatic retry.
6. Confirm an employee cannot inspect or stop another employee's private task or a task in another restaurant; late/duplicate cancellations must be harmless.

**Scope:** B8D13D provides control of each user's own Agent Chat tasks. Cross-user owner takeover and direct external business-operation tools are separate future sections and must not bypass established Gelato permissions and approvals.
