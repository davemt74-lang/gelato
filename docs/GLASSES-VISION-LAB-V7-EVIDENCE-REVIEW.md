# Vision Lab V7 Section 6 — Champion / Challenger Evidence Review

Section 6 is the promotion-evidence gate between a completed model experiment and any future rollout action.

The review binds three immutable evidence groups:
- the challenger's governed golden-test comparison metadata;
- a production-failure challenge-set evaluation, identified by its own SHA-256 and source fingerprint;
- a runtime comparison evaluation with latency, timeout and sample-count evidence.

Non-weakenable policy floors require:
- golden eligibility without a manual override;
- demonstrated golden-test gain;
- no mAP50 or recall regression;
- no listed golden regressions;
- at least 20 production-failure cases;
- no failure-set accuracy regression;
- zero safety-critical regressions;
- zero class-level regressions;
- at least 20 runtime samples;
- no more than 20% latency regression;
- no timeout regression.

Every distinct review is immutable and hash-addressed. Identical evidence deduplicates. Failed evidence remains preserved rather than overwritten by a later passing review.

A passing review sets `promotionEligible=true` only inside the review result. This section does not create, activate, or advance any rollout. Section 7 consumes the passing review under separate governance.
