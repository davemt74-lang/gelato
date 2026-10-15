# FM-G7B1 — Governed Food Monster Skill Registry

This section connects Food Monster's existing floating mascot and Agent Chat canvas to the canonical Gelato Agent Node Registry.

**Capabilities**
- Account-scoped skill discovery via authenticated `api/agent-workspace.php?action=food_monster_skills`.
- Fixed, reviewed, **read-only** prompts for Kitchen, Inventory/Tasks, Scheduling, Purchasing, Prep, Recipes, Equipment, Catering, CRM, Attendance, and manager-only Agent Brain.
- The ⚒ control displays a compact, mobile-compatible skill picker. Selecting a skill **fills** the existing chat bar and requires the user to submit it intentionally.
- A pending typed draft cannot be overwritten without confirmation; opening the catalog causes no external tool execution.

**Safety and scope**
- The server checks the same role and agent permissions already used by authenticated node routes, with a separate dashboard authorization gate for manager Brain.
- The catalog returns no internal API route, credential, LLM tool schema, executable function parameters, permission bypass, or write token. It only exposes allowlisted review metadata.
- Existing Agent Chat handles authenticated routing, tool authorization, approvals, per-organization records, and auditing. The client cannot select arbitrary endpoints.
- FM-G7B1 introduces no database migrations, account changes or additional provider key. Claude Haiku 5.5 and MediaPipe remain unchanged.
- This is the *discovery phase*, not arbitrary auto-execution or assistant-initiated writes. FM-G7B2 can add approved, auditable read-only tool execution after certification.

**Acceptance gates**: PHP registry contract; browser picker/mobile test; all earlier Food Monster regressions; complete GitHub CI; merged-main revision verification; full deployment and MediaPipe hashes. Installed-site checks remain required.