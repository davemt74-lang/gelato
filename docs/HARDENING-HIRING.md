# Durable hiring intake and review

The public `apply.html` form submits contact information, experience, availability,
consent and actual attachment bytes to `api/public-applications.php`. It displays a
receipt only after the restaurant has committed the application, metadata, private
files and audit event. The form does not persist applicant records in browser storage.

The public restaurant and eligible jobs come from server data. Request-supplied
organization IDs cannot select another restaurant. Published, unarchived, unexpired
jobs are required at the moment of submission. Public job pages no longer fall back
to old browser-local demonstration jobs when the server is unavailable.

## Persistence and retries

The existing `forms`, `form_submissions`, `resume_submissions`, `files`, `resume_files`,
`resume_notes` and `resume_status_history` tables remain canonical. Migration
`20261230_job_applications.sql` adds public receipt IDs, tenant-scoped idempotency
keys, content fingerprints, converted-account linkage and durable intake counters.
Existing database application contents are preserved and receive private-review IDs.
Browser-only prototype applications were never delivered and are not silently imported.

A stable submission key survives retries in the current open form. Identical retries
return the original receipt. Reusing that key with different content is rejected.
CSRF-token refresh preserves the same submission key. Closing/reloading the form
starts a new submission; keep the displayed receipt before leaving a successful form.

All business/audit metadata is written in one transaction. Failed writes roll back
and remove newly created private file bytes. If the database's final commit result is
ambiguous, files are preserved so the same-key retry can resolve the original receipt.
A durable, restaurant-scoped client-address counter permits ten submissions per hour;
opening another browser session does not reset it. Proxies should preserve the actual
client address through trusted web-server configuration; arbitrary forwarded headers
are not trusted by this endpoint.

## Attachments

Supported resume files are PDF, DOC and DOCX, up to 10 MiB. Optional videos are MP4,
M4V, WebM and MOV, up to 10 MiB. Up to five JPEG, PNG or WebP photos are accepted, at
most 2 MiB each. All attachments together must be at most 25 MiB. Hosting PHP upload
limits may be lower; failures are reported without claiming the application arrived.

The server checks byte-derived MIME types and supported signatures. Images must be
decodable and within the pixel limit; DOCX must contain the expected document parts,
has a bounded expanded size and cannot contain a macro project. DOCX validation needs
PHP's ZIP extension; PDF remains available if that extension is missing. No server-side
video transcoding or duration measurement is claimed. Files are never unpacked into
the web directory.

Attachments use the Release A private-storage root under the
`hiring-documents/<organization>/<application>/` namespace. Only authorized hiring
reviewers in that organization can fetch them through `api/resume-file.php`, which
checks the application association, private visibility and tenant before opening a
file. Responses disable caching and MIME sniffing. Resumes download as attachments;
validated photos/videos support private inline previews.

## Reviewer workflow

The workspace fetches the shared queue from `api/resumes.php`, following result pages
until complete. Its working copy stays in the login's in-memory server-data map.
`resume.php` loads one database record and authorized attachment URLs. `resumes.view`,
`resumes.review` and `resumes.add_notes` are independently checked on the server.
Status changes lock and compare the current status and write history/audit atomically;
a stale page cannot silently overwrite a later review decision.

The employee invitation button calls the canonical user lifecycle endpoint with the
application ID. That endpoint derives the applicant's details from the saved record,
creates an invitation and links it to the hiring record. It does not invent a local
employee account or set a plaintext default password.

The live application uses the canonical required hiring fields. Browser-local form
builder drafts are not a server-published application schema.

## Native-PDO job writes

Job creation/editing uses unique parameter markers with native prepares enabled.
Explicit tenant-scoped INSERT/UPDATE replaces the former UPSERT, so a duplicate slug
cannot overwrite a different job. The server validates create/edit/publish permissions,
locks edits and audits the save transaction. The workspace acknowledges success only
after that save returns and replaces its job list with the server result.

## Acceptance commands

- `php tests/hardening-hiring-contract.php`
- `node tests/hardening-hiring-http.cjs`
- `node tests/hardening-hiring-browser.test.js`

The HTTP suite uses independent applicant, hiring manager, read-only reviewer, ordinary
employee and other-tenant sessions. It verifies byte-identical private PDF downloads,
photo persistence, retries, CSRF, closed/foreign-job rejection, permissions, private
notes, stale status updates, failed-audit rollback/file cleanup and native-PDO job saves.
