-- FM-G8D1: explicit low-light deferral permits voice-first onboarding.
ALTER TABLE fm_participant_goals ADD COLUMN photos_deferred TINYINT(1) NOT NULL DEFAULT 0 AFTER photo_consent_at;
