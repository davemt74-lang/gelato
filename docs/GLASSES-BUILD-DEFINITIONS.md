# Gelato AR Glasses Plugin — Section 4 Recipe → AR Build Definition

Section 4 turns existing Gelato menu + recipe data into a deterministic, versioned AR build manifest.

## Compilation rules

A build definition is compiled for a specific menu item and recipe.

Recipe selection is conservative:

- an explicitly selected active recipe is accepted;
- otherwise Gelato requires exactly one active recipe whose name exactly matches the menu item;
- zero matches fail;
- multiple exact-name matches fail and require explicit selection.

Menu ingredients remain canonical. Recipe ingredients are matched to them using normalized exact names. Section 4 does not silently use fuzzy AI matching.

A definition is `ready` only when every required menu ingredient maps to exactly one recipe ingredient and every recipe ingredient maps back to the canonical menu ingredient set. Otherwise it is stored as `needs_review`.

## Manifest

The persisted `gelato.ar_build_definition.v1` manifest contains:

- menu item identity
- recipe identity + resolution method
- ordered components
- canonical ingredient IDs
- expected quantities and units
- optional flags
- recipe-match status and notes
- ordered recipe instructions
- component references detected in instruction text
- unresolved mapping entries

Quantities support decimals, simple fractions such as `1/2`, and mixed fractions such as `1 1/2`.

## Versioning and drift

Every compile produces an immutable new version. A new `ready` version supersedes the older ready version. A `needs_review` compile does not displace a known-good ready definition.

A SHA-256 source hash covers menu ingredients/preparation notes plus recipe ingredients/instructions. If either source changes, the active definition reports `stale=true` and build sessions fall back to the canonical menu-ingredient behavior until a fresh ready definition is compiled.

## Build-session integration

A new build session automatically attaches the latest fresh ready definition. Expected quantity/unit values and ordered recipe steps then become part of that session snapshot. This keeps an in-progress cook stable even if the recipe changes later.
