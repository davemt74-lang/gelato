# Gelato Restaurant AI — TODO / Roadmap

_Last updated: 2026-09-13_

## Current active focus — Wholesale module

The next active build area is the **Wholesale module**.

Start with the wholesale operating contract and canonical data model before building a large dashboard. The first implementation should establish:

- wholesale customer / buyer accounts tied to organizations and locations
- wholesale-specific price lists and account-level pricing
- products, variants, case packs, units of measure, and pack-size conversions
- minimum order quantities and order minimums
- quote / draft order / submitted / confirmed / fulfilled / cancelled lifecycle
- pickup, local delivery, and shipment fulfillment modes
- requested delivery / pickup dates and fulfillment windows
- payment terms such as prepaid, due on receipt, Net 7, Net 15, and Net 30
- wholesale tax / exemption metadata without duplicating the existing accounting source of truth
- inventory allocation that distinguishes wholesale commitments from normal restaurant/POS demand
- production / prep demand generated from confirmed wholesale orders
- invoices, payments, credits, refunds, and order balance tracking using canonical financial records
- audit history and role/permission boundaries for wholesale pricing and order changes

### Recommended build sequence

1. **Wholesale contract + schema** — customer accounts, price lists, case/UOM rules, order lifecycle, fulfillment mode, payment terms, permissions, and audit events.
2. **Wholesale catalog + pricing** — account-aware catalog, pack sizes, MOQ/order minimum validation, effective-dated pricing, and price overrides with audit history.
3. **Wholesale order entry** — draft/quote/order workflow, line validation, totals, requested fulfillment date/window, notes, and customer PO/reference numbers.
4. **Inventory + production commitments** — reserve/commit stock safely, expose shortages, and convert confirmed wholesale demand into production/prep requirements without corrupting POS availability.
5. **Fulfillment + invoicing** — pick/pack/ready/delivered lifecycle, partial fulfillment, invoice/balance state, payments, credits, and refunds.
6. **Wholesale operations UI** — account list, order pipeline, fulfillment board, production demand, receivables, and customer history after the operating model is stable.

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

## Architecture notes

- Keep existing canonical service/reservation protection as the source layer and add intelligence as enrichment rather than replacing it.
- Prefer location-scoped policy where operating behavior differs by restaurant/location.
- Preserve organization/location isolation, explicit permissions, audit history, upgrade safety, deterministic tests, and backward-compatible defaults.
- New modules should integrate with existing inventory, purchasing, production/prep, POS/accounting, customer/CRM, and audit systems rather than creating duplicate sources of truth.
