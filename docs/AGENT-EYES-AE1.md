# Agent Eyes AE1 — Live Room Canvas

AE1 makes the existing opt-in Food Monster room camera observable without creating a second capture stream or a new participant identity authority.

## Behavior
- The Agent Eyes launcher opens a fixed, responsive live preview. Closing only hides the preview: it does not secretly start or stop the camera.
- The explicit room-camera control remains authoritative. Permission is requested only on a user camera-start action. Hidden tabs and unload stop the stream and clear ephemeral presence.
- Bounding boxes are drawn from the existing normalized server-returned room observations. Known employee matches are visibly labeled **provisional**; others are **Unknown participant**.
- The participant list uses the same server-returned labels; it never presents local face candidates as verified identity.
- The preview reuses the active video element, so it does not allocate an extra camera stream.
- Video frames, face descriptors, keys and enrollment vectors are never added to room API payloads, LLM prompts, or persistent UI history. The existing CSRF, Super Admin, trusted-device and session-scoped server checks remain unchanged.

## Explicitly deferred
AE1 does not add biometric enrollment, cross-device identity, liveness checks, voice diarization, camera-to-mascot gaze, Vision Lab camera fusion, or persistent participant timelines. AE2–AE5 will handle these separately after AE1 certification.

## Certification
`Agent Eyes AE1 Live Room Canvas` runs JavaScript syntax, room tracking contracts, and Chromium UI acceptance using a fake media stream and mocked face detection. It checks preview state, boxes, known/unknown labels, camera lifecycle, local descriptor privacy, and continued tracking when the preview is closed. Real webcam field acceptance (lighting, frame rate, camera hardware, identification accuracy, and accessibility) remains an explicit deployment sign-off and cannot be claimed from CI alone.
