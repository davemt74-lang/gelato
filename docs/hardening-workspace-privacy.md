# Release A: workspace privacy and public brand rendering

Closes audit findings FE-01, FE-02, FE-03 and the related DATA-08 read-side job seeding.

## Workspace data contract

`includes/workspace-bootstrap.php` builds `RESTAURANT_WORKSPACE_BOOTSTRAP` from the authenticated server actor. The actor always receives their own profile and effective permission union. The organization roster requires `users.view`, the role catalogue requires `roles.view`, and administrative job records require `jobs.view`. None of those permissions implies another. Every database read remains scoped to the actor's organization. Bootstrap data excludes password material.

The bootstrap stays in memory. Client-side role/user edits cannot replace the authenticated actor's permission snapshot. Local UI permissions are presentation only; API endpoints continue to authorize every request independently.

Workspace and jobs GET requests no longer insert or publish example jobs. Existing jobs are preserved. Owners can create and publish openings through their normal authorized workflow.

## Browser state and sign-out

The workspace uses an organization/user/login-specific session-storage namespace for disposable local training and conversation state. The login identifier is a random noncredential nonce that changes on every successful sign-in. A same-session reload preserves its drafts; another user, organization, or login cannot restore them.

The first hardened workspace load removes legacy unscoped roster, roles, chat, audit, jobs and browser-only training histories. Those old records have no trustworthy account attribution and are not migrated. Database records are unaffected. New browser-only histories and drafts are cleared on sign-out; they are not a durable replacement for server training records.

Both server logout endpoints destroy authentication and render a no-store page that runs targeted browser cleanup before redirecting. The cleanup broadcasts to other workspace tabs, clears their in-memory actor and private drafts, and makes them re-enter the server's authentication checks. A changed principal does the same. Public ordering carts and nonprivate device preferences are preserved. If browser storage is blocked, the workspace uses an in-memory fallback.

## Brand rendering

`js/public-brand-render.js` constructs logo nodes with DOM properties. Restaurant names are text/attribute values and never HTML. Image URLs must resolve to HTTP(S) without embedded credentials. The application and careers landing pages both use this renderer; cover images use the same URL policy.

## Regression checks

Run after installing/upgrading the test MariaDB schema and providing the normal test configuration:

```sh
php tests/hardening-workspace-contract.php
node tests/hardening-browser-state.test.js
node tests/hardening-public-brand.test.js
node tests/prep-agent-bridge-routing.cjs
node tests/training-workspace-voice.test.js
```

The combined Release A HTTP test additionally checks the actual authenticated workspace response and server logout. These tests do not imply that prototype user administration or hiring submission has been completed; those remain Release B work.
