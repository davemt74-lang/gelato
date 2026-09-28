# Gelato AR Glasses Plugin — Section 16 Vision Learning & Accuracy Signals

Section 16 turns the Section 15 human-correction ledger into measurable model-quality signals and a governed metadata-only learning export.

## Measurement rule

Gelato does **not** treat an uncorrected observation as proven correct.

The dashboard reports **human correction rate**:

```
observations with a latest human correction / observations in scope
```

This is an intervention signal, not a ground-truth accuracy score.

Outcomes are classified as:

- `uncorrected` — no human correction exists;
- `rejected` — human marked the observation as a false positive;
- `reclassified` — human moved the evidence to a different configured recipe component;
- `quantity_corrected` — human retained the component but changed the quantity;
- `reviewed` — a replacement/review exists without changing component or quantity.

## Dashboard

`glasses-learning.php` is available to users with `glasses.view`.

The dashboard can be scoped by:

- restaurant location;
- KDS station;
- 7 / 30 / 90 / 180 / 365 day window.

It shows:

- total observations;
- human-corrected observations;
- correction rate;
- rejected observations;
- reclassified observations;
- mean source model confidence;
- correction rate by confidence band;
- correction rate by evidence kind;
- component/ingredient correction rates.

Component rows are sorted by correction rate and then observation volume so operators can see where model tuning is most useful.

## Confidence bands

The stable bands are:

- 0.00–0.49
- 0.50–0.74
- 0.75–0.84
- 0.85–0.94
- 0.95–1.00

The bands measure how often humans intervene at each source confidence level. They do not convert model confidence into a claim of empirical accuracy.

## Governed learning dataset

Users with `glasses.manage` may export a JSONL dataset.

The default export contains **only human-reviewed observations**.

Each learning row uses schema:

`gelato.ar_learning.v1`

and preserves:

- observation identity and capture time;
- location / station / menu item;
- paired device platform + SDK/app versions;
- original model component;
- original action, quantity and confidence;
- confidence band;
- transfer/spatial evidence kind;
- source zone and destination region;
- sequence-support flag;
- latest human correction;
- effective corrected component and quantity.

The export does **not** include:

- camera frames;
- images;
- raw image bytes;
- bounding-box geometry.

A dataset manifest records:

- schema `gelato.ar_learning_dataset.v1`;
- whether the export is corrected-only;
- row count;
- deterministic SHA-256 dataset hash;
- generation timestamp.

## APIs

Summary:

```
GET api/glasses-learning.php?view=summary&locationId=...&stationPublicId=...&days=30
```

JSON dataset:

```
GET api/glasses-learning.php?view=dataset&locationId=...&days=30
```

Download corrected JSONL:

```
GET api/glasses-learning.php?view=dataset&locationId=...&days=30&correctedOnly=1&download=1
```

Summary access requires `glasses.view`. Dataset access/download requires `glasses.manage`. Location access remains governed by Gelato operational-location scope.

## Scope boundary

Section 16 does not train, promote, canary, or deploy an ML model.

It produces trustworthy evaluation signals and human-labeled metadata so future model training can be governed separately from live kitchen behavior.
