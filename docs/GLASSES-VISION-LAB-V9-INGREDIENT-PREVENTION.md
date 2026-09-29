# Vision Lab V9 Section 3 — Missing / Wrong Ingredient Prevention

Section 3 turns the immutable V9 scene into a prevention signal before a cook advances the canonical build.

## Contract

- Canonical recipe/build state remains owned by the existing build session, POS, and KDS.
- Every assessment is bound to the scene hash and exact canonical build-context hash.
- A stale scene is rejected after the build advances.
- missing_expected means the current required component is not visible.
- premature_component means a later canonical component is visible before the expected component.
- wrong_component means a different pending canonical component is present instead of the expected one.
- unknown_ingredient means a high-confidence ingredient cannot be mapped to the canonical recipe and is interacting with or near the product.
- duplicate_confirmed_component means a component already accounted for appears to be added again.
- Strong product interaction (touching, on, inside) can produce a stop advisory. This does not block or mutate build, KDS, or POS truth.
- Weak evidence remains a warning; the runtime never guesses ingredient identity.

## Safety boundary

The service never calls build-confirm, build-observe, KDS-transition, build-complete, payment, inventory, or POS mutation paths. It persists only its own immutable assessment and lineage record.

## Device API

- vision.ingredient_guard.assess
- vision.ingredient_guard.verify

## Vision Lab API

- ingredient_guard.assess
- ingredient_guard.verify

The UI surfaces the latest assessments as clear / warning / stop guidance while retaining explicit advisory-only labeling.
