# Vision Operations & Fleet Health

Vision Operations is the read-only operational home for Gelato AR glasses.

It aggregates existing authoritative ledgers rather than creating a new source of truth.

## Fleet health

Each paired device is evaluated from:

- heartbeat freshness;
- device status;
- latest model assignment;
- current model package/version;
- rollout and cohort;
- latest runtime report;
- station calibration;
- latest production drift sample;
- unresolved drift/recovery incident.

Health states are:

- `healthy`
- `warning`
- `critical`
- `stale`
- `offline`
- `revoked`

Heartbeat rules:

- more than 5 minutes old → stale;
- more than 15 minutes old → offline.

Critical drift and failed runtime reports surface ahead of ordinary warnings.

## Fleet summary

The dashboard shows:

- total devices;
- healthy devices;
- devices needing attention;
- offline devices;
- open drift incidents;
- rolled-back devices;
- active model rollouts.

## Filters

Operators can filter the fleet by:

- location;
- station;
- health state;
- detector/model;
- device name, public ID or hardware identifier.

## Needs-attention queue

Non-healthy devices are ranked by operational severity.

Examples include:

- offline/stale device;
- critical or warning drift;
- unresolved recovery;
- failed runtime report;
- held model assignment;
- missing station calibration.

## Device drill-down

Selecting a device shows:

- identity and hardware;
- location/station;
- current model and cohort;
- rollout state;
- calibration version;
- drift and recovery context;
- runtime state/error;
- heartbeat freshness.

The drill-down links to the existing governed source workspaces for model/rollout/remediation, station calibration and learning evidence.

## Fleet timeline

The timeline combines recent:

- device lifecycle/heartbeat events;
- model runtime reports;
- drift/recovery incident activity.

This provides historical operational context without copying or mutating the underlying records.

## Authority boundary

Vision Operations is intentionally read-only.

It does **not**:

- write POS state;
- write KDS state;
- submit build observations;
- evaluate product validation;
- alter rollout selection;
- activate model packages;
- reset calibration;
- resolve drift incidents.

Those actions remain in their existing governed workspaces.

## Admin entry

Vision Operations is available from Restaurant Admin under **Intelligence → Vision Operations**, and from the AR Vision Models page.
