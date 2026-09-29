# V11 Section 2 — Production Evidence Capture

Section 2 extends the existing governed Vision Lab V5 training-media store in place. It does **not** create a second image/evidence repository.

Each captured production-training image can now carry durable lineage to:
- V11 training session;
- V11 assignment and training program;
- canonical employee/operator;
- paired glasses device;
- location/station through the active session;
- optional canonical build session;
- optional ready model package and artifact SHA-256;
- recipe-step key;
- validation outcome;
- retention policy;
- privacy scope;
- explicit training eligibility: review, eligible, or excluded.

Capture requires an active V11 glasses training session. The authenticated device identity cannot be overridden. If a build session is supplied, it must belong to the same device. The existing governed media writer still handles image validation, private storage, hashing, perceptual duplicate detection, annotations, quality, consent and retention.

Eligibility changes are audited through the existing glasses_vision_training_media_events ledger. Excluded evidence requires a reason. Section 2 does not automatically add evidence to a frozen/training dataset; later V11 curation controls decide that.
