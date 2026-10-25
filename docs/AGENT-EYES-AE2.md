# Agent Eyes AE2 — Consented Local Participant Enrollment

## Scope
- A Super Admin on an existing trusted terminal selects a person from **active members of the current Gelato organization**.
- The participant must be physically present and consent. The administrator attests this through an explicit consent control.
- Three individually captured, consistent face descriptors are collected with front/left/right prompts, averaged and AES-GCM encrypted in the same browser-local IndexedDB identity store used by Food Monster.
- Only the existing Gelato user ID, organization, approved terminal, approving Super Admin and consent timestamp are persisted on the server. No image, raw face vector or key is transmitted.
- This is *not* Face/PIN sign-in enrollment, not employee clock-in, and never grants user roles or Agent execution authority.
- The room tracker can display the enrolled owner's or employee's name only as a **provisional visual match** while a currently approved enrollment exists on the current trusted device. Revocation removes this authorization and deletes only AE2-tagged local profiles.
- Multiple visual tracks claiming the same person remain ambiguous. Speaker attribution remains separately governed by verified voice events.

## Limits and controls
- This release handles existing organization members only, not external walk-in guests without a Gelato identity.
- Enrollment stays local to each authorized browser; there is no automatic cross-device transfer of biometric material.
- Capture prompts require deliberate button presses but do not independently prove head angle, live identity, or anti-spoofing.
- A trusted terminal can be revoked and disables further recognition. Browser storage loss requires re-enrollment.
- In-person, real camera and accessibility acceptance remain separate from simulated Chromium tests.

## Migration
Run owner-only `upgrade.php` to apply `database/20261222_agent_eyes_ae2_local_enrollments.sql`. Preserve existing configuration, uploads and storage. AE1 camera consent remains unchanged.
