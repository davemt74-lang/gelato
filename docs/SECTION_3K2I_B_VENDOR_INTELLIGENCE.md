# Section 3K2I-B — Vendor Selection and Purchasing Intelligence

This section extends Need More / Purchasing and the existing Food Monster Prep Agent. It uses the canonical vendor catalog, vendor price history, purchase orders and inventory identities. No new supplier database or migration is introduced.

## Manager workflow

1. Open **Need More / Purchasing** and choose **Compare suppliers** for a saved inventory-linked request.
2. Review the supplier's pack size, cost per saved stock unit, number of whole packs, actual estimated subtotal, rounding overage, vendor minimum and recorded price history. Different units remain ineligible until a verified mapping is saved. Unknown prices remain unknown.
3. Select the exact supplier mapping. A supplier may have more than one SKU for the same stock item; the selected mapping is retained through preview and insertion.
4. **Review preferred supplier change** and **Confirm preferred supplier** update catalog preference and canonical supplier/cost metadata only.
5. **Preview draft with selected supplier** and **Confirm · Create draft only** create a canonical draft. Review and submission remain in Purchasing; physical receipt and Need More receipt allocation retain their existing separate workflows.

Saved lead time is a vendor term, not a guaranteed delivery date. Estimates exclude tax and delivery charges. Below-minimum drafts retain an explicit warning; the existing submission gate still checks the order minimum. An unpriced draft requires purchasing review before submission.

## Food Monster integration

`get_need_more_vendor_options` reads the same saved comparison and exposes numbered supplier options. Numbers are bound to the authenticated user, restaurant, conversation and exact request, and expire after 15 minutes. The model cannot supply mapping IDs, pack sizes or prices.

`propose_daily_prep_action` adds `create_purchase_draft` and `set_preferred_vendor`. Both use the existing expiring Prep Agent Confirm/Cancel flow. Permissions and the reviewed comparison are checked again when the manager confirms. Existing verified-speaker requirements remain in effect for voice actions. A successful draft answer includes the saved PO number and identifies it as a draft.

## Correctness and access controls

- Comparison requires Need More manager/inventory access plus Purchasing View.
- Draft/preference writes also require Purchasing Manage and matching actor/restaurant identity.
- Browser writes use the existing CSRF validation.
- Request quantities, allocations, catalog versions, vendor minimums, preferences and selected mapping form a freshness fingerprint. Changed terms require a new review.
- Both Need More and canonical manual/agent draft creation lock the same stock rows, then check current outstanding orders across suppliers. Draft, submitted and partially received orders all block a second outstanding order for that stock item. Cancelled and fully received orders do not.
- Multi-item drafts lock stock IDs in deterministic order. Invalid lines roll back the entire order.
- Catalog edits validate the exact mapping/stock/vendor identity before changing preferences and commit the catalog, history and canonical metadata together.
- Order events and Need More events preserve the selected mapping. The existing manager inbox receives a draft-review notice without supplier pricing.
- Comparisons, preference changes and draft creation never adjust on-hand stock or create physical inventory transactions.

The former page's malformed inline JavaScript is replaced by an external syntax-checked script. The supplier workspace uses one document scroll, accessible radio choices, explicit review panels, request-generation guards, and disabled mutation controls while a confirmation is running.

## Verification gates

The Prep / Inventory Intelligence workflow runs the existing prep and quicklist contracts plus:

- `tests/need-more-vendor-intelligence-contract.php`
- `tests/purchasing-vendor-integrity-contract.php`
- `tests/need-more-vendor-agent-contract.php`
- `tests/need-more-purchasing-ui.cjs`
- `tests/need-more-purchasing-browser.cjs` (Chromium at 1280, 390 and 360 pixels)

These check real MariaDB data, tenant and permission boundaries, exact supplier identities, normalized and unknown pricing, minima, history, stale previews, atomic preference changes, manual/Need More overlap in both directions, an older repeatable-read snapshot, Food Monster confirmation/cancellation, and asynchronous UI behavior. Browser QA serves the shipped page markup, CSS and script with local API fixtures and checks rendering, overflow, confirmation behavior and runtime errors. The standard purchasing/receiving and other triggered regression workflows remain required before merge.

## Deployment

Use the full deployment package generated from the merged commit. Its application files are at the ZIP root. Preserve the installation's existing configuration, database and uploaded files; upload/extract the deployment ZIP and run `upgrade.php` through the normal installation workflow. Production installation and supplier delivery testing are separate from repository CI certification.
