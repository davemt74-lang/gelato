# Vision Lab V8 Section 2 — Context-Aware Drift Detection

Section 2 explains whether a production health change is associated primarily with a measured environment/configuration shift, model-performance degradation, both, or insufficient/ambiguous evidence.

It is an evidence classifier, not a causal oracle.

## Baseline comparison

Each analysis consumes an intact Section 1 health snapshot and the active drift baseline for the same model package, location and station.

Measured context includes:
- calibration source hash;
- menu signature;
- ingredient signature;
- camera frame geometry and pixel format;
- brightness and contrast;
- camera pitch, yaw and roll.

Performance evidence includes:
- confidence change;
- inference-latency change;
- correction-rate change;
- low-confidence-rate change;
- V7 production-error rate.

## Classifications

- `stable`: context and performance remain close to baseline.
- `context_shift`: material measured context/configuration change without equivalent performance regression.
- `model_degradation`: performance regression while measured context remains close to baseline.
- `mixed`: both context and performance changed materially.
- `ambiguous`: evidence changed but does not cleanly separate the two.
- `insufficient_context`: no comparable baseline or fewer than three contextual production samples.

## Evidence confidence

`confidenceScore` is an evidence-completeness score based on available comparable fields and sample volume. It is **not** a probability that the classification is correct.

## Immutable audit

Every analysis stores:
- health snapshot public ID/hash/source fingerprint;
- exact baseline values and SHA-256 fingerprint;
- exact current-context sample references and fingerprint;
- context/performance signals and weights;
- classification/explanation;
- canonical SHA-256 analysis hash.

A lineage edge connects:
`model_health_snapshot → context_analyzed_as → context_drift_analysis`.

## Authority boundary

Section 2 never:
- claims causal certainty;
- changes calibration;
- changes confidence thresholds;
- activates/advances/rolls back a rollout;
- modifies KDS/POS/build truth.

Those decisions remain governed by later V8 sections and the existing rollout runtime.
