# FM-G8E — Unified Data-Driven Participant Onboarding

- Users are permanent authenticated Gelato accounts; participants are live camera/voice tracks. A shift on the roster does **not** create a visual participant.
- The right sidebar contains **only one compact card per live track**. Each card has a small input meter. Source-linked audio may drive a matching card; the shared microphone only shows a provisional meter with one visible person and does not attribute a speaker among multiple people. Microphone monitoring starts only following explicit Agent Chat listening activation.
- Food Monster reads the existing, permission-scoped employee and account records **before** asking whether the person is a new employee or guest. It asks for a name only when no account can be confirmed.
- Signed-in existing users (including super admin) can explicitly confirm **This is me**. Their name, user ID and type come from authenticated Gelato data. No second user is created.
- For an introduced name, existing account matches are **candidates, not verified identities**. The authorized operator must explicitly link a candidate. The actual person must authenticate using Gelato's existing PIN/password policies for protected actions. Existing role assignments remain unchanged.
- Names without an account lead to a simple employee/guest question. New staff account creation remains under super-admin review. Photos and voice recording remain separately consented, self-only and quality-gated; low-light deferral continues to work.
- Participant history and existing Agent Chat remain linked to authenticated accounts, with no new sub-agent engine or additional onboarding system.
- Software CI does not certify physical voice diarization, low-light recognition or the 20-second capture target. These require device field tests.
