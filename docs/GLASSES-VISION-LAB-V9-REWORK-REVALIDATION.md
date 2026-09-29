# Vision Lab V9 Section 8 — Exception Recovery, Rework & Revalidation

Section 8 preserves failed final-product evidence and adds a governed correction loop.

1. A Section 6 final validation in needs_correction can open a durable rework case.
2. The original validation remains immutable and is never changed to "pass."
3. The cook captures a new V9 scene after correction.
4. Revalidation produces a new immutable Section 6 final validation.
5. A revalidation attempt records the new scene, validation hash, state, and lineage from the original failure.
6. needs_correction or insufficient keeps the case open.
7. ready_candidate resolves the case and becomes the only validation eligible for Section 7 human confirmation.

The rework runtime does not mutate recipe components, quantities, POS, KDS, or inventory. Consequential kitchen handoff still belongs exclusively to Section 7 and the existing canonical Expo handoff engine.
