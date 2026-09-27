# Gelato AR Glasses Plugin — Section 8 AIR3 HUD / Right-Rail Interface

Section 8 implements the first production HUD contract for the glasses concept.

## Persistent projection rule

The center work area stays clear.

At the 1920×1080 reference layout:

- the persistent right rail is 520 px wide;
- it is inset 48 px from the right edge;
- the center safe zone ends 72 px before the right rail begins;
- all persistent ITEM / BUILD STEPS / PRODUCT VALIDATION / NEXT UI is created inside that right rail.

The layout policy is executable core code and is covered by the .NET contract tests.

## Right rail

The rail contains exactly four logical sections:

1. **ITEM** — current product, KDS state, and POS special instructions.
2. **BUILD STEPS** — ordered recipe instructions. Confirmed ingredient-linked steps show complete; the first incomplete ingredient-linked step is active; action-only instructions remain visible.
3. **PRODUCT VALIDATION** — expected/detected quantities and component state.
4. **NEXT** — hidden until product validation exposes `ready_for_finishing`.

When ready:

```
NEXT
EXPO / FINISHING
All ingredients accounted for.
```

After the controlled handoff:

```
SENT TO EXPO / FINISHING
KDS: READY
```

## Ingredient recognition overlay

Recognition may temporarily draw a thin outline around the ingredient bounding box.

The outline:

- has no filled center;
- uses normalized top-left computer-vision coordinates;
- may appear over the food;
- is automatically removed after 1.25 seconds by default;
- is the only intended projection inside the central work area.

This keeps product visibility primary while still confirming what the vision system recognized.

## Runtime binding

`ArHudRuntimeBinder` converts the current `ArWorkflowCoordinator` state into the HUD model at a bounded refresh interval. Vision/detector code can call `ShowIngredientObservation()` without knowing anything about the right-rail implementation.
