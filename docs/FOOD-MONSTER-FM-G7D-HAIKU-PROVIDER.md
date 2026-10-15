# FM-G7D — Claude Haiku 5.5 and provider diagnostics

Food Monster's general conversational route uses the encrypted Anthropic credential already stored in Gelato. The Claude API model is now `claude-haiku-5-5`, not a retired dated Sonnet model. Adaptive thinking is explicitly disabled for short voice replies and generation is bounded to 600 output tokens.

Provider errors distinguish 400 invalid requests, 401 authentication, 402 billing, 403 access, 404 model, 429 limits, 5xx availability, and PHP cURL DNS/TLS/timeouts without exposing API keys, provider response bodies, or private prompts. A saved key marked configured does not prove live API access. If decrypt fails, preserve the original `storage/keys/application.key` or re-save the API key in Gelato LLM Settings.

No schema migrations. Require PHP and browser tests, exact-revision PR checks, and a full-deployment ZIP including MediaPipe. Physical HostGator/Claude connectivity requires field verification.
