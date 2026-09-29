# Vision Lab V7 Section 7 — Governed Promotion & Continuous-Learning Audit

Section 7 closes the production-learning loop without granting Vision Lab permission to activate production rollouts.

## Promotion authorization

A promotion may be authorized only from a **passing, promotion-eligible Section 6 evidence review** whose underlying experiment remains completed and whose dataset/release/qualification/model-package evidence remains valid.

The authorization binds:

- exact production-error IDs and hashes;
- exact mined-candidate IDs and hashes;
- exact retraining-batch evidence hashes;
- mining run hash and source fingerprint;
- retraining batch hash;
- frozen dataset hash;
- qualified training-release hash;
- qualification hash;
- experiment hash;
- passing evidence-review hash;
- champion and challenger artifact SHA-256 values;
- rollout scope and human rationale.

The resulting promotion record is immutable and SHA-256 addressed.

## Fail-closed verification

Before a rollout can be created, Section 7 re-verifies the promotion hash and every bound evidence layer. Changes to production-error hashes, mined-candidate hashes, retraining evidence hashes, experiment/review hashes, dataset/release/qualification hashes, or model artifacts invalidate the chain.

## Rollout handoff

Section 7 may create only a normal **draft** model rollout with an initial 5% canary target.

It does not activate, pause, advance, complete, or otherwise bypass the existing rollout runtime. Existing shadow evaluation and canary gates remain authoritative.

The durable lineage is:

`production errors → mining run → retraining batch/dataset → experiment → evidence review → promotion authorization → draft rollout`

## Audit boundary

Authorization and draft-rollout creation are both recorded as immutable promotion events. Repeated identical requests are idempotent.

Section 7 never mutates POS, KDS, build-session truth, source production observations, training evidence, or model artifacts.
