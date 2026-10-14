# Glasses Runtime — Employee and Kitchen Station
The Runtime panel displays **Assigned Employee** and **Kitchen Station**. The existing internal \`#deviceSelect\` remains mounted but hidden; changing Kitchen Station on the Glasses 1 preview chooses the corresponding authorized virtual KDS display, using the existing device change handler. Camera configuration, lens layout, ticket selection and runtime tabs remain intact.

## Employee meaning
- Glasses 1 preview: selection is stored in the authenticated account's existing glasses display preferences (no new table). This labels the preview operator for future supervised training workflows; it **never authenticates or impersonates** the selected employee, changes roles, or authorizes POS/KDS writes. View-only staff may select only themselves.
- Saved physical glasses: an operator with \`glasses.manage\` may explicitly select **Save Employee**. The server checks active wearer binding, restaurant authorization, current Vision Lab ownership, conflicting employee runtimes, and active training or builds. A Vision Lab-created assignment cannot be reassigned here. The wearer ledger update and runtime update are in one transaction; the new wearer must reconfirm their profile.
- The default Glasses 1 preview exists without hardware. Additional runtime tabs are created **only** after explicitly saving a new physical runtime.

## Persistence
Per-account preview operator and selected KDS preview source reuse \`glasses_user_display_preferences\`. Each physical runtime uses its existing database assignment, and station changes continue to use the previously governed \`runtime.station_update\` path. No new migration required beyond the existing installations for runtime and preferences.

## Acceptance
\`node tests/glasses-runtime-operator-refinement.test.js\`
\`php tests/glasses-runtime-operator-refinement-contract.php\`
Run all simulator, operator, device pairing, camera and deploy regressions against the exact candidate revision. Interactive testing with the user's actual Windows/iPhone camera and production KDS is a separate field gate.
