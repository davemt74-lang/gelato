# Vision Lab V6 Section 6 — Pre-Training Qualification Gate

Section 6 turns dataset readiness into an enforceable training boundary. A V6 release may be built and inspected before qualification, but the model-training path refuses it until a passing qualification attestation is embedded in the release package.

## Non-weakenable profile floors

The selected training profile defines minimum policy floors. API callers may request stricter thresholds, but cannot reduce the profile floor.

Default `yolo11n_640` floors:

- minimum 20 samples per positive class
- minimum 10 negative examples
- minimum 10 hard examples
- minimum 2 attributed operators
- minimum 1 attributed location
- minimum 1 attributed station
- minimum 1 device
- minimum 3 pose buckets
- minimum 2 lighting/exposure buckets
- zero protected-group split leakage
- zero unresolved annotation disagreements
- zero incomplete annotations
- zero exact-duplicate blockers
- zero poor-media blockers
- zero capture-group media leakage

Larger initial profiles raise selected sample/hard-example floors.

## Scorecard

Every check produces:

- stable check key
- human-readable label
- actual value
- required value
- comparison operator
- pass/fail

The readiness score is the percentage of passed checks. **Training unlocks only when every check passes**; a high partial score never overrides a failed gate.

## Attribution and diversity

Unattributed rows do not count toward operator, location, or station diversity. Device, pose, and lighting coverage come from governed V5 training-media metadata. This prevents missing lineage from being counted as diversity.

## Qualification attestation

A passing result creates deterministic `qualification.json` containing:

- schema
- release hash
- frozen dataset hash
- applied split-plan hash
- training profile
- effective policy
- all checks
- score
- `passed: true`
- qualification SHA-256

The qualification hash is SHA-256 over canonical attestation content before the `qualificationHash` field is added.

The qualified package is published as a new private ZIP artifact containing the unchanged training corpus plus the attestation. The corpus `releaseHash` does not change; the ZIP SHA-256 is updated because the attestation was added.

## Trainer enforcement

For V6 releases, `train-release` requires qualification. The Python runtime verifies:

1. `qualification.json` exists,
2. schema is supported,
3. qualification hash recomputes,
4. qualification release hash matches the package release hash,
5. `passed` is true,
6. every check is structurally valid and passing.

Inspection and diagnostic workspace preparation can still open an unqualified release, but model training cannot.

## Persistence

Every attempt is append-only in `glasses_vision_training_qualifications`. A failed first qualification marks the release `blocked`. A passing qualification marks it `qualified` and attaches the attestation artifact.

A later stricter failed audit does not erase an earlier valid qualification for the same immutable corpus; its failed attempt remains in history. Policy/schema evolution should use a new qualification schema when prior attestations need explicit retirement.

Schema: `gelato.vision_training_qualification.v1`.
