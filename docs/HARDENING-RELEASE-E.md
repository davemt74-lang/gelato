# Hardening Release E — runtime boundaries

## Scope and audit accounting

This release follows merged Release D at
`82eda028f40af0c849b403ee037740f96bc3173b`. It implements the final three
findings from the original 31-finding codebase audit.

| Finding | Resulting behavior |
| --- | --- |
| AI-ABUSE-01 | Fresh cookies cannot reset the shared anonymous-assistant allowance. Database admission also limits organization requests, conservative provider allocation and concurrent provider calls. |
| FE-08 | The owner/floor-planner, Operations and Time Clock Agent microphones use the canonical voice pipeline. A spoken action or confirmation requires its own saved, current, verified and approved turn. |
| AI-05 | Automated Unity vision waits for an actual authenticated heartbeat. Gateway calls and model downloads have deadlines and perform transport operations on Unity's synchronization context. |

Releases A–D validated 28 original findings. Closure of the final three depends
on the exact Release E pull-request head passing CI, the merge checks and the
verified deployment package. The [audit ledger](HARDENING-AUDIT-LEDGER.md)
preserves all 31 original rows and the separate 16-item optimization/feature
backlog. Defect remediation does not imply that every proposed feature has
been built or that no future defects remain.

## Anonymous public assistant

The public HTTP controller uses a shared MariaDB admission service before any
paid provider request. Its defaults are 20 requests per network window,
100 provider requests per organization window, a 1,000,000-unit conservative
organization allocation and four in-flight provider reservations. Configuration
belongs to the server operator; a request cannot raise or disable these limits.
See [public assistant limits](HARDENING-PUBLIC-AI-LIMITS.md) for exact window
semantics, configuration ranges and hosting considerations.

Network keys are organization-separated HMAC values derived from the web
server's peer address. IPv4-mapped IPv6 addresses normalize to IPv4, and IPv6
privacy addresses within one /64 share an allowance. Client-supplied forwarded
headers do not provide quota identity. Raw addresses are not written to the
limit tables. Shared networks may therefore share an allowance; a correctly
configured hosting proxy is part of operational acceptance.

Short transactions serialize organization admission and commit before provider
I/O. Requests reserve bounded serialized-payload units plus an output allowance;
those units are a conservative allocation measure, not a billable-token count
or dollar-price guarantee. Failed or unknown provider usage remains charged.
Provider success and failure release the in-flight slot; a bounded lease
recovers abandoned slots. Provider transport, input, history, prompt and response
sizes are bounded. Missing schema, invalid configuration and inconsistent
limiter state fail closed. PHP session locks are released before provider work.

## Microphone commands and confirmations

The three generic writable Agent microphone adapters launch the existing
canonical Agent microphone in their saved conversation. They preserve the
displayed proposal and reject a switch away from another unresolved proposal.
One-shot capture now starts the same fresh per-turn acoustic sampler as
continuous capture and releases its stream afterwards.

The server derives the channel from the saved, owner-scoped conversation turn.
A caller cannot turn that saved spoken command into a typed command by sending
`voice:false`. A voice event must belong to the signed-in employee and current
tenant, have been bound to one canonical message, remain fresh and pass the
existing explicit per-turn approval. Protected spoken turns are single use.
Spoken Confirm requires a new event and approval for the exact confirmation;
it cannot borrow the proposal's event. Confirmation still requires the exact
reviewed proposal and current domain permissions. The Time Clock audit records
the decision message and confirming event.

Acoustic matching is provisional speaker attribution, not a replacement for
account authentication, authorization or explicit approval. Typed and manual
workflows keep their existing authorization paths. Separate read-only KDS,
guided-training and device simulator microphone flows are documented in
[legacy voice boundaries](HARDENING-LEGACY-VOICE.md); this release does not
certify physical microphones or speaker-recognition accuracy.

## Unity runtime health and deadlines

The Unity controller obtains heartbeat state from acknowledged authenticated
gateway calls. Before the first success, heartbeat health is unknown. A failure
or stale success blocks automated processing; repeated frame updates cannot
refresh the timestamp. Independent probes continue while ordinary processing
is blocked, allowing recovery after a fresh success. Unavailable thermal and
resource signals remain unknown rather than being reported as healthy.

Gateway requests and model downloads use a captured Unity synchronization
context for request creation, send, abort and disposal, including calls resumed
by core worker-thread continuations. Deadlines bound stalled transport, caller
cancellation retains its separate meaning, and processing guards are released
after failure. See [Unity runtime boundaries](HARDENING-UNITY-RUNTIME.md).

## Validation and its limits

The dedicated workflow runs for every pull request and main push. It
performs a clean MariaDB installation, every migration, repeat-upgrade and
checksum checks; whole-source PHP lint; shared-counter and real-worker
concurrency contracts; HTTP requests through the production controller with a
local provider stub; canonical voice proposal/confirmation contracts; actual
authenticated HTTP journeys; and Chromium DOM journeys using controlled speech
events. Existing releases' workflow gates remain in place.

Regression evidence must show the specific original failures against immutable
Release D source. A missing helper or compile error does not count as proof of
the original defect. Updated code must independently pass the corresponding
behavioral checks.

The Unity adapter project compiles the actual production gateway, model fetcher
and controller with a deterministic Unity API facade. It exercises integration
behavior, deadlines and transport thread affinity in addition to the existing
hardware-neutral core contracts. This is not a licensed Unity player build,
vendor SDK acceptance or physical AIR3 certification. Provider tests use a
local stub; no paid model traffic or live biometric capture is required.

## Installation and package verification

Run the normal application upgrade after installing the cumulative package.
The additive `database/20261231_public_agent_limits.sql` migration creates two
tables; prior migration bytes remain unchanged. Until these tables are present,
the public assistant cannot admit paid requests. Existing private configuration,
encryption keys and private storage must be preserved through the normal
deployment process. Updated browser asset versions refresh changed scripts.

The release delivery verifies the GitHub outer artifact digest, inner checksum,
all ZIP member CRCs, flat application root, embedded merged commit and packaged
source against the complete merged Git tree. The user-facing download is that
verified inner deployment ZIP. Source merge and package readiness do not deploy
the production host.
