---
paths:
  - 'app/Matomo/Authentication/**'
---

# Authentication

## Treat API credentials as secrets
Never log tokens or the Matomo salt. Bearer and POST tokens are secure sources, query tokens are not, and conflicting sources must fail before authentication.
