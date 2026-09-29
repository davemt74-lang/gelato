# Vision Lab V8 — Production Autonomy, Drift Intelligence & Adaptive Perception Governance

## Section 1 — Production Model Health & Drift Ledger

V8 Section 1 builds an immutable operational-health history over Gelato's existing production drift telemetry and V7 production-error evidence.

It does not replace the existing drift detector or recovery runtime. Those systems remain the source of low-level production telemetry and governed recovery actions.

### Health windows

A snapshot is scoped to:
- exact model package and artifact SHA-256;
- optional location;
- optional station;
- optional device;
- UTC start/end timestamps.

For that window the ledger records:
- drift sample count and production observation count;
- V7 production-error count;
- correction and low-confidence counts;
- critical and warning drift counts;
- observation-weighted mean confidence;
- observation-weighted mean inference latency;
- error, correction and low-confidence rates.

### Immutable evidence

Every snapshot contains:
- a SHA-256 source fingerprint over the exact drift samples and V7 production-error hashes in the window;
- a canonical metrics payload;
- a SHA-256 snapshot hash;
- a model-package → health-snapshot lineage edge.

The same scope/window/evidence is idempotent. Late-arriving evidence creates a new fingerprint rather than rewriting the prior snapshot.

### Health state

Section 1 supplies a deterministic operational summary:
- `insufficient` — no evidence in the window;
- `healthy` — evidence exists without warning thresholds;
- `watch` — warning drift or elevated rates;
- `degraded` — materially elevated error/correction/low-confidence rates;
- `critical` — critical drift evidence exists.

These states are observational inputs for later V8 sections. They do not automatically change thresholds, calibration, training, or rollout state.

### Authority boundary

Section 1 never:
- advances or rolls back a model rollout;
- changes KDS/POS/build truth;
- creates training evidence;
- modifies existing drift samples or production-error events.

All timestamps are normalized to UTC before querying and hashing so identical instants produce identical evidence windows across clients/time zones.
