# Vision Lab V6 Section 3 — Dataset Balance & Diversity Optimizer

Section 3 analyzes a curated dataset without changing its curation decisions, split plan, POS/KDS truth, or production build state.

## Metrics

The optimizer reports:

- positive class counts and minimum/maximum class balance index
- negative/background-example share
- hard-example share from corrected/active/hard evidence
- operator, location, station, and menu-item diversity
- device and camera diversity
- pose bucket coverage
- lighting/exposure coverage
- distance and occlusion coverage
- exact duplicate extras
- poor-quality media
- too-easy media

## Default policy

- target samples per positive class: 50
- target negatives: 10%
- target hard examples: 25%
- dominant environment ceiling: 50%

The API accepts explicit overrides when a training profile needs different targets.

## Recommendations

Recommendations are deterministic and advisory. They may request:

- additional samples for underrepresented classes
- additional negative/background examples
- additional hard examples
- alternate operators, locations, stations, devices, cameras, poses, or lighting
- review/reduction of overrepresented environments
- removal of exact duplicates
- removal or recapture of poor media
- deprioritization of too-easy samples

No recommendation automatically includes, excludes, reassigns, or moves a sample between train/validation/test.

## API

POST `api/glasses-vision-lab.php`

Action: `balance.analyze`

Required:

- `datasetPublicId`

Optional policy:

- `targetPerClass`
- `targetNegativeRatio`
- `targetHardRatio`
- `maxDominantShare`

Response schema: `gelato.vision_dataset_balance.v1`.
