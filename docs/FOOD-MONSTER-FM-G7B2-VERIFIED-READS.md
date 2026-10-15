# FM-G7B2 — Verified read-only Gelato tool execution

B1 exposes a permission-scoped skill catalog. B2 adds a deliberately small authenticated execution path for three high-value data reads:

- Current signed-in account — returns the authenticated user; never infers other website sessions.
- Active clock-ins — reads actual time_clock_entries through the existing Time Clock core, scoped to the organization, requiring timeclock.agent plus (timeclock.view or attendance.view).
- Recorded sales — reads sales_dashboard through Sales Intelligence for today, yesterday, ISO or named-month dates, requiring sales.view plus sales.agent. No daily period means unavailable, NOT a fabricated $0 report.

Agent Workspace chooses the server-authored read-only endpoint. The LLM cannot supply tool endpoints or arguments. The endpoint requires authentication, CSRF, owned conversation thread, exact latest user message, permission recheck and audit. Existing Agent Chat preserves source labels and receipts; voice output speaks the verified reply. Mutating requests remain under existing approval-gated routes.

No new database tables. B1 picker still prepares the question, not auto-executes. Future slices can add vetted reads and approval-only proposals.

Tests: PHP intent and permission contracts, real Chromium tool handoff, prior Food Monster and Agent Brain CI. Installed-site live database and microphone require field acceptance.
