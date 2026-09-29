# V11 Section 1 — Training Assignment & Operator Identity

This section creates the trustworthy identity layer for later V11 evidence, corrections, datasets and model releases.

It reuses:
- canonical employees from organization_memberships/users;
- the existing generic training_assignments ledger;
- paired glasses from glasses_devices;
- existing locations and KDS stations.

A glasses training assignment records employee, program, optional device, location/station, supervisor, due date and mode. Creating it also creates a canonical employee training assignment with assignment_type `glasses_vision_program`.

A training session snapshots who is wearing which active paired glasses, where, under which program, mode and supervisor. Only one active training session may occupy a glasses device at a time. Session completion closes both the V11 assignment and the canonical employee training assignment.

Modes are `training`, `shadow`, and `production_preflight`. Section 1 does not mutate POS/KDS/build truth and does not grant V10 production authorization.
