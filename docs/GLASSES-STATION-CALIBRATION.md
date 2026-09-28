# Gelato AR Glasses Plugin — Section 11 Station Calibration & Ingredient Zones

Section 11 adds versioned physical-station context to the glasses plugin.

The goal is to let vision combine what the camera sees with where ingredients are expected to live at a specific Gelato KDS station.

## Calibration model

Each active KDS station may have one active calibration profile.

A profile records:

- location + station identity;
- platform, such as `inmo_air3`;
- camera frame width and height;
- pixel format;
- version and SHA-256 source hash;
- one or more normalized ingredient zones.

Every new save creates a new immutable version and supersedes the previous active profile. Historical versions remain available for audit/recovery.

## Ingredient zones

A zone maps a normalized camera-frame rectangle to a canonical Gelato ingredient:

```
{
  "zoneKey": "turkey-primary",
  "ingredientId": 42,
  "displayName": "Turkey Pan",
  "x": 0.10,
  "y": 0.20,
  "width": 0.18,
  "height": 0.22,
  "priority": 20
}
```

Coordinates are normalized to `0..1` and must remain completely inside the camera image.

Multiple zones may point to the same ingredient, which supports backup pans or repeated storage locations. Priority resolves overlapping zones deterministically.

## Device compatibility

A paired device requests the active calibration using its assigned station plus the live camera signature.

The response marks the profile compatible only when the configured platform/frame geometry/pixel format match the runtime values.

A mismatch does not stop the KDS or AR build workflow. The client simply does not supply the station profile to the vision detector.

Typical mismatch reasons:

- `platform_mismatch`
- `frame_width_mismatch`
- `frame_height_mismatch`
- `pixel_format_mismatch`

This prevents a zone map created for one camera geometry from being projected onto a different image.

## Client integration

The Unity client exposes `StationCalibration`, `IngredientZone`, and `StationCalibrationPolicy`.

At the start of an active build, `VisionRuntimeController` loads the station profile once using the first valid camera frame.

When compatible, the profile is passed into `VisionFrameContext`, so any future `IVisionDetector` implementation can fuse:

- visual classification;
- active recipe expectation;
- calibrated ingredient-bin location;
- camera pose/calibration.

Calibration is deliberately optional. A missing profile, no station assignment, or calibration API failure degrades to ordinary visual recognition instead of putting the kitchen workflow into an error state.

## APIs

Admin/session API:

- `GET api/glasses-calibrations.php?locationId=...&stationPublicId=...`
- `POST api/glasses-calibrations.php` with `action=calibration.save`

Device API:

- `POST api/glasses-device.php` with `action=calibration.get`, `frameWidth`, `frameHeight`, and `pixelFormat`

Browser management retains normal Gelato authentication/CSRF rules. Device reads use the existing scoped glasses bearer token.

## Scope boundary

Section 11 stores and delivers physical station zones. It does not yet infer zones automatically from camera images and does not change ingredient confidence on its own.

That leaves the next layer free to add spatial evidence fusion without changing the persisted calibration contract.
