# Original codebase audit — remediation ledger

## Scope and evidence

The original audit reviewed commit `5d2f332e630dd3efda07fd36224a05fe2420726b` and recorded 31 findings. Releases A–D validated 28 of them. Release E completes the remediation for all **31/31 original findings** once the workflow, merge and package checks are recorded in [PR #521](https://github.com/davemt74-lang/gelato/pull/521). This ledger tracks the original finding set. The feature and optimization backlog is tracked separately below.

| Release | Original findings | Source and evidence |
| --- | ---: | --- |
| A | 11 | [Identity and private data](HARDENING-RELEASE-A.md), [PR #517](https://github.com/davemt74-lang/gelato/pull/517) |
| B | 7 | [Durable operations](HARDENING-RELEASE-B.md), [PR #518](https://github.com/davemt74-lang/gelato/pull/518) |
| C | 3 | [Food Monster and device ownership](HARDENING-RELEASE-C.md), [PR #519](https://github.com/davemt74-lang/gelato/pull/519) |
| D | 7 | [Data integrity and retention](HARDENING-RELEASE-D.md), [PR #520](https://github.com/davemt74-lang/gelato/pull/520) |
| E | 3 | [Runtime boundaries](HARDENING-RELEASE-E.md), [PR #521](https://github.com/davemt74-lang/gelato/pull/521) |

## Original findings

| Finding | Original severity | Original defect | Remediation release |
| --- | --- | --- | --- |
| AUTH-01 | P1 | Customer signup can claim an existing CRM identity without verifying its email | A |
| AUTH-02 | P1 | Password recovery leaves other authenticated sessions valid; session age is not enforced | A |
| AUTH-03 | P2 | Signup provides an alternate password-check path that ignores account lockout | A |
| AUTH-04 | P2 | Password-reset tokens are checked outside the transaction and can be consumed twice concurrently | A |
| DEPLOY-01 | P1 | Private purchasing documents and task proofs resolve inside the web application directory | A |
| DEPLOY-02 | P1 | The full deployment ZIP omits runtime storage protections needed by fresh installs | A |
| AUTH-05 | P2 | First-use encryption-key creation can overwrite a concurrent request's key | A |
| AI-ABUSE-01 | P2 | The anonymous public assistant's quota resets with each new browser session | E |
| DATA-01 | P1 | Four production write statements reuse named PDO parameters while native prepares are enabled | B |
| DATA-02 | P1 | Manual stock reductions can create a mathematically inconsistent inventory ledger | B |
| DATA-03 | P1 | Direct time-clock actions can create duplicate open punches and overlapping breaks | B |
| DATA-04 | P1 | Receiving and cancellation can overwrite each other using stale purchase-order status | B |
| DATA-05 | P2 | Editing an inventory base unit can silently reinterpret existing stock and open-order quantities | B |
| DATA-06 | P2 | Financial totals are calculated from truncated display lists | D |
| DATA-07 | P2 | Overnight shifts bypass availability and following-day time-off checks | D |
| DATA-08 | P2 | Read requests can publish demo jobs without create/publish permission | A |
| DATA-09 | P2 | Recipe ingredient parsing corrupts multiword names and fraction quantities | D |
| DATA-10 | P2 | Recipe inventory synchronization leaves removed ingredient links active | D |
| AI-01 | P1 | Food Monster rejects legitimate tool output before displaying it | C |
| AI-02 | P1 | A confirmation can execute the proposal from another tab or conversation | C |
| AI-03 | P1 | One paired glasses device can complete another device’s training session | C |
| AI-04 | P2 | Expired training media remains readable and exportable until an explicit cleanup action | D |
| AI-05 | P2 | Unity runtime reports a healthy heartbeat without an actual heartbeat signal | E |
| FE-01 | P1 | Workspace exposes the organization directory and unpublished jobs without their view permissions | A |
| FE-02 | P1 | Private owner chat and user/training data survive logout in unscoped browser storage | A |
| FE-03 | P1 | Public brand rendering permits stored HTML/event-handler injection | A |
| FE-04 | P1 | Public hiring reports receipt while keeping applications only on the applicant device | B |
| FE-05 | P1 | Canonical account controls create/edit/suspend only browser copies | B |
| FE-06 | P2 | Kitchen display accepts an older location/station response after the operator switches views | D |
| FE-07 | P2 | Saving item customization during a slow/failed catalog load erases existing instructions | D |
| FE-08 | P2 | Legacy microphone routes spoken actions as typed commands and skips the voice-event requirement | E |

Additional findings C-01 (fresh authority during high-impact role changes) and C-02 (task archive authority after lock waits) were found during remediation and closed in C and D respectively. They do not change the original denominator.

## Optimization and feature backlog

The audit also recorded 16 opportunities. These are tracked separately from defect closure. FE-O1 was implemented and validated in D. New concurrency, native-PDO, HTTP and browser tests have advanced DATA-O6 and FE-O3, but their wider workflow and cross-browser goals remain open. The remaining opportunities are a roadmap, not release-completion claims.

| Opportunity | Scope | State |
| --- | --- | --- |
| DATA-O1 | Batch wholesale portal detail queries and reuse invoice results | Open roadmap item |
| DATA-O2 | Unify purchasing suggestions with normalized supplier comparison and bulk queries | Open roadmap item |
| DATA-O3 | Apply canonical numeric validation to receipt prices and quantities | Open roadmap item |
| DATA-O4 | Make task batches atomic and idempotent across manual and agent callers | Open roadmap item |
| DATA-O5 | Add an operational data-quality dashboard with repair previews | Open roadmap item |
| DATA-O6 | Add whole-workflow native PDO, concurrency and volume gates | Partly advanced; broader coverage remains |
| AI-O1 | Batch repeated metadata and record queries | Open roadmap item |
| AI-O2 | Make Food Monster cost and provider configuration inspectable | Open roadmap item |
| AI-O3 | Expose a feature readiness matrix for glasses and AI actions | Open roadmap item |
| AI-O4 | Standardize proposal and observed-result envelopes across specialist agents | Open roadmap item |
| AI-O5 | Parse endpoint URI for local-development HTTPS exceptions | Open roadmap item |
| FE-O1 | Public cart badge reads obsolete v1 storage while active cart writes v3 | Delivered in D |
| FE-O2 | Split the glasses simulator by feature and centralize update scheduling | Open roadmap item |
| FE-O3 | Add complete role/session and cross-browser journeys alongside source contracts | Partly advanced; broader coverage remains |
| FE-O4 | Consolidate duplicated workflow setup and pin build inputs immutably | Open roadmap item |
| FE-O5 | Use a shared accessible dialog and status system | Open roadmap item |

## Operational acceptance

Automated tests run against disposable databases, controlled provider and speech inputs, Chromium, and a documented Unity API facade. They do not install production, send external test email, incur paid model usage, validate live microphone accuracy, or certify physical AIR3/vendor-SDK readiness. Hosting, mail delivery, microphone/device support and physical-device acceptance remain deployment responsibilities.
