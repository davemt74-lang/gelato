# Gelato AR Glasses Plugin — Section 17 Hands-Free Review & Correction UX

Section 17 provides the hardware-neutral command layer used by buttons, gestures, speech recognition, accessibility controls, and the desktop AIR3 simulator.

## Design rule

Vendor-specific input code does not call Gelato workflow methods directly.

It submits an explicit command through:

`HandsFreeCommandInput -> HandsFreeCommandRouter -> ArWorkflowCoordinator`

That keeps restaurant behavior deterministic while allowing the final INMO SDK adapter to remain thin.

## Supported commands

Canonical commands:

- `refresh work`
- `start build`
- `validate product`
- `confirm [ingredient]`
- `undo last`
- `resolve unexpected [ingredient]`
- `send to expo`

Supported aliases are intentionally narrow. Ambiguous phrases such as `finish` fail closed.

### Confirm

If exactly one component is in `verify`, `confirm` confirms it.

If multiple components need verification, a bare `confirm` performs no mutation and the HUD asks the cook to name the ingredient.

Examples:

- `confirm turkey`
- `confirm ingredient bacon`

### Unexpected item resolution

If exactly one unexpected component exists, `resolve unexpected` may resolve it.

When multiple unexpected items exist, the ingredient must be named.

Examples:

- `resolve unexpected swiss cheese`
- `ignore unexpected cheese`

### Undo / reject last

`undo last` and `reject last` use Section 15's append-only correction ledger.

They do not delete the original vision observation.

The correction key remains deterministic:

`reject:<observation-key>`

so repeated SDK events are idempotent at the backend.

### Expo

`send to expo` is accepted only when the current Product Validation is `ready_for_finishing`.

Before readiness, it returns `EXPO BLOCKED` and performs no KDS mutation.

After a successful handoff, repeated Expo commands return `ALREADY SENT TO EXPO` without calling the handoff endpoint a second time.

## Input serialization

`HandsFreeCommandRouter` serializes commands through a semaphore.

This prevents double button events, repeated gesture callbacks, or overlapping speech results from racing each other.

The first command completes before the next command is evaluated against workflow state.

## HUD behavior

Command feedback appears in a **HANDS-FREE** panel in the existing right rail.

The center safe zone remains unchanged.

Feedback includes:

- action title;
- concise result;
- attention state for blocked/ambiguous/error results.

The right rail still owns all persistent UI.

## Unity vendor ingress

`HandsFreeCommandInput` exposes:

- `SubmitCommand(string)`
- `RefreshWork()`
- `StartBuild()`
- `ValidateProduct()`
- `Confirm()`
- `RejectLast()`
- `ResolveUnexpected()`
- `SendToExpo()`

The future INMO adapter can bind SDK button, gesture, or recognized-speech events to these callbacks without knowing about KDS, validation, corrections, or Expo internals.

## Desktop simulator

Existing simulator controls now pass through the same router:

- F1 — refresh work
- F2 — start build
- V — validate product
- C — confirm
- X — resolve unexpected
- Z — undo/reject last
- E — send to Expo

This makes the simulator exercise the same command layer that production hardware will use.

## Safety behavior

Section 17 fails closed when:

- a command is unknown;
- no active build exists;
- a confirm/resolve command is ambiguous;
- no last observation exists;
- Expo validation is not ready.

Unknown speech has no kitchen side effects.

No automatic voice recognition is added in this section. The final INMO SDK adapter will provide the physical/voice input events after the vendor package arrives.
