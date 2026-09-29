# V10 Section 5 — Long-Running Station Soak & Fault Injection

Section 5 adds a deterministic, non-consequential station-soak harness for the V10 runtime.

It stresses:
- sustained frame production;
- bounded queue overload and frame dropping;
- detector jitter and timeouts;
- repeated network, camera, and inference interruptions;
- repeated recovery cycles;
- queue drain and worker-idle guarantees;
- monotonic frame delivery;
- recovery success rate;
- unhandled-error and stall counters.

The harness does not submit build observations and does not mutate POS, KDS, build, handoff, rollout, or model state. The same report is evaluated by a server-side read-only acceptance contract. A passing run must score 10/10 under the configured release policy.
