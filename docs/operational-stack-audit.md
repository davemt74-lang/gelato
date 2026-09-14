# Operational Stack Audit

Scope: Native POS, Floor Plan / Table Service, KDS / Expo, shared location permissions, transaction boundaries, and operational API error handling.

## Initial score

8.4 / 10

## Findings addressed

- Enforce `user_roles.location_id` for operational POS, Table Service, and KDS permissions instead of relying on the organization-wide permission union alone.
- Make POS and floor-plan core mutations transaction-composable so POS, KDS, table release, sales posting, and audit writes can commit or roll back together.
- Use database-authoritative KDS elapsed time calculations to avoid PHP/server timezone drift.
- Return safe generic 500-level API messages in production while logging internal exceptions server-side.
- Make KDS runtime controls follow the selected location's update/configure permissions.
- Keep Esc as a reliable route back to the Kitchen Dashboard even when browser Fullscreen API entry is blocked.

## Verification target

10 / 10 requires the dedicated operational hardening contract plus all affected Native POS, KDS, Table Service, POS/Floor integration, and upgrade/idempotence workflows to pass on the exact PR head.