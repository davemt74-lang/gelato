# W2 Wholesale Demand Commitments

Wholesale W2 connects confirmed wholesale demand to the existing recipe, inventory, Operations, and Purchasing systems.

## Contract

- Confirmed, in-production, ready, and out-for-delivery wholesale orders create active ingredient commitments.
- Requested orders do not reserve ingredients until they become confirmed production demand.
- Delivered and cancelled orders release active commitments.
- Commitments are normalized into each inventory item's base unit.
- Physical `inventory_items.on_hand_quantity` is never decremented by W2 planning.
- Existing inventory transactions remain authoritative for actual stock consumption.
- Existing Purchasing suggestions consume active commitments and subtract open purchase-order quantities.
- Normalized W1 wholesale order lines are the source of production demand. Legacy/free-form lines are reported as issues rather than guessed.

## Production conversion

Preferred conversion uses the SKU `recipe_yield_per_batch` field.

Fallback conversion uses the SKU's explicit finished content quantity/unit (for example `5 L` per pan) against the linked recipe's finished yield quantity/unit.

Ingredient quantities come from the existing `inventory_item_sources` recipe mappings and are converted into inventory base units before commitment.
