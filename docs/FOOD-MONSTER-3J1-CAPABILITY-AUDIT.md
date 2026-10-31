# Gelato FM-B8D18J1 — Agent capability integrity audit

Source: `config/food-monster-capability-map.json` (73 historical candidate contracts) and authenticated `fm8d3_catalogue` registrations at merged Section 3I.

This audit **does not** promote a historical discovery candidate into an executable tool. It audits explicit relationships to newer tools and whether those tools are currently registered. Actual execution, permission recheck, voice identity, confirmation, and database effects remain separate acceptance gates.

## Inventory findings

- 73 historical source-only contracts; all retain `contract_candidate_not_granted`.
- 17 candidates have an explicitly reviewed native-tool relationship after fixing `schedule.publish` (formerly miskeyed `schedule.week_publish`).
- 56 have no explicit native-tool relationship in the audited map. This does **not** prove that the domain lacks a specialist handler or endpoint; it means no audited equivalence has been established.
- The code-level owner report now exposes a relationship integrity summary and stale-key diagnostics. Lower-privilege accounts do not receive this privileged inventory.
- No registration is treated as proof of an end-to-end execution.

## Remaining relationship gaps (by source contract family)

| Area | Unmapped historical contracts |
| --- | --- |
| Scheduling | `schedule.availability_save`, `schedule.review_exception`, `schedule.shift_archive`, `schedule.review_swap` |
| Time & attendance | `timeclock.attendance_summary`, `timeclock.correct_entry`, `employee_clock.pin_clock_in` |
| Employee development | `development.view_team`, `development.coaching_note`, `development.recognize`, `development.goal` |
| Employee workspace | `employee.handoffs`, `employee.handoff_save`, `employee.policy_ack` |
| Menu & recipes | `menu.create_edit`, `menu.update_price`, `menu.availability`, `recipe.create`, `recipe.update` |
| Vision & glasses | `vision.run_mining`, `vision.dataset_create`, `vision.dataset_freeze`, `vision.review_sample`, `vision.model_benchmark`, `vision.pilot_kill_switch`, `glasses.pair`, `glasses.get_calibration` |
| Front of house, POS, KDS & orders | `foh.create_reservation`, `foh.seat_reservation`, `foh.table_ready`, `pos.create_check`, `pos.item_add`, `pos.tender`, `kds.transition_item`, `kds.recall_item`, `orders.resolve_exception` |
| Operations, prep, purchasing | `operations.tasks`, `prep.shortages`, `purchasing.vendors` |
| Equipment, CRM & marketing | `equipment.save_asset`, `crm.customer_search`, `crm.consent`, `marketing.offer_save`, `marketing.offer_activate` |
| Catering, wholesale, sales | `catering.list`, `catering.task_save`, `wholesale.fulfillment_list`, `wholesale.dispatch`, `sales.dashboard`, `sales.forecast` |
| Brain, governance & discovery | `brain.search`, `agent.request_approval`, `media.discover`, `public_site.discover`, `admin_settings.discover`, `notifications.discover` |

## Recommended 3J2 integration order

1. **Prep and inventory:** inspect existing shortage and inventory handlers, then connect a model-selected read and an explicitly confirmed prep-list/inventory action where missing.
2. **Task operations:** reconcile task listing, prep-list creation and status changes, using one confirmation route.
3. **Scheduling/CRM gaps:** work through approval/review and customer lookup/consent separately, preserving management privileges.
4. **High-impact POS, KDS, purchasing and payroll corrections:** audit real endpoint and receipt semantics before offering new mutating tools.

## Release gates

- Regression asserts every explicit mapping key exists in the 73-candidate source inventory.
- Regression asserts registered native tools for a fully privileged typed-chat principal.
- Capability report remains owner-only for source contracts and never executes a handler as part of its inventory.
- No code/CI score proves real microphone identity or successful business action execution; those need deployed acceptance tests.
