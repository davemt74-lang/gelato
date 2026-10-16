# FM-G9C — Room Presence and Reuse of Local Face Profiles

## Implementation
1. The only website-authenticated operator is the existing Super Admin. Clock-ins from FM-G9B continue to be separate from physical room presence.
2. Food Monster has one small camera toggle. Camera use always requires the user to enable it and allow HTTPS browser camera permission; camera stops if the tab hides or unloads. The chat microphone is independent.
3. An opt-in browser camera detects up to eight faces and maintains temporary bounding-box-based tracks. Tracks expire after 5.5 seconds without detection; server session observations expire after 15 seconds.
4. Existing **encrypted local** Face/PIN 128-D face descriptors are read *without modification* from the same browser's IndexedDB, and matched conservatively: distance below .42, next-nearest gap >= .085 and >=3 consecutive detections on one track. Multiple tracks claiming one identity are denied as ambiguous.
5. Existing Face/PIN enrollment is checked by the backend: the current Super Admin is logged in, the current trusted device remains approved, and the matched employee account is active and was explicitly enrolled on that device. No new photo, face vector, or camera frame is uploaded. Client matches remain **provisional**, never trusted as login or permission evidence.
6. Camera observations are scoped to the Super Admin's current session and organization; the ephemeral snapshot is available in `foodMonsterContext.room` through the existing Agent Workspace.
7. A small presence strip appears **only when the camera is enabled**. No participant wizard, permanent sidebar, or account switching.

## Important honest limits
- Face matching uses face-api.js 0.22.2 static browser library/model files from public CDNs, as the retained original enrollment did. Media is processed locally in the browser, not uploaded. Internet is needed for those model files until vendored. If face-api cannot load and the browser has native `FaceDetector`, the camera supports **anonymous presence only**.
- Only enrolled **employee profiles on the same trusted browser** can be reused. The former G8B scheme excluded Super Admin face enrollment; 9C cannot identify the owner automatically from nonexistent compatible template. Cross-device templates aren't synchronized. This is a planned later integration and must not be misrepresented as complete.
- The old 2D face vectors have no liveness/spoof protection. Server labels are provisional, so they cannot unlock protected data, change permissions, grant authority, or authenticate employee clock-in.
- No live two-camera/real-world biometric accuracy claim can be made from mocked browser tests. Field certification is required before claiming reliable employee recognition.
- Speaker attribution remains unknown until the conversation/voice integration phase. A face on video is **not** automatically the current speaker.

## Validation
- Pure multi-target tracking unit tests with confidence, ambiguity, consistency and departure.
- MariaDB-backed enrollment/organization/session isolation.
- Chromium camera-start/stop, permission constraints, provisional identity and context integration.
- Existing Face/PIN sign-in, time clock, Agent Workspace, Food Monster and deployment gates remain green.
