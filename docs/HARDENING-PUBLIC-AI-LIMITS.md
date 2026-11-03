# Public assistant shared usage limits

Release E addresses audit finding **AI-ABUSE-01**. The anonymous public assistant previously kept its only allowance in a PHP session. A caller could omit its cookie, create another session, and obtain another allowance. `api/public-agent.php` now runs the shared controller in `includes/public-agent-core.php`; `includes/public-agent-limits.php` implements durable admission against MariaDB.

## What admission protects

Each enabled POST first uses the existing session allowance, closes the PHP session lock, and consumes a shared tenant/network intake allowance. Knowledge retrieval, credentials and a fully bounded provider payload must then be available before a provider reservation can be made. One short transaction reserves the organization's provider request count, conservative token units and an in-flight lease together. The transaction commits before transport starts; it never holds an organization lock across a provider call.

The server selects the same public organization as before: the first public-assistant settings row belonging to an active organization. Client-supplied organization fields do not select a tenant or move usage into another tenant's allowance. All counters and leases include the organization ID. This change does not add a new public tenant-selection interface.

Provider admission is refused if its database, tables or application secret are unavailable or its persisted limit state is malformed. Disabled assistance returns before intake admission. An unavailable answer, invalid input, or missing provider credential can consume intake allowance but consumes no provider request/token allocation or slot. GET readiness does not consume either allowance.

## Operator settings and defaults

The optional `public_agent_limits` section in `config.php` accepts these integer values. The defaults apply when a setting is omitted; zero, null, numeric strings, booleans and out-of-range values fail closed. These settings cannot be changed through an anonymous request or forwarded header.

| Setting | Default | Permitted range | Meaning |
| --- | ---: | ---: | --- |
| `network_requests_per_10_minutes` | 20 | 1–1,000 | Enabled POST intake per tenant and normalized network |
| `organization_requests_per_10_minutes` | 100 | 1–10,000 | Provider attempts across the entire organization |
| `organization_token_units_per_day` | 1,000,000 | 1–1,000,000,000 | Conservative allocation units across a 24-hour window |
| `organization_concurrent_requests` | 4 | 1–32 | Active provider leases across the organization |
| `provider_timeout_seconds` | 30 | 1–30 | Maximum cURL transport time per provider attempt |

The shared counters use fixed windows beginning at their first successful admission: 600 seconds for intake/provider requests and 86,400 seconds for token units. **The 24-hour window does not reset at midnight.** Database UTC is read after obtaining the organization lock, so a lock wait or the PHP application's local timezone cannot start a stale window. Fixed windows permit a burst around a reset boundary; the organization budget and concurrency cap remain independent protections. The secondary browser-session allowance retains its 20 requests in a rolling 600 seconds.

Example configuration:

```php
'public_agent_limits' => [
    'network_requests_per_10_minutes' => 20,
    'organization_requests_per_10_minutes' => 100,
    'organization_token_units_per_day' => 1000000,
    'organization_concurrent_requests' => 4,
    'provider_timeout_seconds' => 30,
],
```

Changing a limit does not erase an existing counter. Lowering it below current usage causes further admissions to wait until that window expires. Changing the application encryption key changes network digests but does not clear organization counters; normal key backup/restore requirements continue to apply.

### Allocation units and input bounds

One provider attempt reserves the complete serialized UTF-8 JSON request size in bytes, plus 500 units for bounded output and 1,024 units for protocol overhead. Both provider payloads explicitly limit output to 500 tokens. The outgoing JSON is serialized with the same flags for measurement and transport, and cannot exceed 131,072 bytes. This measures the system instructions, source headings, public knowledge, model, history, current question and JSON escaping together.

The request body is capped at 65,536 bytes before JSON parsing; the question remains at most 1,000 characters, history at most six entries of 1,200 characters each, the configured system prompt at most 6,000 characters, and selected knowledge at most 14,000 characters. The model name is capped at 160 characters. The serialized ceiling also handles inputs whose control characters or Unicode would expand beyond a character-only estimate. Responses from the upstream transport are capped at 131,072 bytes.

**These are conservative application allocation units, not measured provider billing tokens or a dollar-spend guarantee.** Provider tokenization, usage reporting, model pricing and billing behavior can differ. Use the provider account's own billing controls for a monetary ceiling. This allocation protects the public-assistant route only; it does not meter other application AI routes or unrelated consumers of the same provider account.

The entire reservation remains charged after success, upstream failure, invalid response, timeout, missing usage data, or a process crash. The controller does not refund allocation based on incomplete/untrusted usage fields and does not automatically retry a failed provider call. A request rejected before reservation commits does not consume a provider request, token allocation or slot. Its earlier intake admission remains consumed.

### Concurrency, failures and recovery

The organization row is the first exclusive lock for every admission. Counter writes and the lease insert occur in one transaction; a budget denial or storage failure rolls them all back. A caller-owned transaction is rejected, preventing an outer rollback from erasing an already executed provider call's accounting. Different PHP workers use these same rows.

Every lease has a random identifier, its reserved units and a fixed 60-second expiry. Normal success and every error path release that exact organization's lease in `finally`. A delayed completion cannot delete a replacement lease. If cleanup fails or a worker dies, the slot remains occupied until its lease expires. Valid expired leases are reclaimed during the next provider admission; malformed leases fail closed and require operator investigation rather than silently freeing capacity.

