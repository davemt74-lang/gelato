# FM-G8B — Employee PIN + face-assisted login

**Onboarding:** A new employee uses Gelato's one-time password reset/setup flow to choose a private password and six-digit PIN. Existing employees may change their PIN via profile-pin.php after verifying their password.

**Terminal registration:** A protected Super Admin visits profile-pin.php on a controlled on-site browser and re-enters their password to register the terminal. This issues a random, HttpOnly, Secure, SameSite=Strict cookie for 180 days. SQL stores only its SHA-256 hash. The Super Admin can revoke the terminal.

**Face enrollment:** An employee signs into Gelato normally on that trusted terminal and consents to capturing three local face samples. A face-api.js face descriptor is encrypted in browser IndexedDB with a non-extractable AES-GCM key. No images or face descriptors are sent to Gelato's API or LLM. One face is required throughout. Employee accounts only; owner and CRM customer accounts do not qualify.

**Login:** On login.php, the employee explicitly clicks “Use camera to select my account.” The browser requests camera permission, compares the descriptor against on-device enrolled profiles, rejects ambiguous matches, stops the camera, and **only then** shows the matched employee's PIN form. Login does not happen until the server validates the six-digit PIN against a password hash and verifies a non-revoked trusted device and that specific account's enrollment. The server regenerates the session ID and audits the event. Invalid attempts lock the account credential after five failures and the device after ten failures for fifteen minutes.

**Important limits:** The face match is a user-interface convenience, *not a secure authentication factor*. A 2D webcam cannot reliably distinguish a live person from a photo or screen; the PIN and registered device remain essential. Do not use this on an untrusted browser or for privileged Super Admin accounts. For higher-risk authentication use a password or passkey instead. No face templates are kept on the server. Clearing site storage removes the browser's enrolled templates.

**Deployment:** Run owner-only upgrade.php after deploying to apply database/20261015_fm_g8b_face_pin.sql. Preserve config.php, .env, uploads and storage. Must field-test real HTTPS camera, two-person ambiguity, revoked/expired terminals, bad-PIN lockout, disabled employees and logout on a physical managed terminal. CI mocks recognition and does not constitute live biometric accuracy or spoof resistance certification.

**Release scope:** FM-G8B PIN sign-in is one section of the broader participant onboarding goal. It does not claim that multi-participant automatic recognition, persistent photo galleries, guided goals, CRM guest conversion or manager-authorized clock-in/out are complete.
