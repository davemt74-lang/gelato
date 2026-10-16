# FM-G9A — Identity and delegated authority foundation

## Non-negotiable model

- Exactly one **authenticated website operator** controls a Gelato session. Under the agreed operating model, this is the Super Admin. The canonical `app_require_auth()` user and owner-role check are the only website authority sources.
- **Employees and managers clock in**, using the existing `time_clock_entries` lifecycle. Clock-in is not website login. Their job titles never grant Agent Brain administrative authority.
- **Food Monster** is a separate delegated agent principal. With a real Super Admin session, routine authorized operations are eligible for autonomous execution; high-impact operations require that owner's explicit approval. This section defines the decision, **not a working execution grant**; destination tool permissions and high-impact approval must be enforced in the execution section before any autonomous writes are turned on.
- **Room presence and current speaker** remain unconnected and unknown until FM-G9C/D. An observed face, voice, reported name or clocked-in identity can never impersonate a website session.
- The Super Admin does not need to clock in to converse. Unknown people may converse; enrollment and biometric recognition must not block transcription.

## Implementation

- `includes/food-monster-identity-authority.php` resolves authoritative operator identity, the existing organization-scoped active time-clock roster and a separately named agent authority decision.
- `api/agent-workspace.php?action=bootstrap` returns a new `foodMonsterContext` property from **server-side canonical** authentication and attendance. It adds no participant widgets, tables or onboarding steps.
- Only the Super Admin receives active employee roster data from this context. The response contains no faceprints, voiceprints, credentials or fabricated recognition confidence. Unavailable attendance tables yield an `unavailable` status, not a fabricated empty shift.
- The clock records are read only. No biometric/profile or employment data is migrated or deleted.

## Verification / 10-10 software gates

1. Website session and canonical Super Admin role confirmed.
2. An employee/manager or spoofed role slug cannot grant Food Monster administrative authority.
3. No employee website login is created by clock-in.
4. Existing time-clock records are the sole source for on-duty status.
5. Only the operator's organization roster is visible; non-owner roster requests are withheld.
6. Clock-out removes operational participation, not the account.
7. Routine delegated authority is different from high-impact approval.
8. Unknown impact classes are denied; no client-provided identity used.
9. Room identity remains unknown until recognition/presence phases.
10. Existing chat and time-clock interfaces remain unchanged.

**Not in 9A:** employee non-login clock-in terminal (9B); person tracking or recognition (9C); speaker attribution (9D); operational agent action execution/approval pipeline (9E). No claims of production facial recognition or autonomous writes.
