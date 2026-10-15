# FM-G8D1 — Rapid employee face/voice enrollment and low-light speaker matching

- Existing Gelato employees, including the protected owner when signed in as themselves, are selectable in the guided Agent Brain onboarding goal; linking does not change their role or password.
- Employee photo enrollment: three consented, auto-captured, quality-screened photos. Short voice enrollment uses Gelato's authenticated voice-identity API. Target total capture/processing under twenty seconds in suitable conditions, never guaranteed on slow devices or poor input.
- Camera/Agent popup expands above sticky chat footer. Photos encrypted with existing app key; face descriptors stored encrypted in browser for the signed-in account.
- Poor light can defer photographs and continue with an enrolled voice profile. Voice manager may run an explicitly consented short speaker comparison among currently active Gelato employee accounts in the same organization.
- Matching is conservative: below-threshold or close competing voices are rejected; multiple video tracks are NOT linked to an audio speaker. A result is a possible speaker, not proof of identity or permission to log in/clock in. TTS playback blocks voice sampling.
- Deploy the complete application, preserve config, uploads and storage keys; run upgrade.php to add the low-light schema column. Live microphone/camera/lighting/candidate calibration requires field testing.