The 60-second lease exceeds the maximum 30-second transport timeout. Immediately before transport, the controller rechecks the lease against database UTC and refuses to start if there is not enough remaining time for the configured timeout. PHP session storage is unlocked before shared admission, so one session cannot hide provider concurrency behind a long session lock. Provider timeout bounds this application's transport; it cannot promise that a remote provider stops processing the moment a connection ends.

Expired network buckets are deleted in bounded batches during intake. Provider windows are updated in place and expired leases are reclaimed on demand; no scheduled cleanup is required for correctness. An inactive tenant retains its historical expired rows until it next receives relevant admission or is deleted. Organization deletion cascades to both new tables.

## Network identity and privacy

Only the web server's `REMOTE_ADDR` is used. The application ignores `X-Forwarded-For`, `Forwarded`, `X-Real-IP`, browser identifiers and cookies for shared network identity. IPv4-mapped IPv6 normalizes to IPv4; IPv6 addresses share a /64 network allowance to prevent trivial rotation of privacy addresses. Missing or invalid addresses share one restrictive unknown-network bucket.

The database stores a tenant-domain-separated HMAC-SHA-256 digest made with the existing protected application secret. It stores no raw IP address, cookie or user agent. The same network has different digests in different organizations, and a database-only copy does not provide an unkeyed list of network hashes. These are pseudonymous abuse-control identifiers, not a claim that a keyed identifier is unidentifiable to an operator holding the key. The limiter emits no raw-network log entries; a hosting platform may independently retain normal web access logs.

On a reverse proxy, PHP sees the proxy's address unless the web server has an explicitly trusted proxy policy that validates the sender and normalizes `REMOTE_ADDR` before PHP. Configure that policy at the hosting/web-server boundary when appropriate. Do not copy arbitrary client forwarding headers into `REMOTE_ADDR`. A shared NAT, corporate gateway or unconfigured proxy intentionally shares one network allowance, which can restrict legitimate visitors together. Distributed callers using many networks still share the organization request, allocation and concurrency ceilings. All application workers must use the same MariaDB and protected application key.

## HTTP behavior

Quota denials return HTTP `429` and an integer `Retry-After` header. JSON includes `code: "public_agent_rate_limited"`, the exhausted `reason`, matching `retryAfter` seconds and a visitor-facing message. Reasons are `session`, `network`, `organization_requests`, `organization_token_units` or `concurrency`. The concurrency retry reports the earliest currently occupied lease's expiry; a normally completing request can free capacity sooner.

Unavailable shared admission returns `503` without a provider call. Invalid/oversized input retains `400`, `422` or `413` responses as appropriate. An upstream failure returns `502`; its committed request/allocation remain charged while its lease is released. Provider URLs remain the fixed official OpenAI/Anthropic endpoints with redirect following disabled. There is no production setting that selects a test upstream or bypasses admission.

## Installation and verification

Apply **`database/20261231_public_agent_limits.sql`** through the existing upgrade process. `UpgradeService` automatically discovers the dated migration; no historical migration or base installation script was modified. It adds `public_agent_usage_buckets` and `public_agent_request_leases` with tenant foreign keys and expiry indexes. Deploying PHP before the migration fails provider admission closed; apply the migration before enabling or resuming the public assistant. Existing `config.php` files can omit the new section and receive the documented defaults.

Run the following against a disposable, fully migrated MariaDB database with the normal test configuration:

```sh
php tests/hardening-public-agent-limits.php
php tests/hardening-public-agent-concurrency.php
node tests/hardening-public-agent-http.cjs
```

The contract test covers privacy normalization, tenant isolation, changed sessions, configuration validation, window expiry, conservative charging, atomic rollback, crash/malformed leases, late release, caller transaction rejection and failed persistence. The concurrency test starts three independent PHP workers, observes all three in MariaDB `INNODB_LOCK_WAITS`, then releases the parent lock and checks network, request, allocation and concurrency ceilings separately. It requires the same lock-observation privileges as the existing MariaDB concurrency suites.

The HTTP test requires Linux PHP CLI multiworker serving, cURL, mbstring, Sodium, PDO MySQL and Node; it needs no paid credentials or network service outside localhost. Its CLI-only fixture stages byte-identical production files and runs the same `public_agent_serve()` controller with only provider transport injected into a strict localhost test router. The router serves no other path. The fixture uses a fake encrypted credential, preserves/restores the selected public tenant's settings/credentials/counters, and removes its own knowledge/users/organizations. Run it only against a disposable test database, with other database suites sequential. Both helper PHP entrypoints reject non-CLI execution; the fixture additionally requires its explicit test environment flag, which the Node test supplies. No production endpoint reads that flag.

HTTP cases include new cookie jars, forged forwarding headers and tenant fields, different real loopback source addresses, concurrent provider calls using the same session, request/allocation/concurrency denials, unavailable key/database, disabled/no-knowledge/missing-credential paths, full JSON expansion limits, both provider payload shapes, and actual localhost transport errors, malformed/empty/oversized responses and timeouts. Each denial checks that no extra request reaches the provider stub.

For negative evidence against immutable Release D, run:

```sh
php tests/hardening-public-agent-limits.php baseline /path/to/release-d/api/public-agent.php
```

This command must fail with `Fresh sessions must not obtain provider admission beyond the shared network limit.` It extracts the original `public_agent_rate_limit` function with PHP's tokenizer, runs that unchanged function in 21 fresh sessions and opens no database/provider connection. It needs only the normal bootstrap/session configuration. The positive contract applies the same boundary assertion to the new shared limiter. The baseline deliberately does not count a missing new file or schema as reproduced failure.
