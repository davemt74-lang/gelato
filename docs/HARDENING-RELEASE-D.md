# Hardening Release D — data integrity and retention

## Scope and audit accounting

This release follows merged Release C at
`5f328cc67babaa4a654f1562c8b0e623c3defe98`. It addresses seven additional
findings from the original 31-finding audit, one additional security follow-up,
and one documented optimization opportunity.

| Finding | Behavior covered |
| --- | --- |
| DATA-06 | Financial totals aggregate the complete tenant/account ledger independently of bounded invoice pages. |
| DATA-07 | Availability and approved leave cover every date of an overnight shift, with explicit midnight and daylight-saving behavior. |
| DATA-09 | Ingredient parsing preserves complete names and fractional quantities; ambiguous input requires review. |
| DATA-10 | Recipe synchronization replaces the complete validated source set atomically and retains before/after evidence. |
| FE-06 | KDS data, actions and spoken order slots belong to the current location/station/history generation. |
| FE-07 | Both order customization editors preserve prior instructions until the exact item catalog is ready and a deliberate save occurs. |
| AI-04 | Media deadlines govern reads, training selection, ZIP downloads and qualification before cleanup; bounded cleanup is retryable. |
| Additional C-02 | Task archival refreshes current authority after lock waits and prevents approved-action replay. |
| Opportunity FE-O1 | Public cart badges use the current cart format and pickup location without duplicate legacy counts. |

Releases A–C addressed 21 original findings. This scope brings the original
audit to **28 of 31 addressed**, contingent on the final release validation.
The three remaining original findings are AI-ABUSE-01 (anonymous-assistant
quota identity), FE-08 (legacy microphone/voice-event boundary), and AI-05
(Unity heartbeat/deadline wiring). Additional C-01 was closed in C; additional
C-02 is addressed here. Opportunities are tracked separately from the original
finding count. DATA-08 was already fixed in Release A and is not counted twice.

## Data correctness

Receivables and customer credit amounts are calculated from all applicable
ledger entries. Internal and portal invoice lists stay bounded and expose
counts/continuation. Moving between display pages does not change balances.

Scheduling checks complete local intervals rather than comparing clock strings
from only the first day. Adjacent positive windows may combine; gaps and
approved leave remain meaningful. Exact midnight is an exclusive end. Ambiguous
or nonexistent daylight-saving boundaries are rejected because the existing
DATETIME schema cannot preserve a repeated-hour choice.

Operations and Catering share a bounded known-unit/fraction parser. Raw input
and review flags remain available. Ambiguous ranges and units do not silently
become quantities or apparently complete recipe costs. Recipe sync validates
the desired set before modifying active source links, sums compatible duplicate
lines, retires absent/archived links and saves revision evidence in one
transaction. Stock balances, inventory movement history and consumed order
history are preserved. Existing prep/wholesale services regenerate their saved
projections through their normal explicit refresh operations.

## Concurrent actions and customer changes

Task archive authority uses the canonical account locking order. A revocation
which commits first is visible before archival; a protected-owner grant that
expires while waiting cannot execute. Typed high-impact approval, exact review
evidence, expected status/version, source-linked task restrictions and atomic
history/audit remain required. A durable conversation-owned execution receipt
prevents replay after restored task state or lost session persistence.

KDS captures its exact selected view and generation. Older reads and writes
cannot replace current tickets or enable actions against stale data. Failed
history reads recover and stalled reads have a deadline. Both order editors
maintain item-bound drafts and guard Save during load/failure/closure. Existing
notes, removals, substitutions and paid add-ons survive missing catalog options.
The shared cart badge selects one current cart and the correct pickup location.

## Retention and validation

See [training media retention](HARDENING-MEDIA-RETENTION.md) for access rules,
bounded host cleanup and the scheduler command. Expiry is enforced without
depending on that scheduler. Qualification and cleanup use the same release
lock and qualification counts only included release samples.

The dedicated workflow performs clean installation/all upgrades and repeat
upgrade checks; PHP lint; same-test failures against original Release C modules;
native MariaDB ledger, scheduling, recipe, task-authority and media contracts;
deterministic lock waits; real authenticated HTTP; and actual Chromium UI
journeys with controlled API response ordering. Existing workflow gates remain
in place. Browser fixtures exercise shipped DOM/event code; they do not claim
hardware, live voice or production-host acceptance.

## Installation

No schema migration is added by this release. Use the complete verified package
from the exact merged commit, with the normal site/database backup and existing
configuration/private-file preservation process. Run normal deployment and
upgrade checks for the cumulative application package. Updated asset versions
refresh modified browser code. Configure the optional bounded host cleanup
command to reclaim expired private bytes automatically.

The release delivery checks the GitHub outer artifact, inner checksum, every
archive CRC, flat application root, embedded commit SHA and every packaged Git
source blob against the complete merged tree. The download supplied to the user
is the verified inner ZIP. Production hosting is not changed by this workflow.
