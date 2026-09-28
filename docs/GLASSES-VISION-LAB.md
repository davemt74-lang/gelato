# Vision Lab V2

Vision Lab V2 is Gelato's governed workspace for assigning AR glasses to people and turning production evidence into versioned model-training datasets.

## User ↔ glasses assignment

Assignments are historical records, not a single user field on a device.

Each assignment records:
- user;
- glasses device;
- location/station;
- role;
- assignment source;
- assignment/release timestamps;
- release reason.

Reassigning a device closes the previous active assignment first.

Supported assignment roles include operator, trainer, reviewer and calibration operator.

## Evidence attribution

Learning evidence can now identify:
- wearer/operator at observation time;
- corrector at human-correction time;
- annotator;
- reviewer;
- adjudicator.

These identities stay separate because producing evidence is not the same as certifying it as ground truth.

## Training missions

Model-training missions can be assigned to:
- user;
- glasses device;
- location;
- station.

Mission targets include total samples, positive examples, negatives and hard examples.

Mission provenance supports manual, active-learning, drift, new-menu and other source references.

Employee training is intentionally separate from model training.

## Review queue

Human-corrected production observations can be imported into Vision Lab.

Each sample remains pending until reviewed. Review decisions:
- approve;
- reject;
- relabel;
- needs adjudication.

Approved samples are eligible for governed datasets.

## Dataset versions

Dataset versions start as drafts.

Approved samples can be assigned to train, validation or test splits. Freezing a dataset:
- locks mutation;
- records row/class counts;
- stores split coverage;
- records contributor IDs;
- creates an immutable SHA-256 over the canonical manifest.

Frozen datasets cannot be modified.

## Dataset coverage

Vision Lab reports:
- per-class sample counts;
- train/validation/test counts;
- contributor diversity;
- whether validation and test coverage exist.

These are explicit dimensions rather than a synthetic AI-quality score.

## Production authority boundary

Vision Lab never:
- changes POS state;
- transitions KDS;
- submits production build observations;
- validates a product;
- performs Expo handoff;
- activates a model rollout.

It governs training evidence only.

## Vision Operations

Vision Operations shows the current wearer assignment in device drill-down and links directly to Vision Lab.

## Image data

This section governs metadata/evidence lineage. Browser image capture and YOLO export remain in the existing simulator training workflow. No new server-side kitchen-image upload path is introduced here.
