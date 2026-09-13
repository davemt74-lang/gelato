# Gelato Restaurant AI — TODO / Roadmap

_Last updated: 2026-09-13_

## Current active focus — Wholesale module

The next active build area is **Wholesale**.

### Architecture review — existing foundation

Wholesale is **not a greenfield module**. Preserve and extend the existing architecture rather than creating a parallel stack.

Already canonical:

- **Wholesale lead pipeline:** `wholesale_leads` + `wholesale_lead_activities`, including stages, projected value, assignment, follow-up, public lead intake, audit history, and Agent context.
- **B2B customer accounts:** `wholesale_accounts`, linked buyer users, delivery/account locations, customer invitations, account preferences, payment-terms field, price-tier field, and private/customer notes.
- **Private buyer portal:** account-scoped profile, requests, quotes, orders, customer Agent, least-privilege `wholesale_customer` role, CSRF protection, and organization/account isolation.
- **Quotes and orders:** quote acceptance is transactional and one quote can create only one order. Existing quote/order records preserve totals and item snapshots.
- **Operations execution:** wholesale orders already sync into canonical `restaurant_tasks` as production-item and fulfillment tasks. Operations drives `in_production`, `ready`, `out_for_delivery`, and `delivered` state while keeping pricing and private margin out of worker-facing tasks.
- **Inventory:** `inventory_items`, `inventory_item_sources`, and `inventory_transactions` are the canonical stock source. Do not create a wholesale inventory table.
- **Prep / demand:** Prep Intelligence already sees wholesale tasks as source commitments and owns `inventory_forecasts`.
- **Purchasing:** vendor catalog, pack/UOM data, purchase orders, receiving, vendor price history, and purchasing suggestions already operate on canonical inventory. Purchasing suggestions already consume `inventory_forecasts`.
- **Cost / margin:** Sales Cost Intelligence already resolves recipe/inventory costs and unit conversions. Reuse this costing path rather than adding wholesale-specific food-cost math.
- **Restaurant POS / sales:** native POS remains the restaurant check/tender engine. Sales Intelligence is the canonical reporting ledger and supports source-provider separation.
- **Consumer CRM:** `crm_customers` remains the individual/POS customer model. `wholesale_accounts` remains the canonical B2B customer model; do not collapse wholesale companies into consumer CRM records.

### Highest-priority gaps

1. **Canonical wholesale catalog, pricing, and order lines — P0**
   - Current quotes/orders store `items_json`, and administrative APIs accept caller-provided item arrays and financial totals.
   - Add canonical wholesale products/SKUs or variants, sell UOM/case-pack definitions, effective-dated price lists, account price-list assignment, MOQ/order-minimum rules, and normalized quote/order line rows.
   - Recalculate quote/order subtotal and totals server-side from canonical lines and price snapshots.
   - Preserve `items_json` as a backward-compatible immutable display/snapshot field while normalized rows become the source for new behavior.

2. **Wholesale demand → inventory forecast → purchasing — P0**
   - Wholesale production tasks already appear as Prep commitments, but current inventory forecast calculation is driven primarily by prep recommendations/recipe mappings and does not consume those wholesale commitments as ingredient demand.
   - Map each wholesale SKU to a recipe/product yield and convert confirmed order quantities into dated ingredient demand.
   - Feed that demand into the canonical `inventory_forecasts` path so shortages automatically flow into existing Purchasing suggestions and open-PO suppression.
   - Prefer a generic demand-commitment mechanism that can also support Catering rather than adding wholesale-only forecast math.

3. **Inventory commitments / available-to-promise — P1**
   - Confirming an order should not immediately reduce physical on-hand stock.
   - Add a commitment/reservation layer against canonical inventory with source type/public ID, required date, quantity, state, and audit trail.
   - Expose available-to-promise as on-hand minus active commitments.
   - Post actual consumption through canonical `inventory_transactions` when production/fulfillment consumes stock.

4. **Wholesale receivables and invoicing — P1**
   - Gelato currently has restaurant POS tenders and sales/cost reporting but no general customer A/R ledger.
   - Do **not** represent Net 7 / Net 15 / Net 30 wholesale receivables as POS checks or POS tenders.
   - Add a small wholesale subledger for invoices, payments, credits/refunds, due dates, balances, and account aging.
   - Publish recognized wholesale sales into Sales Intelligence as a distinct internal wholesale source for reporting/margin without fabricating restaurant POS transactions.

