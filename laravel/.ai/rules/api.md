---
paths:
  - 'app/Matomo/Api/**'
---

# Api

## Preserve the Matomo API boundary
Keep module, method, query, response, and status contracts unchanged. Do not cut an endpoint over until auth, formats, permissions, side effects, and plugin hooks have parity and the legacy path remains a tested fallback.

## Keep every public response format exact
Scalar, row, and list responses must match Matomo for JSON, XML, CSV, TSV, HTML, original, console, and RSS output. Preserve content types, empty results, download headers, Unicode conversion, serialization, and JSONP validation.
