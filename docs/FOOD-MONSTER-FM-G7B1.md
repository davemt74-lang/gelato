# FM-G7B1 — Governed skill discovery registry

This is the first increment of FM-G7B, not an execution loop.

- Fixed allowlisted skills reuse existing Gelato domain node routes.
- A skill is visible only when **all** permissions are held by the authenticated account.
- Revoked permissions are re-evaluated on resolution, never trusted from a cached plan.
- Discovery never returns internal route names. The model cannot add routes, permissions, callbacks or arguments.
- Proposed modifications remain delegated to existing destination-node confirmation and audit handling.
- No model-generated commands are executed in this section.
- This module is intentionally not exposed as a standalone public API.

Run `php tests/food-monster-g7b1-contract.php`. Before using it from Agent Chat in G7B2, verify routes and permissions against current node contracts, add org/thread ownership and acceptance tests, and keep approval state separate from natural-language confirmations.
