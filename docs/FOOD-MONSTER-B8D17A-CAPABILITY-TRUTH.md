# Food Monster B8D17A — Capability Truth & Diagnostics

## What this section actually adds

- The existing `list_my_gelato_capabilities` native Chat tool reports current, permission-scoped registered tools and specialist handoffs, rather than implying every discovered API or skill candidate is callable.
- Authenticated `api/agent-workspace.php?action=food_monster_capability_truth` exposes the same read-only server registry snapshot. It does **not** call a model, open a record, or execute a business operation.
- Agent Canvas adds an on-demand **Tools & status** dialog, usable on mobile and desktop. It separates native registered tools, routed specialist agents, prompt-only templates and Super Admin-only candidate contracts.
- All proposed B8C contracts remain explicitly **not executable**. Existing endpoint and destination-specific permission, CSRF, confirmation, replay and audit policies remain authoritative.
- Provider reachability, production DB reads, real writes and microphone/barge-in field performance are explicitly **not tested** by this snapshot. These require operator acceptance on `gelato.vp3.me`.

## Privacy and limits

The source-only candidate action inventory is visible to an authenticated **Super Admin** only. Staff and managers see their own registered tools and allowed specialist domains, but not the entire privileged proposed-action list. No keys, database credentials, profile identifiers, record content, transcripts, camera data or voiceprints are returned. The dialog requires a deliberate click and never grants private room-voice access.

The presence of `execute` in a registered native tool is not proof the call succeeded. The report labels all registered tools and agents as unverified until actual authorized user-level acceptance executes them. A specialist handoff routes into a separate endpoint that must reauthorize every action. Chat prompt-picker entries merely fill a draft.

## Certification

`tests/food-monster-b8d17a-capability-truth.php` asserts permission filtering, false-positive resistance, owner-only source inventory, no side effects, truthful status, anonymous rejection and UI/API route integration. The Chromium browser acceptance verifies the open/close dialog, mobile access, escaping malicious labels and read-only network requests. GitHub Actions also runs existing B8D3 tool-selection and B8C source-evidence checks. The latter must update whenever the `api/agent-workspace.php` source changes.

**Release gate:** source tests + required PR checks + merged-main checks must be green. Live records, actual Claude/OpenAI connectivity, write proofs and listening/barge-in continue as separate field-acceptance gates; do not claim 10/10 production acceptance from static checks alone.
