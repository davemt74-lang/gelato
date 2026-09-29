# V11 Section 5 — Dataset Assembly & Versioning

V11 Section 5 extends the existing V6 dataset/version/item system. It does not create a second dataset repository.

Assembly accepts only V11 production media that is:
- active;
- explicitly marked trainingEligibility=eligible;
- linked to an active V11 training-session history;
- backed by an approved canonical training sample;
- not poor quality when the default policy is used.

Selection is deterministic and bounded. It deduplicates by sample, exact image SHA-256, perceptual hash and capture group, then applies class/operator/device/location/station dominance caps. Existing V11 hard-example scores are retained as training-value provenance but hard examples are not required unless policy raises minimumHardExampleScore.

Selected samples are inserted through the existing glasses_vision_lab_add_dataset_sample() function. Dataset items retain the exact governed media and mined-candidate lineage, eligibility snapshot and training-value score.

Assembly automatically creates and applies the existing V6 group-aware split plan with capture-group, build-session, operator, location and station barriers enabled. This protects train/validation/test from obvious lineage leakage.

The dataset remains a normal V6 draft until the existing governed freeze path is invoked. V11 adds a freeze guard requiring eligible assembly provenance and all three splits. Once frozen, the existing immutable V6 manifest/hash/release behavior applies.
