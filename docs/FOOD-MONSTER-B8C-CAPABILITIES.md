# Food Monster B8C — Complete Capability Discovery and Skill Taxonomy

## Outcome and ownership

Gelato's Food Monster is intended to help a signed-in employee or administrator use **all features that person is authorized to use**. It does not become a root operator. The feature is the business module; the skill is the user's goal; the tool is a specific Gelato API operation or native Agent node.

**Example:** Employee Scheduling → Scheduling skills → `schedule.shift_save` → `api/scheduling.php` `shift_save` → `schedule.manage` → review/confirmation/audit before execution.

**Different example:** Time & Attendance → Time Clock skills → `timeclock.clock_in` → `api/timeclock.php` `clock_in` → `timeclock.self` → same authenticated employee; no ability to clock arbitrary co-workers in. The separate employee PIN terminal requires Super Admin and verified employee PIN; an Agent tool cannot bypass either requirement.

## Inventory scope (source-backed)

The canonical, machine-readable source snapshot is `config/food-monster-capability-map.json`.

| Inventory | B8C snapshot | Meaning |
| --- | ---: | --- |
| Feature families | 27 | Business-domain grouping |
| API endpoint files | 135 | Every `api/*.php` file, exactly once |
| Explicit action comparisons | 561 | Literal `$action === '...'` style matches; not every possible runtime dispatch |
| Native Agent nodes | 23 | Registrations and current modes from `gaw_agent_nodes()` |
| Candidate task-level skills | 73 | Curated intent-to-tool links awaiting full B8D execution contracts |
| Vision Lab action comparisons | 123 | Every explicit action comparison discovered in `api/glasses-vision-lab.php` |

**Important:** The 561 static comparisons are *not* 561 verified callable Agent tools. Some APIs dispatch using `in_array`, computed actions, GET query parameters, or included core files. A file without a direct `app_require_auth()` call is *not* presumed public. The manifest exposes source-discovery evidence and review state; endpoint-specific authentication and authorization remain authoritative.

### Core feature → skill families

| Feature/module | Representative skills |
| --- | --- |
| Employee Scheduling | View shifts, coverage, requests, time off; save/archive shifts; publish a week; review exceptions |
| Time & Attendance | Own entries; clock in/out; breaks; attendance summaries; manager corrections; PIN terminal |
| Employee Development | Coaching notes, recognition, development goals, guided training |
| Employee Workspace | Handoffs, announcements, policies, checklists |
| Menu & Product Management | Item discovery, item edits, availability, prices, category operations |
| Recipes & Production | Recipe search, standards, revisions and archiving |
| Vision Lab & Model Lifecycle | Samples, annotations, datasets, model benchmarks, canaries, promotion, rollback |
| Smart Glasses | Pairing, camera operation, calibrations, production evidence |
| Front of House | Tables, seating, reservations, cleaning status, live shifts |
| POS | Checks, items, kitchen submission, tender, void/refund controls |
| KDS | Kitchen tickets, item transitions, routing and recall |
| Online Orders & Pickup | Fulfillment, exceptions, recovery and handoff |
| Restaurant Operations | Tasks, checklists, inventory, daily manager |
| Prep & Inventory | Prep plans, demand signals, inventory intelligence |
| Purchasing | Suppliers, suggestions, purchase orders, receiving |
| Equipment & Floor Planning | Equipment records, locations, maintenance |
| CRM | Customer search, notes, tags, consent |
| Marketing | Packages, offers, campaigns, public pages |
| Catering | Events, menus, requirements and staffing |
| Wholesale | Accounts, fulfillment batches, delivery, receivables |
| Sales & Cost Intelligence | Dashboards, forecasts, imports, analysis |
| Media | Media library discovery and management |
| Public Site | Public brand, jobs, catering and wholesale surfaces |
| Administration | Roles, permissions, provider configuration |
| Notifications | Notifications and evidence retrieval |
| Agent Brain & Command Center | Native Agent routing, search, high-level summaries |
| Food Monster Conversation & Governance | Chat, voice, training, proposals and approvals |

The JSON contains the exact route mapping for every discovered API, including the smaller modules not expanded here.

## Contracts required for B8D

Each executable skill must have a reviewed contract:

1. A stable skill ID, plain-English intent and domain.
2. A specific registered tool/service operation (never arbitrary URLs or SQL).
3. Strict input schema and output/evidence schema.
4. Current organization and, where applicable, location or subject ownership.
5. Exact permissions **as enforced by the endpoint**, with staff/manager/Super Admin distinctions.
6. Appropriate action class: read, routine write, approval write, or high-impact action.
7. CSRF/session or device identity binding; voice commands cannot inherit someone else's credentials.
8. Idempotency and replay protection; no duplicate clock-ins, purchases, tenders, reservations, or notifications.
9. Audit entries, provenance, error recovery and truthful execution status.
10. End-to-end tests for permission denial, stale requests, duplicate prevention, rollback, and UI/voice confirmation.

The `permissionRequirement` field of B8C skill candidates is **an audit prompt, not runtime authorization logic**. Text marked `verify core` must be resolved in B8D. Existing API and Agent node policy is never bypassed by this manifest.

## Release sequence

- **B8C — Coverage map (this PR):** all API files and Agent nodes accounted for, action evidence checked against source, skill candidates grouped, zero execution grants.
- **B8D — Executable skill contracts:** build verified request/response validators, permission-aware skill filtering, service adapters and the first complete vertical slices (scheduling, timeclock, menu/recipes, training). A new feature family can be wired only once its contract is safe and tested.
- **B8E — Unified Agent execution:** reliable conversational planning and tool use for authorized tasks, confirmation UX, Brain evidence and smart followups.
- **B8F — Certification:** comprehensive adversarial permission/voice/replay/duplicate and browser acceptance; clean deployment packaging.

Each section is scored against acceptance criteria, tested, opened as a PR, and merged only after all required checks are green. No later section is started on an unmerged predecessor.

## CI and safeguards

`tests/food-monster-b8c-capability-map.php` validates current Git blob evidence, exactly one mapping per `api/*.php`, all native Agent nodes, every declared static action comparison, candidate tool references and no execution permissions. `.github/workflows/food-monster-b8c-capabilities.yml` runs it on PR changes to any API or the Agent registry. `config/.htaccess` denies HTTP serving the internal map on Apache.

No database, provider, live customer data, outbound request or write action is needed to run the test.

## B8C quality scorecard (ten requirements)

1. Every API endpoint mapped.
2. Every native Agent node mapped.
3. Static Vision Lab action list verified.
4. Every feature assigned to a family.
5. Every family represented by at least one skill candidate.
6. Explicit scheduling/timeclock separation.
7. Endpoint permissions treated as authoritative, hints not substituted.
8. No runnable tool grant.
9. Source-drift and newly introduced API coverage tested.
10. CI and deployment inclusion verified.

Mark the section **10/10 only when the PR checks and merged-main verification are green**.
