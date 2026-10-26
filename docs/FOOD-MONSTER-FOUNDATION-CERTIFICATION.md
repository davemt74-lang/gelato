# Gelato Agent reliability and security certification plan

This plan is gated: no subsequent section starts before the current section
has passed all required CI checks, been reviewed, merged, and verified.

## Section A — Private voice boundary

- Treat browser acoustic matching as provisional speaker context, never account authentication.
- Bind each voice event to one canonical message with a short expiry and atomic claim.
- Let the signed-in user explicitly approve an individual spoken private-data request.
- Require a one-use session-held grant bound to organization, user, thread, message and time.
- Do not inherit a previously verified participant's rights on a new turn.
- Deny and test expired, forged, repeated, wrong-user, wrong-thread and wrong-organization grants.
- Preserve anonymous/public conversation and typed authenticated access.

**Important:** The explicit UI confirmation is consent from an already authenticated
browser session. It is not multi-factor authentication, and a physically unattended
signed-in workstation still requires ordinary screen lock and staff privacy controls.

## Foundation gate (must pass before further feature expansion)

1. Clean install and upgrade; verify DB tables, migrations, retained encryption key,
   and saved provider credentials without exposing secrets.
2. Admin LLM test with an actual configured provider; test a real round-trip and
   readable error on failure, not merely "credentials present".
3. Authenticated Agent Chat: public menu, owner schedule, day roster, inventory,
   in-house recipe; compare each actual record with Gelato's database/UI.
4. Permission matrix: owner/manager/employee/unknown speaker; assert read-only,
   private-read, proposed-write and high-impact approval boundaries independently.
5. Two-speaker and speaker-handoff acceptance with microphone, echo, interruption,
   refresh/reconnect and no stale speaker attribution.
6. Missing provider and failed provider: no regex/keyword fallback to private agents;
   human-readable status and a real configured-model retry/failover in Section C.
7. Server-side deployment contract: storage protection on clean installs; no
   exposed tests or CI metadata; full ZIP manifest/checksum/byte-size parity
   with merged main and previous full releases.
8. Real workflow acceptance: select one simple domain action, capture browser
   request IDs, server logs, tool invocation and persisted result end-to-end.

## Sections B–E

- B: unified model-first routing, authenticated context and failure/failover handling.
- C: provider health, credential recovery, direct tool proof per operation.
- D: enumerate all 73 capability contracts; distinguish executable reads, governed
  writes and discovery-only contracts. Test each implemented permission path.
- E: production-shaped browser/DB acceptance, deployment parity and full release ZIP.

Automated tests do not by themselves certify microphone identity separation or a
real production provider/database connection. Mark those as unverified until
tested in an authorized environment.
