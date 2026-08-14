---
paths:
  - 'app/Matomo/Authentication/**'
  - 'app/Matomo/Security/**'
---

# Authentication

## Treat API credentials as secrets
Never log tokens or the Matomo salt. Bearer and POST tokens are secure sources, query tokens are not, and conflicting sources must fail before authentication.

## Keep temporary session tokens compatible
When `force_api_session=1`, validate the `MATOMO_SESSID` database session before stored API tokens. Decode session data with object creation disabled, enforce both expiry values and password-change time, compare tokens with `hash_equals`, then fall back to stored token auth when the session check fails.

## Enforce the reporting API IP allowlist first
Resolve the client IP with Matomo's configured proxy headers and proxy ranges. When the reporting API allowlist is active, reject a non-matching IP before method dispatch or authentication reads user data.

## Keep site access extensible
Load core roles from the existing access and site tables. Dispatch `UserSiteAccessLoaded` for regular users before returning permissions so migrated plugins can add or remove site access without changing core auth code.

Keep view-only, write-only, and admin site ID lists separate. Superusers receive every site for the admin role and an empty list for the view-only and write-only roles, matching Matomo's access contract.
