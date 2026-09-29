# Vision Lab V9 Section 2 — Recipe Step Recognition

Section 2 interprets an immutable Section 1 live scene against the **existing canonical build definition** and returns an advisory current recipe step plus the next declared step.

It does not create another recipe engine and it never marks a build step complete.

## Canonical recipe ownership

Recognition consumes the build definition captured by the active build session:

- stable `step:<n>` keys;
- canonical recipe instruction text;
- declared step order;
- compiler-linked component keys;
- current build-component statuses;
- current expected component.

The build definition must still be the same canonical definition attached to the active build. Recognition also recomputes the live canonical build-context SHA-256. A scene that was valid when captured cannot generate new current-step guidance after component/KDS/build state advances. Historical recognitions remain verifiable.

## Scene evidence

Section 2 scores each declared step using only governed Section 1 scene evidence and canonical build state.

Ingredient/component cues include:

- visible governed ingredient mappings;
- whether a visible component belongs to the declared step;
- the current expected component;
- whether the step's component requirements are already complete;
- whether earlier component-linked steps are complete.

Action cues include visible relationships between:

- cutting tools and the product;
- spreading/mixing tools and the product/container;
- ovens, fryers, grills or toasters and the product;
- hands and the product for fold/wrap steps;
- product and calibrated plate/handoff surfaces.

No action cue creates an ingredient observation.

## Recognition policy

Policy: `gelato.recipe_step_policy.v1`

- recognized minimum score: **0.65**;
- recognized minimum margin over the second candidate: **0.12**;
- ambiguous minimum score: **0.50**.

A high score without sufficient separation is **ambiguous**, not guessed.

The exact policy is stored in immutable recognition evidence so a later scoring-policy change requires a new policy identity rather than silently changing historical interpretation.

## Results

A recognition is one of:

- `recognized`;
- `ambiguous`;
- `insufficient`.

A recognized result includes:

- recognized step key/order/text;
- next declared step key/order/text when present;
- confidence score;
- candidate margin;
- immutable candidate evidence.

## Integrity

Every recognition is SHA-256 bound to:

- exact scene ID/hash/source fingerprint;
- canonical build-context hash;
- canonical build definition;
- visible component keys;
- component statuses;
- scene entity hashes;
- same-frame relationships;
- exact recognition policy;
- complete candidate scoring and final result.

Recognition identity is transactional and idempotent per scene + policy.

Verification revalidates the upstream Section 1 scene and recomputes the recognition hash.

## Authority boundary

Recipe-step recognition is **advisory only**.

It never:

- calls `glasses_build_observe`;
- calls `glasses_build_confirm`;
- confirms or rejects a component;
- advances a build/recipe step;
- transitions KDS;
- changes POS;
- changes calibration/model/rollout state.

The glasses UI may say **"Current: Slice pizza — Next: Bake pizza"**, but canonical kitchen state changes only through the existing governed build/KDS flows.
