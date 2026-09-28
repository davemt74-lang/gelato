# Vision Lab V5 — Governed Training Media, Image Fingerprints & Visual Dataset Quality

Vision Lab V5 adds a private, explicitly opted-in media layer to the existing Vision Lab training-data pipeline.

## Privacy and retention boundary

Browser camera frames continue to stay local by default.

Server retention happens only when an operator explicitly enables **Store selected captures in private Vision Lab media** and uploads the local captures.

Every retained media record stores:

- consent basis: `training_media_opt_in`;
- retention deadline;
- creator;
- linked sample / mission / build / device / wearer when available;
- immutable server-verified SHA-256;
- deletion history.

Raw image bytes are stored under `storage/vision-training-media`, protected by the existing private `storage/.htaccess` rule.

Raw media retrieval requires `glasses.manage`. Metadata/quality summaries may be visible through the normal Vision Lab permission surface.

Deletion physically removes the private bytes and records a durable deletion event. Retention cleanup deletes items whose governed retention date has expired.

## Server-verified media facts

On upload, Gelato independently verifies:

- valid decodable image;
- supported MIME type: JPEG, PNG or WebP;
- byte size;
- image dimensions;
- SHA-256;
- annotation geometry.

The upload limit is 12 MiB per frame and dimensions are bounded to 64–8192 pixels per side.

## Browser-derived visual features

The Web Glasses Simulator calculates and submits documented browser-derived features:

- 64-bit difference hash (dHash);
- mean luminance / brightness;
- luminance contrast;
- Laplacian-variance blur estimate;
- current detector confidence when available;
- camera device/facing/resolution/FPS;
- optional pitch/yaw/roll;
- distance bucket;
- occlusion bucket;
- capture-group and burst index.

These are explicitly provenance-labelled as browser features. They are not represented as server-verified cryptographic facts.

## Quality flags

V5 identifies:

- low resolution;
- underexposure;
- overexposure;
- low contrast;
- blur;
- tiny bounding boxes;
- oversized bounding boxes;
- very-high-confidence / `too_easy` examples.

The visual-quality dashboard separates good, warning and poor media.

## Duplicate detection

### Exact duplicates

SHA-256 detects identical retained bytes.

Exact duplicate clusters are release blockers for a dataset.

### Near duplicates

Browser dHash is compared with Hamming distance. Pairs at distance 6 or less are surfaced as near duplicates.

Near duplicates are advisory rather than automatic blockers because perceptual similarity does not prove that two frames have identical training value.

V5 emits review/exclusion recommendations instead of silently deleting them.

## Capture-group leakage

Each browser capture session receives a stable capture-group ID and burst indexes.

For a governed dataset, V5 checks whether frames from one capture group cross train / validation / test boundaries.

Capture-group leakage is a hard release blocker.

This complements the V4 build-session leakage check.

## Environment and visual diversity

V5 reports:

- camera-device diversity;
- pitch/yaw/roll pose buckets when supplied;
- distance coverage;
- occlusion coverage;
- brightness/lighting buckets;
- time-of-day coverage.

This data feeds V4 Dataset Intelligence and collection planning.

## Exclusion and recapture recommendations

V5 produces explicit recommendations:

- exact duplicate → exclude redundant copies;
- near duplicate → review or exclude;
- poor visual quality → recapture or exclude;
- too easy → deprioritize.

These are recommendations only. V5 does not silently delete or approve training evidence.

## Dataset release integration

V5 extends the existing dataset freeze gate.

A new dataset freeze is blocked when its retained media has:

- exact duplicate clusters;
- capture-group split leakage;
- poor-quality media.

V4 readiness also receives a `visualMediaClear` condition.

Historical already-frozen datasets remain idempotently frozen; V5 does not retroactively mutate immutable releases.

## Immutable release identity

Frozen datasets now use `gelato.vision_dataset.v3`.

The frozen manifest includes the governed training-media export manifest:

- media public ID;
- SHA-256;
- dHash + feature provenance;
- dimensions;
- MIME/size;
- capture group and burst;
- quality flags;
- retention metadata;
- dataset split.

Private bytes and private storage paths are excluded.

The dataset SHA-256 therefore covers the visual-media lineage/fingerprints without embedding kitchen imagery into the manifest.

## Governed media export

Vision Lab can export a metadata-only `gelato.vision_training_media_export.v1` manifest.

The export explicitly marks `privateBytesExcluded=true`.

Individual raw images remain accessible only through the authenticated, permission-checked media endpoint.

## Production authority boundary

Training media never:

- writes POS state;
- transitions KDS;
- submits production build observations;
- validates a product;
- changes Expo handoff;
- changes calibration;
- promotes or activates models.

The path is:

Local capture → explicit opt-in → private media → pending Vision Lab sample → human review → dataset → visual-quality gate → immutable release → train/evaluate → governed rollout.