5. **Fulfillment location/window + domain history — P2**
   - Link each order to a specific `wholesale_account_locations` record where applicable.
   - Add requested/promised fulfillment windows, customer PO/reference number, tax/exemption metadata, and partial-fulfillment support.
   - Add append-only wholesale quote/order events for price, state, fulfillment, and financial changes while retaining global `app_audit` logging.

6. **CRM bridge only where useful — P2**
   - Keep B2B account/company data in Wholesale and consumer/person data in CRM.
   - A future optional bridge may connect a buyer/contact to CRM history or marketing consent, but Wholesale must not duplicate the CRM consent model or make CRM the source of B2B account truth.

### Recommended build sequence

1. **W1 — Wholesale Commerce Contract**
   - Canonical products/SKUs or variants.
   - Sell UOM/case packs and recipe/yield mapping hooks.
   - Effective-dated price lists and account price-list assignment.
   - MOQ/order-minimum validation.
   - Normalized quote/order item rows with immutable price snapshots.
   - Server-side subtotal/tax/fee/total calculation.
   - Append-only quote/order events.
   - Backward-compatible support for existing `items_json` records and existing buyer portal flows.

2. **W2 — Demand Commitments**
   - Convert confirmed wholesale product quantities into dated ingredient demand.
   - Integrate that demand with Prep `inventory_forecasts`.
   - Prove shortages automatically flow into Purchasing suggestions and that open purchase orders suppress duplicate buying recommendations.
   - Generalize the commitment mechanism enough for Catering to use it later.

3. **W3 — Inventory Allocation + Fulfillment**
   - Available-to-promise.
   - Inventory commitments/reservations.
   - Specific fulfillment location and requested/promised windows.
   - Partial fulfillment.
   - Canonical inventory consumption when product is produced/fulfilled.

4. **W4 — Wholesale A/R**
   - Invoices, payment terms, due dates, payments, credits/refunds, aging, and balances.
   - Recognized wholesale revenue posted into Sales Intelligence under a distinct internal wholesale source.
   - No synthetic restaurant POS checks/tenders for terms-based B2B balances.

5. **W5 — Portal / Operations expansion**
   - Account-aware catalog and reorder experience.
   - Fulfillment board and production-demand views.
   - Receivables visibility.
   - Customer purchase/history views using the contracts above.

### Boundary rules

- Do not create duplicate vendor, purchase-order, inventory, recipe, task, POS, or consumer-CRM systems for Wholesale.
- Keep `wholesale_accounts` as the B2B customer/account source of truth.
- Keep `restaurant_tasks` as the execution source of truth for wholesale production/fulfillment work.
- Keep `inventory_items` / `inventory_transactions` as physical inventory truth.
- Keep Purchasing as the replenishment source of truth.
- Keep Sales Cost Intelligence as the recipe/inventory cost calculation source.
- Keep POS for restaurant transactions; use a Wholesale receivables subledger for terms-based B2B balances.
- Every new wholesale write must remain organization/account scoped, permission checked, audited, upgrade-safe, and contract tested.

## Deferred — Table Turn Forecasting + Seating Pace Intelligence

PR #40 completed **Table Turn + Host Readiness Intelligence** and merged into `main` at `539230312be9bd8c4a95f9f35fcb4add84346611`.

The next Host Stand intelligence phase is intentionally deferred while Wholesale is the active focus.

When resumed, build **Table Turn Forecasting + Seating Pace Intelligence** using the existing reservation-protection and readiness layers. The phase should use:

- reservations and waitlist demand
- active dining visits
- projected dining clear times
- current dirty / cleaning / ready table state
- table reset targets and reservation-ready buffers
- table capacity and combination availability
- incoming 15 / 30 / 60 minute demand windows

Expected output should include projected tables/covers becoming available, near-term capacity pressure, likely seating windows, and host-facing pace guidance.

### Guardrail

This forecasting remains **table-, reservation-, and demand-based only**. Do not convert it into employee performance scoring, worker ranking, disciplinary recommendations, or staffing-performance surveillance.

## General architecture notes

- Add intelligence as enrichment around existing canonical source layers rather than replacing them.
- Prefer location-scoped policy where operating behavior differs by restaurant/location.
- Preserve organization/location isolation, explicit permissions, audit history, upgrade safety, deterministic tests, and backward-compatible defaults.
- New modules should integrate with existing inventory, purchasing, production/prep, POS/sales, customer/CRM, and audit systems rather than creating duplicate sources of truth.
