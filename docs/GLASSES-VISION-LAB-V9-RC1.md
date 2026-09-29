# Vision Lab V9 — Kitchen Intelligence RC1

V9 RC1 is the release-hardening point for Sections 1–9.

## Canonical journey

POS/KDS order → active build session → governed live scene → recipe-step guidance → ingredient prevention → quantity verification → placement/quality verification → final product validation → rework/revalidation when needed → explicit human confirmation → canonical Expo/KDS handoff → governed kitchen-outcome evidence.

## Authority boundaries

- POS, KDS, build-session, and Expo handoff remain canonical.
- Sections 1–6 are advisory or validating only and cannot complete kitchen work.
- Section 7 is the sole V9 consequential boundary and requires explicit authenticated human confirmation before delegating to the existing canonical Expo handoff.
- Section 8 preserves failed evidence and creates superseding validation rather than rewriting history.
- Section 9 creates pending-review evidence only and cannot retrain, promote, roll out, or activate a model.

## RC1 acceptance

The V9 release gate requires:
- all nine section contracts green;
- clean schema install and all migrations exhausted;
- upgrade checksum validation and idempotence;
- release manifest/readiness complete;
- no duplicate KDS/build completion path in V9 runtimes;
- existing V8 governance plus build, calibration, rollout, corrections, and KDS regressions green;
- production deploy package generated from the exact green feature head.
