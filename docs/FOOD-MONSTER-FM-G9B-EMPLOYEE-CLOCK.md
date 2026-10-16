# FM-G9B — Employee clock-in, not website login

## Working behavior
- The Super Admin remains logged into Gelato. A clocked-in employee does **not** become the website operator.
- When someone says "Monster, clock me in", "I want to log in for my shift", or "clock me out" in Agent Chat, the agent opens a small on-screen shift PIN dialog. It never creates a website session.
- Employee selects their existing name, enters their **clock-only** six-digit PIN and clocks in/out using canonical `tv_clock_in` / `tv_clock_out`, which create the existing attendance and shift events.
- Setup or reset of a clock PIN requires the Super Admin's account password. Shift PIN is not the prior Face/PIN website sign-in credential. Five wrong attempts lock employee PIN for fifteen minutes.
- The dialog is available in the floating Food Monster, full Agent Canvas, and existing Time Clock page. No enrollment wizard, independent employee website account login, new sidebar, camera dependency, or voice gate.
- Successful shift actions emit `gelato-employee-clock-changed` and request fresh server-scoped FM-G9A context. Agent Brain continues to use canonical attendance and has no new employee permissions.
- Browser transcription/chat may be used as a *request to open the PIN dialog*, never as authentication. PIN must be entered manually, and identity isn't inferred from the words of a speaker.

## Security boundaries
- `api/employee-clock.php` requires authenticated Super Admin session and CSRF protection for writes.
- Person IDs must belong to active organization-scoped staff with non-owner roles; owner, customer and wholesale customer are excluded, even when the browser provides those IDs.
- Employee PIN is Argon2id-hashed and rate-limited. Website session data is never changed by the endpoint.
- Only the registered Super Admin operator is recorded as system actor; attendance belongs to the employee. Neither implies a recognized camera participant or speaking person.
- Database-level row locks serialize employee clock mutations and prevent duplicate shift entries from simultaneous requests.

## Acceptance
- No employee website sign-in required; owner session survives.
- Employee cannot issue an administrative tool action or reset their own PIN using the popup.
- Employee ID and PIN combination must match same organization.
- Clock-in/out persists to the existing time-clock table and is visible through FM-G9A context.
- Invalid PIN is rejected and locked after repeated failures.
- Unknown, unassigned, owner, and customer accounts are excluded.
- Existing clocking, voice and Agent Workspace suites pass.
- Voice/typed conversation opens the same popup with no extra participant-management interface.
