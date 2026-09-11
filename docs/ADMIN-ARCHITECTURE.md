# Restaurant Training Admin Architecture

## Current build

The current package is a local, frontend-only prototype. It demonstrates the complete interface and data flow for:

- Super Admin, Manager, Employee, and custom account types
- Permission-aware navigation and actions
- Credential-based prototype login
- Owner system-agent briefings
- User account administration
- Resume submission and review
- Resume-to-employee conversion
- Resume form builder
- Public landing-page builder
- Brand settings
- Profile menu and personal settings
- Existing menu training, quizzes, flashcards, simulations, certifications, ingredients, allergens, and progress tracking

Browser storage is used only to make the prototype interactive. It is not an authentication or private-record storage solution.

## Account model

### Super Admin

The Super Admin is the protected store-owner account. Application and database services must enforce these rules:

1. At least one active owner membership must always exist.
2. A non-owner cannot assign or remove an owner role.
3. An owner cannot suspend or delete the final active owner.
4. Owner-level agent insights remain scoped to the owner permission.

### Manager

Manager access is permission-driven. A manager can create employees, review training, issue certifications, and process resumes only when the matching permissions are granted.

### Employee

Employees access their own profile, assigned training, menu knowledge, and personal progress. They do not receive organization-wide records.

### New Resume

`new` is a resume workflow status, not an authenticated account type. An applicant becomes a user only after an authorized user converts the submission into an invited employee account.

## Security boundary

Production authentication must replace the browser prototype with:

- Argon2id or bcrypt password hashes
- Server-generated random session tokens stored as hashes
- HTTP-only, secure, SameSite cookies
- CSRF protection
- Login throttling and lockouts
- Expiring invitation and password-reset tokens
- Permission checks inside every backend endpoint
- Private file storage outside the public web root
- MIME, extension, size, malware, and checksum validation for resume files
- Append-only audit events for privileged actions

## Owner system agent

The first backend version does not require an LLM. A scheduled rule engine can generate owner insights from authoritative database records.

Initial rule examples:

- `employee_training_overdue`
- `quiz_score_below_threshold`
- `certification_expiring`
- `employee_ready_for_certification`
- `employee_inactive`
- `new_resume_received`
- `resume_unreviewed`
- `team_weak_menu_section`

The agent can summarize and prioritize records. It must not autonomously hire, reject, suspend, certify, or change permissions.

## Suggested API modules

```text
/api/auth
/api/profile
/api/users
/api/roles
/api/permissions
/api/positions
/api/training
/api/menu
/api/resumes
/api/forms
/api/public-pages
/api/brand
/api/agent-insights
/api/notifications
/api/audit
```

Every API query must include organization scope derived from the authenticated membership, never from a client-supplied organization ID alone.

## Recommended implementation sequence

1. Authentication, sessions, CSRF, organization membership
2. Roles, permissions, protected owner rules, audit events
3. User accounts, invitations, profile images, position assignments
4. Resume form, secure upload, review queue, status history
5. Form and public-page versioning
6. Brand settings and public rendering
7. Training record migration from browser storage
8. Owner-agent rules and notifications
9. Reporting and data export
