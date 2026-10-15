# FM-G8A — Active Participants & Local Camera View

Adds a dedicated Food Monster Agent/Camera switch and explicitly enabled camera on/off toggle to `food-monster.php`.

## Stage
- Central Camera mode shows the *existing* webcam stream and tracked face boxes, temporary IDs and detection confidence (not identity confidence).
- Agent mode retains floating mascot and sticky Agent Chat, with active participant cards on the right.
- Each tracked face has a short-lived, local browser card. Disappearing faces expire; Camera Off, tab hidden and pagehide erase track data and photos.
- Optional “Capture local thumbnails” stores resized previews **in RAM only**, never in the backend or persistent browser storage.
- Optional, manually enabled shared room mic meter does not identify who spoke; microphone tracks are released when disabled or page hidden.
- Unknown arrivals can trigger a single generic visible greeting, rate-limited. Named greetings are limited to the **existing opt-in encrypted, locally matched profile of the signed-in account**. A camera box, employee schedule or login session by itself never names a visible person.
- Existing camera settings, 3-sample self-enrollment, voice echo guard, microphone, Agent Brain and LLM settings are preserved.

## Staff context
- New authenticated, read-only and no-store endpoint `api/food-monster-participants.php` returns the **signed-in browser account**, authorized team schedule status and time-clock presence. These are independent facts.
- Schedule roster requires `schedule.view` or `schedule.manage`; `schedule.self` only returns the signed-in user's schedule. Attendance requires both the `timeclock.agent` capability and authorized `timeclock.view` or `attendance.view`. No one else's active **website login** is inferred from the time-clock or camera.
- “Employee not scheduled” is determined from actual attendance + today's shifts, not appearance. “Guest” is not assigned solely because the camera saw an unknown person.
- No new database migration. Existing schedule/time-clock tables are read only when present and permission-granted.

## Certification and next slice
- PHP contract, PHP syntax, real Chromium view/tracking/photo tests, existing FM-G3 camera enrollment tests and full deploy packaging required before merge.
- Physical webcam model accuracy and bystander consent on the installed site remain field checks.
- FM-G8B: multi-person voluntary enrollment/consent and secure per-participant identification, speaker-specific voice meters only with a tested speech-diarization mechanism. Until implemented, do not infer a particular speaker from shared mic levels.
