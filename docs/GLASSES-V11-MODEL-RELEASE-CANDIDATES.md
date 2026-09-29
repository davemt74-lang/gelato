# Vision Lab V11 Section 8 — Model Packaging, Registry & Release Candidate Governance

Section 8 keeps `glasses_vision_model_packages` as the canonical model registry. A V11 release candidate is a governance envelope around an existing benchmark-passing challenger package, not a second package registry.

Each RC binds the experiment hash, training-run/config hashes, frozen dataset and V11 assembly hashes, qualified release/qualification hashes, benchmark hash, model artifact URL/SHA/size, detector/runtime/platform, minimum SDK/app compatibility, labels/decoder metadata when available, and human release notes. The canonical manifest is SHA-256 addressed.

RC lifecycle is `pending_approval -> approved|rejected`. Approval re-verifies manifest integrity, benchmark status, and underlying package readiness. Approval never activates a rollout.

For V11 experiments, governed promotion requires an approved release candidate before authorization may proceed. Legacy non-V11 V7 flows retain their existing behavior.

Production activation, shadow evaluation, canary advancement, rollback and fleet assignment remain downstream in the existing rollout system.
