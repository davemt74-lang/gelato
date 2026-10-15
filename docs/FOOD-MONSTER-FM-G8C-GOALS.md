# FM-G8C — Participant onboarding goals

## What this release establishes

- **Full-screen Camera**: camera video covers the viewport and the geometry overlay uses the same cover scaling. Agent view retains the right-side participant panel.
- **Stable tracking labels**: temporary participant IDs remain stable across short detection gaps, normal head movement, and when the camera mode changes. IDs are *not* identity matches.
- **Goal conversation**: a new person gets a local, opt-in greeting asking their name. Name capture can use voice/Agent Chat; it is persisted in a scoped onboarding goal only after a response or manual action. The model is bypassed only for an expected onboarding answer.
- **Durable goal**: organization-scoped, actor-isolated records survive page refresh and support cancel or complete. The goal engine prompts for type, separately consented photo gallery, optional voice enrollment, and employee or guest next steps.
- **Photo gallery**: only explicit consent and capture save images; files are validated, capped at 140 KB and eight per goal, encrypted with Gelato's Sodium application key and stored in MySQL BLOBs, and served through an authenticated, non-cacheable endpoint. Photos may be deleted individually.
- **Employee provisioning**: only a protected super-admin can propose an employee email + assignable non-owner role, then separately confirm; a random unusable password is stored and a one-time password reset email is issued. Existing password/PIN setup remains available after the employee signs in. No manager can create a Super Admin.
- **Voice enrollment**: consent recorded, but employee voice enrollment must occur through the employee's own authenticated Time Clock voice workflow. The goal only completes for an employee after three photos, a linked account and an active voice profile.
- **Guest-to-CRM**: guest must explicitly consent to contact creation, and acting staff needs existing crm.manage. Never auto-opt-in to marketing, even if guest photo consent exists.

## Privacy and accurate identity

A face detector does not establish a legal name, employee role, schedule, time-clock status, or identity. A self-introduced name is labelled unverified. A face photo is never a PIN or password; employee sign-in uses the separately certified trusted-device face + PIN process.

## Deploy

Full HostGator ZIP. Preserve config, storage/keys/application.key, and uploads. Deleting the application key makes all existing gallery images undecryptable. Apply the new \`20261015_fm_g8c_goals.sql\` migration via protected \`upgrade.php\`. Then hard-refresh Food Monster. Verify live camera stability, consent, multi-person ambiguity, employee onboarding, authorized account approval, voice enrollment and guest CRM conversion on the installed system.

Real camera/voice identification field acceptance remains separate from synthetic browser/CI tests.
