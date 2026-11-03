# Glasses device accountability

Device bearer authentication now requires the device's saved `paired_by` user to
be active, unarchived, and an active member of the device's own restaurant. An
active membership in a different restaurant cannot authorize the device.

The canonical Expo handoff rechecks the same accountable identity under user and
membership locks before validation or kitchen writes. This also rejects a cached
device array after its accountable account has been suspended. The lock order
matches account lifecycle changes, so a handoff and suspension cannot pass stale
checks in opposite orders.

Device tokens retain their existing device and restaurant boundaries. This change
does not add permissions to the device, change wearer ownership, or expand the
device API's scope.

## Existing installations

No schema migration or historical data rewrite is required. A device with a
missing or inactive accountable account now fails closed with an instruction to
ask an active manager to revoke and re-pair it. The existing administrative flow
preserves the device's identity and issues a new bearer token. Reactivating the
same restaurant account restores its accountability for the existing token.

Legacy database test fixtures that previously created only a global pairing user
now create the corresponding active restaurant membership explicitly. Existing
authorization assertions remain in place.

## Verification

```sh
php tests/hardening-glasses-account-authority.php
php tests/glasses-foundation-contract.php
php tests/glasses-expo-handoff-contract.php
```

The focused MariaDB regression pairs a real device and exercises token
authentication and the canonical handoff actor path. It covers active pairing,
tenant suspension while another restaurant remains active, global suspension,
archival, membership only in another restaurant, missing accountability, and
re-pairing with token rotation. All fixture data is rolled back. The fixture
rejects HTTP execution.
